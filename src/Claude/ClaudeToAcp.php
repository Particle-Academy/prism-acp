<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Meta;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;
use Prism\Acp\Text;

/**
 * Translate the Claude CLI's `stream-json` output into ACP session updates. Ported from prism-acp-ts
 * src/claude/to-acp.ts, whose comments carry the reasoning; the ones kept here are the ones a reader of THIS file needs.
 *
 * Every shape was taken from captured traffic (`claude --print --output-format stream-json --verbose
 * --include-partial-messages`), except `background_tasks_changed` and `task_progress`, mapped from the Claude Agent
 * SDK reference because no capture holds them yet.
 *
 * Stateful on purpose: `content_block_start` names a block and its index, and the deltas that follow carry only the
 * index, so the mapper remembers what each index is.
 *
 * An update is a PHP array; a JSON object from the provider stays a stdClass. A map this mapper builds that can be
 * empty is cast to an object, so `{}` never goes out as `[]`.
 */
final class ClaudeToAcp
{
    /**
     * ACP's tool kinds, mapped from a tool NAME. A heuristic, so an unrecognised tool gets NO kind rather than `other`:
     * `other` asserts "none of the ten", omitting it says "we do not know which".
     *
     * An array lookup, never an object's: the reference's object literal answered `__proto__` with `{}` (G-96).
     */
    private const TOOL_KINDS = [
        'read' => 'read',
        'notebookread' => 'read',
        'write' => 'edit',
        'edit' => 'edit',
        'notebookedit' => 'edit',
        'multiedit' => 'edit',
        'bash' => 'execute',
        'bashoutput' => 'execute',
        'killshell' => 'execute',
        'glob' => 'search',
        'grep' => 'search',
        'webfetch' => 'fetch',
        'websearch' => 'fetch',
        'task' => 'think',
        'todowrite' => 'other',
    ];

    /** @var list<array{0: string, 1: string, 2: 'string'|'text'|'number'|'boolean'}> */
    private const BACKGROUND_TASK_FIELDS = [
        ['run_id', 'runId', 'string'],
        ['task_type', 'taskType', 'string'],
        ['subagent_type', 'subagentType', 'string'],
        ['description', 'description', 'text'],
        ['parent_task_id', 'parentTaskId', 'string'],
        ['ambient', 'ambient', 'boolean'],
    ];

    /** @var list<array{0: string, 1: string, 2: 'string'|'text'|'number'|'boolean'}> */
    private const TASK_FIELDS = [
        ['run_id', 'runId', 'string'],
        ['tool_use_id', 'toolUseId', 'string'],
        ['description', 'description', 'text'],
        ['task_type', 'taskType', 'string'],
        ['subagent_type', 'subagentType', 'string'],
        ['is_backgrounded', 'isBackgrounded', 'boolean'],
        ['spawn_depth', 'spawnDepth', 'number'],
        ['parent_task_id', 'parentTaskId', 'string'],
        ['ambient', 'ambient', 'boolean'],
        ['status', 'status', 'string'],
        ['reason', 'reason', 'string'],
        ['output_file', 'outputFile', 'string'],
        ['summary', 'summary', 'text'],
        ['last_tool_name', 'lastToolName', 'string'],
    ];

    /** @var list<array{0: string, 1: string, 2: 'string'|'text'|'number'|'boolean'}> */
    private const TASK_PATCH_FIELDS = [
        ['status', 'status', 'string'],
        ['description', 'description', 'text'],
        ['end_time', 'endTime', 'number'],
        ['total_paused_ms', 'totalPausedMs', 'number'],
        ['error', 'error', 'text'],
        ['is_backgrounded', 'isBackgrounded', 'boolean'],
    ];

    /** @var list<array{0: string, 1: string, 2: 'string'|'text'|'number'|'boolean'}> */
    private const TASK_USAGE_FIELDS = [
        ['total_tokens', 'totalTokens', 'number'],
        ['tool_uses', 'toolUses', 'number'],
        ['duration_ms', 'durationMs', 'number'],
    ];

    private const TASK_EVENTS = [
        'task_started' => ['started', 'task started'],
        'task_progress' => ['progress', 'task progress'],
        'task_updated' => ['updated', 'task updated'],
        'task_notification' => ['ended', 'task ended'],
    ];

    private const STDOUT_OPEN = '<local-command-stdout>';

    private const STDOUT_CLOSE = '</local-command-stdout>';

    /**
     * Frames this mapper did not recognise. Kept rather than counted: the first question anyone asks is which.
     *
     * @var list<mixed>
     */
    public array $unmapped = [];

    /**
     * Live content blocks, keyed by the index the CLI assigns them (spelled as JavaScript spells the number).
     *
     * @var array<string, array{kind: 'text'|'thinking'|'tool_use', toolCallId?: string, name?: string, rawInputJson: string}>
     */
    private array $blocks = [];

    /**
     * The last MAIN-THREAD API call of the turn so far, which is what the context holds. The `result` frame's usage
     * totals every call in the turn, so it cannot say how full the context is. Cleared at each `result`.
     *
     * @var array{inputTokens: int|float, cacheReadInputTokens: int|float, cacheCreationInputTokens: int|float, outputTokens: int|float}|null
     */
    private ?array $lastCall = null;

    /**
     * The message id of the last call, so a call already seen on the stream is not overwritten by its replay. Null is
     * the reset state and Undefined a message that had no id: the reference compares them as different values.
     */
    private mixed $lastCallId = null;

    /** A compaction's post-compaction size in this turn, from `compact_boundary`; cleared at each `result`. */
    private int|float|null $compactedTo = null;

    /** @var array<string, true> Subagent calls already announced: the CLI can repeat a frame. */
    private array $announced = [];

    /**
     * Translate one CLI frame into zero or more ACP updates.
     *
     * @return list<array<string, mixed>>
     */
    public function frame(mixed $input): array
    {
        if (! $input instanceof \stdClass) {
            return $this->unknown($input);
        }

        switch (self::tag(Js::get($input, 'type'))) {
            case 'stream_event':
                $event = Js::get($input, 'event');
                $parent = Js::get($input, 'parent_tool_use_id');
                if ($event instanceof \stdClass) {
                    $eventType = Js::get($event, 'type');
                    $message = Js::get($event, 'message');
                    if ($eventType === 'message_start' && $message instanceof \stdClass) {
                        $this->noteCall($parent, Js::get($message, 'usage'));
                        if ($parent === null) {
                            $this->lastCallId = Undefined::get($message, 'id');
                        }
                    } elseif ($eventType === 'message_delta') {
                        $this->noteCall($parent, Js::get($event, 'usage'));
                    }
                }

                return $this->streamEvent($event);
            case 'user':
                return $this->userFrame($input);
            case 'system':
                return $this->systemFrame($input);
            case 'result':
                return $this->resultFrame($input);
            case 'rate_limit_event':
                return $this->rateLimit($input);
            case 'assistant':
                // Its CONTENT is a replay of deltas already emitted, so it is ignored; its USAGE is the one per-call
                // figure a turn without streaming has. A SYNTHETIC message is the CLI's own answer: it never streams,
                // so this is the only place its text appears, and its zero usage must never become the context meter.
                $message = Js::get($input, 'message');
                $parent = Js::get($input, 'parent_tool_use_id');
                if ($message instanceof \stdClass && Js::get($message, 'model') === '<synthetic>') {
                    return $this->syntheticText($message);
                }
                if ($message instanceof \stdClass && $parent === null && ! self::same($this->lastCallId, Undefined::get($message, 'id'))) {
                    $this->noteCall($parent, Js::get($message, 'usage'));
                    $this->lastCallId = Undefined::get($message, 'id');
                }

                // A SUBAGENT's calls never stream, so this frame is the only place their names and arguments appear.
                return $this->subagentCalls($input);
            default:
                return $this->unknown($input);
        }
    }

    /** Note one call's usage. Later frames for the same call (its `message_delta`) carry the final output count. */
    private function noteCall(mixed $parent, mixed $usage): void
    {
        if ($parent !== null || ! $usage instanceof \stdClass) {
            return;
        }
        if (! Js::isNumber(Js::get($usage, 'input_tokens')) && ! Js::isNumber(Js::get($usage, 'output_tokens'))) {
            return;
        }
        $this->lastCall = [
            'inputTokens' => self::numberOf(Js::get($usage, 'input_tokens')),
            'cacheReadInputTokens' => self::numberOf(Js::get($usage, 'cache_read_input_tokens')),
            'cacheCreationInputTokens' => self::numberOf(Js::get($usage, 'cache_creation_input_tokens')),
            'outputTokens' => self::numberOf(Js::get($usage, 'output_tokens')),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function syntheticText(\stdClass $message): array
    {
        $content = Js::get($message, 'content');
        $updates = [];
        foreach (is_array($content) ? $content : [] as $block) {
            $text = Js::string($block, 'text');
            if (Js::get($block, 'type') === 'text' && $text !== null) {
                $updates[] = ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => $text]];
            }
        }

        return $updates;
    }

    /**
     * A subagent's `tool_use` blocks, as `tool_call`s marked with the call that spawned the subagent. Complete when
     * they arrive (nothing streams), so the arguments go straight into `rawInput`.
     *
     * @return list<array<string, mixed>>
     */
    private function subagentCalls(\stdClass $input): array
    {
        $parent = Js::get($input, 'parent_tool_use_id');
        $message = Js::get($input, 'message');
        $content = Js::get($message, 'content');
        if (! is_string($parent) || ! is_array($content)) {
            return [];
        }

        $updates = [];
        foreach ($content as $block) {
            if (! $block instanceof \stdClass || Js::get($block, 'type') !== 'tool_use') {
                continue;
            }
            $toolCallId = Js::get($block, 'id');
            if (! is_string($toolCallId) || isset($this->announced[$toolCallId])) {
                continue;
            }
            $this->announced[$toolCallId] = true;

            $name = Js::string($block, 'name') ?? 'unknown';
            $update = [
                'sessionUpdate' => 'tool_call',
                'toolCallId' => $toolCallId,
                'title' => Text::stripAnsiControlSequences($name)['text'],
                'name' => $name,
                'status' => 'pending',
            ];
            $rawInput = Js::get($block, 'input');
            if ($rawInput instanceof \stdClass) {
                $update['rawInput'] = $rawInput;
            }
            $kind = self::kindOf($name);
            if ($kind !== null) {
                $update['kind'] = $kind;
            }
            $updates[] = Meta::with($update, [Meta::PARENT_TOOL_CALL => $parent]);
        }

        return $updates;
    }

    /** @return list<array<string, mixed>> */
    private function streamEvent(mixed $event): array
    {
        if (! $event instanceof \stdClass) {
            return $this->unknown($event);
        }

        return match (Js::get($event, 'type')) {
            'content_block_start' => $this->blockStart($event),
            'content_block_delta' => $this->blockDelta($event),
            'content_block_stop' => $this->blockStop($event),
            // Envelope boundaries with no ACP counterpart: the content they wrap is already mapped. Listed so they are
            // not absorbed by the default branch, which would make the unmapped list too noisy to read.
            'message_start', 'message_delta', 'message_stop' => [],
            default => $this->unknown($event),
        };
    }

    /** @return list<array<string, mixed>> */
    private function blockStart(\stdClass $event): array
    {
        $index = self::indexOf(Js::get($event, 'index'));
        $block = Js::get($event, 'content_block');
        $type = Js::get($block, 'type');

        if ($type === 'text' || $type === 'thinking') {
            $this->blocks[$index] = ['kind' => $type, 'rawInputJson' => ''];

            return [];
        }

        if ($type === 'tool_use') {
            $toolCallId = Js::string($block, 'id') ?? "tool_{$index}";
            $name = Js::string($block, 'name') ?? 'unknown';
            $this->blocks[$index] = ['kind' => 'tool_use', 'toolCallId' => $toolCallId, 'name' => $name, 'rawInputJson' => ''];

            $update = [
                'sessionUpdate' => 'tool_call',
                'toolCallId' => $toolCallId,
                // `name` is the machine lookup key and stays verbatim; `title` is UI chrome.
                'title' => Text::stripAnsiControlSequences($name)['text'],
                'name' => $name,
                'status' => 'pending',
            ];
            $kind = self::kindOf($name);
            if ($kind !== null) {
                $update['kind'] = $kind;
            }

            return [$update];
        }

        return $this->unknown($event);
    }

    /** @return list<array<string, mixed>> */
    private function blockDelta(\stdClass $event): array
    {
        $index = self::indexOf(Js::get($event, 'index'));
        $delta = Js::get($event, 'delta');

        switch (self::tag(Js::get($delta, 'type'))) {
            case 'text_delta':
                return [[
                    'sessionUpdate' => 'agent_message_chunk',
                    'content' => ['type' => 'text', 'text' => self::stringOr(Js::get($delta, 'text'), '')],
                ]];

            case 'thinking_delta':
                // The estimate rides in _meta, never in usage_update: it is an estimate, and usage_update is measurement.
                $chunk = [
                    'sessionUpdate' => 'agent_thought_chunk',
                    'content' => ['type' => 'text', 'text' => self::stringOr(Js::get($delta, 'thinking'), '')],
                ];
                $estimate = Js::get($delta, 'estimated_tokens');

                return [Js::isNumber($estimate) ? Meta::with($chunk, [Meta::THINKING_TOKENS_ESTIMATE => $estimate]) : $chunk];

            case 'signature_delta':
                // PRESERVED: the integrity signature on a thinking block.
                return [Meta::with(
                    ['sessionUpdate' => 'agent_thought_chunk', 'content' => ['type' => 'text', 'text' => '']],
                    [Meta::THINKING_SIGNATURE => self::stringOr(Js::get($delta, 'signature'), '')],
                )];

            case 'input_json_delta':
                // Accumulated, not emitted per fragment: half an argument list would misrepresent what is going to run.
                if (($this->blocks[$index]['kind'] ?? null) === 'tool_use') {
                    $this->blocks[$index]['rawInputJson'] .= self::stringOr(Js::get($delta, 'partial_json'), '');
                }

                return [];

            default:
                return $this->unknown($event);
        }
    }

    /** @return list<array<string, mixed>> */
    private function blockStop(\stdClass $event): array
    {
        $index = self::indexOf(Js::get($event, 'index'));
        $state = $this->blocks[$index] ?? null;
        unset($this->blocks[$index]);

        if ($state === null || $state['kind'] !== 'tool_use' || ! isset($state['toolCallId'])) {
            return [];
        }

        $update = ['sessionUpdate' => 'tool_call_update', 'toolCallId' => $state['toolCallId'], 'status' => 'in_progress'];

        // rawInput only when the arguments parse: a half-written string under a field named rawInput is worse than none.
        $parsed = self::tryParse($state['rawInputJson']);
        if ($parsed !== Undefined::Value) {
            $update['rawInput'] = $parsed;
        }

        return [$update];
    }

    /** @return list<array<string, mixed>> */
    private function userFrame(\stdClass $input): array
    {
        $message = Js::get($input, 'message');
        $content = Js::get($message, 'content');

        if (is_string($content)) {
            // A built-in command's own output (e.g. /compact's "Compacted"), replayed as a user frame wrapped in
            // <local-command-stdout>. It is what the command SAID, so it is shown as agent text, marked as a command's.
            $trimmed = Js::trim($content);
            if (strlen($trimmed) >= strlen(self::STDOUT_OPEN) + strlen(self::STDOUT_CLOSE)
                && str_starts_with($trimmed, self::STDOUT_OPEN) && str_ends_with($trimmed, self::STDOUT_CLOSE)) {
                $said = Js::trim(substr($trimmed, strlen(self::STDOUT_OPEN), -strlen(self::STDOUT_CLOSE)));

                return $said === '' ? [] : [Meta::with(
                    ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => $said]],
                    [Meta::LOCAL_COMMAND => true],
                )];
            }
            // The summary a compaction carries forward: the model's next context, not something said here.
            if (Js::get($input, 'isSynthetic') === true) {
                return [];
            }
        }

        $updates = [];
        foreach (is_array($content) ? $content : [] as $entry) {
            if (! $entry instanceof \stdClass || Js::get($entry, 'type') !== 'tool_result') {
                continue;
            }
            $toolCallId = Js::get($entry, 'tool_use_id');
            if (! is_string($toolCallId)) {
                continue;
            }
            // Defaulting a failed call to `completed` would report a failure as a success: the direction that misleads.
            $update = [
                'sessionUpdate' => 'tool_call_update',
                'toolCallId' => $toolCallId,
                'status' => Js::get($entry, 'is_error') === true ? 'failed' : 'completed',
                'content' => [['type' => 'content', 'content' => ['type' => 'text', 'text' => self::textOf(Js::get($entry, 'content'))]]],
            ];
            $parent = Js::get($input, 'parent_tool_use_id');
            $updates[] = is_string($parent) ? Meta::with($update, [Meta::PARENT_TOOL_CALL => $parent]) : $update;
        }

        return $updates !== [] ? $updates : $this->unknown($input);
    }

    /** @return list<array<string, mixed>> */
    private function systemFrame(\stdClass $input): array
    {
        $subtype = self::tag(Js::get($input, 'subtype'));
        switch ($subtype) {
            case 'commands_changed':
                // Reported even when empty: `[]` says the agent offers none, which is a claim worth making.
                $commands = Js::get($input, 'commands');

                return [['sessionUpdate' => 'available_commands_update', 'availableCommands' => is_array($commands) ? $commands : []]];

            case 'compact_boundary':
                $meta = Js::get($input, 'compact_metadata');
                $meta = $meta instanceof \stdClass ? $meta : new \stdClass;
                $pre = Js::number($meta, 'pre_tokens');
                $post = Js::number($meta, 'post_tokens');
                if ($post !== null) {
                    $this->compactedTo = $post;
                }
                $compaction = [
                    'trigger' => Js::string($meta, 'trigger'),
                    'preTokens' => $pre,
                    'postTokens' => $post,
                ];
                $dropped = Js::number($meta, 'cumulative_dropped_tokens');
                if ($dropped !== null) {
                    $compaction['droppedTokens'] = $dropped;
                }
                $duration = Js::number($meta, 'duration_ms');
                if ($duration !== null) {
                    $compaction['durationMs'] = $duration;
                }
                $compaction['raw'] = $meta;
                $message = $pre !== null && $post !== null
                    ? 'Compacted the context from '.Js::localeEn($pre).' to '.Js::localeEn($post).' tokens.'
                    : 'Compacted the context.';

                return [Meta::with(['sessionUpdate' => 'notice', 'notice' => ['level' => 'info', 'message' => $message]], [Meta::COMPACTION => $compaction])];

            case 'thinking_tokens':
                return [Meta::with(
                    ['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'thinking']],
                    [Meta::THINKING_TOKENS_ESTIMATE => Js::get($input, 'estimated_tokens')],
                )];

            case 'permission_denied':
                return $this->permissionDenied($input);

            case 'init':
                // The CLI's OWN session id: the only string `--resume` accepts, so the only one `session/load` can use.
                // Emitted on the FIRST frame: a session that fails early still needs to be resumable.
                $cliSessionId = Js::get($input, 'session_id');
                if (! is_string($cliSessionId)) {
                    return $this->unknown($input);
                }
                $model = Js::get($input, 'model');
                $meta = [Meta::CLI_SESSION_ID => $cliSessionId];
                if (is_string($model) && $model !== '') {
                    $meta[Meta::MODEL] = $model;
                }
                $updates = [Meta::with(['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'session started']], $meta)];
                // After the notice, so the resume id stays the first thing a client learns.
                $commands = self::commandsFromInit($input);
                if ($commands !== null) {
                    $updates[] = $commands;
                }

                return $updates;

            case 'background_tasks_changed':
                return $this->backgroundTasks($input);

            case 'task_started':
            case 'task_progress':
            case 'task_updated':
            case 'task_notification':
                return $this->task($input, $subtype);

            default:
                // `status` and `api_retry` included: not mapped, but RECORDED. api_retry is how an outranked credential
                // announces itself (401, ten times).
                return $this->unknown($input);
        }
    }

    /** @return list<array<string, mixed>> */
    private function permissionDenied(\stdClass $input): array
    {
        $toolCallId = Js::get($input, 'tool_use_id');
        if (! is_string($toolCallId)) {
            return $this->unknown($input);
        }

        // `failed` is ACP's nearest honest status; the metadata carries that it was a refusal, not a crash. Emitted even
        // without a prior tool_call: a missing opener must not hide the denial.
        $details = [];
        $sanitizedAny = false;
        foreach (['tool_name', 'decision_reason_type', 'decision_reason_code', 'decision_reason'] as $field) {
            $value = Js::get($input, $field);
            if (is_string($value)) {
                $sanitized = Text::stripAnsiControlSequences($value);
                $details[$field] = $sanitized['text'];
                $sanitizedAny = $sanitizedAny || $sanitized['changed'];
            }
        }
        $message = Js::get($input, 'message');
        $content = is_string($message) ? Text::stripAnsiControlSequences($message) : null;
        if ($content !== null && $content['changed']) {
            $sanitizedAny = true;
        }
        // Preserve the fact of sanitization without echoing removed provider bytes.
        if ($sanitizedAny) {
            $details['providerTextSanitized'] = true;
        }

        $update = ['sessionUpdate' => 'tool_call_update', 'toolCallId' => $toolCallId, 'status' => 'failed'];
        if ($content !== null) {
            $update['content'] = [['type' => 'content', 'content' => ['type' => 'text', 'text' => $content['text']]]];
        }

        return [Meta::with($update, [Meta::PERMISSION_DENIED => (object) $details])];
    }

    /**
     * The full live set of background tasks. An empty array is emitted: "nothing is running" is a claim, and a client
     * that replaces its cached set with each payload needs it to clear.
     *
     * @return list<array<string, mixed>>
     */
    private function backgroundTasks(\stdClass $input): array
    {
        $entries = Js::get($input, 'tasks');
        if (! is_array($entries)) {
            return $this->unknown($input);
        }

        $tasks = [];
        $malformed = false;
        foreach ($entries as $entry) {
            $taskId = Js::get($entry, 'task_id');
            if (! $entry instanceof \stdClass || ! is_string($taskId) || $taskId === '') {
                $malformed = true;

                continue;
            }
            $tasks[] = ['taskId' => $taskId, ...self::copyFields($entry, self::BACKGROUND_TASK_FIELDS)];
        }
        // A bad entry must not withhold the rest of the set, and must not vanish either: the frame is recorded.
        if ($malformed) {
            $this->unmapped[] = $input;
        }

        return [Meta::with(
            ['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'background tasks']],
            [Meta::BACKGROUND_TASKS => $tasks],
        )];
    }

    /**
     * One task's lifecycle event, as a debug notice with the detail under META_TASK: ACP's `subagent_update` has no
     * published payload shape to put it in.
     *
     * @return list<array<string, mixed>>
     */
    private function task(\stdClass $input, string $subtype): array
    {
        $taskId = Js::get($input, 'task_id');
        if (! is_string($taskId) || $taskId === '') {
            return $this->unknown($input);
        }

        [$event, $message] = self::TASK_EVENTS[$subtype];
        $task = ['event' => $event, 'taskId' => $taskId, ...self::copyFields($input, self::TASK_FIELDS)];
        $sanitized = ($task['providerTextSanitized'] ?? null) === true;

        $patchSource = Js::get($input, 'patch');
        if ($patchSource instanceof \stdClass) {
            $patch = self::copyFields($patchSource, self::TASK_PATCH_FIELDS);
            if (($patch['providerTextSanitized'] ?? null) === true) {
                unset($patch['providerTextSanitized']);
                $sanitized = true;
            }
            $task['patch'] = (object) $patch;
        }
        $usage = Js::get($input, 'usage');
        if ($usage instanceof \stdClass) {
            $task['usage'] = (object) self::copyFields($usage, self::TASK_USAGE_FIELDS);
        }
        if ($sanitized) {
            $task['providerTextSanitized'] = true;
        }

        return [Meta::with(['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => $message]], [Meta::TASK => $task])];
    }

    /** @return list<array<string, mixed>> */
    private function resultFrame(\stdClass $input): array
    {
        $usage = Js::get($input, 'usage');
        $usage = $usage instanceof \stdClass ? $usage : new \stdClass;
        // The TURN's totals, kept in _meta for cost. Not `used`: they count the context once per call in the turn.
        $split = [
            'scope' => 'turn',
            'inputTokens' => self::numberOf(Js::get($usage, 'input_tokens')),
            'outputTokens' => self::numberOf(Js::get($usage, 'output_tokens')),
            'cacheReadInputTokens' => Js::number($usage, 'cache_read_input_tokens'),
            'cacheCreationInputTokens' => Js::number($usage, 'cache_creation_input_tokens'),
        ];
        $size = self::contextWindowOf(Js::get($input, 'modelUsage'));
        $lastCall = $this->lastCall;
        $compactedTo = $this->compactedTo;
        $this->lastCall = null;
        $this->lastCallId = null;
        $this->compactedTo = null;

        // ACP requires BOTH `used` and `size` on usage_update; without a context window there is no honest update.
        if ($size === null) {
            return [Meta::with(
                ['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'turn complete']],
                [Meta::UNMAPPED_FRAME => ['reason' => 'usage_update needs a context size', 'usage' => $usage]],
            )];
        }

        // A compaction with no model call after it: the context now holds what the compaction left, which the CLI measured.
        if ($lastCall === null && $compactedTo !== null) {
            return [Meta::with(['sessionUpdate' => 'usage_update', 'used' => $compactedTo, 'size' => $size], [Meta::USAGE => $split])];
        }

        if ($lastCall === null) {
            return [Meta::with(
                ['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'turn complete']],
                [Meta::UNMAPPED_FRAME => ['reason' => 'usage_update needs a per-call usage; the turn reported none', 'usage' => $usage]],
            )];
        }

        $used = $lastCall['inputTokens'] + $lastCall['cacheReadInputTokens'] + $lastCall['cacheCreationInputTokens'] + $lastCall['outputTokens'];
        $update = Meta::with(['sessionUpdate' => 'usage_update', 'used' => $used, 'size' => $size], [Meta::USAGE => [...$split, 'lastCall' => $lastCall]]);
        $cost = Js::get($input, 'total_cost_usd');
        if (Js::isNumber($cost)) {
            $update['cost'] = ['amount' => $cost, 'currency' => 'USD'];
        }

        return [$update];
    }

    /**
     * A notice because it is user-facing, AND _meta so a client can act on the reset times. The payload is NARROWED:
     * one it cannot read emits no rate-limit value at all and keeps the frame, with the reason naming the field.
     *
     * @return list<array<string, mixed>>
     */
    private function rateLimit(\stdClass $input): array
    {
        $read = ClaudeRateLimit::read(Js::get($input, 'rate_limit_info') ?? $input);

        if (is_string($read)) {
            return [Meta::with(
                ['sessionUpdate' => 'notice', 'notice' => ['level' => 'warning', 'message' => 'The provider reported a rate limit.']],
                [Meta::UNMAPPED_FRAME => ['reason' => $read, 'frame' => $input]],
            )];
        }

        return [Meta::with(
            ['sessionUpdate' => 'notice', 'notice' => ['level' => 'warning', 'message' => $read->notice()]],
            [Meta::RATE_LIMIT => $read],
        )];
    }

    /**
     * Record a frame with no mapping and emit nothing. Nothing is dropped silently.
     *
     * @return list<array<string, mixed>>
     */
    private function unknown(mixed $frame): array
    {
        $this->unmapped[] = $frame;

        return [];
    }

    /**
     * The `available_commands_update` an `init` frame implies: its `slash_commands` (offered without the leading `/`)
     * then its `skills`, each once, with plugins and subagents in `_meta`. `terminal_slash_commands`, and any skill
     * sharing a name with one, are left out: they act on the interactive TUI. Null when `init` carries neither list:
     * "never told" is not "offers none".
     *
     * @return array<string, mixed>|null
     */
    private static function commandsFromInit(\stdClass $input): ?array
    {
        $slash = Js::get($input, 'slash_commands');
        $skills = Js::get($input, 'skills');
        if (! is_array($slash) && ! is_array($skills)) {
            return null;
        }

        $terminalOnly = [];
        $terminal = Js::get($input, 'terminal_slash_commands');
        foreach (is_array($terminal) ? $terminal : [] as $command) {
            if (is_string($command) && $command !== '') {
                $terminalOnly[self::withoutSlash($command)] = true;
            }
        }

        $seen = [];
        $availableCommands = [];
        $add = function (mixed $raw, string $source) use (&$seen, &$availableCommands, $terminalOnly): void {
            if (! is_string($raw) || $raw === '') {
                return;
            }
            $name = self::withoutSlash($raw);
            if ($name === '' || isset($seen[$name]) || isset($terminalOnly[$name])) {
                return;
            }
            $seen[$name] = true;
            $availableCommands[] = [
                'name' => $name,
                'description' => $source === 'skill' ? 'Skill' : 'Command',
                '_meta' => [Meta::COMMAND_SOURCE => $source],
            ];
        };
        foreach (is_array($slash) ? $slash : [] as $command) {
            $add($command, 'command');
        }
        foreach (is_array($skills) ? $skills : [] as $skill) {
            $add($skill, 'skill');
        }

        $plugins = [];
        $rawPlugins = Js::get($input, 'plugins');
        foreach (is_array($rawPlugins) ? $rawPlugins : [] as $plugin) {
            $name = Js::get($plugin, 'name');
            if (! $plugin instanceof \stdClass || ! is_string($name) || $name === '') {
                continue;
            }
            $entry = ['name' => $name];
            foreach (['source', 'version'] as $field) {
                $value = Js::get($plugin, $field);
                if (is_string($value) && $value !== '') {
                    $entry[$field] = $value;
                }
            }
            $plugins[] = $entry;
        }
        $agents = Js::get($input, 'agents');
        $subagents = array_values(array_filter(is_array($agents) ? $agents : [], fn (mixed $a): bool => is_string($a) && $a !== ''));

        return Meta::with(
            ['sessionUpdate' => 'available_commands_update', 'availableCommands' => $availableCommands],
            [Meta::PLUGINS => $plugins, Meta::SUBAGENTS => $subagents],
        );
    }

    /**
     * Copy the fields that are present AND of the documented type; a field of the wrong type is left out, not coerced.
     * Sets `providerTextSanitized` when a `text` field had control sequences removed.
     *
     * @param  list<array{0: string, 1: string, 2: 'string'|'text'|'number'|'boolean'}>  $specs
     * @return array<string, mixed>
     */
    private static function copyFields(\stdClass $source, array $specs): array
    {
        $out = [];
        $sanitized = false;
        foreach ($specs as [$from, $to, $type]) {
            $value = Js::get($source, $from);
            if ($type === 'text') {
                if (! is_string($value)) {
                    continue;
                }
                $clean = Text::stripAnsiControlSequences($value);
                $out[$to] = $clean['text'];
                $sanitized = $sanitized || $clean['changed'];
            } elseif (($type === 'string' && is_string($value)) || ($type === 'number' && Js::isNumber($value)) || ($type === 'boolean' && is_bool($value))) {
                $out[$to] = $value;
            }
        }
        if ($sanitized) {
            $out['providerTextSanitized'] = true;
        }

        return $out;
    }

    /**
     * A discriminator, for a `switch`: the string, or '' for anything else. PHP's switch compares loosely, so a
     * provider's `"type": true` would otherwise match the first case; JavaScript's matches none.
     */
    private static function tag(mixed $value): string
    {
        // '' is no case's label, so a non-string discriminator reaches the default branch, as it does there.
        return is_string($value) ? $value : '';
    }

    private static function kindOf(string $name): ?string
    {
        return self::TOOL_KINDS[mb_strtolower($name, 'UTF-8')] ?? null;
    }

    /** One leading "/" removed, as `replace(/^\//, '')` does. */
    private static function withoutSlash(string $name): string
    {
        return str_starts_with($name, '/') ? substr($name, 1) : $name;
    }

    /** A block index as a map key: the number as JavaScript spells it, or -1 when it is not a number. */
    private static function indexOf(mixed $value): string
    {
        return Js::isNumber($value) ? Js::numberToString($value) : '-1';
    }

    private static function numberOf(mixed $value): int|float
    {
        return Js::isNumber($value) ? $value : 0;
    }

    private static function stringOr(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * JavaScript's `===` between two parsed values: by value for numbers (1 and 1.0 are one number) and strings, by
     * identity for objects. Two arrays are never the same, as two separately parsed arrays never are there.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if (Js::isNumber($a) && Js::isNumber($b)) {
            return $a == $b;
        }
        if (is_array($a) || is_array($b)) {
            return false;
        }

        return $a === $b;
    }

    /** The parsed arguments, or Undefined when there are none or they do not parse. */
    private static function tryParse(string $text): mixed
    {
        if (Js::trim($text) === '') {
            return Undefined::Value;
        }
        try {
            return Json::decode($text);
        } catch (\JsonException) {
            return Undefined::Value;
        }
    }

    /** The context window of whichever model the turn reported usage for. */
    private static function contextWindowOf(mixed $modelUsage): int|float|null
    {
        if (! $modelUsage instanceof \stdClass) {
            return null;
        }
        foreach (get_object_vars($modelUsage) as $entry) {
            $size = Js::number($entry, 'contextWindow');
            if ($size !== null) {
                return $size;
            }
        }

        return null;
    }

    /** Flatten a tool result's content, which is a string or an array of blocks. */
    private static function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (! is_array($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $entry) {
            $text .= Js::string($entry, 'text') ?? '';
        }

        return $text;
    }
}

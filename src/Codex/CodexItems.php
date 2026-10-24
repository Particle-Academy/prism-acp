<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;
use Prism\Acp\Text;

/** Live and replay item semantics share this mapper, including duplicate suppression and command previews. */
trait CodexItems
{
    private function mapItem(\stdClass $item, bool $replay, bool $completed = false): void
    {
        $type = Js::string($item, 'type') ?? 'unknown';
        $id = Js::string($item, 'id') ?? Js::string($item, 'itemId') ?? 'codex-'.$type.'-'.(++$this->itemSequence);
        $finished = $completed || $replay;
        $toolUpdate = $completed && ! $replay ? 'tool_call_update' : 'tool_call';
        if (! $replay) {
            if ($completed) {
                unset($this->activeItemIds[$id]);
                $this->resumeTurnDeadlineIfReady(true);
            } else {
                $this->activeItemIds[$id] = true;
                $this->pauseTurnDeadline();
            }
        }
        if ($finished && Js::has($item, 'status') && ! in_array(Js::string($item, 'status') ?? '', ['completed', 'failed', 'declined', 'canceled', 'cancelled', 'exited', 'interrupted'], true)) {
            $this->unmapped('completed Codex item had an unknown status', $item);
        }
        if ($type === 'userMessage') {
            if ($replay || $completed) {
                $text = CodexPayload::textFromContent(Js::get($item, 'content'));
                if ($text !== '') {
                    $this->emit(['sessionUpdate' => 'user_message_chunk', 'content' => ['type' => 'text', 'text' => $text]]);
                } else {
                    $this->unmapped('Codex userMessage had no text content', $item);
                }
            }

            return;
        }
        if ($type === 'agentMessage') {
            $text = Js::string($item, 'text') ?? '';
            if ($replay) {
                if ($text !== '') {
                    $this->emit(['sessionUpdate' => 'agent_message_chunk', 'messageId' => $id, 'content' => ['type' => 'text', 'text' => $text]]);
                } else {
                    $this->unmapped('replayed agentMessage had no text', $item);
                }
            } elseif ($completed) {
                $had = isset($this->messageDeltas[$id]);
                unset($this->messageDeltas[$id]);
                if (! $had && $text !== '') {
                    $this->emit(['sessionUpdate' => 'agent_message_chunk', 'messageId' => $id, 'content' => ['type' => 'text', 'text' => $text]]);
                } elseif (! $had) {
                    $this->unmapped('completed agentMessage had no text or deltas', $item);
                }
            }

            return;
        }
        if ($type === 'reasoning') {
            $summary = Js::get($item, 'summary');
            $content = Js::get($item, 'content');
            $text = is_array($summary) ? implode('', array_map(CodexPayload::textFromContent(...), $summary)) : '';
            if ($text === '' && is_array($content)) {
                $text = implode('', array_map(CodexPayload::textFromContent(...), $content));
            }
            if ($replay) {
                if ($text !== '') {
                    $this->emit(['sessionUpdate' => 'agent_thought_chunk', 'content' => ['type' => 'text', 'text' => $text]]);
                } else {
                    $this->unmapped('replayed reasoning item had no summary', $item);
                }
            } elseif ($completed) {
                $had = isset($this->reasoningDeltas[$id]);
                unset($this->reasoningDeltas[$id]);
                if (! $had && $text !== '') {
                    $this->emit(['sessionUpdate' => 'agent_thought_chunk', 'content' => ['type' => 'text', 'text' => $text]]);
                } elseif (! $had) {
                    $this->unmapped('completed reasoning item had no summary or deltas', $item);
                }
            }

            return;
        }
        if ($type === 'commandExecution' || $type === 'execCommand') {
            $command = Js::string($item, 'command') ?? '';
            $output = Js::string($item, 'aggregatedOutput') ?? (! $replay ? ($this->commandOutputs[$id]['text'] ?? null) : null);
            if ($finished && ! $replay) {
                $this->clearCommandOutput($id);
            }
            $rawInput = $command === '' ? [] : ['command' => $command];
            if (Js::has($item, 'cwd')) {
                $rawInput['cwd'] = Js::get($item, 'cwd');
            }
            $update = ['sessionUpdate' => $toolUpdate, 'toolCallId' => $id, 'title' => $command === '' ? 'Run command' : Text::stripAnsiControlSequences($command)['text'],
                'kind' => 'execute', 'status' => $finished ? CodexPayload::status(Js::get($item, 'status'), Js::get($item, 'exitCode')) : 'in_progress', 'rawInput' => (object) $rawInput];
            if ($finished) {
                $raw = [];
                if ($output !== null) {
                    $raw['output'] = $output;
                }
                foreach (['exitCode', 'durationMs'] as $key) {
                    if (Js::has($item, $key)) {
                        $raw[$key] = Js::get($item, $key);
                    }
                }
                $update['rawOutput'] = (object) $raw;
            }
            if ($finished && $output !== null) {
                $update['content'] = CodexPayload::textToolContent($output);
            }
            $this->emit($update);

            return;
        }
        if ($type === 'fileChange' || $type === 'patchApply') {
            if (! $finished) {
                $this->items[$id] = $item;
            }
            $changes = Js::get($item, 'changes') ?? Js::get($item, 'fileChanges') ?? Undefined::get($item, 'patch');
            if ($changes === Undefined::Value) {
                $this->unmapped('Codex file-change item omitted changes', $item);
            }
            $update = ['sessionUpdate' => $toolUpdate, 'toolCallId' => $id, 'title' => 'Apply file changes', 'kind' => 'edit',
                'status' => $finished ? CodexPayload::status(Js::get($item, 'status')) : 'in_progress'];
            if ($changes !== Undefined::Value) {
                $update['rawInput'] = $changes;
            }
            $this->emit($update);
            if ($completed) {
                unset($this->items[$id]);
            }

            return;
        }
        if ($type === 'mcpToolCall' || $type === 'dynamicToolCall') {
            $output = $type === 'mcpToolCall' ? (Js::get($item, 'result') ?? Undefined::get($item, 'error'))
                : (object) ['contentItems' => Js::get($item, 'contentItems'), 'success' => Js::get($item, 'success')];
            $content = $type === 'mcpToolCall' ? Js::get(Js::get($item, 'result'), 'content') : Js::get($item, 'contentItems');
            $text = [];
            if (is_array($content)) {
                foreach ($content as $entry) {
                    $entryType = Js::get($entry, 'type');
                    $entryText = Js::get($entry, 'text');
                    if (($entryType === 'text' || ($type === 'dynamicToolCall' && $entryType === 'inputText')) && is_string($entryText)) {
                        array_push($text, ...CodexPayload::textToolContent($entryText));
                    }
                }
            }
            $error = Js::string(Js::get($item, 'error'), 'message');
            if ($error !== null) {
                array_push($text, ...CodexPayload::textToolContent($error));
            }
            $update = ['sessionUpdate' => $toolUpdate, 'toolCallId' => $id,
                'title' => Text::stripAnsiControlSequences(Js::string($item, 'tool') ?? Js::string($item, 'name') ?? 'Tool call')['text'],
                'status' => $finished ? (Js::get($item, 'success') === false || Js::get($item, 'error') !== null ? 'failed' : CodexPayload::status(Js::get($item, 'status'))) : 'in_progress'];
            if (Js::has($item, 'arguments')) {
                $update['rawInput'] = Js::get($item, 'arguments');
            }
            if ($finished && $output !== Undefined::Value) {
                $update['rawOutput'] = $output;
            }
            if ($finished && $text !== []) {
                $update['content'] = $text;
            }
            $this->emit($update);

            return;
        }
        if ($type === 'contextCompaction') {
            $this->emit(['sessionUpdate' => 'compaction_update', 'status' => $finished ? 'completed' : 'in_progress']);

            return;
        }
        $this->unmapped('unmapped Codex item: '.$type, $item);
    }

    private function clearCommandOutput(string $id): void
    {
        if (isset($this->commandOutputTimers[$id])) {
            $this->loop->cancel($this->commandOutputTimers[$id]);
        }
        unset($this->commandOutputTimers[$id],$this->commandOutputs[$id]);
    }

    private function clearCommandOutputs(): void
    {
        foreach ($this->commandOutputTimers as $timer) {
            $this->loop->cancel($timer);
        }
        $this->commandOutputTimers = [];
        $this->commandOutputs = [];
    }
}

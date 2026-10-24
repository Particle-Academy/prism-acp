<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Meta;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\SessionId;
use Prism\Acp\Support\Js;
use Prism\Acp\Text;
use Prism\Acp\TurnFailure;

/** The pinned driver's incoming RPC and notification dispatch. */
trait CodexNotifications
{
    private function receive(mixed $raw): void
    {
        if ($this->closed) {
            return;
        }
        $frame = $raw;
        if (is_string($raw)) {
            try {
                $frame = Json::decode($raw);
            } catch (\Throwable) {
                $description = 'string('.Js::length($raw).')';
                $this->unmapped('malformed Codex JSON frame: '.$description, (object) ['type' => 'malformed_json', 'description' => $description]);

                return;
            }
        }
        if (! $frame instanceof \stdClass) {
            $this->unmapped('Codex frame was not an object', (object) ['description' => CodexPayload::describe($frame)]);

            return;
        }
        $method = Js::string($frame, 'method');
        $params = Js::get($frame, 'params');
        $threadId = Js::string($params, 'threadId');
        if ($threadId === null && $method === 'thread/started' && SessionId::isValidCliSessionId(Js::get(Js::get($params, 'thread'), 'id'))) {
            $threadId = Js::string(Js::get($params, 'thread'), 'id');
        }
        $id = Js::get($frame, 'id');
        $hasId = is_string($id) || Js::isNumber($id);
        if ($method !== null && $threadId !== null && $this->threadId !== null && $threadId !== $this->threadId) {
            $this->unmapped('Codex frame belongs to another thread: '.$method, (object) ['method' => $method]);
            if ($hasId) {
                $this->sendServerReply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'thread is not owned by this driver']]);
            }

            return;
        }
        $this->noteTurnActivity($method, $params);
        if ($method !== null && $hasId) {
            $turnId = Js::string($params, 'turnId');
            if ($this->activeTurn !== null && $turnId !== null && $turnId !== $this->activeTurn) {
                $this->unmapped('Codex request belongs to another turn: '.$method, $frame);
                $this->sendServerReply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'turn is not active in this driver']]);

                return;
            }
            if (in_array($method, self::APPROVAL_METHODS, true)) {
                $this->handleApproval($id, $method, $params);
            } elseif ($method === 'mcpServer/elicitation/request') {
                $server = Js::string($params, 'serverName') ?? 'an MCP server';
                $message = Js::string($params, 'message') ?? '';
                $mode = Js::string($params, 'mode') ?? 'unknown';
                $this->events->onProtocolError?->__invoke('Codex asked '.Json::encode($server).' for an answer this driver cannot give ('.$mode.'): '.Text::stripAnsiControlSequences($message)['text'].'. Declined. Set mcpToolApproval for '.Json::encode($server).' to let its tools run without asking.', true);
                $this->sendServerReply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['action' => 'decline']]);
            } else {
                $this->unmapped('unhandled Codex server request: '.$method, $frame);
                $this->sendServerReply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'method not supported: '.$method]]);
            }

            return;
        }
        if ($method !== null) {
            $this->handleNotification($method, $params);

            return;
        }
        $this->requirePeer()->receive($frame);
    }

    private function handleNotification(string $method, mixed $params): void
    {
        $object = $params instanceof \stdClass ? $params : new \stdClass;
        $turnId = Js::string($object, 'turnId') ?? Js::string(Js::get($object, 'turn'), 'id');
        if ($this->activeTurn !== null && $turnId !== null && $turnId !== $this->activeTurn) {
            $this->unmapped('Codex notification belongs to another turn: '.$method, (object) ['method' => $method, 'params' => $params]);

            return;
        }
        if (! in_array($method, self::KNOWN_NOTIFICATIONS, true)) {
            $this->unmapped('unmapped Codex notification: '.$method, (object) ['method' => $method, 'params' => $params]);

            return;
        }
        switch ($method) {
            case 'serverRequest/resolved':
                $id = Js::get($object, 'requestId');
                if (is_string($id) || Js::isNumber($id)) {
                    $key = is_string($id) ? $id : Js::numberToString($id);
                    if (isset($this->pendingApprovals[$key])) {
                        ($this->pendingApprovals[$key]['resolved'])();
                    }
                } else {
                    $this->unmapped('serverRequest/resolved omitted requestId', $object);
                }

                return;
            case 'item/commandExecution/outputDelta':
                $itemId = Js::string($object, 'itemId');
                $delta = Js::get($object, 'delta');
                if ($itemId === null || ! is_string($delta)) {
                    $this->unmapped('command output delta omitted itemId or string delta', $object);

                    return;
                }
                $previous = $this->commandOutputs[$itemId] ?? null;
                $combined = ($previous['text'] ?? '').$delta;
                $truncated = Js::length($combined) > 1000000;
                $this->commandOutputs[$itemId] = ['text' => CodexPayload::tail($combined, 1000000), 'truncated' => $truncated || ($previous['truncated'] ?? false)];
                if ($truncated && ! ($previous['truncated'] ?? false)) {
                    $this->unmapped('command output preview truncated to the last 1000000 characters', (object) ['itemId' => $itemId]);
                }
                if (! isset($this->commandOutputTimers[$itemId])) {
                    $this->commandOutputTimers[$itemId] = $this->loop->delay(0.2, function () use ($itemId): void {
                        unset($this->commandOutputTimers[$itemId]);
                        $output = $this->commandOutputs[$itemId] ?? null;
                        if ($output !== null) {
                            $this->emit(['sessionUpdate' => 'tool_call_update', 'toolCallId' => $itemId, 'content' => CodexPayload::textToolContent($output['text'])]);
                        }
                    });
                }

                return;
            case 'model/rerouted':
                $model = Js::string($object, 'toModel');
                if ($model === null || $model === '') {
                    $this->unmapped('model/rerouted omitted toModel', $object);
                } else {
                    $this->reportModel($model);
                }

                return;
            case 'thread/started':
                $thread = Js::get($object, 'thread');
                if (Js::has($thread, 'id')) {
                    $this->setThreadId(Js::get($thread, 'id'));
                } else {
                    $this->unmapped('thread/started omitted thread.id', $object);
                }

                return;
            case 'thread/status/changed':
                $flags = Js::get(Js::get($object, 'status'), 'activeFlags');
                if (is_array($flags) && in_array('waitingOnApproval', $flags, true)) {
                    $this->emit(['sessionUpdate' => 'notice', 'notice' => ['level' => 'info', 'message' => 'Codex is waiting for permission.']]);
                }
                $this->unmapped('thread/status/changed has no other ACP mapping', $object);

                return;
            case 'turn/started':
                $id = Js::string(Js::get($object, 'turn'), 'id');
                if ($id !== null) {
                    $this->activeTurn = $id;
                    $this->turnInFlight = true;
                    $this->sendPendingCancel($id);
                } else {
                    $this->unmapped('turn/started omitted turn.id', $object);
                }

                return;
            case 'turn/completed':
                $turn = Js::get($object, 'turn');
                $id = Js::string($turn, 'id') ?? $this->activeTurn;
                $status = Js::string($turn, 'status') ?? 'failed';
                if (! Js::has($turn, 'status')) {
                    $this->unmapped('turn/completed omitted status', $object);
                } elseif (! in_array($status, ['completed', 'interrupted', 'canceled', 'cancelled', 'failed'], true)) {
                    $this->unmapped('turn/completed had an unknown status', $object);
                }
                if ($id === null) {
                    $this->unmapped('turn/completed did not contain a turn id', $object);

                    return;
                }
                if ($status === 'completed') {
                    $outcome = new TurnOutcome('end_turn', false, $status);
                } elseif (in_array($status, ['interrupted', 'canceled', 'cancelled'], true)) {
                    $outcome = new TurnOutcome('cancelled', false, $status);
                } else {
                    $outcome = new TurnOutcome(null, true, $status, TurnFailure::fromCodex(Js::get($turn, 'error')));
                }
                $this->finishTurn($outcome, $id);

                return;
            case 'item/started': case 'item/completed':
                $item = Js::get($object, 'item');
                $this->mapItem($item instanceof \stdClass ? $item : new \stdClass, false, $method === 'item/completed');

                return;
            case 'item/agentMessage/delta': case 'item/reasoning/summaryTextDelta': case 'item/reasoning/textDelta':
                $message = $method === 'item/agentMessage/delta';
                $id = Js::string($object, 'itemId');
                if ($id !== null) {
                    if ($message) {
                        $this->messageDeltas[$id] = true;
                    } else {
                        $this->reasoningDeltas[$id] = true;
                    }
                    $this->activeItemIds[$id] = true;
                    $this->pauseTurnDeadline();
                } else {
                    $this->unmapped($method.' omitted itemId', $object);
                }
                $delta = Js::get($object, 'delta');
                if (! is_string($delta)) {
                    $this->unmapped($method.' omitted string delta', $object);

                    return;
                }
                $update = ['sessionUpdate' => $message ? 'agent_message_chunk' : 'agent_thought_chunk'];
                if ($message && $id !== null) {
                    $update['messageId'] = $id;
                }
                $update['content'] = ['type' => 'text', 'text' => $delta];
                $this->emit($update);

                return;
            case 'turn/plan/updated': $this->emitPlan(Js::get($object, 'plan'), 'plan');

                return;
            case 'turn/plan/delta': $this->emit(['sessionUpdate' => 'plan_update', 'delta' => Js::get($object, 'delta') ?? $object]);

                return;
            case 'thread/tokenUsage/updated': $this->emitUsage(Js::get($object, 'tokenUsage'));

                return;
            case 'account/rateLimits/updated': $this->emitRateLimit(Js::get($object, 'rateLimits'));

                return;
            case 'contextCompacted': case 'thread/compaction/started':
                $this->emit(CodexPayload::publicUpdate('compaction_update', $object));

                return;
            default: $this->emit(CodexPayload::publicUpdate('subagent_update', $object));
        }
    }

    private function emitRateLimit(mixed $value): void
    {
        $read = CodexRateLimit::read($value);
        if (is_string($read)) {
            $this->unmapped($read, (object) ['method' => 'account/rateLimits']);

            return;
        }
        $this->emit(Meta::with(['sessionUpdate' => 'notice', 'notice' => ['level' => $read->ordinaryUsageAllowed === false ? 'warning' : 'info', 'message' => $read->notice()]], [Meta::RATE_LIMIT => $read]));
    }

    private function emitUsage(mixed $value): void
    {
        $total = Js::get($value, 'total');
        if (! $total instanceof \stdClass) {
            $this->unmapped('thread/tokenUsage/updated omitted total', $value);

            return;
        }
        $input = Js::get($total, 'inputTokens');
        if (! Js::isSafeInteger($input) || $input < 0) {
            $this->unmapped('thread/tokenUsage/updated total.inputTokens expected a non-negative integer', $value);

            return;
        }
        $output = Js::get($total, 'outputTokens');
        if (! Js::isSafeInteger($output) || $output < 0) {
            $this->unmapped('thread/tokenUsage/updated total.outputTokens expected a non-negative integer', $value);

            return;
        }
        if ($input + $output > 9007199254740991) {
            $this->unmapped('thread/tokenUsage/updated total token sum exceeds a safe integer', $value);

            return;
        }
        foreach (['cachedInputTokens', 'reasoningOutputTokens'] as $field) {
            $count = Js::get($total, $field);
            if ($count !== null && (! Js::isSafeInteger($count) || $count < 0)) {
                $this->unmapped('thread/tokenUsage/updated total.'.$field.' expected a non-negative integer or null', $value);

                return;
            }
        }
        $window = Js::get($value, 'modelContextWindow');
        if (! Js::isSafeInteger($window) || $window <= 0) {
            $this->unmapped('thread/tokenUsage/updated modelContextWindow expected a positive safe integer', $value);

            return;
        }
        $last = Js::get($value, 'last');
        $used = Js::get($last, 'totalTokens');
        if (! Js::isSafeInteger($used) || $used < 0) {
            $this->unmapped('thread/tokenUsage/updated last.totalTokens expected a non-negative integer', $value);

            return;
        }
        $counts = ['inputTokens' => $total->inputTokens, 'outputTokens' => $total->outputTokens,
            'cachedInputTokens' => Js::get($total, 'cachedInputTokens'), 'reasoningOutputTokens' => Js::get($total, 'reasoningOutputTokens')];
        $this->emit(['sessionUpdate' => 'usage_update', 'used' => $used, 'size' => $window, '_meta' => [Meta::USAGE => ['scope' => 'thread', ...$counts], ...$counts]]);
    }

    private function emitPlan(mixed $value, string $sessionUpdate): void
    {
        if (! is_array($value)) {
            $this->unmapped('turn/plan/updated omitted plan array', $value);

            return;
        }
        $entries = [];
        foreach ($value as $index => $entry) {
            $status = Js::string($entry, 'status');
            if (! in_array($status, ['completed', 'inProgress', 'pending'], true)) {
                $this->unmapped('turn/plan/updated entry had an unknown status', $entry);
            }
            $entries[] = ['content' => Js::string($entry, 'step') ?? Js::string($entry, 'title') ?? 'Step '.($index + 1),
                'status' => $status === 'completed' ? 'completed' : ($status === 'inProgress' ? 'in_progress' : 'pending')];
        }
        $this->emit(['sessionUpdate' => $sessionUpdate, 'entries' => $entries]);
    }
}

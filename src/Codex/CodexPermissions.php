<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Meta;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;
use Prism\Acp\Text;

/** Five distinct native approval shapes, kept distinct through the shared ACP permission surface. */
trait CodexPermissions
{
    private function handleApproval(int|float|string $id, string $method, mixed $value): void
    {
        $params = $value instanceof \stdClass ? $value : new \stdClass;
        $key = is_string($id) ? $id : Js::numberToString($id);
        if (isset($this->pendingApprovals[$key])) {
            $this->unmapped('duplicate active Codex approval request id', (object) ['method' => $method, 'id' => $id]);
            ($this->pendingApprovals[$key]['cancel'])();

            return;
        }
        $result = new Deferred($this->loop);
        $settled = false;
        $cleared = false;
        if ($this->pendingApprovals === []) {
            $this->pauseTurnDeadline();
        }
        $release = function () use ($key): void {
            unset($this->pendingApprovals[$key]);
            $this->resumeTurnDeadlineIfReady();
        };
        $finish = function (mixed $value) use (&$settled, $result): void {
            if ($settled) {
                return;
            } $settled = true;
            $result->resolve($value);
        };
        $this->pendingApprovals[$key] = [
            'resolved' => function () use (&$cleared, &$settled, $release, $result): void {
                if ($cleared) {
                    return;
                } $cleared = true;
                $settled = true;
                $release();
                $result->resolve(null);
            },
            'cancel' => function () use (&$cleared, &$settled, $release, $result, $id, $method): void {
                if ($cleared) {
                    return;
                } $cleared = true;
                $settled = true;
                $release();
                try {
                    $this->transport?->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => self::cancelApprovalResult($method)]);
                } catch (\Throwable) {
                }
                $result->resolve(null);
            },
        ];
        // Match a Promise continuation: native resolution/turn completion may clear an answer before it is sent.
        $result->onSettle(function () use ($result, &$cleared, $release, $id): void {
            $this->loop->defer(function () use ($result, &$cleared, $release, $id): void {
                $value = $result->await();
                if ($value === null || $cleared) {
                    return;
                }
                $cleared = true;
                $release();
                try {
                    $this->transport?->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $value]);
                } catch (\Throwable) {
                }
            });
        });
        [$request,$choices] = $this->permissionRequest($method, $params);
        try {
            $promise = $this->events->onRequestPermission?->__invoke($request);
            if (! $promise instanceof Deferred) {
                $finish(self::cancelApprovalResult($method));

                return;
            }
            $promise->then(function (mixed $outcome) use ($finish, $method, $choices): void {
                $finish($outcome instanceof PermissionOutcome ? self::codexDecision($method, $outcome, $choices) : self::cancelApprovalResult($method));
            }, fn () => $finish(self::cancelApprovalResult($method)));
        } catch (\Throwable) {
            $finish(self::cancelApprovalResult($method));
        }
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function permissionRequest(string $method, \stdClass $params): array
    {
        $choices = [];
        $options = [];
        $add = function (string $id, string $name, string $kind, mixed $decision, ?array $meta = null) use (&$choices, &$options): void {
            $option = ['optionId' => $id, 'name' => Text::stripAnsiControlSequences($name)['text'], 'kind' => $kind];
            if ($meta !== null) {
                $option['_meta'] = $meta;
            } $choices[$id] = $decision;
            $options[] = $option;
        };
        $toolId = Js::string($params, 'itemId') ?? Js::string($params, 'callId') ?? Js::string($params, 'approvalId') ?? 'codex-permission-'.(++$this->approvalSequence);
        $title = 'Codex requests permission';
        $raw = Undefined::Value;
        $reason = Js::string($params, 'reason');
        if ($method === 'item/commandExecution/requestApproval') {
            $title = Js::string($params, 'command') ?? 'Run command';
            $network = Js::get($params, 'networkApprovalContext');
            $host = Js::string($network, 'host');
            $protocol = Js::string($network, 'protocol');
            if ($host !== null && $protocol !== null) {
                $title = 'Allow network access to '.$host.' ('.$protocol.')';
            } elseif (Js::get($params, 'kind') === 'writeStdin') {
                $title = 'Send input to terminal: '.(Js::string($params, 'command') ?? '');
            }
            $raw = new \stdClass;
            foreach (['command', 'cwd', 'kind', 'environmentId', 'additionalPermissions', 'commandActions', 'networkApprovalContext', 'proposedNetworkPolicyAmendments'] as $field) {
                if (Js::has($params, $field)) {
                    $raw->{$field} = Js::get($params, $field);
                }
            }
            $amendment = self::stringArray(Js::get($params, 'proposedExecpolicyAmendment'));
            $offered = Js::get($params, 'availableDecisions');
            if ($offered === null) {
                $offered = ['accept', 'acceptForSession', 'decline', 'cancel'];
                if ($amendment !== null) {
                    $offered[] = (object) ['acceptWithExecpolicyAmendment' => (object) ['execpolicy_amendment' => $amendment]];
                }
                $rules = Js::get($params, 'proposedNetworkPolicyAmendments');
                if (is_array($rules)) {
                    foreach ($rules as $rule) {
                        $offered[] = (object) ['applyNetworkPolicyAmendment' => (object) ['network_policy_amendment' => $rule]];
                    }
                }
            } elseif (! is_array($offered)) {
                $offered = [];
            }
            $networkChoice = 0;
            foreach ($offered as $decision) {
                if ($decision === 'accept') {
                    $add('codex-accept', 'Allow once', 'allow_once', 'accept');
                } elseif ($decision === 'acceptForSession') {
                    $add('codex-accept-session', 'Allow for this session', 'allow_always', 'acceptForSession');
                } elseif ($decision === 'cancel') {
                    $add('codex-cancel', 'Reject', 'reject_once', 'cancel');
                } elseif ($decision === 'decline') {
                    $add('codex-decline', 'Reject and continue', 'reject_once', 'decline');
                } elseif (Js::get($decision, 'acceptWithExecpolicyAmendment') instanceof \stdClass) {
                    $argv = self::stringArray(Js::get(Js::get($decision, 'acceptWithExecpolicyAmendment'), 'execpolicy_amendment')) ?? $amendment;
                    if ($argv !== null) {
                        $add('codex-accept-amendment', 'Allow and remember this command', 'allow_always',
                            (object) ['acceptWithExecpolicyAmendment' => (object) ['execpolicy_amendment' => $argv]], [Meta::EXEC_POLICY_AMENDMENT => $argv]);
                    } else {
                        $this->unmapped('Codex execpolicy amendment omitted its argv', (object) ['method' => $method, 'params' => $params]);
                    }
                } elseif (Js::get($decision, 'applyNetworkPolicyAmendment') instanceof \stdClass) {
                    $rule = Js::get(Js::get($decision, 'applyNetworkPolicyAmendment'), 'network_policy_amendment');
                    $ruleHost = Js::string($rule, 'host');
                    $action = Js::get($rule, 'action');
                    if ($ruleHost !== null && ($action === 'allow' || $action === 'deny')) {
                        $add('codex-network-'.($networkChoice++), ($action === 'allow' ? 'Allow' : 'Deny').' '.$ruleHost.' and remember',
                            $action === 'allow' ? 'allow_always' : 'reject_always', (object) ['applyNetworkPolicyAmendment' => (object) ['network_policy_amendment' => (object) ['host' => $ruleHost, 'action' => $action]]]);
                    } else {
                        $this->unmapped('Codex network policy amendment omitted host or action', (object) ['method' => $method]);
                    }
                } else {
                    $this->unmapped('unmapped Codex command approval decision', (object) ['method' => $method, 'decision' => $decision]);
                }
            }
        } elseif ($method === 'item/fileChange/requestApproval') {
            $title = 'Apply file changes';
            $item = $this->items[$toolId] ?? null;
            $raw = Js::get($item, 'changes') ?? Js::get($item, 'fileChanges') ?? (object) ['grantRoot' => Js::get($params, 'grantRoot')];
            $add('codex-accept', 'Allow once', 'allow_once', 'accept');
            $add('codex-accept-session', 'Allow for this session', 'allow_always', 'acceptForSession');
            $add('codex-decline', 'Reject', 'reject_once', 'decline');
            $add('codex-cancel', 'Cancel request', 'reject_once', 'cancel');
        } elseif ($method === 'item/permissions/requestApproval') {
            $title = 'Request additional permissions';
            $raw = Undefined::get($params, 'permissions');
            $permissions = Js::has($params, 'permissions') ? ['permissions' => Js::get($params, 'permissions')] : [];
            $add('codex-grant-turn', 'Allow for this turn', 'allow_once', (object) [...$permissions, 'scope' => 'turn']);
            $add('codex-grant-session', 'Allow for this session', 'allow_always', (object) [...$permissions, 'scope' => 'session']);
            $add('codex-deny', 'Reject', 'reject_once', (object) ['permissions' => new \stdClass, 'scope' => 'turn']);
        } else {
            $patch = $method === 'applyPatchApproval';
            $title = $patch ? 'Apply file changes' : (Js::string($params, 'command') ?? 'Run command');
            $toolId = Js::string($params, 'callId') ?? $toolId;
            if ($patch) {
                $raw = Undefined::get($params, 'fileChanges');
            } else {
                $raw = new \stdClass;
                foreach (['command', 'cwd'] as $field) {
                    if (Js::has($params, $field)) {
                        $raw->{$field} = Js::get($params, $field);
                    }
                }
            }
            $add('codex-approve', 'Allow once', 'allow_once', (object) ['decision' => 'approved']);
            $add('codex-approve-session', 'Allow for this session', 'allow_always', (object) ['decision' => 'approved_for_session']);
            $add('codex-deny', 'Reject and continue', 'reject_once', (object) ['decision' => (object) ['denied' => (object) ['rejection' => 'Declined by user']]]);
            $add('codex-abort', 'Cancel this turn', 'reject_once', (object) ['decision' => 'abort']);
        }
        if ($options === []) {
            $fallback = $method === 'item/permissions/requestApproval' ? (object) ['permissions' => new \stdClass, 'scope' => 'turn']
                : (in_array($method, ['applyPatchApproval', 'execCommandApproval'], true) ? (object) ['decision' => 'abort'] : 'cancel');
            $add('codex-cancel', 'Reject', 'reject_once', $fallback);
            $this->unmapped('Codex approval had no recognised available decisions', (object) ['method' => $method, 'params' => $params]);
        }
        $cleanTitle = Text::stripAnsiControlSequences($title);
        $cleanReason = $reason === null ? null : Text::stripAnsiControlSequences($reason);
        $tool = ['toolCallId' => $toolId, 'title' => $cleanTitle['text'], 'status' => 'pending'];
        if (str_contains($method, 'commandExecution') || $method === 'execCommandApproval') {
            $tool['kind'] = 'execute';
        } elseif (str_contains($method, 'fileChange') || $method === 'applyPatchApproval') {
            $tool['kind'] = 'edit';
        }
        if ($raw !== Undefined::Value) {
            $tool['rawInput'] = $raw;
        }
        $meta = [Meta::NS.'/codex_permission_method' => $method];
        if ($cleanReason !== null) {
            $meta['reason'] = $cleanReason['text'];
        }
        if ($cleanTitle['changed'] || ($cleanReason['changed'] ?? false)) {
            $meta['providerTextSanitized'] = true;
        }

        return [['toolCall' => $tool, 'options' => $options, '_meta' => $meta], $choices];
    }

    /** @param array<string,mixed> $choices */
    private static function codexDecision(string $method, PermissionOutcome $outcome, array $choices): mixed
    {
        if ($outcome->outcome !== 'selected' || $outcome->optionId === null || ! array_key_exists($outcome->optionId, $choices)) {
            return self::cancelApprovalResult($method);
        }
        $choice = $choices[$outcome->optionId];
        if ($method === 'item/permissions/requestApproval') {
            return $choice instanceof \stdClass ? $choice : (object) ['permissions' => new \stdClass, 'scope' => 'turn'];
        }
        if ($method === 'applyPatchApproval' || $method === 'execCommandApproval') {
            $decision = Js::has($choice, 'decision') ? Js::get($choice, 'decision') : $choice;
            $denied = Js::get($decision, 'denied');
            if ($outcome->reason !== null && $outcome->reason !== '' && $decision instanceof \stdClass && $denied instanceof \stdClass) {
                $decision = (object) ['denied' => (object) [...get_object_vars($denied), 'rejection' => $outcome->reason], ...array_diff_key(get_object_vars($decision), ['denied' => true])];
            }

            return (object) ['decision' => $decision];
        }

        return (object) ['decision' => $choice];
    }

    private static function cancelApprovalResult(string $method): \stdClass
    {
        if ($method === 'item/permissions/requestApproval') {
            return (object) ['permissions' => new \stdClass, 'scope' => 'turn'];
        }

        return (object) ['decision' => in_array($method, ['applyPatchApproval', 'execCommandApproval'], true) ? 'abort' : 'cancel'];
    }

    /** @return list<string>|null */
    private static function stringArray(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                return null;
            }
        }

        return $value;
    }
}

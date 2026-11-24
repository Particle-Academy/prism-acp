<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Mcp\McpServers;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Support\Js;
use Tests\Support\AcpSchema;
use Tests\Support\G362Oracle;
use Tests\Support\ScriptedCodexTransport;

/** @return array{sent:list<mixed>,events:list<array<string,mixed>>,closes:int} */
function runCodexScenario(stdClass $scenario): array
{
    $loop = new Loop;
    $events = [];
    $transport = null;
    $childEnv = null;
    $options = (array) ($scenario->options ?? new stdClass);
    $credentialRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-codex-key-golden-'.bin2hex(random_bytes(5));
    $privateHome = $credentialRoot.DIRECTORY_SEPARATOR.'app';
    $subscriptionHome = $credentialRoot.DIRECTORY_SEPARATOR.'subscription';
    if (isset($options['credentials'])) {
        $credentials = $options['credentials'];
        $options['credentials'] = new ProviderCredentials($credentials->mode, $credentials->apiKey ?? null,
            isset($credentials->home) ? str_replace('@key-home', $privateHome, $credentials->home) : null);
    }
    if (isset($options['mcpServers'])) {
        $options['mcpServers'] = McpServers::read($options['mcpServers']);
    }
    foreach (['parentEnv', 'mcpToolApproval'] as $dictionary) {
        if (isset($options[$dictionary])) {
            $options[$dictionary] = (array) $options[$dictionary];
        }
    }
    if (isset($options['parentEnv'])) {
        $options['parentEnv'] = array_map(fn (string $value): string => str_replace('@subscription-home', $subscriptionHome, $value), $options['parentEnv']);
    }
    $driver = new CodexDriver(new CodexDriverOptions(...['cwd' => '/fixture', 'parentEnv' => [], ...$options]), new DriverEvents(
        onUpdate: function (array $value) use (&$events): void {
            $events[] = ['type' => 'update', 'value' => $value];
        },
        onProtocolError: function (string $problem, ?bool $recoverable) use (&$events): void {
            $event = ['type' => 'problem', 'problem' => $problem];
            if ($recoverable !== null) {
                $event['detail'] = ['recoverable' => $recoverable];
            } $events[] = $event;
        },
        onTurnEnd: function (TurnOutcome $value) use (&$events): void {
            $outcome = ['stopReason' => $value->stopReason, 'isError' => $value->isError, 'raw' => $value->raw];
            if ($value->failure !== null) {
                $outcome['failure'] = $value->failure;
            }
            $events[] = ['type' => 'turnEnd', 'value' => $outcome];
        },
        onExit: function (?int $code, ?string $signal) use (&$events): void {
            $events[] = ['type' => 'exit', 'code' => $code, 'signal' => $signal];
        },
        onRequestPermission: function (array $value) use (&$events, $scenario, $loop): Deferred {
            $events[] = ['type' => 'permission', 'value' => $value];
            $choice = $scenario->permission ?? null;
            if ($choice === 'throw') {
                throw new RuntimeException('fixture callback');
            }
            if ($choice === 'pending') {
                return new Deferred($loop);
            }
            $answer = PermissionOutcome::cancelled();
            if ($choice === 'unknown') {
                $answer = PermissionOutcome::selected('unoffered');
            } elseif ($choice === 'allow') {
                $id = $value['options'][0]['optionId'];
                foreach ($value['options'] as $option) {
                    if ($option['kind'] === 'allow_once') {
                        $id = $option['optionId'];
                        break;
                    }
                }
                $answer = PermissionOutcome::selected($id);
            }
            $done = new Deferred($loop);
            $done->resolve($answer);

            return $done;
        }), function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use (&$transport, &$childEnv, $loop, $scenario): ScriptedCodexTransport {
            $childEnv = $options->env;

            return $transport = new ScriptedCodexTransport($handlers, $loop, $scenario);
        }, $loop);
    try {
        $driver->start();
        $driver->ready()->await();
        $loop->drain();
        foreach ($scenario->actions as $action) {
            switch ($action->type) {
                case 'frame':
                    $frame = Json::decode(Json::encode($action->frame));
                    if (isset($action->repeatDelta)) {
                        $frame->params->delta = str_repeat($action->repeatDelta->text, $action->repeatDelta->count);
                    }
                    $transport->feed($frame);
                    break;
                case 'prompt': $driver->prompt($action->content);
                    break;
                case 'cancel': $driver->cancel()->await();
                    break;
                case 'models': $events[] = ['type' => 'models', 'value' => $driver->modelState()->await()];
                    break;
                case 'setModel': $driver->setModel($action->model)->await();
                    break;
                case 'wait':
                    $wait = new Deferred($loop);
                    $loop->delay($action->ms / 1000, fn () => $wait->resolve(null));
                    $wait->await();
                    break;
                default: throw new LogicException('Unknown oracle action.');
            }
            $loop->drain();
        }
    } finally {
        $driver->kill();
        $loop->drain();
        if (is_dir($privateHome)) {
            rmdir($privateHome);
        }
        if (is_dir($credentialRoot)) {
            rmdir($credentialRoot);
        }
    }

    $result = ['sent' => $transport->sent ?? [], 'events' => $events, 'closes' => $transport->closes ?? 0];
    if ($scenario->auditCredentials ?? false) {
        $result['childEnv'] = $childEnv === null ? null : (object) $childEnv;
        $result['withheld'] = $driver->withheldCredentials();
    }
    if ($scenario->auditMcp ?? false) {
        $names = array_values(array_filter(array_keys($childEnv), static fn (string $name): bool => str_starts_with($name, 'PRISM_ACP_MCP_TOKEN_')));
        expect($names)->toHaveCount(1)->and($names[0])->toMatch('/\APRISM_ACP_MCP_TOKEN_[A-F0-9]{32}\z/')
            ->and($childEnv[$names[0]])->toBe('fixture-explicit-token');
        $result['childEnv'] = (object) $childEnv;

        $result = (array) Json::decode(str_replace($names[0], 'PRISM_ACP_MCP_TOKEN_FIXTURE_0', Json::encode($result)));
    }

    return (array) Json::decode(str_replace([substr(Json::encode($privateHome), 1, -1), substr(Json::encode($subscriptionHome), 1, -1)],
        ['@key-home', '@subscription-home'], Json::encode($result)));
}

$codexFixtures = Json::decode((string) file_get_contents(dirname(__DIR__).'/fixtures/codex-driver.json'));
dataset('codex oracle scenarios', array_combine(array_map(fn (stdClass $value): string => $value->name, $codexFixtures->scenarios),
    array_map(fn (stdClass $value): array => [$value], $codexFixtures->scenarios)));

it('matches the published TypeScript driver protocol oracle', function (stdClass $scenario): void {
    $actual = runCodexScenario($scenario);
    $actual = compactCodexLargeText($actual);
    // Canonicalize object member order only. Strict JSON text keeps booleans, numbers, nulls and array shapes distinct.
    expect(Json::encode(canonicalCodexValue(Json::decode(Json::encode($actual)))))
        ->toBe(Json::encode(canonicalCodexValue(G362Oracle::codex($scenario->expected))));
})->with('codex oracle scenarios');

/*
 * G-362. Codex plans were sent with no `priority` (required: a client built from the ACP schema drops every entry), and
 * `turn/plan/delta` was forwarded as `plan_update` with a `delta` field, a shape ACP does not have. Codex 0.163.0 has
 * no turn/plan/delta; a plan arrives whole, through turn/plan/updated. Same rows as prism-acp-ts's codex-driver test.
 */
function codexPlanScenario(array $frames): stdClass
{
    $actions = [(object) ['type' => 'prompt', 'content' => 'plan it']];
    foreach ($frames as [$method, $params]) {
        $actions[] = (object) ['type' => 'frame', 'frame' => (object) ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]];
    }
    $actions[] = (object) ['type' => 'frame', 'frame' => (object) ['jsonrpc' => '2.0', 'method' => 'turn/completed', 'params' => (object) ['turn' => (object) ['id' => 'turn-fixture', 'status' => 'completed']]]];

    return (object) ['name' => 'g362', 'actions' => $actions];
}

/** @return list<array<string, mixed>> */
function codexPlanUpdates(array $frames): array
{
    $updates = [];
    foreach (runCodexScenario(codexPlanScenario($frames))['events'] as $event) {
        if ($event['type'] === 'update') {
            $updates[] = $event['value'];
        }
    }

    return $updates;
}

it('sends every Codex plan entry with the priority the schema requires (G-362)', function (): void {
    $plans = array_values(array_filter(codexPlanUpdates([['turn/plan/updated', (object) ['threadId' => 'thread-fixture', 'turnId' => 'turn-fixture',
        'plan' => [(object) ['step' => 'first', 'status' => 'completed'], (object) ['step' => 'second', 'status' => 'inProgress'], (object) ['step' => 'third', 'status' => 'pending']]]]]),
        fn (array $update) => $update['sessionUpdate'] === 'plan'));
    expect($plans)->toBe([['sessionUpdate' => 'plan', 'entries' => [
        ['content' => 'first', 'priority' => 'medium', 'status' => 'completed'],
        ['content' => 'second', 'priority' => 'medium', 'status' => 'in_progress'],
        ['content' => 'third', 'priority' => 'medium', 'status' => 'pending'],
    ]]]);
});

it('never sends plan_update for a Codex plan delta, and says it did not map it (G-362)', function (): void {
    $updates = codexPlanUpdates([['turn/plan/delta', (object) ['turnId' => 'turn-fixture', 'delta' => 'progress']]]);
    $unmapped = array_filter($updates, fn (array $update) => ($update['_meta'][\Prism\Acp\Meta::UNMAPPED_FRAME]['reason'] ?? null) === G362Oracle::DELTA_REASON);
    expect(array_filter($updates, fn (array $update) => $update['sessionUpdate'] === 'plan_update' || array_key_exists('delta', $update)))->toBe([])
        ->and($unmapped)->toHaveCount(1);
});

it('sends only schema-valid plan messages through a whole Codex plan turn (G-362)', function (): void {
    // Every plan-family message. The driver's own `notice` and `compaction_update` predate ACP's stable shapes for
    // those variants and do not match them; that is a separate gap in the register, not something this may hide.
    $updates = codexPlanUpdates([
        ['turn/plan/updated', (object) ['turnId' => 'turn-fixture', 'plan' => [(object) ['step' => 'a', 'status' => 'inProgress'], (object) ['step' => 7, 'status' => 'pending'], new stdClass]]],
        ['turn/plan/delta', (object) ['turnId' => 'turn-fixture', 'delta' => 'progress']],
        ['turn/plan/updated', (object) ['turnId' => 'turn-fixture', 'plan' => []]],
    ]);
    $plans = array_values(array_filter($updates, fn (array $update) => str_starts_with((string) $update['sessionUpdate'], 'plan')));
    expect(array_column($plans, 'sessionUpdate'))->toBe(['plan', 'plan']);
    foreach ($plans as $plan) {
        expect(AcpSchema::sessionUpdate($plan))->toBe([]);
    }
});

/** Compare long command previews by exact UTF-16 length and UTF-8 digest, keeping fixtures compact. */
function compactCodexLargeText(mixed $value): mixed
{
    if (is_string($value) && Js::length($value) > 1024) {
        return (object) ['$comparison' => 'long-string', 'utf16Length' => Js::length($value), 'utf8Sha256' => hash('sha256', $value)];
    }
    if (is_array($value)) {
        return array_map(compactCodexLargeText(...), $value);
    }
    if ($value instanceof stdClass) {
        $result = new stdClass;
        foreach (get_object_vars($value) as $key => $entry) {
            $result->{$key} = compactCodexLargeText($entry);
        }

        return $result;
    }

    return $value;
}

function canonicalCodexValue(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map(canonicalCodexValue(...), $value);
    }
    if (! $value instanceof stdClass) {
        return $value;
    }
    $properties = get_object_vars($value);
    ksort($properties, SORT_STRING);

    return (object) array_map(canonicalCodexValue(...), $properties);
}

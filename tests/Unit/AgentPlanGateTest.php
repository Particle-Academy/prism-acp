<?php

declare(strict_types=1);

use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\Json;
use Tests\Support\AcpSchema;
use Tests\Support\FakeDriver;

require_once dirname(__DIR__).'/Support/FakeDriver.php';

/*
 * G-362 at the seam every driver shares, row for row with prism-acp-ts's acp-agent test. `plan_update` and
 * `plan_removed` MUST NOT reach a client that did not advertise `clientCapabilities.plan`, and a plan entry MUST carry
 * a priority. Both are enforced where every driver's updates leave the agent, so no driver can send either.
 */

/** @return array{driver: FakeDriver, log: list<mixed>, sent: list<mixed>, problems: list<string>} */
function planGateSession(mixed $clientCapabilities): array
{
    $loop = new Loop;
    $sent = [];
    $log = [];
    $problems = [];
    $drivers = [];
    $peer = new JsonRpcPeer(send: function (mixed $message) use (&$sent): void {
        $sent[] = Json::decode(Json::encode($message));
    }, loop: $loop);
    new AcpAgent(
        peer: $peer,
        driverFactory: function (array $options, DriverEvents $events) use (&$drivers, &$log, $loop) {
            return $drivers[] = FakeDriver::make(count($drivers), [], $options, $events, $log, $loop);
        },
        newSessionId: fn (): string => 'sess_plan',
        onProtocolError: function (string $sessionId, string $problem) use (&$problems): void {
            $problems[] = $problem;
        },
        loop: $loop,
    );
    $params = ['protocolVersion' => 1];
    if ($clientCapabilities !== null) {
        $params['clientCapabilities'] = $clientCapabilities;
    }
    $peer->receive(Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $params])));
    $peer->receive(Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'session/new', 'params' => ['cwd' => getcwd(), 'mcpServers' => []]])));
    $loop->drain();

    return ['driver' => $drivers[0], 'log' => &$log, 'sent' => &$sent, 'problems' => &$problems, 'loop' => $loop];
}

/** @return list<stdClass> the session/update params sent */
function planGateUpdates(array $session): array
{
    $session['loop']->drain();

    $notifications = array_values(array_filter($session['sent'], fn (mixed $m) => ($m->method ?? null) === 'session/update'));

    return array_map(fn (stdClass $m) => $m->params, $notifications);
}

function planGateEmit(array $session, array $update): void
{
    ($session['driver']->events->onUpdate)($update);
}

const GATE_ENTRY = ['content' => 'step', 'priority' => 'medium', 'status' => 'pending'];

function planGateItems(): array
{
    return ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [GATE_ENTRY], '_meta' => ['particle.academy/plan' => ['revision' => 1]]]];
}

it('tells each driver whether the client accepts plan updates', function (mixed $capabilities, bool $plan): void {
    $session = planGateSession($capabilities);
    expect(Json::encode($session['log'][0]['call'][2]['clientCapabilities']))->toBe(Json::encode(['plan' => $plan]))
        ->and(array_keys($session['log'][0]['call'][2]))->toBe(['cwd', 'mcpServers', 'clientCapabilities']);
})->with([
    [(object) ['plan' => new stdClass], true],
    [(object) ['plan' => null], false],
    [null, false],
]);

it('forwards plan_update and plan_removed to a client that advertised plan', function (): void {
    $session = planGateSession((object) ['plan' => new stdClass]);
    planGateEmit($session, planGateItems());
    planGateEmit($session, ['sessionUpdate' => 'plan_removed', 'planId' => 'pl_1']);
    $updates = planGateUpdates($session);
    expect(Json::encode(array_map(fn ($u) => $u->update, $updates)))
        ->toBe(Json::encode([planGateItems(), ['sessionUpdate' => 'plan_removed', 'planId' => 'pl_1']]));
    foreach ($updates as $params) {
        expect(AcpSchema::sessionNotification($params))->toBe([]);
    }
});

it('turns plan_update into the full-replacement plan for a client that did not', function (): void {
    $session = planGateSession(new stdClass);
    planGateEmit($session, planGateItems());
    $updates = planGateUpdates($session);
    expect(Json::encode(array_map(fn ($u) => $u->update, $updates)))
        ->toBe(Json::encode([['sessionUpdate' => 'plan', 'entries' => [GATE_ENTRY], '_meta' => ['particle.academy/plan' => ['revision' => 1]]]]))
        ->and(AcpSchema::sessionNotification($updates[0]))->toBe([]);
});

it('sends no plan_removed, markdown plan_update or delta to a client that did not advertise plan, and reports each', function (): void {
    $session = planGateSession(null);
    planGateEmit($session, ['sessionUpdate' => 'plan_removed', 'planId' => 'pl_1']);
    planGateEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'markdown', 'planId' => 'pl_1', 'content' => '# plan']]);
    planGateEmit($session, ['sessionUpdate' => 'plan_update', 'delta' => 'progress']);
    expect(planGateUpdates($session))->toBe([])->and($session['problems'])->toHaveCount(3);
});

it('refuses a plan_update that is not ACP-shaped even for a client that advertised plan', function (): void {
    $session = planGateSession((object) ['plan' => new stdClass]);
    planGateEmit($session, ['sessionUpdate' => 'plan_update', 'delta' => 'progress']);
    planGateEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'entries' => []]]);
    expect(planGateUpdates($session))->toBe([])->and($session['problems'])->toHaveCount(2);
});

it('gives a plan entry with no valid priority the neutral one', function (mixed $capabilities): void {
    $session = planGateSession($capabilities);
    planGateEmit($session, ['sessionUpdate' => 'plan', 'entries' => [
        ['content' => 'a', 'status' => 'pending'], ['content' => 'b', 'priority' => 'urgent', 'status' => 'completed'], ['content' => 'c', 'priority' => 'high', 'status' => 'pending'],
    ]]);
    $updates = planGateUpdates($session);
    expect(array_map(fn ($e) => $e->priority, $updates[0]->update->entries))->toBe(['medium', 'medium', 'high'])
        ->and(AcpSchema::sessionNotification($updates[0]))->toBe([])
        ->and($session['problems'])->toHaveCount(1);
})->with([[(object) ['plan' => new stdClass]], [new stdClass]]);

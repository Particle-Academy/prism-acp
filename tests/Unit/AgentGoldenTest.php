<?php

declare(strict_types=1);

use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\TurnFailure;
use Tests\Support\FakeDriver;

require_once dirname(__DIR__).'/Support/FakeDriver.php';

/*
 * THE AGENT SURFACE BEHAVES AS THE REFERENCE'S DOES. Every scenario in tests/fixtures/agent-scenarios.json -- client
 * frames and driver events, against a scripted driver -- went through prism-acp-ts's AcpAgent, and
 * agent-expected.json is what it sent, what it asked of the driver and what it reported, in order. The same steps run
 * here and the two logs are compared entry by entry, as JSON text. Regenerate with tools/golden/agent.mjs.
 */

$fixtures = dirname(__DIR__).'/fixtures';
$scenarios = Json::decode((string) file_get_contents("{$fixtures}/agent-scenarios.json"))->scenarios;
$expected = Json::decode((string) file_get_contents("{$fixtures}/agent-expected.json"))->results;

/** @return list<string> the scenario's log, each entry as JSON text */
function runScenario(stdClass $scenario): array
{
    $loop = new Loop;
    $log = [];
    $drivers = [];
    $ids = 0;
    $spec = json_decode(Json::encode($scenario->driver ?? new stdClass), true);
    $capabilities = $scenario->capabilities === null ? null : json_decode(Json::encode($scenario->capabilities), true);
    $probe = isset($scenario->probe) ? $scenario->probe : null;

    $peer = new JsonRpcPeer(
        send: function (mixed $message) use (&$log): void {
            $log[] = ['sent' => $message];
        },
        onProtocolError: function (string $problem) use (&$log): void {
            $log[] = ['peerProblem' => $problem];
        },
        loop: $loop,
    );
    new AcpAgent(
        peer: $peer,
        driverFactory: function (array $options, DriverEvents $events) use (&$drivers, &$log, $spec, $loop) {
            $driver = FakeDriver::make(count($drivers), $spec, $options, $events, $log, $loop);
            $drivers[] = $driver;

            return $driver;
        },
        driverCapabilities: $capabilities,
        probeSession: $probe === null ? null : fn (string $id) => isset($probe->{$id})
            ? ['existence' => $probe->{$id}->existence, 'detail' => $probe->{$id}->detail]
            : ['existence' => 'present', 'detail' => ''],
        agentInfo: ['name' => 'golden', 'version' => '1'],
        newSessionId: function () use (&$ids): string {
            return 's'.(++$ids);
        },
        onStderr: function (string $sessionId, string $line) use (&$log): void {
            $log[] = ['stderr' => [$sessionId, $line]];
        },
        onProtocolError: function (string $sessionId, string $problem, ?bool $recoverable) use (&$log): void {
            $log[] = ['problem' => [$sessionId, $problem, $recoverable]];
        },
        loop: $loop,
    );

    foreach ($scenario->steps as $step) {
        $log[] = ['step' => $step];
        if (property_exists($step, 'client')) {
            $peer->receive($step->client);
        } else {
            $events = $drivers[$step->driver]->events;
            if (property_exists($step, 'update')) {
                ($events->onUpdate)(json_decode(Json::encode($step->update), true));
            }
            if (property_exists($step, 'turnEnd')) {
                $end = $step->turnEnd;
                $failure = isset($end->failure) ? new TurnFailure($end->failure->reason, $end->failure->kind, $end->failure->resetsAtMs ?? null) : null;
                ($events->onTurnEnd)(new TurnOutcome($end->stopReason, $end->isError, $end->raw, $failure));
            }
            if (property_exists($step, 'exit')) {
                ($events->onExit)($step->exit, null);
            }
            if (property_exists($step, 'stderr')) {
                ($events->onStderr)($step->stderr);
            }
            if (property_exists($step, 'protocolError')) {
                ($events->onProtocolError)($step->protocolError, $step->recoverable ?? null);
            }
            if (property_exists($step, 'permission')) {
                $index = $step->driver;
                ($events->onRequestPermission)(json_decode(Json::encode($step->permission), true))->then(
                    function (PermissionOutcome $outcome) use (&$log, $index): void {
                        $shape = ['outcome' => $outcome->outcome];
                        if ($outcome->optionId !== null) {
                            $shape['optionId'] = $outcome->optionId;
                        }
                        if ($outcome->reason !== null) {
                            $shape['reason'] = $outcome->reason;
                        }
                        $log[] = ['permission' => [$index, $shape]];
                    },
                    function (Throwable $error) use (&$log, $index): void {
                        $log[] = ['permissionError' => [$index, $error->getMessage()]];
                    },
                );
            }
        }
        $loop->drain();
    }

    return array_map(fn (array $entry): string => Json::encode($entry), $log);
}

it('runs every scenario', function () use ($scenarios, $expected) {
    expect(count($scenarios))->toBe(count($expected))->toBeGreaterThan(8);
});

foreach ($scenarios as $i => $scenario) {
    it("behaves as the reference: {$scenario->name}", function () use ($scenario, $expected, $i) {
        $want = array_map(fn (mixed $entry): string => Json::encode($entry), $expected[$i]->log);
        $got = runScenario($scenario);
        foreach ($want as $n => $line) {
            expect($got[$n] ?? '(nothing)')->toBe($line, "entry {$n} differs");
        }
        expect($got)->toHaveCount(count($want));
    });
}

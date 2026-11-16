<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransport;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Meta;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\StopVerdict;

/**
 * G-299: feed frames the Codex peer cannot correlate through the real driver frame path, then stop startup before any
 * provider. Returns everything the driver surfaced: updates, protocol errors, stderr and thrown messages.
 *
 * @param  list<mixed>  $frames
 * @return array{updates: list<array<string,mixed>>, surfaced: string}
 */
function feedUncorrelated(array $frames): array
{
    $loop = new Loop;
    $updates = [];
    $surfaced = [];
    $events = new DriverEvents(
        onUpdate: function (array $update) use (&$updates, &$surfaced): void {
            $updates[] = $update;
            $surfaced[] = Json::encode($update);
        },
        onProtocolError: function (string $problem, ?bool $recoverable) use (&$surfaced): void {
            $surfaced[] = $problem;
        },
        onStderr: function (string $line) use (&$surfaced): void {
            $surfaced[] = $line;
        },
    );
    $thrown = new ArrayObject;
    $factory = function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use ($loop, $frames, $thrown): CodexTransport {
        return new class($handlers, $loop, $frames, $thrown) implements CodexTransport
        {
            /** @param list<mixed> $frames @param ArrayObject<int, string> $thrown */
            public function __construct(private readonly CodexTransportHandlers $handlers, private readonly Loop $loop,
                private readonly array $frames, private readonly ArrayObject $thrown) {}

            public function start(): Deferred
            {
                foreach ($this->frames as $frame) {
                    try {
                        ($this->handlers->onFrame)($frame);
                    } catch (Throwable $cause) {
                        $this->thrown[] = $cause->getMessage();
                    }
                }
                $done = new Deferred($this->loop);
                $done->reject(new RuntimeException('test interception before provider startup'));

                return $done;
            }

            public function send(mixed $frame): void {}

            public function close(): void {}

            public function stop(int $graceMs = 5000): Deferred
            {
                $done = new Deferred($this->loop);
                $done->resolve(new StopVerdict(true, true, 0, true));

                return $done;
            }

            public function processTree(): ?ProcessTree
            {
                return null;
            }
        };
    };
    $driver = new CodexDriver(new CodexDriverOptions(cwd: sys_get_temp_dir(), parentEnv: []), $events, $factory, $loop);
    try {
        $driver->start();
        $loop->drain();
    } catch (Throwable $cause) {
        $surfaced[] = $cause->getMessage();
    } finally {
        $driver->stop(0)->await();
    }

    return ['updates' => $updates, 'surfaced' => implode("\n", [...$surfaced, ...$thrown->getArrayCopy()])];
}

/** @return array<string, array{0: Closure(string): list<mixed>}> */
function uncorrelatedShapes(): array
{
    $decoded = fn (string $json): mixed => Json::decode($json);

    return [
        'unknown-id result' => [fn (string $m) => ['{"jsonrpc":"2.0","id":987654321,"result":{"echo":"'.$m.'","path":"/home/'.$m.'"}}']],
        'unknown-id error with data' => [fn (string $m) => ['{"jsonrpc":"2.0","id":41,"error":{"code":-32603,"message":"'.$m.'","data":{"input":"'.$m.'"}}}']],
        'marker as the string id' => [fn (string $m) => ['{"jsonrpc":"2.0","id":"'.$m.'","result":null}']],
        'neither method nor id' => [fn (string $m) => ['{"jsonrpc":"2.0","result":"'.$m.'"}']],
        'non-string method with id' => [fn (string $m) => ['{"jsonrpc":"2.0","id":7,"method":5,"params":"'.$m.'"}']],
        'decoded objects, not text' => [fn (string $m) => [$decoded('{"jsonrpc":"2.0","id":99,"result":"'.$m.'"}'), $decoded('{"result":["'.$m.'"]}')]],
        'duplicate replies' => [fn (string $m) => array_fill(0, 3, '{"jsonrpc":"2.0","id":12,"result":"'.$m.'"}')],
    ];
}

it('never forwards the body, id or problem text of a reply that matches no request (G-299)', function (Closure $frames) {
    $marker = 'planted-uncorrelated-'.bin2hex(random_bytes(8));
    $frameList = $frames($marker);
    $seen = feedUncorrelated($frameList);

    expect($seen['surfaced'])->not->toContain($marker)
        ->and($seen['surfaced'])->not->toContain('response for unknown id')
        ->and($seen['surfaced'])->not->toContain('neither a method nor an id')
        ->and($seen['updates'])->toHaveCount(count($frameList));
    foreach ($seen['updates'] as $update) {
        // Byte-for-byte the reference's 0.38.0 report.
        expect(Json::encode($update))->toBe(Json::encode(Meta::with(
            ['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'Codex RPC response could not be correlated']],
            [Meta::UNMAPPED_FRAME => ['reason' => 'Codex RPC response could not be correlated', 'frame' => (object) ['type' => 'rpc_response_gap']]],
        )));
    }
})->with(uncorrelatedShapes());

it('still reports a correlation gap rather than dropping it silently', function () {
    $seen = feedUncorrelated(['{"jsonrpc":"2.0","id":5,"result":true}']);

    expect($seen['updates'])->toHaveCount(1)
        ->and($seen['updates'][0]['notice']['message'])->toBe(CodexDriver::UNCORRELATED_RESPONSE);
});

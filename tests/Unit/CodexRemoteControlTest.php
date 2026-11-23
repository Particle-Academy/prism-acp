<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexRemoteControl;
use Prism\Acp\Codex\CodexTransport;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Codex\StdioCodexTransport;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\StopVerdict;

/*
 * Ported from prism-acp-ts test/remote-control-off.test.ts. Codex launches never carry a remote-control argument, and
 * no remoteControl/* frame reaches a transport, whichever transport the embedder supplied.
 */
const CODEX_REFUSAL = 'Prism\Acp\Claude\RemoteControlRefused';

final class RecordingCodexTransport implements CodexTransport
{
    /** @var list<stdClass> */
    public array $sent = [];

    public function __construct(private readonly CodexTransportHandlers $handlers, private readonly Loop $loop) {}

    public function start(): Deferred
    {
        return $this->settled(null);
    }

    public function send(mixed $frame): void
    {
        $decoded = Json::decode(Json::encode($frame));
        $this->sent[] = $decoded;
        if (! isset($decoded->id, $decoded->method)) {
            return;
        }
        $result = $decoded->method === 'thread/start' ? ['thread' => ['id' => 'thread-fixture']] : new stdClass;
        $reply = Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => $decoded->id, 'result' => $result]));
        $this->loop->defer(fn () => ($this->handlers->onFrame)($reply));
    }

    public function close(): void {}

    public function stop(int $graceMs = 5000): Deferred
    {
        return $this->settled(new StopVerdict(true, true, 0, true));
    }

    public function processTree(): ?ProcessTree
    {
        return null;
    }

    private function settled(mixed $value): Deferred
    {
        $done = new Deferred($this->loop);
        $done->resolve($value);

        return $done;
    }
}

/** @param list<string> $binaryArgs */
function codexLaunchRefusal(array $binaryArgs): ?string
{
    try {
        new CodexDriver(new CodexDriverOptions(cwd: sys_get_temp_dir(), binaryArgs: $binaryArgs));
    } catch (Throwable $thrown) {
        return $thrown::class;
    }

    return null;
}

function unstartedCodexTransport(): StdioCodexTransport
{
    return new StdioCodexTransport(new CodexTransportOptions(sys_get_temp_dir(), []), new CodexTransportHandlers(
        onFrame: static fn (mixed $frame) => null,
        onStderr: static fn (string $line) => null,
        onProblem: static fn (string $problem, ?bool $recoverable = null) => null,
        onExit: static fn (?int $code, ?string $signal) => null,
    ), new Loop);
}

function codexSendRefusal(mixed $frame): ?string
{
    try {
        unstartedCodexTransport()->send($frame);
    } catch (Throwable $thrown) {
        return $thrown::class;
    }

    return null;
}

/** @return array{0: CodexDriver, 1: RecordingCodexTransport, 2: JsonRpcPeer} */
function startedCodexDriver(Loop $loop): array
{
    $transport = null;
    $driver = new CodexDriver(new CodexDriverOptions(cwd: sys_get_temp_dir(), parentEnv: []), new DriverEvents,
        function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use (&$transport, $loop): CodexTransport {
            return $transport = new RecordingCodexTransport($handlers, $loop);
        }, $loop);
    $driver->start();
    $driver->ready()->await();
    /** @var JsonRpcPeer $peer */
    $peer = (new ReflectionProperty(CodexDriver::class, 'peer'))->getValue($driver);

    return [$driver, $transport, $peer];
}

dataset('codex refused arguments', [
    '--remote-control', ' --remote-control', '--REMOTE-CONTROL', "--remote\u{200B}-control", "\u{FEFF}--remote-control",
    '--remote-control=on', 'remote-control', 'Remote-Control', "remote-control\t", 'enable-remote-control',
    'ENABLE-remote-control', "enable\u{00AD}-remote-control", 'daemon', 'DAEMON', ' daemon ', '--remote', '--Remote=x',
    'cloud', 'CLOUD', "cl\u{200D}oud", 'cloud=x',
]);

dataset('codex refused methods', [
    'remoteControl/enable', 'remoteControl/disable', 'remoteControl/status', ' remoteControl/enable',
    'remotecontrol/enable', 'REMOTECONTROL/ENABLE', "remote\u{200B}Control/enable", "\u{FEFF}remoteControl/enable",
    "remoteControl\t/enable", 'remoteControl',
]);

it('refuses host arguments at driver construction', function (string $arg) {
    expect(codexLaunchRefusal(['/fixture/cli.php', $arg]))->toBe(CODEX_REFUSAL);
})->with('codex refused arguments');

it('refuses host arguments at transport start, for an embedder without the driver', function (string $arg) {
    $transport = new StdioCodexTransport(new CodexTransportOptions(sys_get_temp_dir(), [], PHP_BINARY, ['/fixture/absent.php', $arg]),
        new CodexTransportHandlers(
            onFrame: static fn (mixed $frame) => null,
            onStderr: static fn (string $line) => null,
            onProblem: static fn (string $problem, ?bool $recoverable = null) => null,
            onExit: static fn (?int $code, ?string $signal) => null,
        ), new Loop);
    $thrown = null;
    try {
        $transport->start()->await();
    } catch (Throwable $caught) {
        $thrown = $caught::class;
    } finally {
        $transport->stop(200)->await();
    }
    expect($thrown)->toBe(CODEX_REFUSAL);
})->with(['--remote-control', ' daemon ', "cl\u{200D}oud"]);

it('refuses a host argument it cannot read rather than passing it', function () {
    expect(codexLaunchRefusal(["daemon\xFF"]))->toBe(CODEX_REFUSAL);
});

it('leaves unrelated host arguments alone (the positive control)', function (string $arg) {
    expect(codexLaunchRefusal(['/fixture/cli.php', $arg]))->toBeNull();
})->with(['--max-old-space-size=4096', '--cloudless', '/path/daemon/cli.js', 'remote-controlled', '--no-warnings']);

it('refuses the frame through the real driver peer and writes nothing', function (string $method) {
    $loop = new Loop;
    [$driver, $transport, $peer] = startedCodexDriver($loop);
    $before = count($transport->sent);
    $thrown = null;
    try {
        $peer->request($method, ['fixture' => true])->await();
    } catch (Throwable $caught) {
        $thrown = $caught::class;
    }
    $notified = null;
    try {
        $peer->notify($method);
    } catch (Throwable $caught) {
        $notified = $caught::class;
    }
    $driver->stop(100)->await();
    expect($thrown)->toBe(CODEX_REFUSAL)->and($notified)->toBe(CODEX_REFUSAL)->and(count($transport->sent))->toBe($before);
})->with('codex refused methods');

it('completes a full driver startup without a remote method', function () {
    $loop = new Loop;
    [$driver, $transport] = startedCodexDriver($loop);
    $methods = array_map(static fn (stdClass $frame): string => (string) ($frame->method ?? ''), $transport->sent);
    $driver->stop(100)->await();
    expect($methods)->toContain('thread/start')
        ->and(array_values(array_filter($methods, static fn (string $method): bool => stripos($method, 'remote') !== false)))->toBe([]);
});

it('refuses the frame at the stdio transport before touching stdin, in every PHP frame shape', function (string $method) {
    $serializable = new class($method) implements JsonSerializable
    {
        public function __construct(private readonly string $method) {}

        public function jsonSerialize(): array
        {
            return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $this->method];
        }
    };
    expect(codexSendRefusal(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method]))->toBe(CODEX_REFUSAL)
        ->and(codexSendRefusal((object) ['jsonrpc' => '2.0', 'method' => $method]))->toBe(CODEX_REFUSAL)
        ->and(codexSendRefusal($serializable))->toBe(CODEX_REFUSAL);
})->with('codex refused methods');

it('writes the value it checked when a frame serializes differently the second time', function () {
    $frame = new class implements JsonSerializable
    {
        private int $calls = 0;

        public function jsonSerialize(): array
        {
            return ['jsonrpc' => '2.0', 'method' => $this->calls++ === 0 ? 'thread/start' : 'remoteControl/enable'];
        }
    };
    expect(Json::encode(CodexRemoteControl::checkedFrame($frame)))->toBe('{"jsonrpc":"2.0","method":"thread/start"}');
});

it('refuses a method it cannot read rather than writing it', function () {
    expect(codexSendRefusal(['jsonrpc' => '2.0', 'id' => 1, 'method' => "remoteControl/enable\xFF"]))->toBe(CODEX_REFUSAL);
});

it('passes ordinary requests and replies (the positive control)', function (mixed $frame) {
    expect(codexSendRefusal($frame))->toBe(RuntimeException::class);
})->with([
    'a request' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'thread/start']],
    'a reply naming the method in its result' => [['jsonrpc' => '2.0', 'id' => 1, 'result' => ['method' => 'remoteControl/enable']]],
    'a longer word' => [['method' => 'remoteControlled/x']],
]);

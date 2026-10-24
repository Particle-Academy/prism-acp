<?php

declare(strict_types=1);

namespace Tests\Support;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Codex\CodexTransport;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\StopVerdict;
use Prism\Acp\Support\Js;

/** The oracle scenario's provider replies, delivered on the real PHP event loop. */
final class ScriptedCodexTransport implements CodexTransport
{
    /** @var list<mixed> */
    public array $sent = [];

    public int $closes = 0;

    /** @var array<string,int> */
    private array $indexes = [];

    public function __construct(private readonly CodexTransportHandlers $handlers,
        private readonly Loop $loop, private readonly \stdClass $scenario) {}

    public function start(): Deferred
    {
        $done = new Deferred($this->loop);
        $done->resolve(null);

        return $done;
    }

    public function close(): void
    {
        $this->closes++;
    }

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

    public function feed(mixed $frame): void
    {
        ($this->handlers->onFrame)($frame);
    }

    public function send(mixed $frame): void
    {
        $this->sent[] = $frame;
        $object = Json::decode(Json::encode($frame));
        $method = Js::string($object, 'method');
        if ($method === null || ! Js::has($object, 'id')) {
            return;
        }
        $index = $this->indexes[$method] ?? 0;
        $this->indexes[$method] = $index + 1;
        $script = Js::get(Js::get($this->scenario, 'replies'), $method);
        $reply = is_array($script) ? ($script[$index] ?? null) : null;
        if (! $reply instanceof \stdClass) {
            $reply = (object) ['result' => $this->defaultResult($method)];
        }
        $response = (object) ['jsonrpc' => '2.0', 'id' => $object->id, ...get_object_vars($reply)];
        $this->loop->defer(fn () => ($this->handlers->onFrame)($response));
    }

    private function defaultResult(string $method): \stdClass
    {
        return match ($method) {
            'thread/start','thread/resume' => (object) ['thread' => (object) ['id' => 'thread-fixture'], 'model' => 'fixture-model'],
            'turn/start' => (object) ['turn' => (object) ['id' => 'turn-fixture']],
            'thread/turns/list','thread/items/list' => (object) ['data' => [], 'nextCursor' => null],
            'model/list' => (object) ['data' => [(object) ['id' => 'fixture-model', 'model' => 'fixture-model', 'displayName' => 'Fixture', 'isDefault' => true]], 'nextCursor' => null],
            'account/rateLimits/read' => (object) ['ordinaryUsageAllowed' => true, 'rateLimits' => (object) [
                'primary' => (object) ['usedPercent' => 10, 'windowDurationMins' => 300, 'resetsAt' => 1800000000], 'secondary' => null]],
            default => new \stdClass,
        };
    }
}

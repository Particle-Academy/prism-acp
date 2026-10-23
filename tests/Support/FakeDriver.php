<?php

declare(strict_types=1);

namespace Tests\Support;

use Prism\Acp\Agent\AgentDriver;
use Prism\Acp\Agent\CancelsTurns;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\ReportsModels;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Prompt\PromptRefused;

/**
 * The scenario driver of tests/fixtures/agent-scenarios.json, as tools/golden/agent.mjs builds it for the reference: it
 * records every call and behaves as the scenario says. PHP declares a driver's optional abilities by interface, so
 * there is one class per combination (FakeDriver::make picks it).
 */
class FakeDriver implements AgentDriver
{
    protected int $prompts = 0;

    protected int $cancels = 0;

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $options
     * @param  list<mixed>  $log
     */
    public function __construct(
        protected readonly int $index,
        protected readonly array $spec,
        protected readonly array $options,
        public readonly DriverEvents $events,
        protected array &$log,
        protected readonly Loop $loop,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $options
     * @param  list<mixed>  $log
     */
    public static function make(int $index, array $spec, array $options, DriverEvents $events, array &$log, Loop $loop): self
    {
        $cancel = self::pick($spec['cancel'] ?? 'ok', 0) !== 'none';
        $models = isset($spec['models']) && $spec['models'] !== 'none';

        return match (true) {
            $cancel && $models => new FakeDriverWithAll($index, $spec, $options, $events, $log, $loop),
            $cancel => new FakeDriverWithCancel($index, $spec, $options, $events, $log, $loop),
            $models => new FakeDriverWithModels($index, $spec, $options, $events, $log, $loop),
            default => new self($index, $spec, $options, $events, $log, $loop),
        };
    }

    public function start(): void
    {
        $this->log[] = ['call' => [$this->index, 'start', $this->options]];
    }

    public function prompt(string|array $content): void
    {
        $this->log[] = ['call' => [$this->index, 'prompt', $content]];
        $how = self::pick($this->spec['promptThrows'] ?? null, $this->prompts++);
        if ($how === 'refused') {
            throw new PromptRefused(0, 'image/png', 'the driver will not send it');
        }
        if ($how === 'error') {
            throw new \RuntimeException('the agent could not be written to');
        }
    }

    public function endInput(): void
    {
        $this->log[] = ['call' => [$this->index, 'endInput']];
    }

    public function kill(): void
    {
        $this->log[] = ['call' => [$this->index, 'kill']];
    }

    public function cliSessionId(): ?string
    {
        return $this->spec['cliSessionId'] ?? null;
    }

    public function withheldCredentials(): array
    {
        return [];
    }

    protected static function pick(mixed $value, int $n): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        return $value[$n] ?? $value[count($value) - 1] ?? null;
    }

    /** @return Deferred<null> */
    protected function doCancel(): Deferred
    {
        $this->log[] = ['call' => [$this->index, 'cancel']];
        $done = new Deferred($this->loop);
        self::pick($this->spec['cancel'] ?? 'ok', $this->cancels++) === 'reject'
            ? $done->reject(new \RuntimeException('the agent refused the interrupt'))
            : $done->resolve(null);

        return $done;
    }

    /** @return Deferred<mixed> */
    protected function doModelState(): Deferred
    {
        $done = new Deferred($this->loop);
        $this->spec['models'] === 'reject'
            ? $done->reject(new \RuntimeException('no model list'))
            : $done->resolve(json_decode(Json::encode($this->spec['models']), true));

        return $done;
    }

    /** @return Deferred<null> */
    protected function doSetModel(string $modelId): Deferred
    {
        $this->log[] = ['call' => [$this->index, 'setModel', $modelId]];
        $done = new Deferred($this->loop);
        str_starts_with($modelId, '-')
            ? $done->reject(new \RuntimeException('refusing model '.Json::encode($modelId)))
            : $done->resolve(null);

        return $done;
    }
}

final class FakeDriverWithCancel extends FakeDriver implements CancelsTurns
{
    public function cancel(): Deferred
    {
        return $this->doCancel();
    }
}

final class FakeDriverWithModels extends FakeDriver implements ReportsModels
{
    public function modelState(): Deferred
    {
        return $this->doModelState();
    }

    public function setModel(string $modelId): Deferred
    {
        return $this->doSetModel($modelId);
    }
}

final class FakeDriverWithAll extends FakeDriver implements CancelsTurns, ReportsModels
{
    public function cancel(): Deferred
    {
        return $this->doCancel();
    }

    public function modelState(): Deferred
    {
        return $this->doModelState();
    }

    public function setModel(string $modelId): Deferred
    {
        return $this->doSetModel($modelId);
    }
}

<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Ndjson\Frame;
use Prism\Acp\Ndjson\NdjsonFramer;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\ProviderOnLoop;
use Prism\Acp\Process\StopVerdict;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\Utf8Stream;

/** App-server stdio through the same contained provider transport as Claude; no shell or event-loop dependency. */
final class StdioCodexTransport implements CodexTransport
{
    private readonly Loop $loop;

    private readonly NdjsonFramer $framer;

    private readonly Utf8Stream $stderrText;

    private ?ProviderOnLoop $provider = null;

    private bool $started = false;

    private bool $closing = false;

    private bool $stderrOverflowReported = false;

    private string $stderrBuffer = '';

    private ?int $exitCode = null;

    public function __construct(
        private readonly CodexTransportOptions $options,
        private readonly CodexTransportHandlers $handlers,
        ?Loop $loop = null,
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->framer = new NdjsonFramer;
        $this->stderrText = new Utf8Stream;
    }

    public function start(): Deferred
    {
        return Deferred::async(function (): void {
            if ($this->started) {
                throw new \LogicException('Codex transport already started');
            }
            $this->started = true;
            $process = Provider::launch($this->options->binary,
                [...$this->options->binaryArgs, 'app-server', '--listen', 'stdio://'],
                $this->options->cwd, $this->options->env);
            $this->provider = new ProviderOnLoop($process, $this->loop,
                onStdout: fn (string $bytes) => $this->frames($this->framer->push($bytes)),
                onStderr: fn (string $bytes) => $this->stderr($this->stderrText->push($bytes)),
                onExited: function (int $code): void {
                    $this->exitCode = $code;
                },
                onClosed: function (): void {
                    $this->closing = true;
                    $this->frames($this->framer->end());
                    $this->stderr($this->stderrText->end());
                    ($this->handlers->onExit)($this->exitCode, null);
                });
        }, $this->loop);
    }

    public function send(mixed $frame): void
    {
        if ($this->provider === null || $this->provider->isClosed() || $this->closing) {
            throw new \RuntimeException('Codex app-server stdin is not open');
        }
        $this->provider->provider->write(NdjsonFramer::encodeLine($frame));
    }

    public function close(): void
    {
        if ($this->closing) {
            return;
        }
        $this->closing = true;
        $this->provider?->provider->closeInput();
        $this->provider?->kill();
    }

    public function stop(int $graceMs = 5000): Deferred
    {
        $this->closing = true;
        if ($this->provider !== null) {
            return $this->provider->stop($graceMs);
        }
        $done = new Deferred($this->loop);
        $done->resolve(new StopVerdict(true, true, 0, true));

        return $done;
    }

    public function processTree(): ?ProcessTree
    {
        return $this->provider?->provider->tree();
    }

    /** @param list<Frame> $frames */
    private function frames(array $frames): void
    {
        foreach ($frames as $frame) {
            if ($frame->ok) {
                ($this->handlers->onFrame)($frame->value);
            } else {
                ($this->handlers->onProblem)($frame->error->message ?? 'invalid Codex frame', true);
            }
        }
    }

    private function stderr(string $text): void
    {
        $this->stderrBuffer .= $text;
        if (Js::length($this->stderrBuffer) > 64000) {
            if (! $this->stderrOverflowReported) {
                $this->stderrOverflowReported = true;
                ($this->handlers->onProblem)('Codex stderr buffer exceeded 64000 characters', null);
            }
            $this->stderrBuffer = CodexPayload::tail($this->stderrBuffer, 64000);
        }
        while (($newline = strpos($this->stderrBuffer, "\n")) !== false) {
            $line = substr($this->stderrBuffer, 0, $newline);
            $this->stderrBuffer = substr($this->stderrBuffer, $newline + 1);
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
            if ($line !== '') {
                ($this->handlers->onStderr)('Codex stderr line withheld ('.Js::length($line).' characters)');
            }
        }
    }
}

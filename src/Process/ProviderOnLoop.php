<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;

/**
 * A provider process on an event loop: its output is delivered to callbacks as it arrives, and stop() waits on timers
 * rather than blocking, so one session stopping does not stall the others. What the reference's child-process events
 * are to its drivers, this is to this port's.
 */
final class ProviderOnLoop
{
    /** How long a stop waits for the supervisor's report after terminating the tree. The same 10 s as the reference. */
    private const REPORT_SECONDS = 10.0;

    /** @var array<int, int> loop watcher ids, by the id of the stream they watch */
    private array $watchers = [];

    private bool $closed = false;

    private bool $killed = false;

    /** @var list<\Closure(): void> */
    private array $onClose = [];

    /** @var list<int> the timers of a stop in progress */
    private array $stopTimers = [];

    /**
     * @param  \Closure(string): void  $onStdout
     * @param  \Closure(string): void  $onStderr
     * @param  \Closure(int): void  $onExited  the provider exited on its own, with this code
     * @param  \Closure(): void  $onClosed  nothing more will arrive: the tree is gone and every stream has ended
     */
    public function __construct(
        public readonly ProviderProcess $provider,
        private readonly Loop $loop,
        private readonly \Closure $onStdout,
        private readonly \Closure $onStderr,
        private readonly \Closure $onExited,
        private readonly \Closure $onClosed,
    ) {
        $this->sync();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Terminate the whole tree now. */
    public function kill(): void
    {
        $this->killed = true;
        $this->provider->kill();
    }

    /**
     * Close input, give the provider $graceMs to exit on its own, terminate the tree, and settle with the verdict once
     * the tree is reported empty (or the wait for the report gives up).
     *
     * @return Deferred<mixed> settles with a StopVerdict
     */
    public function stop(int $graceMs = 5000): Deferred
    {
        $verdict = new Deferred($this->loop);
        $graceful = fn (): bool => $this->provider->exitCode() !== null && ! $this->killed;
        if ($this->closed) {
            $verdict->resolve($this->provider->verdict($graceful()));

            return $verdict;
        }
        $this->provider->closeInput();
        $settle = function () use ($verdict, $graceful): void {
            foreach ($this->stopTimers as $timer) {
                $this->loop->cancel($timer);
            }
            $this->stopTimers = [];
            if (! $verdict->isSettled()) {
                $verdict->resolve($this->provider->verdict($graceful()));
            }
        };
        $this->onClose[] = $settle;
        $this->stopTimers[] = $this->loop->delay($graceMs / 1000, function () use ($settle): void {
            if ($this->provider->exitCode() === null) {
                $this->kill();
            }
            $this->stopTimers[] = $this->loop->delay(self::REPORT_SECONDS, $settle);
        });

        return $verdict;
    }

    /** Read what is ready and hand it on. Called by the loop when a watched stream is readable. */
    private function pump(): void
    {
        foreach ($this->provider->read(0.0) as [$kind, $data]) {
            match ($kind) {
                'stdout' => ($this->onStdout)((string) $data),
                'stderr' => ($this->onStderr)((string) $data),
                'exited' => ($this->onExited)((int) $data),
                'closed' => $this->close(),
            };
        }
        $this->sync();
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ($this->onClosed)();
        $listeners = $this->onClose;
        $this->onClose = [];
        foreach ($listeners as $listener) {
            $listener();
        }
    }

    /** Watch exactly the streams that can still produce something. */
    private function sync(): void
    {
        $live = [];
        foreach ($this->provider->streams() as $stream) {
            $live[(int) $stream] = $stream;
        }
        foreach ($this->watchers as $key => $watcher) {
            if (! isset($live[$key])) {
                $this->loop->cancel($watcher);
                unset($this->watchers[$key]);
            }
        }
        foreach ($live as $key => $stream) {
            $this->watchers[$key] ??= $this->loop->onReadable($stream, fn () => $this->pump());
        }
        if ($live === [] && ! $this->closed) {
            // Everything ended between two reads: one more read collects the `closed` the provider reports.
            $this->loop->defer(function (): void {
                if (! $this->isClosed()) {
                    $this->pump();
                }
            });
        }
    }
}

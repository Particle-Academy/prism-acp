<?php

declare(strict_types=1);

namespace Prism\Acp\Async;

/**
 * The event loop the agent and its drivers run on: streams to watch for input, timers, and work deferred to the next
 * turn of the loop. The reference is Node, where all three are the runtime's; here they are this class, and the code
 * that uses them reads the same way. Asynchronous functions are Fibers (see Deferred::await()).
 *
 * Streams are watched with stream_select, so on Windows they must be sockets: a provider is reached through its
 * supervisor's loopback sockets for exactly that reason (see WindowsProviderProcess).
 *
 * One loop per process is the normal case, and Loop::get() returns it. Tests and embedders may make their own.
 */
final class Loop
{
    private static ?self $default = null;

    /** @var array<int, array{0: resource, 1: \Closure(): void}> */
    private array $readers = [];

    /** @var array<int, array{0: float, 1: \Closure(): void}> */
    private array $timers = [];

    /** @var list<\Closure(): void> */
    private array $deferred = [];

    private int $nextId = 1;

    private bool $stopped = false;

    public static function get(): self
    {
        return self::$default ??= new self;
    }

    /**
     * Call $callback whenever $stream has input (or has closed). Returns an id for cancel().
     *
     * @param  resource  $stream
     * @param  \Closure(): void  $callback
     */
    public function onReadable(mixed $stream, \Closure $callback): int
    {
        $id = $this->nextId++;
        $this->readers[$id] = [$stream, $callback];

        return $id;
    }

    /**
     * Call $callback once, after $seconds. Returns an id for cancel().
     *
     * @param  \Closure(): void  $callback
     */
    public function delay(float $seconds, \Closure $callback): int
    {
        $id = $this->nextId++;
        $this->timers[$id] = [microtime(true) + max(0.0, $seconds), $callback];

        return $id;
    }

    /**
     * Call $callback on the next turn of the loop, before any stream is waited on.
     *
     * @param  \Closure(): void  $callback
     */
    public function defer(\Closure $callback): void
    {
        $this->deferred[] = $callback;
    }

    /** Stop watching a stream, or drop a timer that has not fired. An unknown id is ignored. */
    public function cancel(int $id): void
    {
        unset($this->readers[$id], $this->timers[$id]);
    }

    /**
     * Run until $until() answers true, stop() is called, or nothing is left to wait for. A callback that throws ends
     * the run with that exception: an error that escaped an asynchronous function must not be swallowed by the loop.
     *
     * @param  (\Closure(): bool)|null  $until
     */
    public function run(?\Closure $until = null): void
    {
        $this->stopped = false;
        while (! $this->stopped) {
            $this->runDeferred();
            if ($until !== null && $until()) {
                return;
            }
            if ($this->readers === [] && $this->timers === [] && $this->deferred === []) {
                return;
            }
            $this->tick();
        }
    }

    /**
     * Run deferred work until none is left, without waiting on a stream or a timer: everything already able to run,
     * runs. The reference's "await a macrotask" in a test.
     */
    public function drain(): void
    {
        $this->runDeferred();
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** One wait: the streams, up to the next timer. */
    private function tick(): void
    {
        $now = microtime(true);
        $next = null;
        foreach ($this->timers as [$at]) {
            $next = $next === null ? $at : min($next, $at);
        }
        $timeout = $this->deferred !== [] ? 0.0 : ($next === null ? 1.0 : max(0.0, $next - $now));

        if ($this->readers !== []) {
            $read = [];
            foreach ($this->readers as $id => [$stream]) {
                if (is_resource($stream)) {
                    $read[$id] = $stream;
                } else {
                    // A stream that was closed under its watcher: tell the watcher once, then forget it.
                    $callback = $this->readers[$id][1];
                    unset($this->readers[$id]);
                    $callback();
                }
            }
            if ($read !== []) {
                $write = $except = null;
                $seconds = (int) floor($timeout);
                $micro = (int) (($timeout - $seconds) * 1_000_000);
                $ready = @stream_select($read, $write, $except, $seconds, $micro);
                if ($ready === false) {
                    // An interrupted select (a signal) is not an error; anything else would spin, so wait instead.
                    usleep(1000);
                } else {
                    foreach ($read as $stream) {
                        foreach ($this->readers as $id => [$watched, $callback]) {
                            if ($watched === $stream) {
                                $callback();
                            }
                        }
                    }
                }
            }
        } elseif ($timeout > 0) {
            usleep((int) ($timeout * 1_000_000));
        }

        $now = microtime(true);
        $due = array_filter($this->timers, fn (array $timer): bool => $timer[0] <= $now);
        asort($due);
        foreach (array_keys($due) as $id) {
            if (isset($this->timers[$id])) {
                $callback = $this->timers[$id][1];
                unset($this->timers[$id]);
                $callback();
            }
        }
    }

    private function runDeferred(): void
    {
        while ($this->deferred !== []) {
            $callback = array_shift($this->deferred);
            $callback();
        }
    }
}

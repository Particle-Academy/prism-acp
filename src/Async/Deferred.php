<?php

declare(strict_types=1);

namespace Prism\Acp\Async;

/**
 * A value that arrives later: the reference's Promise. Settled once, with a value or a Throwable; await() returns the
 * value or throws.
 *
 * await() suspends the current Fiber until the value arrives, so an asynchronous function is a Fiber (see async()).
 * Called outside any Fiber, it runs the loop until the value arrives instead: the top level of a script may wait.
 *
 * @template T
 */
final class Deferred
{
    private bool $settled = false;

    private mixed $value = null;

    private ?\Throwable $error = null;

    /** @var list<\Closure(): void> */
    private array $listeners = [];

    public function __construct(private readonly ?Loop $loop = null) {}

    /** @param T $value */
    public function resolve(mixed $value = null): void
    {
        $this->settle($value, null);
    }

    public function reject(\Throwable $error): void
    {
        $this->settle(null, $error);
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    /**
     * Run $listener once settled (now, if it already is).
     *
     * @param  \Closure(): void  $listener
     */
    public function onSettle(\Closure $listener): void
    {
        if ($this->settled) {
            $listener();

            return;
        }
        $this->listeners[] = $listener;
    }

    /**
     * Run $onValue or $onError once settled. A catch() for a promise nobody awaits: the reference's `.catch(() => {})`.
     *
     * @param  (\Closure(T): void)|null  $onValue
     * @param  (\Closure(\Throwable): void)|null  $onError
     */
    public function then(?\Closure $onValue, ?\Closure $onError = null): void
    {
        $this->onSettle(function () use ($onValue, $onError): void {
            if ($this->error !== null) {
                $onError?->__invoke($this->error);
            } else {
                $onValue?->__invoke($this->value);
            }
        });
    }

    /** @return T */
    public function await(): mixed
    {
        if (! $this->settled) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                $this->onSettle(function () use ($fiber): void {
                    $this->loop()->defer(static function () use ($fiber): void {
                        if ($fiber->isSuspended()) {
                            $fiber->resume();
                        }
                    });
                });
                // Asked through a method: the value is settled from outside this frame, while it is suspended.
                while (! $this->isSettled()) {
                    \Fiber::suspend();
                }
            } else {
                $this->loop()->run(fn (): bool => $this->settled);
                if (! $this->isSettled()) {
                    throw new \LogicException('the loop ran out of work before this value arrived');
                }
            }
        }
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    /**
     * Run $work as an asynchronous function: in a Fiber, so it may await. The returned Deferred settles with its result.
     * Its value is typed mixed: a caller that needs a type checks what it got.
     *
     * @param  \Closure(): mixed  $work
     * @return self<mixed>
     */
    public static function async(\Closure $work, ?Loop $loop = null): self
    {
        $result = new self($loop);
        $fiber = new \Fiber(static function () use ($work, $result): void {
            try {
                $result->resolve($work());
            } catch (\Throwable $error) {
                $result->reject($error);
            }
        });
        $fiber->start();

        return $result;
    }

    private function settle(mixed $value, ?\Throwable $error): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->value = $value;
        $this->error = $error;
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            $listener();
        }
    }

    private function loop(): Loop
    {
        return $this->loop ?? Loop::get();
    }
}

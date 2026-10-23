<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * A provider CLI running under a supervisor that owns its whole process tree.
 *
 * PHP is synchronous, so instead of events this offers read(): one call waits (up to a timeout) on the provider's
 * stdout, stderr and the supervisor's control channel together, and returns what arrived. The tree ends when stop()
 * or kill() is called, when the provider exits on its own (its leftover children go with it), when this object is
 * destroyed, and when the host process dies.
 */
interface ProviderProcess
{
    public function tree(): ProcessTree;

    /** Write to the provider's stdin. Blocks until written (the supervisor's pump drains it). */
    public function write(string $bytes): void;

    /** Close the provider's stdin: an end-of-input, not a kill. */
    public function closeInput(): void;

    /**
     * Wait up to $timeout seconds for output or a change of state, and return what arrived, in order.
     *
     * @return list<array{0: 'stdout'|'stderr', 1: string}|array{0: 'exited', 1: int}|array{0: 'closed', 1: null}>
     *                                                                                                             'exited' when the provider ended on its own (with its code); 'closed' once, when nothing more will arrive
     */
    public function read(float $timeout): array;

    /** Whether the provider and its supervisor can still produce anything. */
    public function isOpen(): bool;

    /**
     * Close stdin, wait the grace, terminate the tree, and return what was found. This BLOCKS for up to the grace and
     * the report; on an event loop, use ProviderOnLoop::stop() instead.
     */
    public function stop(int $graceMs = 5000): StopVerdict;

    /** Terminate the whole tree now, without waiting for the verdict. */
    public function kill(): void;

    /**
     * The streams still able to produce something, for an event loop to watch: read(0.0) when one is readable. A
     * stream that reached its end is no longer listed, so a watcher never spins on it.
     *
     * @return list<resource>
     */
    public function streams(): array;

    /** The provider's exit code once it exited on its own; null while running, or when it was terminated. */
    public function exitCode(): ?int;

    /**
     * The verdict from what has been observed so far: for a stop driven by an event loop, called once the provider
     * is closed (or the wait for its report gave up).
     */
    public function verdict(bool $graceful): StopVerdict;
}

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

    /** Close stdin, wait the grace, terminate the tree, and return what was found. */
    public function stop(int $graceMs = 5000): StopVerdict;

    /** Terminate the whole tree now, without waiting for the verdict. */
    public function kill(): void;
}

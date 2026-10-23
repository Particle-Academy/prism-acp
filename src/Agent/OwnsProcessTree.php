<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\StopVerdict;

/** A driver whose agent runs under a supervisor that owns its whole process tree. */
interface OwnsProcessTree
{
    /**
     * End the agent and its whole tree, and say whether anything is left: close input, wait the grace, terminate.
     *
     * @return Deferred<mixed> settles with a StopVerdict
     */
    public function stop(int $graceMs = 5000): Deferred;

    /** The agent's pid and what holds its tree; null until it is running. */
    public function processTree(): ?ProcessTree;
}

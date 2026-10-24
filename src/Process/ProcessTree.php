<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/** The provider's process, and what holds its tree. Record these to find the tree from outside. */
final readonly class ProcessTree
{
    public function __construct(
        public int $providerPid,
        public int $supervisorPid,
        /** 'job-object' (Windows) or 'process-group' (Linux). */
        public string $containment,
        /** The provider's process group, for 'process-group' containment. */
        public ?int $pgid = null,
    ) {}
}

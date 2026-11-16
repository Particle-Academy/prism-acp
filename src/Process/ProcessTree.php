<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/** The provider's process, and what holds its tree. Record these to find the tree from outside. */
final readonly class ProcessTree
{
    public function __construct(
        public int $providerPid,
        public int $supervisorPid,
        /** 'job-object' (Windows), 'cgroup' (Linux with a delegated cgroup v2) or 'process-group' (otherwise). */
        public string $containment,
        /** The provider's process group: it leads one under 'cgroup' and 'process-group' containment alike. */
        public ?int $pgid = null,
        /** What this containment does NOT hold, in words (see Provider::containment()); null for a Job Object. */
        public ?string $weakness = null,
    ) {}
}

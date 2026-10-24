<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/** The platform cannot provide this release's process-tree ownership guarantees. No provider was spawned. */
final class ContainmentUnavailable extends ProcessTreeException {}

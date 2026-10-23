<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

/** One ACP session: its driver, its turn in flight, and whether its agent has exited. Internal to AcpAgent. */
final class Session
{
    public ?AgentDriver $driver = null;

    public ?Turn $turn = null;

    public bool $exited = false;

    public function __construct(
        public readonly string $id,
        public readonly string $cwd,
    ) {}
}

<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Process\ProcessTree;

/** The app-server's framed JSON-RPC channel; the production transport owns its process tree. */
interface CodexTransport
{
    /** @return Deferred<mixed> */
    public function start(): Deferred;

    public function send(mixed $frame): void;

    public function close(): void;

    /** @return Deferred<mixed> settles with StopVerdict */
    public function stop(int $graceMs = 5000): Deferred;

    public function processTree(): ?ProcessTree;
}

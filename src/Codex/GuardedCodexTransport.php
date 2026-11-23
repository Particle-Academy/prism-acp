<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Process\ProcessTree;

/** Every frame the driver writes passes the remote-control check first, whichever transport the embedder supplied. */
final readonly class GuardedCodexTransport implements CodexTransport
{
    public function __construct(private CodexTransport $inner) {}

    public function start(): Deferred
    {
        return $this->inner->start();
    }

    public function send(mixed $frame): void
    {
        $this->inner->send(CodexRemoteControl::checkedFrame($frame));
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function stop(int $graceMs = 5000): Deferred
    {
        return $this->inner->stop($graceMs);
    }

    public function processTree(): ?ProcessTree
    {
        return $this->inner->processTree();
    }
}

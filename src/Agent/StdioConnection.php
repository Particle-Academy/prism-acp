<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\Frame;
use Prism\Acp\Ndjson\NdjsonFramer;

/** One client's input, delivered to the peer as frames until it ends. Internal to Stdio::serve. */
final class StdioConnection
{
    private readonly NdjsonFramer $framer;

    private ?int $watcher = null;

    /** @var Deferred<mixed> */
    public readonly Deferred $closed;

    /**
     * @param  resource  $input
     * @param  (\Closure(string): void)|null  $onProtocolError
     */
    public function __construct(
        private readonly mixed $input,
        private readonly JsonRpcPeer $peer,
        private readonly AcpAgent $agent,
        private readonly ?\Closure $onProtocolError,
        private readonly Loop $loop,
    ) {
        $this->framer = new NdjsonFramer;
        $this->closed = new Deferred($loop);
        stream_set_blocking($input, false);
        $this->watcher = $loop->onReadable($input, fn () => $this->read());
    }

    private function read(): void
    {
        $chunk = @fread($this->input, 65536);
        if ($chunk !== false && $chunk !== '') {
            $this->deliver($this->framer->push($chunk));

            return;
        }
        if ($chunk === false) {
            $this->finish(false, 'client input failed');
        } elseif (feof($this->input)) {
            $this->finish(true, 'client disconnected');
        }
    }

    /** @param list<Frame> $frames */
    private function deliver(array $frames): void
    {
        foreach ($frames as $frame) {
            if (! $frame->ok) {
                $this->onProtocolError?->__invoke($frame->error === null ? 'NDJSON frame could not be read' : $frame->error->message);

                continue;
            }
            $this->peer->receive($frame->value);
        }
    }

    private function finish(bool $flush, string $reason): void
    {
        if ($this->closed->isSettled()) {
            return;
        }
        if ($this->watcher !== null) {
            $this->loop->cancel($this->watcher);
            $this->watcher = null;
        }
        // Flush first: a client's last message may have no trailing newline.
        if ($flush) {
            $this->deliver($this->framer->end());
        }
        // Every session's agent outlives this stream unless it is told otherwise.
        $this->agent->closeAll();
        $this->peer->fail(new \RuntimeException($reason));
        $this->closed->resolve(null);
    }
}

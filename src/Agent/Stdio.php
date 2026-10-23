<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\NdjsonFramer;

/**
 * Serve the ACP agent over a pipe: the client spawns the agent and they exchange NDJSON on its stdin and stdout. The
 * lines that join NdjsonFramer, JsonRpcPeer and AcpAgent to a pair of streams. Ported from prism-acp-ts
 * src/acp/stdio.ts; the streams are parameters so the whole surface can be driven in a test.
 *
 * The input must be selectable. On Windows a process's stdin is a pipe, which stream_select cannot wait on: pass
 * Stdio::stdin(), which relays it through a loopback socket there and is STDIN itself elsewhere.
 */
final class Stdio
{
    /**
     * @param  resource  $input
     * @param  resource  $output
     * @param  \Closure(JsonRpcPeer): AcpAgent  $agent  builds the agent on the peer this serves
     * @param  (\Closure(string): void)|null  $onProtocolError  a frame that arrived but was not usable
     * @return array{agent: AcpAgent, peer: JsonRpcPeer, closed: Deferred<mixed>}
     */
    public static function serve(mixed $input, mixed $output, \Closure $agent, ?\Closure $onProtocolError = null, ?Loop $loop = null): array
    {
        $loop ??= Loop::get();
        $framer = new NdjsonFramer;
        $peer = new JsonRpcPeer(
            send: function (mixed $message) use ($output): void {
                $line = NdjsonFramer::encodeLine($message);
                while ($line !== '') {
                    $written = @fwrite($output, $line);
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('the client\'s input is closed');
                    }
                    $line = substr($line, $written);
                }
                fflush($output);
            },
            onProtocolError: fn (string $problem) => $onProtocolError?->__invoke($problem),
            loop: $loop,
        );
        $served = $agent($peer);
        $closed = new Deferred($loop);

        $deliver = function (array $frames) use ($peer, $onProtocolError): void {
            foreach ($frames as $frame) {
                if (! $frame->ok) {
                    $onProtocolError?->__invoke($frame->error === null ? 'NDJSON frame could not be read' : $frame->error->message);

                    continue;
                }
                $peer->receive($frame->value);
            }
        };
        $watcher = null;
        $finish = function (bool $flush, string $reason) use (&$watcher, $loop, $framer, $deliver, $served, $peer, $closed): void {
            if ($closed->isSettled()) {
                return;
            }
            if ($watcher !== null) {
                $loop->cancel($watcher);
            }
            // Flush first: a client's last message may have no trailing newline.
            if ($flush) {
                $deliver($framer->end());
            }
            // Every session's agent outlives this stream unless it is told otherwise.
            $served->closeAll();
            $peer->fail(new \RuntimeException($reason));
            $closed->resolve(null);
        };
        stream_set_blocking($input, false);
        $watcher = $loop->onReadable($input, function () use ($input, $framer, $deliver, $finish): void {
            $chunk = @fread($input, 65536);
            if ($chunk !== false && $chunk !== '') {
                $deliver($framer->push($chunk));

                return;
            }
            if ($chunk === false) {
                $finish(false, 'client input failed');
            } elseif (feof($input)) {
                $finish(true, 'client disconnected');
            }
        });

        return ['agent' => $served, 'peer' => $peer, 'closed' => $closed];
    }

    /**
     * This process's stdin, as a stream an event loop can wait on. On Windows, a relay process holds the real stdin
     * and forwards it over a loopback socket that only it can authenticate to; it exits when stdin ends or the
     * socket closes. Elsewhere, STDIN itself.
     *
     * @return resource
     */
    public static function stdin(): mixed
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return STDIN;
        }
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException('no loopback listener for the stdin relay');
        }
        $address = 'tcp://'.stream_socket_get_name($server, false);
        $token = bin2hex(random_bytes(32));
        $relay = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/resources/stdin-relay.php'],
            [0 => STDIN, 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null,
            [...getenv(), 'PRISM_ACP_RELAY' => $address, 'PRISM_ACP_RELAY_TOKEN' => $token], ['bypass_shell' => true]);
        if (! is_resource($relay)) {
            fclose($server);
            throw new \RuntimeException('the stdin relay could not be started');
        }
        $deadline = microtime(true) + 10;
        try {
            while (microtime(true) < $deadline) {
                $socket = @stream_socket_accept($server, max(0.1, $deadline - microtime(true)));
                if ($socket === false) {
                    continue;
                }
                stream_set_timeout($socket, 5);
                // Byte by byte, unbuffered: anything read past the token line would sit in PHP's buffer, where
                // stream_select cannot see it, and the client's first message would wait for its second.
                stream_set_read_buffer($socket, 0);
                $line = '';
                while (strlen($line) < 128 && ($byte = fread($socket, 1)) !== false && $byte !== '' && $byte !== "\n") {
                    $line .= $byte;
                }
                if (hash_equals($token, $line)) {
                    return $socket;
                }
                fclose($socket);
            }
        } finally {
            fclose($server);
        }
        proc_terminate($relay);

        throw new \RuntimeException('the stdin relay did not connect');
    }
}

<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\NdjsonFramer;
use Prism\Acp\Process\WindowsProviderProcess;

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
    /** Keep the relay's control connection alive for as long as its input stream. */
    private static ?WindowsProviderProcess $inputRelay = null;

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
            onProtocolError: function (string $problem) use ($onProtocolError): void {
                $onProtocolError?->__invoke($problem);
            },
            loop: $loop,
        );
        $served = $agent($peer);
        $connection = new StdioConnection($input, $peer, $served, $onProtocolError, $loop);

        return ['agent' => $served, 'peer' => $peer, 'closed' => $connection->closed];
    }

    /**
     * This process's stdin, as a stream an event loop can wait on. On Windows, a relay process holds the real stdin
     * and forwards it through the authenticated supervisor's stdout socket. The relay enters the Job Object before
     * it runs; the supervisor ends it even if it is blocked reading when the host dies. Elsewhere, STDIN itself.
     *
     * @return resource
     */
    public static function stdin(): mixed
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return STDIN;
        }
        self::$inputRelay ??= WindowsProviderProcess::launch(
            PHP_BINARY,
            // Relay stdout becomes the client's ACP input. Host php.ini diagnostics must never enter it.
            ['-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__, 2).'/resources/stdin-relay.php'],
            getcwd() ?: sys_get_temp_dir(),
            ChildEnv::build(getenv())->env,
            STDIN,
        );

        return self::$inputRelay->output();
    }
}

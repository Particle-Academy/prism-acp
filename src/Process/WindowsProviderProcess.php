<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * A provider running under the Windows Job Object supervisor (resources/windows/supervisor.cs), in its TCP mode.
 *
 * Why TCP: PHP cannot select() on Windows pipes (a pipe reports readable and the read then stalls), and the real Claude
 * CLI refuses a socket as its stdin. So the supervisor gives the provider genuine pipes and forwards their bytes over
 * three loopback TCP streams, which PHP can select. The provider is created suspended and assigned to a Job Object with
 * kill-on-close and no breakaway before it runs, so every descendant, a detached grandchild included, is in the job.
 *
 * Every connection is authenticated with a random 256-bit token (compared in constant time), roles are taken in order
 * and never twice, and every handshake read is bounded in size and time. The supervisor connects only to the literal
 * IPv4 loopback address. The tree ends on stop() or kill(); when this object is destroyed (its control socket closes);
 * and when the host process dies (the OS closes that socket, and the supervisor reads it as a kill).
 */
final class WindowsProviderProcess implements ProviderProcess
{
    private const HANDSHAKE_SECONDS = 15.0;

    private const LINE_SECONDS = 5.0;

    private const MAX_AUTH_LINE = 128;

    private const MAX_CONTROL_LINE = 262_144;

    private string $controlBuffer = '';

    private bool $controlEof = false;

    private bool $stdoutEof = false;

    private bool $stderrEof = false;

    private bool $inputClosed = false;

    private bool $closedReported = false;

    private ?int $exitCode = null;

    private ?int $emptyCount = null;

    private bool $killed = false;

    /** @var list<array{0: 'stdout'|'stderr', 1: string}|array{0: 'exited', 1: int}|array{0: 'closed', 1: null}> */
    private array $pending = [];

    /**
     * @param  resource  $supervisor
     * @param  resource  $control
     * @param  resource  $stdin
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private function __construct(
        private $supervisor,
        private $control,
        private $stdin,
        private $stdout,
        private $stderr,
        private readonly ProcessTree $tree,
    ) {}

    /**
     * Start $command $args under the supervisor. Never through a shell; see WindowsCommand.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env  the provider's environment (build it with ChildEnv)
     */
    public static function launch(string $command, array $args, string $cwd, array $env): self
    {
        $resolved = WindowsCommand::resolve($command, $env);
        $commandLine = WindowsCommand::commandLine([$resolved['application'], ...$resolved['args'], ...$args]);
        $directory = realpath($cwd);
        if ($directory === false || ! is_dir($directory)) {
            throw new ProcessTreeException('the working directory does not exist');
        }
        $executable = WindowsSupervisorBuild::executable();

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new ProcessTreeException('cannot contain the provider: no loopback listener for the supervisor');
        }
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $token = bin2hex(random_bytes(32));

        $supervisor = proc_open([$executable],
            // The supervisor creates the provider with handle inheritance on, so it holds none of ours.
            [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes, $directory,
            [...$env, 'PRISM_ACP_TREE_CHANNEL' => "tcp://127.0.0.1:{$port}", 'PRISM_ACP_TREE_TOKEN' => $token],
            ['bypass_shell' => true]);
        if (! is_resource($supervisor)) {
            fclose($server);
            throw new ProcessTreeException('cannot contain the provider: the supervisor could not be started');
        }

        $sockets = [];
        try {
            $deadline = microtime(true) + self::HANDSHAKE_SECONDS;
            $control = self::acceptRole($server, $deadline, "hello {$token}");
            $sockets[] = $control;
            self::send($control, "auth {$token}\n");
            self::send($control, 'spawn '.base64_encode($resolved['application']).' '.base64_encode($commandLine).' '.base64_encode($directory)."\n");
            $streams = [];
            for ($fd = 0; $fd < 3; $fd++) {
                $stream = self::acceptRole($server, $deadline, "stdio {$token} {$fd}");
                $sockets[] = $stream;
                self::send($stream, "auth {$token} {$fd}\n");
                $streams[$fd] = $stream;
            }
            $status = self::readLine($control, min($deadline, microtime(true) + self::LINE_SECONDS), self::MAX_CONTROL_LINE);
            if ($status === null || preg_match('/^started (\d+)$/', $status, $started) !== 1) {
                $code = $status !== null && preg_match('/^failed ([\w-]{1,40})$/', $status, $failed) === 1 ? $failed[1] : 'no report';
                throw new ProcessTreeException("failed to start the provider ({$code})");
            }
        } catch (\Throwable $e) {
            foreach ($sockets as $socket) {
                @fclose($socket);
            }
            proc_terminate($supervisor);
            proc_close($supervisor);
            throw $e instanceof ProcessTreeException ? $e : new ProcessTreeException('failed to start the provider (handshake)');
        } finally {
            fclose($server);
        }

        foreach ([$control, ...$streams] as $socket) {
            stream_set_blocking($socket, false);
        }
        stream_set_blocking($streams[0], true);
        $status = proc_get_status($supervisor);

        return new self($supervisor, $control, $streams[0], $streams[1], $streams[2],
            new ProcessTree((int) $started[1], (int) $status['pid'], 'job-object'));
    }

    public function tree(): ProcessTree
    {
        return $this->tree;
    }

    public function write(string $bytes): void
    {
        if ($this->inputClosed) {
            throw new ProcessTreeException('the provider\'s input is closed');
        }
        while ($bytes !== '') {
            $written = @fwrite($this->stdin, $bytes);
            if ($written === false || $written === 0) {
                throw new ProcessTreeException('the provider\'s input could not be written');
            }
            $bytes = substr($bytes, $written);
        }
    }

    public function closeInput(): void
    {
        if (! $this->inputClosed) {
            $this->inputClosed = true;
            @stream_socket_shutdown($this->stdin, STREAM_SHUT_WR);
        }
    }

    public function read(float $timeout): array
    {
        if ($this->pending === []) {
            $this->poll($timeout);
        }
        $events = $this->pending;
        $this->pending = [];

        return $events;
    }

    public function isOpen(): bool
    {
        return ! $this->closedReported || $this->pending !== [];
    }

    public function stop(int $graceMs = 5000): StopVerdict
    {
        $this->closeInput();
        $graceEnd = microtime(true) + $graceMs / 1000;
        while ($this->exitCode === null && ! $this->allEof() && microtime(true) < $graceEnd) {
            $this->poll(min(0.05, max(0.0, $graceEnd - microtime(true))));
        }
        $graceful = $this->exitCode !== null && ! $this->killed;
        if ($this->exitCode === null) {
            $this->kill();
        }
        $reportEnd = microtime(true) + 10.0;
        while (! $this->allEof() && microtime(true) < $reportEnd) {
            $this->poll(0.05);
        }
        $this->finish();

        return $this->verdict($graceful);
    }

    public function verdict(bool $graceful): StopVerdict
    {
        if ($this->emptyCount !== null) {
            return new StopVerdict($graceful, $this->emptyCount === 0, $this->emptyCount, true);
        }

        // The supervisor ended without reporting. Its job handle closed with it, so the kernel ended the tree,
        // but nobody observed that.
        return new StopVerdict($graceful, true, null, false);
    }

    public function streams(): array
    {
        $streams = [];
        foreach ([[$this->stdout, $this->stdoutEof], [$this->stderr, $this->stderrEof], [$this->control, $this->controlEof]] as [$stream, $eof]) {
            if (! $eof && is_resource($stream)) {
                $streams[] = $stream;
            }
        }

        return $streams;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function kill(): void
    {
        $this->killed = true;
        if (! $this->controlEof && is_resource($this->control)) {
            @fwrite($this->control, "kill\n");
        }
    }

    public function __destruct()
    {
        // Closing the control socket is a kill: the supervisor reads its EOF and ends the tree.
        foreach ([$this->control, $this->stdin, $this->stdout, $this->stderr] as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        if (is_resource($this->supervisor)) {
            proc_close($this->supervisor);
        }
    }

    private function poll(float $timeout): void
    {
        $read = [];
        if (! $this->stdoutEof) {
            $read[] = $this->stdout;
        }
        if (! $this->stderrEof) {
            $read[] = $this->stderr;
        }
        if (! $this->controlEof) {
            $read[] = $this->control;
        }
        if ($read === []) {
            $this->finish();

            return;
        }
        $write = $except = null;
        $seconds = (int) floor($timeout);
        $ready = @stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1_000_000));
        if ($ready === false || $ready === 0) {
            return;
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, 65536);
            $eof = ($chunk === false || $chunk === '') && feof($stream);
            if ($stream === $this->control) {
                if ($eof) {
                    $this->controlEof = true;
                } else {
                    $this->onControl((string) $chunk);
                }
            } elseif ($stream === $this->stdout) {
                $eof ? $this->stdoutEof = true : ($chunk !== false && $chunk !== '' ? $this->pending[] = ['stdout', $chunk] : null);
            } else {
                $eof ? $this->stderrEof = true : ($chunk !== false && $chunk !== '' ? $this->pending[] = ['stderr', $chunk] : null);
            }
        }
        if ($this->allEof()) {
            $this->finish();
        }
    }

    private function onControl(string $chunk): void
    {
        $this->controlBuffer .= $chunk;
        if (strlen($this->controlBuffer) > self::MAX_CONTROL_LINE) {
            // A supervisor never says this much; treat it as broken and end the tree.
            $this->controlBuffer = '';
            $this->kill();

            return;
        }
        while (($newline = strpos($this->controlBuffer, "\n")) !== false) {
            $line = rtrim(substr($this->controlBuffer, 0, $newline), "\r");
            $this->controlBuffer = (string) substr($this->controlBuffer, $newline + 1);
            if (preg_match('/^exited (\d+)$/', $line, $m) === 1) {
                $this->exitCode = (int) $m[1];
                $this->pending[] = ['exited', $this->exitCode];
            } elseif (preg_match('/^empty (\d+)$/', $line, $m) === 1) {
                $this->emptyCount = (int) $m[1];
            }
        }
    }

    private function allEof(): bool
    {
        return $this->controlEof && $this->stdoutEof && $this->stderrEof;
    }

    private function finish(): void
    {
        if ($this->closedReported || ! $this->allEof()) {
            return;
        }
        $this->closedReported = true;
        $this->pending[] = ['closed', null];
    }

    /**
     * Accept the next connection whose first line is exactly $expected. Anything else (a stranger, a wrong token, a
     * line too long or too slow) is closed and the wait goes on, until the overall deadline.
     *
     * @param  resource  $server
     * @return resource
     */
    private static function acceptRole($server, float $deadline, string $expected)
    {
        while (($remaining = $deadline - microtime(true)) > 0) {
            $connection = @stream_socket_accept($server, $remaining);
            if ($connection === false) {
                break;
            }
            stream_set_read_buffer($connection, 0);
            $line = self::readLine($connection, min($deadline, microtime(true) + self::LINE_SECONDS), self::MAX_AUTH_LINE);
            if ($line !== null && strlen($line) === strlen($expected) && hash_equals($expected, $line)) {
                return $connection;
            }
            fclose($connection);
        }
        throw new ProcessTreeException('failed to start the provider (the supervisor did not connect)');
    }

    /**
     * One LF-terminated line, read a byte at a time so nothing after it is consumed. Null on EOF, timeout or excess length.
     *
     * @param  resource  $stream
     */
    private static function readLine($stream, float $deadline, int $limit): ?string
    {
        stream_set_blocking($stream, true);
        $line = '';
        while (strlen($line) <= $limit) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            stream_set_timeout($stream, (int) floor($remaining), (int) (fmod($remaining, 1.0) * 1_000_000));
            $byte = fread($stream, 1);
            if ($byte === false || $byte === '') {
                return null;
            }
            if ($byte === "\n") {
                return rtrim($line, "\r");
            }
            $line .= $byte;
        }

        return null;
    }

    /** @param resource $stream */
    private static function send($stream, string $line): void
    {
        if (@fwrite($stream, $line) !== strlen($line)) {
            throw new ProcessTreeException('failed to start the provider (the supervisor connection broke)');
        }
    }
}

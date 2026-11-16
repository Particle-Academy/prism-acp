<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

use Prism\Acp\Support\PrivateDirectory;

/**
 * A provider running under the POSIX supervisor (resources/posix/supervisor.php), on Linux or macOS. Ported from the
 * non-Windows path of prism-acp-ts src/process-tree.ts.
 *
 * The supervisor is a separate PHP CLI process. It forks the provider as the leader of its own process group and ends
 * the tree (see PosixTree) on stop() or kill(); when the provider exits on its own; when this object is destroyed (its
 * control socket closes); and when the host process dies, even by SIGKILL, because the kernel closes a dead process's
 * sockets and the supervisor reads that as a kill.
 *
 * What it holds differs by platform, and Provider::containment() says which:
 *  - Linux: the process group, plus every descendant found through /proc by parent chain or by the per-launch
 *    PRISM_ACP_TREE_ID cookie in its environment, so a child that called setsid is still ended. NOT held (G-92): a
 *    descendant that clears its environment, leaves the group and is orphaned before the stop.
 *  - macOS: the process group ONLY. A descendant that leaves the group (setsid, setpgid) escapes the stop.
 *
 * The provider's stdio are pipes this process selects on directly. The control channel is a Unix socket in a fresh
 * 0700 directory, removed as soon as the supervisor has authenticated with a random 256-bit token (compared in
 * constant time). Every file descriptor this process holds beyond stdio is replaced by /dev/null in the supervisor, so
 * neither it nor the provider inherits the host's sockets, files or listeners.
 */
final class PosixProviderProcess implements ProviderProcess
{
    private const HANDSHAKE_SECONDS = 15.0;

    private const LINE_SECONDS = 5.0;

    private const MAX_AUTH_LINE = 128;

    private const MAX_CONTROL_LINE = 262_144;

    /** sun_path is 104 bytes on macOS and 108 on Linux, including its terminator. */
    private const MAX_SOCKET_PATH = 100;

    private static ?string $supervisorPhp = null;

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
     * The PHP CLI that runs the supervisor. Default: PHP_BINARY when this is the CLI SAPI. A host on FPM, CGI or another
     * embedded SAPI names a trusted absolute PHP CLI here once, before the first launch; there is no PATH guess.
     */
    public static function useSupervisorPhp(?string $phpCli): void
    {
        if ($phpCli !== null && ($phpCli === '' || preg_match('/[\x00\r\n]/', $phpCli) === 1 || ! PosixCommand::isAbsolute($phpCli) || ! is_file($phpCli))) {
            throw new ProcessTreeException('the supervisor PHP CLI must be an absolute existing path');
        }
        self::$supervisorPhp = $phpCli;
    }

    /**
     * Start $command $args under the supervisor. Never through a shell; see PosixCommand.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env  the provider's environment (build it with ChildEnv)
     */
    public static function launch(string $command, array $args, string $cwd, array $env): self
    {
        $missing = PosixTree::missing();
        if ($missing !== []) {
            throw new ContainmentUnavailable('cannot contain the provider: this platform lacks '.implode(', ', $missing));
        }
        $php = self::$supervisorPhp ?? (PHP_SAPI === 'cli' ? PHP_BINARY : null);
        if ($php === null) {
            throw new ContainmentUnavailable('cannot contain the provider: the POSIX supervisor needs a PHP CLI; from a non-CLI SAPI, name one with PosixProviderProcess::useSupervisorPhp()');
        }
        $application = PosixCommand::resolve($command, $env);
        $directory = realpath($cwd);
        if ($directory === false || ! is_dir($directory)) {
            throw new ProcessTreeException('the working directory does not exist');
        }

        $socketDirectory = PrivateDirectory::create('prism-acp-tree-');
        $path = $socketDirectory.'/tree.sock';
        $removeChannel = static function () use ($socketDirectory, $path): void {
            @unlink($path);
            @rmdir($socketDirectory);
        };
        if (strlen($path) > self::MAX_SOCKET_PATH) {
            $removeChannel();
            throw new ProcessTreeException('cannot contain the provider: the temporary directory path is too long for a Unix socket');
        }
        $server = @stream_socket_server('unix://'.$path, $errno, $errstr);
        if ($server === false) {
            $removeChannel();
            throw new ProcessTreeException('cannot contain the provider: no private socket for the supervisor');
        }
        $token = bin2hex(random_bytes(32));
        try {
            $inherited = self::openDescriptors();
        } catch (ContainmentUnavailable $e) {
            fclose($server);
            $removeChannel();
            throw $e;
        }
        // Opened AFTER the listing, so it takes the lowest free number: the one the listing's own (now closed)
        // directory handle had, which is in the list. Nothing else can land on a listed number before the spawn.
        $null = fopen('/dev/null', 'r+');
        if ($null === false) {
            fclose($server);
            $removeChannel();
            throw new ProcessTreeException('cannot contain the provider: /dev/null could not be opened');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        foreach ($inherited as $fd) {
            // Shadow every inherited descriptor: PHP marks few of its own close-on-exec, and an exec'd provider would
            // otherwise hold this host's database connections, log files and listeners.
            $descriptors[$fd] = $null;
        }
        $supervisor = @proc_open([$php,
            // Its stdout and stderr are the provider's: a diagnostic of its own must never enter them.
            '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'html_errors=0',
            '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
            // Required, not run as the main script: the CLI keeps its main script open, and the provider would
            // inherit that descriptor. A required file is closed once compiled.
            '-r', 'require $argv[1];', dirname(__DIR__, 2).'/resources/posix/supervisor.php'],
            $descriptors, $pipes, $directory,
            [...$env, 'PRISM_ACP_TREE_CHANNEL' => $path, 'PRISM_ACP_TREE_TOKEN' => $token]);
        fclose($null);
        if (! is_resource($supervisor)) {
            fclose($server);
            $removeChannel();
            throw new ProcessTreeException('cannot contain the provider: the supervisor could not be started');
        }

        $control = null;
        try {
            $deadline = microtime(true) + self::HANDSHAKE_SECONDS;
            $control = self::acceptHello($server, $deadline, "hello {$token}");
            fclose($server);
            $server = null;
            // Authenticated: nothing else may ever connect.
            $removeChannel();
            self::send($control, implode(' ', ['spawn', base64_encode($application), base64_encode($directory), ...array_map(base64_encode(...), $args)])."\n");
            $status = self::readLine($control, min($deadline, microtime(true) + self::LINE_SECONDS), self::MAX_CONTROL_LINE);
            if ($status === null || preg_match('/^started (\d+)$/', $status, $started) !== 1) {
                $code = $status !== null && preg_match('/^failed ([\w-]{1,40})$/', $status, $failed) === 1 ? $failed[1] : 'no report';
                if (str_starts_with($code, 'missing-')) {
                    throw new ContainmentUnavailable('cannot contain the provider: the supervisor PHP CLI lacks '.substr($code, 8));
                }
                throw new ProcessTreeException("failed to start the provider ({$code})");
            }
        } catch (\Throwable $e) {
            if (is_resource($control)) {
                @fclose($control);
            }
            if (is_resource($server)) {
                fclose($server);
            }
            $removeChannel();
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            proc_terminate($supervisor, 9);
            proc_close($supervisor);
            throw $e instanceof ProcessTreeException ? $e : new ProcessTreeException('failed to start the provider (handshake)');
        }

        stream_set_blocking($control, false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $status = proc_get_status($supervisor);
        $pid = (int) $started[1];

        return new self($supervisor, $control, $pipes[0], $pipes[1], $pipes[2],
            new ProcessTree($pid, (int) $status['pid'], 'process-group', $pid, Provider::weakness()));
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
            @fclose($this->stdin);
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

        // The supervisor ended without reporting. Unlike a Job Object, nothing in the kernel ends a process group with
        // it, so nothing is claimed: the reference's verdict for this case, word for word.
        return new StopVerdict($graceful, false, null, false);
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
        foreach ([$this->control, $this->stdin, $this->stdout, $this->stderr] as $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        if (is_resource($this->supervisor)) {
            proc_close($this->supervisor);
        }
    }

    /**
     * The descriptors this process holds beyond stdio, from /proc/self/fd on Linux or /dev/fd on macOS.
     *
     * @return list<int>
     */
    private static function openDescriptors(): array
    {
        $listing = @scandir(PHP_OS_FAMILY === 'Linux' ? '/proc/self/fd' : '/dev/fd');
        if ($listing === false) {
            throw new ContainmentUnavailable('cannot contain the provider: this process\'s open descriptors cannot be listed');
        }
        $fds = [];
        foreach ($listing as $name) {
            if (ctype_digit($name) && (int) $name > 2) {
                $fds[] = (int) $name;
            }
        }

        return $fds;
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
     * Accept the connection whose first line is exactly $expected. Anything else (a wrong token, a line too long or
     * too slow) is closed and the wait goes on, until the overall deadline.
     *
     * @param  resource  $server
     * @return resource
     */
    private static function acceptHello($server, float $deadline, string $expected)
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

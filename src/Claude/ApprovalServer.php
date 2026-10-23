<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Ndjson\Json;

/**
 * The driver's end of the permission-prompt channel: bound before Claude starts, it answers each hook call with the
 * client's decision. A call without the token is refused (401), a body that is not a JSON object too (400), and an
 * oversized one (413). Anything that is not a PermissionOutcome -- a handler that failed, or answered with something
 * else -- is a cancellation, which denies.
 *
 * The wire is this package's own, between its hook and this server: the token line, then the hook input; the answer
 * is a status line, then the JSON the hook prints.
 */
final class ApprovalServer
{
    /** A hook input is a tool call's arguments; a file being written can be large, so the bound is generous. */
    private const MAX_REQUEST_BYTES = 16 * 1024 * 1024;

    private readonly string $token;

    /** @var resource|null */
    private $server = null;

    private ?int $acceptWatcher = null;

    /** @var array<int, array{socket: resource, buffer: string, watcher: int|null}> */
    private array $connections = [];

    /** @param \Closure(\stdClass): Deferred<mixed> $ask */
    public function __construct(
        private readonly string $address,
        private readonly \Closure $ask,
        private readonly Loop $loop,
    ) {
        $this->token = bin2hex(random_bytes(32));
    }

    /**
     * A channel for this session: loopback TCP on Windows, a unix socket in $directory elsewhere.
     *
     * @param  \Closure(\stdClass): Deferred<mixed>  $ask
     */
    public static function forDirectory(string $directory, \Closure $ask, Loop $loop): self
    {
        return new self(PHP_OS_FAMILY === 'Windows' ? 'tcp://127.0.0.1:0' : 'unix://'.$directory.'/approve.sock', $ask, $loop);
    }

    /**
     * Bind now, so the channel exists before the CLI is started, and say what the hook needs.
     *
     * @return array{socket: string, token: string}
     */
    public function listen(): array
    {
        $server = @stream_socket_server($this->address, $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException('the permission-prompt channel could not be opened');
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->acceptWatcher = $this->loop->onReadable($server, fn () => $this->accept());
        $bound = str_starts_with($this->address, 'tcp://') ? 'tcp://'.stream_socket_get_name($server, false) : $this->address;

        return ['socket' => $bound, 'token' => $this->token];
    }

    public function close(): void
    {
        if ($this->acceptWatcher !== null) {
            $this->loop->cancel($this->acceptWatcher);
            $this->acceptWatcher = null;
        }
        foreach (array_keys($this->connections) as $key) {
            $this->drop($key);
        }
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        $this->server = null;
        if (str_starts_with($this->address, 'unix://')) {
            @unlink(substr($this->address, 7));
        }
    }

    private function accept(): void
    {
        if (! is_resource($this->server)) {
            return;
        }
        $socket = @stream_socket_accept($this->server, 0);
        if ($socket === false) {
            return;
        }
        stream_set_blocking($socket, false);
        $key = (int) $socket;
        $this->connections[$key] = ['socket' => $socket, 'buffer' => '', 'watcher' => null];
        $this->connections[$key]['watcher'] = $this->loop->onReadable($socket, fn () => $this->read($key));
    }

    private function read(int $key): void
    {
        $connection = $this->connections[$key] ?? null;
        if ($connection === null) {
            return;
        }
        $chunk = @fread($connection['socket'], 65536);
        if ($chunk !== false && $chunk !== '') {
            $this->connections[$key]['buffer'] .= $chunk;
            if (strlen($this->connections[$key]['buffer']) > self::MAX_REQUEST_BYTES) {
                $this->answer($key, '413', null);
            }

            return;
        }
        if (! feof($connection['socket'])) {
            return;
        }
        // The hook has sent everything: decide.
        if ($connection['watcher'] !== null) {
            $this->loop->cancel($connection['watcher']);
            $this->connections[$key]['watcher'] = null;
        }
        $this->handle($key, $connection['buffer']);
    }

    private function handle(int $key, string $request): void
    {
        $newline = strpos($request, "\n");
        $presented = $newline === false ? $request : substr($request, 0, $newline);
        if (! hash_equals($this->token, $presented)) {
            $this->answer($key, '401', null);

            return;
        }
        try {
            $input = Json::decode((string) substr($request, $newline + 1));
        } catch (\JsonException) {
            $input = null;
        }
        if (! $input instanceof \stdClass) {
            $this->answer($key, '400', null);

            return;
        }
        try {
            $pending = ($this->ask)($input);
        } catch (\Throwable) {
            $this->answer($key, '200', PermissionOutcome::cancelled());

            return;
        }
        $pending->then(
            fn (mixed $outcome) => $this->answer($key, '200', $outcome instanceof PermissionOutcome ? $outcome : PermissionOutcome::cancelled()),
            fn (\Throwable $error) => $this->answer($key, '200', PermissionOutcome::cancelled()),
        );
    }

    private function answer(int $key, string $status, ?PermissionOutcome $outcome): void
    {
        $connection = $this->connections[$key] ?? null;
        if ($connection === null) {
            return;
        }
        $body = $outcome === null ? '' : Json::encode(['hookSpecificOutput' => Approvals::decisionFor($outcome)]);
        stream_set_blocking($connection['socket'], true);
        @fwrite($connection['socket'], $status."\n".$body);
        $this->drop($key);
    }

    private function drop(int $key): void
    {
        $connection = $this->connections[$key] ?? null;
        unset($this->connections[$key]);
        if ($connection === null) {
            return;
        }
        if ($connection['watcher'] !== null) {
            $this->loop->cancel($connection['watcher']);
        }
        if (is_resource($connection['socket'])) {
            @fclose($connection['socket']);
        }
    }
}

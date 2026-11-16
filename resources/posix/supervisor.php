<?php

/*
 * prism-acp's POSIX supervisor (Linux, macOS): a separate PHP CLI process that owns one provider's process tree. Ported
 * from SUPERVISOR_JS in prism-acp-ts src/process-tree.ts, with the same protocol, one line each way over a private Unix
 * socket authenticated by a random token:
 *
 *   -> hello <token>        <- spawn <spec>        -> started <pid> <mode> | failed <code>
 *   <- kill                 -> exited <code>       -> empty <processes left>
 *
 * (`<mode>` is this port's addition: `cgroup` or `process-group`.)
 *
 * The provider is forked, made the leader of its own process group, and exec'd directly, never through a shell; its
 * stdio is this process's, which the host connected to its own pipes. On Linux with a cgroup v2 delegated to this user
 * (see PosixCgroup), the provider first moves itself into a fresh cgroup of its own, BEFORE it execs, so everything it
 * starts is born there; then no environment is ever read. Otherwise it is the process group, the /proc parent chain and
 * the PRISM_ACP_TREE_ID cookie (see PosixTree), and only then are environments read, for the cookie's presence.
 *
 * The tree ends on `kill`, when the provider exits on its own, on SIGTERM/SIGINT/SIGHUP, and when the control socket
 * closes: the host dying closes it, even a SIGKILL, because the kernel closes a dead process's sockets. This process is
 * outside the provider's group and cgroup, so it survives the kill to report on it.
 */

declare(strict_types=1);

require_once __DIR__.'/../../src/Process/PosixTree.php';
require_once __DIR__.'/../../src/Process/PosixCgroup.php';

use Prism\Acp\Process\PosixCgroup;
use Prism\Acp\Process\PosixTree;

$channel = getenv('PRISM_ACP_TREE_CHANNEL');
$token = getenv('PRISM_ACP_TREE_TOKEN');
// '1' (the default): a delegated cgroup when there is one. '0': never. 'freeze': no cgroup.kill, freeze and kill.
$cgroupMode = getenv('PRISM_ACP_TREE_CGROUP');
putenv('PRISM_ACP_TREE_CHANNEL');
putenv('PRISM_ACP_TREE_TOKEN');
putenv('PRISM_ACP_TREE_CGROUP');
if (! is_string($channel) || $channel === '' || ! is_string($token) || $token === '') {
    exit(125);
}
// How environments are read in process-group mode. Only a test's wrapper script defines the override.
/** @var bool|Closure(string): ?string $environReader */
$environReader = isset($prismAcpEnvironReader) && $prismAcpEnvironReader instanceof Closure ? $prismAcpEnvironReader : true;

$socket = @stream_socket_client('unix://'.$channel, $errno, $errstr, 10);
if ($socket === false) {
    exit(125);
}

$say = static function (string $line) use ($socket): void {
    @fwrite($socket, $line."\n");
};
$say('hello '.$token);

$missing = PosixTree::missing();
$child = null;
$tree = null;
$cgroup = null;
$reaped = false;
$exit = null;
$finished = false;
$stopRequested = false;

if ($missing === []) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, static function () use (&$stopRequested): void {
            $stopRequested = true;
        });
    }
    if ($cgroupMode !== '0') {
        $delegated = PosixCgroup::delegated();
        if ($delegated['path'] !== null) {
            PosixCgroup::sweepStale($delegated['path']);
            $cgroup = PosixCgroup::create($delegated['path'], $cgroupMode !== 'freeze');
        }
    }
}

/** Collect the provider's exit, once. */
$reap = static function (bool $block = false) use (&$child, &$reaped, &$exit): bool {
    if ($child === null || $reaped) {
        return $reaped;
    }
    $status = 0;
    if (pcntl_waitpid($child, $status, $block ? 0 : WNOHANG) === $child) {
        $reaped = true;
        $exit = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + (int) pcntl_wtermsig($status);
    }

    return $reaped;
};

$finish = static function () use (&$child, &$tree, &$cgroup, &$finished, &$exit, $reap, $say, $socket): never {
    if ($child === null || ! $tree instanceof PosixTree) {
        $cgroup?->remove(0.0);
        exit(1);
    }
    $finished = true;
    // The cgroup first: nothing in it can leave or fork once it is killed or frozen. Then the group and parent chain,
    // for anything that left the cgroup (see PosixCgroup), and in process-group mode the cookie too.
    $cgroup?->kill();
    $tree->terminate();
    $started = microtime(true);
    do {
        $reap();
        $members = $cgroup?->pids() ?? [];
        $left = count($members) + $tree->left($members);
        if ($left === 0) {
            break;
        }
        $cgroup?->kill(0.2);
        usleep(20_000);
    } while (microtime(true) - $started < 5.0);
    if ($left === 0) {
        $cgroup?->remove();
    }
    $say('empty '.$left);
    @fclose($socket);
    exit(is_int($exit) ? min($exit, 255) : 1);
};

$spawn = static function (array $parts) use (&$child, &$tree, &$cgroup, $missing, $environReader, $say, $socket): void {
    if ($missing !== []) {
        $say('failed missing-'.(str_contains($missing[0], 'pcntl') ? 'pcntl' : (str_contains($missing[0], 'posix') ? 'posix' : 'proc')));
        exit(127);
    }
    $decode = static fn (string $value): string => (string) base64_decode($value, true);
    $command = $decode($parts[1]);
    $cwd = $decode($parts[2]);
    $args = array_map($decode, array_slice($parts, 3));
    $cookie = bin2hex(random_bytes(16));
    $env = [];
    foreach (getenv() as $name => $value) {
        if (! in_array($name, ['PRISM_ACP_TREE_CHANNEL', 'PRISM_ACP_TREE_TOKEN', 'PRISM_ACP_TREE_CGROUP', PosixTree::COOKIE_ENV], true)) {
            $env[$name] = $value;
        }
    }
    $env[PosixTree::COOKIE_ENV] = $cookie;
    // The child says whether it entered its cgroup before it execs, so the mode reported is the mode it runs in.
    $handshake = $cgroup !== null ? stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) : false;

    $pid = pcntl_fork();
    if ($pid === -1) {
        $say('failed fork');
        exit(127);
    }
    if ($pid === 0) {
        // The provider: its own group and cgroup, none of this process's signal handling or sockets, then exec.
        fclose($socket);
        posix_setpgid(0, 0);
        if ($handshake !== false) {
            fclose($handshake[0]);
            fwrite($handshake[1], $cgroup->adoptSelf() ? 'c' : 'g');
            fclose($handshake[1]);
        }
        foreach ([SIGTERM, SIGINT, SIGHUP, SIGPIPE] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        if (@chdir($cwd)) {
            @pcntl_exec($command, $args, $env);
        }
        exit(127);
    }
    // Both sides set the group, so neither can act before it exists.
    @posix_setpgid($pid, $pid);
    $mode = 'process-group';
    if ($handshake !== false) {
        fclose($handshake[1]);
        stream_set_timeout($handshake[0], 5);
        $mode = fread($handshake[0], 1) === 'c' ? 'cgroup' : 'process-group';
        fclose($handshake[0]);
        if ($mode !== 'cgroup') {
            $cgroup->remove(0.0);
            $cgroup = null;
        }
    }
    $child = $pid;
    // In cgroup mode no environment is read at all: the cgroup, not a cookie, holds the tree.
    $tree = new PosixTree($pid, $cookie, posix_getpid(), environ: $mode === 'cgroup' ? false : $environReader);
    $tree->adopt($pid);
    $say('started '.$pid.' '.$mode);
};

stream_set_blocking($socket, false);
$buffer = '';
while (true) {
    if ($stopRequested) {
        $finish();
    }
    if ($child !== null && ! $finished && $reap()) {
        // Ended on its own: say so, then end whatever it left behind.
        $say('exited '.(int) $exit);
        $finish();
    }
    $read = [$socket];
    $write = $except = null;
    $ready = @stream_select($read, $write, $except, 0, 50_000);
    if ($ready === false || $ready === 0) {
        continue;
    }
    $chunk = @fread($socket, 4096);
    if ($chunk === false || $chunk === '') {
        if (feof($socket)) {
            // The host closed the channel, or died: either way, a kill.
            $finish();
        }

        continue;
    }
    $buffer .= $chunk;
    if (strlen($buffer) > 262_144) {
        $finish();
    }
    while (($newline = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $newline);
        $buffer = substr($buffer, $newline + 1);
        $parts = explode(' ', $line);
        if ($parts[0] === 'spawn' && $child === null && count($parts) >= 3) {
            $spawn($parts);
        } elseif ($line === 'kill') {
            $finish();
        }
    }
}

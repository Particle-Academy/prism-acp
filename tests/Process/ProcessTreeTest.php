<?php

declare(strict_types=1);

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\ProcessTreeException;
use Prism\Acp\Process\ProviderOnLoop;
use Prism\Acp\Process\ProviderProcess;
use Prism\Acp\Process\WindowsProviderProcess;

/*
 * A PROVIDER'S WHOLE PROCESS TREE ENDS WITH IT. Ported from prism-acp-ts test/process-tree.test.ts.
 *
 * Every tree is real processes, and its deepest member is started DETACHED: the adversarial case, since a child that
 * only lives in its parent's group would die with almost any approach. Each test reads the pids the tree wrote and
 * asks the OS, not the supervisor's own report.
 *
 * The tree fixture (tests/fixtures/process-tree.cjs) is the reference's own, run with node: PHP cannot start a
 * detached grandchild that outlives its parent on Windows without a shell. Skipped by name where node is absent.
 */

function nodeBinary(): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        $candidate = rtrim($dir, '\\/').DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node');
        if ($dir !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function treeDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-tree-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function alivePid(int $pid): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return file_exists("/proc/{$pid}");
    }
    $p = proc_open(['tasklist', '/FI', "PID eq {$pid}", '/NH', '/FO', 'CSV'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    proc_close($p);

    return str_contains($out, "\"{$pid}\"");
}

/** @return list<int> */
function pidsIn(string $file, int $count, float $seconds = 15.0): array
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        $pids = is_file($file) ? array_map('intval', array_values(array_filter(explode("\n", trim((string) file_get_contents($file)))))) : [];
        if (count($pids) >= $count) {
            return $pids;
        }
        usleep(50_000);
    }
    throw new RuntimeException("timed out waiting for {$count} pids");
}

/** @param list<int> $pids */
function survivors(array $pids, float $seconds = 5.0): array
{
    $end = microtime(true) + $seconds;
    do {
        $alive = array_values(array_filter($pids, 'alivePid'));
        if ($alive === []) {
            return [];
        }
        usleep(100_000);
    } while (microtime(true) < $end);

    return $alive;
}

/** @param array<string, string> $extra */
function launchTree(string $dir, array $extra = []): ProviderProcess
{
    $env = ChildEnv::build(getenv(), allow: array_keys($extra), set: $extra)->env;

    return WindowsProviderProcess::launch((string) nodeBinary(), [dirname(__DIR__).'/fixtures/process-tree.cjs', $dir.DIRECTORY_SEPARATOR.'pids'], $dir, $env);
}

describe('Windows: a stop ends the whole tree, and says so', function () {
    it('stdin and stdout are the provider\'s; the tree is reported; stop leaves no process of it', function () {
        $dir = treeDir();
        $provider = launchTree($dir);
        $pids = pidsIn($dir.'/pids', 3);
        expect($provider->tree()->providerPid)->toBe($pids[0])
            ->and($provider->tree()->containment)->toBe('job-object');

        $provider->write("ping\n");
        $out = '';
        $end = microtime(true) + 10;
        while (! str_contains($out, 'echo:ping') && microtime(true) < $end) {
            foreach ($provider->read(0.2) as [$kind, $data]) {
                $out .= $kind === 'stdout' ? $data : '';
            }
        }
        expect($out)->toContain('echo:ping');

        $verdict = $provider->stop(200);
        expect($verdict->toArray())->toBe(['graceful' => false, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and(survivors($pids))->toBe([]);
    });

    it('a provider that exits within the grace is graceful, and its children still go', function () {
        $dir = treeDir();
        $provider = launchTree($dir, ['EXIT_ON_EOF' => '1']);
        $pids = pidsIn($dir.'/pids', 3);
        $verdict = $provider->stop(5000);
        expect($verdict->toArray())->toBe(['graceful' => true, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and(survivors($pids))->toBe([]);
    });

    it('a provider that ends on its own takes its leftover children with it, with no stop at all', function () {
        $dir = treeDir();
        $provider = launchTree($dir, ['EXIT_ON_EOF' => '1']);
        $pids = pidsIn($dir.'/pids', 3);
        $provider->closeInput();
        $events = [];
        $end = microtime(true) + 15;
        while ($provider->isOpen() && microtime(true) < $end) {
            array_push($events, ...$provider->read(0.2));
        }
        expect(array_values(array_filter($events, fn ($e) => $e[0] === 'exited')))->toBe([['exited', 7]])
            ->and(survivors($pids))->toBe([]);
    });

    it('kill() ends the tree too', function () {
        $dir = treeDir();
        $provider = launchTree($dir);
        $pids = pidsIn($dir.'/pids', 3);
        $provider->kill();
        expect(survivors($pids))->toBe([]);
    });

    it('an ORPHANED detached grandchild is still in the job, and still ends', function () {
        $dir = treeDir();
        $provider = launchTree($dir, ['ORPHAN' => '1']);
        $pids = pidsIn($dir.'/pids', 3);
        $end = microtime(true) + 10;
        while (alivePid($pids[1]) && microtime(true) < $end) {
            usleep(100_000);
        }
        expect(alivePid($pids[2]))->toBeTrue();
        expect($provider->stop(100)->empty)->toBeTrue()->and(survivors($pids))->toBe([]);
    });

    it('destroying the object ends the tree: its control socket closes, which the supervisor reads as a kill', function () {
        $dir = treeDir();
        $provider = launchTree($dir);
        $pids = pidsIn($dir.'/pids', 3);
        unset($provider);
        expect(survivors($pids))->toBe([]);
    });
})->skip(PHP_OS_FAMILY !== 'Windows' || nodeBinary() === null, 'Windows, with node for the tree fixture');

describe('Windows: on an event loop', function () {
    it('delivers output as it arrives, and stops the tree without blocking the loop', function () {
        $dir = treeDir();
        $loop = new Loop;
        $out = '';
        $closed = false;
        $onLoop = new ProviderOnLoop(launchTree($dir), $loop,
            onStdout: function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
            onStderr: fn (string $chunk) => null,
            onExited: fn (int $code) => null,
            onClosed: function () use (&$closed): void {
                $closed = true;
            },
        );
        $pids = pidsIn($dir.'/pids', 3);
        $onLoop->provider->write("ping\n");
        // A closure, not an arrow function: an arrow function would capture $out once, empty, and wait forever.
        $end = microtime(true) + 15;
        $loop->run(function () use (&$out, $end): bool {
            return str_contains($out, 'echo:ping') || microtime(true) > $end;
        });

        // A timer that must still fire while the stop waits out its grace.
        $ticks = 0;
        $tick = function () use ($loop, &$ticks, &$tick): void {
            $ticks++;
            $loop->delay(0.02, $tick);
        };
        $loop->delay(0.02, $tick);
        $verdict = $onLoop->stop(300)->await();

        expect($verdict->toArray())->toBe(['graceful' => false, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and($closed)->toBeTrue()
            ->and($ticks)->toBeGreaterThan(5)
            ->and(survivors($pids))->toBe([]);
    });

    it('reports a natural exit and its code, then closes', function () {
        $dir = treeDir();
        $loop = new Loop;
        $exited = null;
        $closed = new Deferred($loop);
        $onLoop = new ProviderOnLoop(launchTree($dir, ['EXIT_ON_EOF' => '1']), $loop,
            onStdout: fn (string $chunk) => null,
            onStderr: fn (string $chunk) => null,
            onExited: function (int $code) use (&$exited): void {
                $exited = $code;
            },
            onClosed: fn () => $closed->resolve(true),
        );
        $pids = pidsIn($dir.'/pids', 3);
        $onLoop->provider->closeInput();
        $closed->await();

        expect($exited)->toBe(7)
            ->and($onLoop->stop()->await()->toArray())->toBe(['graceful' => true, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and(survivors($pids))->toBe([]);
    });
})->skip(PHP_OS_FAMILY !== 'Windows' || nodeBinary() === null, 'Windows, with node for the tree fixture');

describe('Windows: the host dying ends the tree', function () {
    it('a hard kill of the host process leaves nothing of the tree within 2 s', function () {
        $dir = treeDir();
        $host = $dir.'/host.php';
        $autoload = str_replace('\\', '/', dirname(__DIR__, 2).'/vendor/autoload.php');
        $fixture = str_replace('\\', '/', dirname(__DIR__).'/fixtures/process-tree.cjs');
        $node = str_replace('\\', '/', (string) nodeBinary());
        $pidFile = str_replace('\\', '/', $dir.'/pids');
        $cwd = str_replace('\\', '/', $dir);
        file_put_contents($host, <<<PHP
            <?php
            require '{$autoload}';
            \$env = Prism\Acp\Env\ChildEnv::build(getenv())->env;
            \$p = Prism\Acp\Process\WindowsProviderProcess::launch('{$node}', ['{$fixture}', '{$pidFile}'], '{$cwd}', \$env);
            echo json_encode(['providerPid' => \$p->tree()->providerPid]), "\\n";
            while (true) { \$p->read(1.0); }
            PHP);
        $process = proc_open([PHP_BINARY, $host], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $pids = pidsIn($dir.'/pids', 3, 30);
        expect(array_values(array_filter($pids, 'alivePid')))->toBe($pids);
        $hostPid = proc_get_status($process)['pid'];
        $kill = proc_open(['taskkill', '/F', '/PID', (string) $hostPid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $kp);
        proc_close($kill);
        $started = microtime(true);
        expect(survivors($pids, 2.0))->toBe([])->and(microtime(true) - $started)->toBeLessThan(2.5);
        proc_close($process);
    });
})->skip(PHP_OS_FAMILY !== 'Windows' || nodeBinary() === null, 'Windows, with node for the tree fixture');

describe('Windows: the provider is run directly, never through a shell', function () {
    it('arguments arrive exactly, shell metacharacters included', function () {
        $dir = treeDir();
        file_put_contents($dir.'/argv.php', '<?php echo json_encode(array_slice($argv, 1));');
        $args = ['plain', 'with space', 'quote"inside', 'trailing\\', '', 'a&b|c>d %PATH% ^x', 'ünïcödé', '$(echo hi)'];
        $provider = WindowsProviderProcess::launch(PHP_BINARY, [$dir.'/argv.php', ...$args], $dir, ChildEnv::build(getenv())->env);
        $out = '';
        $end = microtime(true) + 15;
        while ($provider->isOpen() && microtime(true) < $end) {
            foreach ($provider->read(0.2) as [$kind, $data]) {
                $out .= $kind === 'stdout' ? $data : '';
            }
        }
        expect(json_decode($out, true))->toBe($args);
    });

    it('a binary that does not exist is refused before anything starts', function () {
        expect(fn () => WindowsProviderProcess::launch('prism-acp-no-such-binary', [], sys_get_temp_dir(), ChildEnv::build(getenv())->env))
            ->toThrow(ProcessTreeException::class, 'not found');
    });
})->skip(PHP_OS_FAMILY !== 'Windows', 'Windows');

<?php

declare(strict_types=1);

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\PosixProviderProcess;
use Prism\Acp\Process\ProcessTreeException;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\ProviderOnLoop;
use Prism\Acp\Process\ProviderProcess;
use Tests\Support\TreeProbe;

/*
 * A PROVIDER'S WHOLE PROCESS TREE ENDS WITH IT, on Linux and macOS. The POSIX half of ProcessTreeTest, ported from
 * prism-acp-ts test/process-tree.test.ts.
 *
 * Every tree is real processes (tests/fixtures/process-tree.php), and by default its deepest member calls setsid: the
 * adversarial case, since a child that stays in its parent's group dies with almost any approach. Each test reads the
 * pids the tree wrote and asks the OS, not the supervisor's report.
 *
 * macOS holds the process group ONLY, and the tests say so rather than skip: where a Linux test proves a setsid'd
 * descendant is ended, its macOS branch proves that descendant ESCAPES, so a change that made macOS look stronger than
 * it is would fail here too.
 */

// These are the process-group(+proc) tests, so cgroup mode is forced off even where a delegated cgroup exists: the
// fallback must keep behaving exactly as it did. PosixCgroupTest covers cgroup mode.
beforeEach(fn () => PosixProviderProcess::useCgroups(false));
afterEach(fn () => PosixProviderProcess::useCgroups(true));

/** @param array<string, string> $extra */
function launchPosixTree(string $dir, array $extra = []): ProviderProcess
{
    $env = ChildEnv::build(getenv(), allow: array_keys($extra), set: $extra)->env;

    return Provider::launch(PHP_BINARY, [dirname(__DIR__).'/fixtures/process-tree.php', $dir.'/pids'], $dir, $env);
}

/** The fixture options under which this platform's containment holds the whole tree. */
function groupedOnMac(): array
{
    return PHP_OS_FAMILY === 'Darwin' ? ['STAY_IN_GROUP' => '1'] : [];
}

describe('POSIX: a stop ends the whole tree, and says so', function () {
    it('reports process-group containment, with what it does not hold, before and after launch', function () {
        $containment = Provider::containment();
        expect($containment['kind'])->toBe('process-group')
            ->and($containment['weakness'])->toBe(PHP_OS_FAMILY === 'Linux' ? Provider::LINUX_WEAKNESS : Provider::MACOS_WEAKNESS);

        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, groupedOnMac());
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $tree = $provider->tree();
        expect($tree->providerPid)->toBe($pids[0])
            ->and($tree->containment)->toBe('process-group')
            ->and($tree->pgid)->toBe($pids[0])
            ->and($tree->weakness)->toBe($containment['weakness'])
            ->and($tree->supervisorPid)->not->toBe($tree->providerPid);
        $provider->stop(0);
    });

    it('stdin and stdout are the provider\'s; a stop leaves no process of a tree with a grandchild', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, groupedOnMac());
        $pids = TreeProbe::pids($dir.'/pids', 3);

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
            ->and(TreeProbe::survivors($pids))->toBe([]);
    });

    it('a provider that exits within the grace is graceful, and its children still go', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, ['EXIT_ON_EOF' => '1', ...groupedOnMac()]);
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $verdict = $provider->stop(5000);
        expect($verdict->toArray())->toBe(['graceful' => true, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and(TreeProbe::survivors($pids))->toBe([]);
    });

    it('a provider that ends on its own takes its leftover children with it, with no stop at all', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, ['EXIT_ON_EOF' => '1', ...groupedOnMac()]);
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $provider->closeInput();
        $events = [];
        $end = microtime(true) + 15;
        while ($provider->isOpen() && microtime(true) < $end) {
            array_push($events, ...$provider->read(0.2));
        }
        expect(array_values(array_filter($events, fn ($e) => $e[0] === 'exited')))->toBe([['exited', 7]])
            ->and(TreeProbe::survivors($pids))->toBe([]);
    });

    it('kill() ends the tree too', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, groupedOnMac());
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $provider->kill();
        expect(TreeProbe::survivors($pids))->toBe([]);
    });

    it('destroying the object ends the tree: its control socket closes, which the supervisor reads as a kill', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, groupedOnMac());
        $pids = TreeProbe::pids($dir.'/pids', 3);
        unset($provider);
        expect(TreeProbe::survivors($pids))->toBe([]);
    });

    it('a stop that reaches no supervisor report claims nothing', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, groupedOnMac());
        $pids = TreeProbe::pids($dir.'/pids', 3);
        // The supervisor dies first, so no `empty` report can arrive. Unlike a Job Object, nothing ends the group with it.
        posix_kill($provider->tree()->supervisorPid, 9);
        $verdict = $provider->stop(0);
        expect($verdict->toArray())->toBe(['graceful' => false, 'empty' => false, 'survivors' => null, 'verified' => false]);
        TreeProbe::cleanup($pids);
    });
})->skip(! TreeProbe::supported(), TreeProbe::skipReason());

describe('POSIX: a descendant that leaves the process group', function () {
    it('Linux: one that called setsid and was ORPHANED is still ended, through its tree cookie', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, ['ORPHAN' => '1']);
        $pids = TreeProbe::pids($dir.'/pids', 3);
        // Level 1 is gone, so no parent chain reaches level 2, and level 2 is in a session of its own.
        expect(TreeProbe::waitGone($pids[1]))->toBeTrue()->and(TreeProbe::alive($pids[2]))->toBeTrue();
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids);
        TreeProbe::cleanup($pids);
        expect($verdict->toArray())->toBe(['graceful' => false, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and($left)->toBe([]);
    })->skip(PHP_OS_FAMILY !== 'Linux', 'the /proc cookie sweep is Linux only');

    it('Linux: one that called setsid but still has its parent is ended through the parent chain or cookie', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir);
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids);
        TreeProbe::cleanup($pids);
        expect($verdict->empty)->toBeTrue()->and($left)->toBe([]);
    })->skip(PHP_OS_FAMILY !== 'Linux', 'the /proc sweep is Linux only');

    it('KNOWN HOLE (G-92), Linux: one that clears its environment, calls setsid and is orphaned escapes, unseen', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir, ['ORPHAN' => '1', 'CLEAR_ENV' => '1']);
        $pids = TreeProbe::pids($dir.'/pids', 3);
        expect(TreeProbe::waitGone($pids[1]))->toBeTrue();
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids, 1.0);
        TreeProbe::cleanup($pids);
        // This documents a limit; it is not a goal. If containment ever holds this case, flip the assertion and close G-92.
        expect($left)->toBe([$pids[2]])
            ->and($verdict->empty)->toBeTrue();
    })->skip(PHP_OS_FAMILY !== 'Linux', 'documents the Linux hole');

    it('macOS (WEAKER, labelled): one that called setsid escapes the stop, and the verdict cannot see it', function () {
        $dir = TreeProbe::dir();
        $provider = launchPosixTree($dir);
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids, 1.0);
        TreeProbe::cleanup($pids);
        expect($left)->toBe([$pids[2]])
            ->and($verdict->empty)->toBeTrue()
            ->and($provider->tree()->weakness)->toBe(Provider::MACOS_WEAKNESS);
    })->skip(PHP_OS_FAMILY !== 'Darwin', 'documents the macOS limit');
})->skip(! TreeProbe::supported(), TreeProbe::skipReason());

describe('POSIX: the host dying ends the tree', function () {
    it('a SIGKILL of the host process leaves nothing of the tree within 3 s', function () {
        $dir = TreeProbe::dir();
        $host = $dir.'/host.php';
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        $fixture = dirname(__DIR__).'/fixtures/process-tree.php';
        $extra = var_export(groupedOnMac(), true);
        file_put_contents($host, <<<PHP
            <?php
            require '{$autoload}';
            Prism\Acp\Process\PosixProviderProcess::useCgroups(false);
            \$extra = {$extra};
            \$env = Prism\Acp\Env\ChildEnv::build(getenv(), allow: array_keys(\$extra), set: \$extra)->env;
            \$p = Prism\Acp\Process\Provider::launch(PHP_BINARY, ['{$fixture}', '{$dir}/pids'], '{$dir}', \$env);
            echo json_encode(['providerPid' => \$p->tree()->providerPid]), "\\n";
            while (true) { \$p->read(1.0); }
            PHP);
        $process = proc_open([PHP_BINARY, $host], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $pids = TreeProbe::pids($dir.'/pids', 3, 30);
        expect(array_values(array_filter($pids, TreeProbe::alive(...))))->toBe($pids);
        $hostPid = proc_get_status($process)['pid'];
        posix_kill($hostPid, 9);
        $started = microtime(true);
        $left = TreeProbe::survivors($pids, 3.0);
        $elapsed = microtime(true) - $started;
        TreeProbe::cleanup($pids);
        proc_close($process);
        expect($left)->toBe([])->and($elapsed)->toBeLessThan(3.5);
    });
})->skip(! TreeProbe::supported(), TreeProbe::skipReason());

describe('POSIX: on an event loop', function () {
    it('delivers output as it arrives, and stops the tree without blocking the loop', function () {
        $dir = TreeProbe::dir();
        $loop = new Loop;
        $out = '';
        $closed = false;
        $onLoop = new ProviderOnLoop(launchPosixTree($dir, groupedOnMac()), $loop,
            onStdout: function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
            onStderr: fn (string $chunk) => null,
            onExited: fn (int $code) => null,
            onClosed: function () use (&$closed): void {
                $closed = true;
            },
        );
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $onLoop->provider->write("ping\n");
        $end = microtime(true) + 15;
        $loop->run(function () use (&$out, $end): bool {
            return str_contains($out, 'echo:ping') || microtime(true) > $end;
        });

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
            ->and(TreeProbe::survivors($pids))->toBe([]);
    });

    it('reports a natural exit and its code, then closes', function () {
        $dir = TreeProbe::dir();
        $loop = new Loop;
        $exited = null;
        $closed = new Deferred($loop);
        $onLoop = new ProviderOnLoop(launchPosixTree($dir, ['EXIT_ON_EOF' => '1', ...groupedOnMac()]), $loop,
            onStdout: fn (string $chunk) => null,
            onStderr: fn (string $chunk) => null,
            onExited: function (int $code) use (&$exited): void {
                $exited = $code;
            },
            onClosed: fn () => $closed->resolve(true),
        );
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $onLoop->provider->closeInput();
        $closed->await();

        expect($exited)->toBe(7)
            ->and($onLoop->stop()->await()->toArray())->toBe(['graceful' => true, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and(TreeProbe::survivors($pids))->toBe([]);
    });
})->skip(! TreeProbe::supported(), TreeProbe::skipReason());

describe('POSIX: the provider is run directly, never through a shell, and inherits nothing of the host', function () {
    it('arguments arrive exactly, shell metacharacters included', function () {
        $dir = TreeProbe::dir();
        file_put_contents($dir.'/argv.php', '<?php echo json_encode(array_slice($argv, 1));');
        $args = ['plain', 'with space', 'quote"inside', 'trailing\\', '', 'a&b|c>d $PATH `x` ; rm -rf /', 'ünïcödé', '$(echo hi)', '*'];
        $provider = Provider::launch(PHP_BINARY, [$dir.'/argv.php', ...$args], $dir, ChildEnv::build(getenv())->env);
        $out = '';
        $end = microtime(true) + 15;
        while ($provider->isOpen() && microtime(true) < $end) {
            foreach ($provider->read(0.2) as [$kind, $data]) {
                $out .= $kind === 'stdout' ? $data : '';
            }
        }
        expect(json_decode($out, true))->toBe($args);
    });

    it('a binary that does not exist is refused before anything starts, and a relative path is refused', function () {
        $env = ChildEnv::build(getenv())->env;
        expect(fn () => Provider::launch('prism-acp-no-such-binary', [], sys_get_temp_dir(), $env))
            ->toThrow(ProcessTreeException::class, 'not found')
            ->and(fn () => Provider::launch('./php', [], sys_get_temp_dir(), $env))
            ->toThrow(ProcessTreeException::class, 'must be absolute');
    });

    it('a bare name is found on the CHILD\'s PATH, never in the working directory', function () {
        $dir = TreeProbe::dir();
        $planted = $dir.'/prism-acp-planted';
        file_put_contents($planted, "#!/bin/sh\necho planted\n");
        chmod($planted, 0700);
        $env = ChildEnv::build(getenv(), set: ['PATH' => '.:'.$dir.'/nowhere'])->env;
        expect(fn () => Provider::launch('prism-acp-planted', [], $dir, $env))->toThrow(ProcessTreeException::class, 'not found on PATH');
    });

    it('Linux: the provider holds none of the host\'s files, sockets or listeners', function () {
        $dir = TreeProbe::dir();
        $secret = $dir.'/host-only-file';
        $handle = fopen($secret, 'w');
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $provider = Provider::launch(PHP_BINARY, [dirname(__DIR__).'/fixtures/descriptors.php'], $dir, ChildEnv::build(getenv())->env);
        $out = '';
        $end = microtime(true) + 15;
        while ($provider->isOpen() && microtime(true) < $end) {
            foreach ($provider->read(0.2) as [$kind, $data]) {
                $out .= $kind === 'stdout' ? $data : '';
            }
        }
        fclose($handle);
        fclose($listener);
        $fds = json_decode($out, true);
        expect($fds)->toBeArray()->toHaveKeys(['0', '1', '2']);
        foreach ($fds as $fd => $target) {
            if ((int) $fd <= 2) {
                continue;
            }
            // What a fresh PHP may hold for itself: its own script, /dev/null shadows, and the listing's own handle
            // (closed again by the time it is named, so it names nothing).
            expect($target)->not->toContain('host-only-file')->not->toStartWith('socket:')
                ->and(in_array($target, ['', '/dev/null', '/proc/'.$provider->tree()->providerPid.'/fd', realpath(dirname(__DIR__).'/fixtures/descriptors.php')], true)
                    || str_starts_with($target, 'anon_inode:'))->toBeTrue("fd {$fd} -> {$target}");
        }
    })->skip(PHP_OS_FAMILY !== 'Linux', '/proc/self/fd is Linux only');
})->skip(! TreeProbe::supported(), TreeProbe::skipReason());

describe('POSIX: a platform without what containment needs refuses before spawn', function () {
    foreach (['ext-pcntl' => 'pcntl_fork', 'ext-posix' => 'posix_setpgid'] as $extension => $function) {
        it("without {$extension}: ContainmentUnavailable naming it, and nothing ran", function () use ($extension, $function) {
            $dir = TreeProbe::dir();
            $marker = $dir.'/spawned';
            $script = $dir.'/host.php';
            $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
            file_put_contents($script, <<<PHP
                <?php
                require '{$autoload}';
                try {
                    Prism\Acp\Process\Provider::launch(PHP_BINARY, ['-r', 'file_put_contents(\$argv[1], "spawned");', '{$marker}'], '{$dir}', Prism\Acp\Env\ChildEnv::build(getenv())->env);
                    echo 'launched';
                } catch (Throwable \$e) {
                    echo get_class(\$e), ': ', \$e->getMessage();
                }
                PHP);
            // The extension is still installed; disabling the function is how a host's php.ini removes it.
            $process = proc_open([PHP_BINARY, '-d', "disable_functions={$function}", $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = (string) stream_get_contents($pipes[1]);
            proc_close($process);
            usleep(300_000);
            expect($out)->toStartWith('Prism\Acp\Process\ContainmentUnavailable: ')->toContain($extension)
                ->and(file_exists($marker))->toBeFalse();
        });
    }

    it('from a non-CLI SAPI with no named supervisor PHP, it refuses before spawn', function () {
        expect(fn () => PosixProviderProcess::useSupervisorPhp('relative/php'))->toThrow(ProcessTreeException::class, 'absolute');
    });
})->skip(PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin', 'POSIX only');

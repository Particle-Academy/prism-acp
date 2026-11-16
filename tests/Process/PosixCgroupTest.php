<?php

declare(strict_types=1);

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\PosixCgroup;
use Prism\Acp\Process\PosixProviderProcess;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\ProviderProcess;
use Tests\Support\TreeProbe;

/*
 * CGROUP MODE (Linux, with a cgroup v2 delegated to this user). The provider moves itself into a fresh cgroup of its
 * own before it execs, so everything it starts is born there and cannot leave by setsid, a cleared environment or
 * being orphaned: the G-92 case, now held. And no environment is read at all, which the observed supervisor proves.
 *
 * Skipped, with the reason, where no delegated cgroup exists. CI runs this file inside one (see ci.yml), and a step
 * there fails the build if cgroup mode is not actually active, so a skip cannot pass for coverage.
 */

function delegatedCgroup(): ?string
{
    return TreeProbe::supported() ? PosixCgroup::delegated()['path'] : null;
}

function cgroupSkipReason(): string
{
    return TreeProbe::supported() ? 'no delegated cgroup v2 here ('.PosixCgroup::delegated()['reason'].'); UNCHECKED' : TreeProbe::skipReason();
}

/**
 * The tree fixture under the OBSERVED supervisor: every environment read is recorded in $reads (or throws).
 *
 * @param  array<string, string>  $extra
 */
function launchObservedTree(string $dir, array $extra, string $observe): ProviderProcess
{
    $set = [...$extra, 'PRISM_TEST_ENVIRON' => $observe];
    PosixProviderProcess::useSupervisorScript(dirname(__DIR__).'/fixtures/observed-supervisor.php');
    try {
        return Provider::launch(PHP_BINARY, [dirname(__DIR__).'/fixtures/process-tree.php', $dir.'/pids'],
            $dir, ChildEnv::build(getenv(), allow: array_keys($set), set: $set)->env);
    } finally {
        PosixProviderProcess::useSupervisorScript(null);
    }
}

/** The cgroup a running process is in, relative to the cgroup2 mount. */
function cgroupOf(int $pid): ?string
{
    $membership = @file_get_contents("/proc/{$pid}/cgroup");

    return is_string($membership) && preg_match('/^0::(\/[^\n]*)$/m', $membership, $m) === 1 ? $m[1] : null;
}

afterEach(function () {
    PosixProviderProcess::useCgroups(true);
    PosixProviderProcess::useSupervisorScript(null);
});

describe('cgroup mode: the provider\'s own cgroup holds the whole tree', function () {
    it('is the mode reported, before and after launch, and the provider really is in a fresh cgroup of ours', function () {
        expect(Provider::containment())->toMatchArray(['kind' => 'cgroup', 'mode' => 'cgroup', 'weakness' => Provider::LINUX_CGROUP_WEAKNESS]);
        $dir = TreeProbe::dir();
        $provider = launchObservedTree($dir, [], 'record:'.$dir.'/reads');
        $pids = TreeProbe::pids($dir.'/pids', 3);
        $tree = $provider->tree();
        $cgroup = cgroupOf($pids[0]);
        expect($tree->containment)->toBe('cgroup')
            ->and($tree->weakness)->toBe(Provider::LINUX_CGROUP_WEAKNESS)
            ->and(basename((string) $cgroup))->toMatch(PosixCgroup::NAME_PATTERN)
            ->and(cgroupOf($pids[2]))->toBe($cgroup)
            ->and(cgroupOf($tree->supervisorPid))->not->toBe($cgroup);
        $verdict = $provider->stop(100);
        expect($verdict->toArray())->toBe(['graceful' => false, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and(TreeProbe::survivors($pids))->toBe([])
            // The provider's cgroup is removed once empty.
            ->and(is_dir('/sys/fs/cgroup'.$cgroup))->toBeFalse();
    });

    it('ends a descendant that cleared its environment, called setsid and was orphaned (G-92, now held), reading no environment', function (string $kill) {
        PosixProviderProcess::useCgroups($kill === 'freeze' ? 'freeze' : true);
        $dir = TreeProbe::dir();
        $provider = launchObservedTree($dir, ['ORPHAN' => '1', 'CLEAR_ENV' => '1'], 'record:'.$dir.'/reads');
        $pids = TreeProbe::pids($dir.'/pids', 3);
        expect(TreeProbe::waitGone($pids[1]))->toBeTrue()->and(TreeProbe::alive($pids[2]))->toBeTrue();
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids);
        TreeProbe::cleanup($pids);
        expect($provider->tree()->containment)->toBe('cgroup')
            ->and($verdict->toArray())->toBe(['graceful' => false, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and($left)->toBe([])
            ->and(file_exists($dir.'/reads'))->toBeFalse();
    })->with(['cgroup.kill' => 'kill', 'freeze, then SIGKILL each member' => 'freeze']);

    it('reads no environment even when every read would throw', function () {
        $dir = TreeProbe::dir();
        $provider = launchObservedTree($dir, ['ORPHAN' => '1', 'CLEAR_ENV' => '1'], 'throw');
        $pids = TreeProbe::pids($dir.'/pids', 3);
        expect(TreeProbe::waitGone($pids[1]))->toBeTrue();
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids);
        TreeProbe::cleanup($pids);
        expect($verdict->toArray())->toBe(['graceful' => false, 'empty' => true, 'survivors' => 0, 'verified' => true])->and($left)->toBe([]);
    });

    it('a SIGKILL of the host ends the tree, the env-cleared orphan included, within 3 s', function () {
        $dir = TreeProbe::dir();
        $host = $dir.'/host.php';
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        $fixture = dirname(__DIR__).'/fixtures/process-tree.php';
        file_put_contents($host, <<<PHP
            <?php
            require '{$autoload}';
            \$set = ['ORPHAN' => '1', 'CLEAR_ENV' => '1'];
            \$p = Prism\Acp\Process\Provider::launch(PHP_BINARY, ['{$fixture}', '{$dir}/pids'], '{$dir}', Prism\Acp\Env\ChildEnv::build(getenv(), allow: array_keys(\$set), set: \$set)->env);
            echo \$p->tree()->containment, "\\n";
            while (true) { \$p->read(1.0); }
            PHP);
        $process = proc_open([PHP_BINARY, $host], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $mode = trim((string) fgets($pipes[1]));
        $pids = TreeProbe::pids($dir.'/pids', 3, 30);
        expect(TreeProbe::waitGone($pids[1]))->toBeTrue();
        posix_kill(proc_get_status($process)['pid'], 9);
        $started = microtime(true);
        $left = TreeProbe::survivors([$pids[0], $pids[2]], 3.0);
        $elapsed = microtime(true) - $started;
        TreeProbe::cleanup($pids);
        proc_close($process);
        expect($mode)->toBe('cgroup')->and($left)->toBe([])->and($elapsed)->toBeLessThan(3.5);
    });

    it('KNOWN RESIDUAL: one that moves its own pid into the delegated parent cgroup, leaves the group and is orphaned escapes', function () {
        $dir = TreeProbe::dir();
        $provider = launchObservedTree($dir, ['ORPHAN' => '1', 'CLEAR_ENV' => '1', 'ESCAPE_CGROUP' => '1'], 'record:'.$dir.'/reads');
        $pids = TreeProbe::pids($dir.'/pids', 3);
        expect(TreeProbe::waitGone($pids[1]))->toBeTrue();
        $escaped = cgroupOf($pids[2]);
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids, 1.0);
        TreeProbe::cleanup($pids);
        // A limit, not a goal: the delegation is to this user, so the kernel allows the move. If containment ever
        // holds this, flip the assertion and update Provider::LINUX_CGROUP_WEAKNESS.
        expect(basename((string) $escaped))->not->toMatch(PosixCgroup::NAME_PATTERN)
            ->and($left)->toBe([$pids[2]])
            ->and($verdict->empty)->toBeTrue()
            ->and(file_exists($dir.'/reads'))->toBeFalse();
    });

    it('positive control: the forced fallback DOES read environments, and still has the G-92 hole', function () {
        PosixProviderProcess::useCgroups(false);
        $dir = TreeProbe::dir();
        $provider = launchObservedTree($dir, ['ORPHAN' => '1', 'CLEAR_ENV' => '1'], 'record:'.$dir.'/reads');
        $pids = TreeProbe::pids($dir.'/pids', 3);
        expect(TreeProbe::waitGone($pids[1]))->toBeTrue();
        $verdict = $provider->stop(100);
        $left = TreeProbe::survivors($pids, 1.0);
        TreeProbe::cleanup($pids);
        expect($provider->tree()->containment)->toBe('process-group')
            ->and($provider->tree()->weakness)->toBe(Provider::LINUX_WEAKNESS)
            ->and(file_exists($dir.'/reads'))->toBeTrue()
            ->and($left)->toBe([$pids[2]])
            ->and($verdict->empty)->toBeTrue();
    });
})->skip(delegatedCgroup() === null, cgroupSkipReason());

describe('cgroup mode: stale provider cgroups from a crashed supervisor', function () {
    it('removes only EMPTY cgroups with our exact naming, never a populated, foreign or nested one', function () {
        $parent = (string) delegatedCgroup();
        $ours = $parent.'/prism-acp-'.bin2hex(random_bytes(8));
        $busy = PosixCgroup::create($parent);
        $nested = $parent.'/prism-acp-'.bin2hex(random_bytes(8));
        $lookalikes = [$parent.'/prism-acp-'.bin2hex(random_bytes(7)), $parent.'/prism-acp-'.strtoupper(bin2hex(random_bytes(8))),
            $parent.'/other-'.bin2hex(random_bytes(8)), $parent.'/xprism-acp-'.bin2hex(random_bytes(8))];
        $sleeper = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            mkdir($ours);
            mkdir($nested);
            mkdir($nested.'/child');
            foreach ($lookalikes as $path) {
                mkdir($path);
            }
            expect($busy)->not->toBeNull()->and($busy->adopt(proc_get_status($sleeper)['pid']))->toBeTrue();

            $removed = PosixCgroup::sweepStale($parent);

            expect($removed)->toBe([basename($ours)])
                ->and(is_dir($ours))->toBeFalse()
                ->and(is_dir($busy->path))->toBeTrue()
                ->and(is_dir($nested.'/child'))->toBeTrue();
            foreach ($lookalikes as $path) {
                expect(is_dir($path))->toBeTrue($path);
            }
        } finally {
            proc_terminate($sleeper, 9);
            proc_close($sleeper);
            $busy?->kill();
            $busy?->remove();
            foreach ([$nested.'/child', $nested, $ours, ...$lookalikes] as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
        }
    });

    it('a launch sweeps an empty stale cgroup it finds', function () {
        $parent = (string) delegatedCgroup();
        $stale = $parent.'/prism-acp-'.bin2hex(random_bytes(8));
        mkdir($stale);
        $dir = TreeProbe::dir();
        $provider = Provider::launch(PHP_BINARY, ['-r', 'echo "up";'], $dir, ChildEnv::build(getenv())->env);
        $provider->stop(1000);
        expect(is_dir($stale))->toBeFalse();
    });
})->skip(delegatedCgroup() === null, cgroupSkipReason());

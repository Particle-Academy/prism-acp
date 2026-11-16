<?php

declare(strict_types=1);

use Prism\Acp\Process\PosixTree;
use Tests\Support\TreeProbe;

/*
 * THE PID-REUSE GUARD. A pid recorded as part of a tree is only signalled while its start time still matches: once the
 * process is gone, the kernel may hand its pid to anyone. This cannot wait for a real reuse, so it plants the situation
 * directly: an unrelated process the tree "remembers" under a start time it does not have. That is exactly what a reused
 * pid looks like to the supervisor.
 */

/** A sleeping PHP process; with $ownGroup it leads a process group of its own, like a provider. */
function sleeper(bool $ownGroup): array
{
    $code = ($ownGroup ? 'posix_setpgid(0, 0);' : '').'fwrite(STDOUT, "up\n"); sleep(60);';
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    expect(fgets($pipes[1]))->toBe("up\n");

    return [$process, proc_get_status($process)['pid']];
}

describe('PosixTree: a reused pid is never signalled', function () {
    it('leaves alone a process whose start time differs from the one recorded, while ending the tree', function () {
        [$treeProcess, $tree] = sleeper(true);
        [$bystanderProcess, $bystander] = sleeper(false);
        try {
            $actual = TreeProbe::startTime($bystander);
            expect($actual)->toBeString();
            $posix = new PosixTree($tree, bin2hex(random_bytes(16)), getmypid());
            $posix->adopt($tree);
            // The bystander "was" in the tree under another start time: its pid has been reused since.
            $posix->remember($bystander, (string) ((int) $actual + 1));
            $posix->terminate();

            expect(TreeProbe::waitGone($tree, 5.0))->toBeTrue()
                ->and(TreeProbe::alive($bystander))->toBeTrue()
                ->and($posix->left())->toBe(0);
        } finally {
            proc_terminate($treeProcess, 9);
            proc_terminate($bystanderProcess, 9);
            proc_close($treeProcess);
            proc_close($bystanderProcess);
        }
    });

    it('positive control: with its true start time recorded, the same process IS signalled', function () {
        [$treeProcess, $tree] = sleeper(true);
        [$bystanderProcess, $bystander] = sleeper(false);
        try {
            $posix = new PosixTree($tree, bin2hex(random_bytes(16)), getmypid());
            $posix->adopt($tree);
            $posix->remember($bystander, (string) TreeProbe::startTime($bystander));
            $posix->terminate();

            expect(TreeProbe::waitGone($tree, 5.0))->toBeTrue()
                ->and(TreeProbe::waitGone($bystander, 5.0))->toBeTrue();
        } finally {
            proc_terminate($treeProcess, 9);
            proc_terminate($bystanderProcess, 9);
            proc_close($treeProcess);
            proc_close($bystanderProcess);
        }
    });

    it('never adopts this process itself, even when it carries the cookie or shares the group', function () {
        $posix = new PosixTree(posix_getpgid(getmypid()), 'x', getmypid());
        $posix->track($posix->scan());
        expect(array_key_exists(getmypid(), $posix->known()))->toBeFalse();
    });
})->skip(PHP_OS_FAMILY !== 'Linux' || ! TreeProbe::supported(), PHP_OS_FAMILY !== 'Linux' ? 'start times come from /proc, Linux only' : TreeProbe::skipReason());

it('names what is missing, and on an unsupported platform names the platform', function () {
    $missing = PosixTree::missing();
    if (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'Darwin') {
        expect($missing)->toBe(array_values(array_filter([
            function_exists('pcntl_fork') && function_exists('pcntl_exec') && function_exists('pcntl_waitpid') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals') ? null : 'ext-pcntl',
            function_exists('posix_kill') && function_exists('posix_setpgid') && function_exists('posix_getpid') && function_exists('posix_get_last_error') ? null : 'ext-posix',
            PHP_OS_FAMILY !== 'Linux' || is_readable('/proc/self/stat') ? null : 'a readable /proc',
        ])));
    } else {
        expect($missing)->toBe([PHP_OS_FAMILY.' (supported: Windows, Linux, macOS)']);
    }
});

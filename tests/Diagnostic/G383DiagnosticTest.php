<?php

declare(strict_types=1);

use Prism\Acp\Support\DirectoryProtectionException;
use Prism\Acp\Support\PrivateDirectory;
use Tests\Support\CredentialPaths;
use Tests\Support\CredentialTree;

// G-383 diagnostic only (draft PR, never merged). Owned temporary fixtures only.

function g383Fresh(string $path): array
{
    clearstatcache(true);

    return lstat($path);
}

function g383Backdate(string $path): void
{
    $past = time() - 3600;
    if (! touch($path, $past, $past)) {
        throw new RuntimeException('backdate failed');
    }
}

it('G-383 diagnostic', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('linux diagnostic');
    }
    $report = [];

    // 1. Probe sensitivity: with atime backdated below mtime/ctime, does reading THROUGH the link show?
    $tree = new CredentialTree;
    $target = PrivateDirectory::create('prism-acp-g383-target-');
    $alias = $tree->home.DIRECTORY_SEPARATOR.'link';
    CredentialPaths::link($target, $alias);
    g383Backdate($target);
    $before = g383Fresh($target);
    scandir($alias);
    $after = g383Fresh($target);
    $report['control_read_through_link_changes_atime'] = $after['atime'] !== $before['atime'];
    $report['control_atime_delta'] = $after['atime'] - $before['atime'];

    // 1b. Without backdating (the current test's setup): is a read through the link visible at all?
    $before = g383Fresh($target);
    scandir($alias);
    $after = g383Fresh($target);
    $report['unbackdated_read_through_link_changes_atime'] = $after['atime'] !== $before['atime'];

    // 2. The property: backdated target, run the protection, compare fresh readings.
    g383Backdate($target);
    $before = g383Fresh($target);
    $threw = false;
    try {
        PrivateDirectory::assertProtected($tree->home);
    } catch (DirectoryProtectionException) {
        $threw = true;
    }
    $after = g383Fresh($target);
    $report['protection_threw'] = $threw;
    $report['protection_changed_target_lstat'] = $after !== $before;
    $report['protection_atime_delta'] = $after['atime'] - $before['atime'];

    // 2b. Unreadable target: the protection must refuse identically and emit no warning.
    chmod($target, 0000);
    $warnings = [];
    set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;

        return true;
    });
    $threw = false;
    try {
        PrivateDirectory::assertProtected($tree->home);
    } catch (DirectoryProtectionException) {
        $threw = true;
    } finally {
        restore_error_handler();
    }
    chmod($target, 0700);
    $report['unreadable_target_threw'] = $threw;
    $report['unreadable_target_warnings'] = count($warnings);
    CredentialPaths::unlink($alias);
    $tree->remove();
    PrivateDirectory::remove($target);

    // 3. Mechanism: does lstat() after symlink() serve PHP's stat cache?
    $target = PrivateDirectory::create('prism-acp-g383-cache-');
    $home = PrivateDirectory::create('prism-acp-g383-cache-home-');
    clearstatcache(true);
    $first = lstat($target);
    exec('touch -a -d @1000000000 '.escapeshellarg($target), $out, $code);
    symlink($target, $home.DIRECTORY_SEPARATOR.'link');
    $cached = lstat($target);
    $fresh = g383Fresh($target);
    $report['external_touch_exit'] = $code;
    $report['lstat_after_symlink_is_stale_cache'] = $cached['atime'] === $first['atime'] && $fresh['atime'] === 1000000000;
    unlink($home.DIRECTORY_SEPARATOR.'link');
    rmdir($home);
    rmdir($target);

    // 3b. Does create() itself leave a cached lstat older than its own readdir?
    $staleAfterCreate = 0;
    $iterations = 300;
    for ($i = 0; $i < $iterations; $i++) {
        $t = PrivateDirectory::create('prism-acp-g383-loop-');
        $raw = lstat($t);
        $fresh = g383Fresh($t);
        if ($raw !== $fresh) {
            $staleAfterCreate++;
        }
        rmdir($t);
    }
    $report['create_iterations'] = $iterations;
    $report['create_cached_vs_fresh_mismatch'] = $staleAfterCreate;

    exec('findmnt -no TARGET,FSTYPE,OPTIONS -T '.escapeshellarg(sys_get_temp_dir()), $mount);
    $report['tmp_mount'] = implode(' ', $mount);

    echo 'G383 '.json_encode($report)."\n";
    expect(true)->toBeTrue();
});

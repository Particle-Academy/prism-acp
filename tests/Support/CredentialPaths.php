<?php

declare(strict_types=1);

namespace Tests\Support;

/** Fixture-only directory aliases; every caller supplies paths under its exact owned temporary root. */
final class CredentialPaths
{
    public static function link(string $target, string $alias): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            if (! symlink($target, $alias)) {
                throw new \RuntimeException('fixture symlink creation failed');
            }

            return;
        }
        $command = "New-Item -ItemType Junction -Path '".str_replace("'", "''", $alias)."' -Target '".str_replace("'", "''", $target)."' -ErrorAction Stop | Out-Null";
        $powershell = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $process = proc_open([$powershell, '-NoProfile', '-NonInteractive', '-Command', $command],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new \RuntimeException('fixture junction process did not start');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('fixture junction failed: '.$out.$err);
        }
    }

    public static function unlink(string $alias): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if (is_dir($alias)) {
                rmdir($alias);
            }
        } elseif (is_link($alias)) {
            unlink($alias);
        }
    }
}

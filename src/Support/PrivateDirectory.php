<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

use Prism\Acp\Env\ChildEnv;

/**
 * A temporary directory only this process's user can read, made BEFORE a credential is written into it. On Windows a
 * file mode means nothing, so inherited grants are removed and the current identity alone is granted access, as the
 * reference does with whoami and icacls. Any failure removes the directory and says so without naming a path.
 */
final class PrivateDirectory
{
    public static function create(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(6));
        if (! @mkdir($directory, 0700)) {
            throw new \RuntimeException('failed to create a private temporary directory');
        }
        try {
            self::protect($directory);
        } catch (\Throwable) {
            @rmdir($directory);
            throw new \RuntimeException('failed to protect a private temporary directory');
        }

        return $directory;
    }

    /** Restrict a newly-created host-chosen directory BEFORE the native CLI can write credentials into it. */
    public static function protect(string $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::restrictOnWindows($directory);
        } elseif (! @chmod($directory, 0700)) {
            throw new \RuntimeException('failed to protect a private directory');
        }
    }

    /** Remove the files named and then the directory. Returns false when something could not be removed. */
    public static function remove(string $directory, string ...$files): bool
    {
        $ok = true;
        foreach ($files as $file) {
            if (is_file($file) && ! @unlink($file)) {
                $ok = false;
            }
        }
        if (is_dir($directory) && ! @rmdir($directory)) {
            $ok = false;
        }

        return $ok;
    }

    private static function restrictOnWindows(string $directory): void
    {
        $system32 = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32';
        $env = ChildEnv::build(getenv())->env;
        $identity = self::run([$system32.'\\whoami.exe', '/user', '/fo', 'csv', '/nh'], $env);
        if (preg_match('/\bS-\d+(?:-\d+)+\b/', $identity, $sid) !== 1) {
            throw new \RuntimeException('cannot identify the Windows process identity');
        }
        self::run([$system32.'\\icacls.exe', $directory, '/inheritance:r', '/grant:r', "*{$sid[0]}:(OI)(CI)F"], $env);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private static function run(array $command, array $env): string
    {
        $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new \RuntimeException('could not run a system tool');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('a system tool failed');
        }

        return $out;
    }
}

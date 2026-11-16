<?php

declare(strict_types=1);

namespace Tests\Support;

use Prism\Acp\Process\PosixTree;

/** Ask the OS, not the supervisor, whether processes of a tree are still running. POSIX only. */
final class TreeProbe
{
    public static function supported(): bool
    {
        return (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'Darwin') && PosixTree::missing() === [];
    }

    public static function skipReason(): string
    {
        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            return 'POSIX process-group containment; this is '.PHP_OS_FAMILY;
        }

        return 'POSIX containment needs '.implode(', ', PosixTree::missing()).'; UNCHECKED here';
    }

    /** Running, not a zombie: a zombie runs nothing and is only waiting to be reaped. */
    public static function alive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $stat = self::read("/proc/{$pid}/stat");
            if (! is_string($stat)) {
                return false;
            }
            $state = substr($stat, (int) strrpos($stat, ')') + 2, 1);

            return $state !== 'Z' && $state !== 'X';
        }

        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }

    public static function startTime(int $pid): ?string
    {
        $stat = self::read("/proc/{$pid}/stat");
        if (! is_string($stat)) {
            return null;
        }

        return explode(' ', substr($stat, (int) strrpos($stat, ')') + 2))[19] ?? null;
    }

    public static function dir(): string
    {
        $dir = sys_get_temp_dir().'/prism-acp-ptree-'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    /** @return list<int> */
    public static function pids(string $file, int $count, float $seconds = 15.0): array
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            clearstatcache(true, $file);
            $pids = is_file($file) ? array_map('intval', array_values(array_filter(explode("\n", trim((string) file_get_contents($file)))))) : [];
            if (count($pids) >= $count) {
                return $pids;
            }
            usleep(50_000);
        }
        throw new \RuntimeException("timed out waiting for {$count} pids");
    }

    /**
     * @param  list<int>  $pids
     * @return list<int> the ones still running after up to $seconds
     */
    public static function survivors(array $pids, float $seconds = 5.0): array
    {
        $end = microtime(true) + $seconds;
        do {
            $alive = array_values(array_filter($pids, self::alive(...)));
            if ($alive === []) {
                return [];
            }
            usleep(100_000);
        } while (microtime(true) < $end);

        return $alive;
    }

    /** Wait until $pid has stopped running (or the deadline passes). */
    public static function waitGone(int $pid, float $seconds = 10.0): bool
    {
        $end = microtime(true) + $seconds;
        while (self::alive($pid) && microtime(true) < $end) {
            usleep(50_000);
        }

        return ! self::alive($pid);
    }

    /** @param list<int> $pids */
    public static function cleanup(array $pids): void
    {
        foreach ($pids as $pid) {
            if (self::alive($pid)) {
                @posix_kill($pid, 9);
            }
        }
    }

    private static function read(string $path): ?string
    {
        set_error_handler(static fn (): bool => true);
        try {
            $content = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        return is_string($content) ? $content : null;
    }
}

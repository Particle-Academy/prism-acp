<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * What to run on Linux or macOS, WITHOUT a shell: exec needs a path, so a bare name is looked up on the child's PATH.
 * As on Windows (WindowsCommand), the current directory is never searched -- an empty or relative PATH entry is
 * skipped -- so a workspace cannot plant a binary, and a binary given as a path must be absolute.
 */
final class PosixCommand
{
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }

    /** @param array<string, string> $env the child's environment, whose PATH is searched */
    public static function resolve(string $command, array $env): string
    {
        if ($command === '' || str_contains($command, "\0")) {
            throw new ProcessTreeException('the provider binary was not found on PATH');
        }
        if (str_contains($command, '/')) {
            if (! self::isAbsolute($command)) {
                throw new ProcessTreeException('a provider binary given as a path must be absolute');
            }
            if (! self::runnable($command)) {
                throw new ProcessTreeException('the provider binary was not found');
            }

            return $command;
        }
        foreach (explode(':', $env['PATH'] ?? '') as $directory) {
            if (! self::isAbsolute($directory)) {
                continue;
            }
            $candidate = rtrim($directory, '/').'/'.$command;
            if (self::runnable($candidate)) {
                return $candidate;
            }
        }
        throw new ProcessTreeException('the provider binary was not found on PATH');
    }

    private static function runnable(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path) && is_executable($path);
    }
}

<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * The Windows supervisor executable: built ONCE per install from resources/windows/supervisor.cs with the csc.exe that
 * ships with .NET Framework 4 (part of every supported Windows), its SHA-256 recorded at build and verified before
 * EVERY launch. A mismatch rebuilds once, then refuses. Every failure is a ProcessTreeException naming its cause; an
 * uncontained provider is never offered as a fallback.
 *
 * This package carries its own copy of the supervisor source. It is the same C# the TypeScript port was offered, but
 * the two packages never share files: each port is released on its own.
 */
final class WindowsSupervisorBuild
{
    private static ?string $directoryOverride = null;

    /** Where the supervisor is kept. Default: %LOCALAPPDATA%/prism-acp-php/supervisor-<source hash>. */
    public static function useDirectory(?string $directory): void
    {
        if ($directory !== null && ! WindowsCommand::isAbsolute($directory)) {
            throw new ProcessTreeException('the supervisor directory must be absolute');
        }
        self::$directoryOverride = $directory;
    }

    public static function sourcePath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'windows'.DIRECTORY_SEPARATOR.'supervisor.cs';
    }

    public static function directory(): string
    {
        if (self::$directoryOverride !== null) {
            return self::$directoryOverride;
        }
        $base = getenv('LOCALAPPDATA') ?: sys_get_temp_dir();
        $version = substr((string) hash_file('sha256', self::sourcePath()), 0, 16);

        return $base.'\\prism-acp-php\\supervisor-'.$version;
    }

    /** The verified supervisor executable, built first if it is missing or altered. */
    public static function executable(): string
    {
        $directory = self::directory();
        $state = self::verified($directory);
        if ($state['state'] === 'ok') {
            return $state['executable'];
        }

        $windows = getenv('SystemRoot') ?: (getenv('windir') ?: 'C:\\Windows');
        $csc = null;
        foreach (['Framework64', 'FrameworkArm64', 'Framework'] as $bits) {
            $candidate = $windows.'\\Microsoft.NET\\'.$bits.'\\v4.0.30319\\csc.exe';
            if (is_file($candidate)) {
                $csc = $candidate;
                break;
            }
        }
        if ($csc === null) {
            throw new ProcessTreeException('cannot contain the provider: the .NET Framework 4 compiler (csc.exe) was not found');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ProcessTreeException('cannot contain the provider: the supervisor directory could not be created');
        }
        $unique = bin2hex(random_bytes(6));
        $source = $directory.'\\supervisor-'.$unique.'.cs';
        $built = $directory.'\\prism-acp-supervisor-'.$unique.'.exe';
        try {
            if (! copy(self::sourcePath(), $source)) {
                throw new ProcessTreeException('cannot contain the provider: the supervisor source could not be written');
            }
            $compiler = proc_open([$csc, '-nologo', '-optimize+', '-target:exe', '-out:'.$built, $source],
                [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
            if (! is_resource($compiler) || proc_close($compiler) !== 0) {
                @unlink($built);
                throw new ProcessTreeException('cannot contain the provider: the supervisor failed to compile');
            }
        } finally {
            @unlink($source);
        }
        if (! is_file($built)) {
            throw new ProcessTreeException('cannot contain the provider: the supervisor was removed right after it was built (antivirus quarantine is the likely cause)');
        }
        $hash = (string) hash_file('sha256', $built);
        $executable = $directory.'\\prism-acp-supervisor-'.$hash.'.exe';
        $pending = $directory.'\\supervisor-'.$unique.'.sha256';
        $publicationFailed = false;
        try {
            // Publish a complete inode exclusively; never overwrite a generation another process selected.
            if (! @link($built, $executable) && (! is_file($executable) || ! hash_equals($hash, (string) hash_file('sha256', $executable)))) {
                throw new ProcessTreeException('supervisor generation publication failed');
            }
            if (! hash_equals($hash, (string) hash_file('sha256', $executable))) {
                throw new ProcessTreeException('supervisor generation verification failed');
            }
            $pointer = @fopen($pending, 'x');
            if ($pointer === false) {
                throw new ProcessTreeException('supervisor pointer creation failed');
            }
            try {
                if (fwrite($pointer, $hash) !== strlen($hash)) {
                    throw new ProcessTreeException('supervisor pointer write failed');
                }
            } finally {
                fclose($pointer);
            }
            if (! @rename($pending, $directory.'\\prism-acp-supervisor.current.sha256')) {
                throw new ProcessTreeException('supervisor pointer publication failed');
            }
        } catch (\Throwable) {
            $publicationFailed = true; // A competing publisher is accepted only after independently verifying it.
        } finally {
            @unlink($built);
            if (is_file($pending)) {
                @unlink($pending);
            }
        }
        $after = self::verified($directory);
        if ($after['state'] === 'ok') {
            return $after['executable'];
        }
        if ($publicationFailed) {
            throw new ProcessTreeException('cannot contain the provider: the supervisor generation or atomic pointer could not be published; the cache filesystem must support hard links and atomic replacement');
        }
        throw new ProcessTreeException($after['state'] === 'mismatch'
            ? 'cannot contain the provider: the supervisor does not match its recorded hash, and could not be replaced'
            : 'cannot contain the provider: the supervisor is missing after it was built');
    }

    /** @return array{state: 'ok', executable: string}|array{state: 'missing'|'mismatch'} */
    private static function verified(string $directory): array
    {
        $pointer = $directory.'\\prism-acp-supervisor.current.sha256';
        clearstatcache(true, $pointer);
        if (! is_file($pointer)) {
            return ['state' => 'missing'];
        }
        $size = @filesize($pointer);
        $recorded = trim((string) @file_get_contents($pointer, false, null, 0, 81));
        if ($size === false || $size > 80 || preg_match('/\A[0-9a-f]{64}\z/', $recorded) !== 1) {
            return ['state' => 'mismatch'];
        }
        $executable = $directory.'\\prism-acp-supervisor-'.$recorded.'.exe';
        clearstatcache(true, $executable);
        if (! is_file($executable)) {
            return ['state' => 'missing'];
        }

        return hash_equals($recorded, (string) hash_file('sha256', $executable))
            ? ['state' => 'ok', 'executable' => $executable] : ['state' => 'mismatch'];
    }
}

<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * What to run on Windows, and how to write its command line, WITHOUT a shell. Ported from prism-acp-ts
 * src/process-tree.ts, rule for rule:
 *
 * - The binary is found on PATH with PATHEXT, the way CreateProcess callers do, but the CURRENT DIRECTORY IS NEVER
 *   SEARCHED (cmd.exe searches it first, so a workspace could plant a "claude.exe").
 * - A relative path is refused. An .exe or .com runs as itself.
 * - A .cmd or .bat runs only if it is an npm shim, as node + its script. Anything else would need cmd.exe, which
 *   interprets its arguments, so it is refused rather than run unquoted.
 * - The command line is quoted so the C runtime and CommandLineToArgvW read back exactly the argv given, with
 *   backslashes literal except before a quote. Shell metacharacters (& | % ^ > $) are plain text: no shell ever reads it.
 */
final class WindowsCommand
{
    private const NPM_SHIM = '/"%_prog%"\s+"%dp0%\\\\([^"%\r\n]+)"\s+%\*\s*$/m';

    /**
     * @param  list<string>  $argv
     */
    public static function commandLine(array $argv): string
    {
        $quoted = [];
        foreach ($argv as $arg) {
            if ($arg !== '' && preg_match('/[\s"]/u', $arg) !== 1) {
                $quoted[] = $arg;

                continue;
            }
            $out = '"';
            $backslashes = 0;
            // Bytes, not characters: a UTF-8 continuation byte is never a backslash or a quote.
            foreach (str_split($arg) as $ch) {
                if ($ch === '\\') {
                    $backslashes++;

                    continue;
                }
                $out .= $ch === '"' ? str_repeat('\\', $backslashes * 2 + 1).'"' : str_repeat('\\', $backslashes).$ch;
                $backslashes = 0;
            }
            $quoted[] = $out.str_repeat('\\', $backslashes * 2).'"';
        }

        return implode(' ', $quoted);
    }

    /**
     * Resolve $command against PATH, as CreateProcess callers do, never the current directory.
     *
     * @param  array<string, string>  $env  the child's environment; PATH and PATHEXT are read from it, case-insensitively
     * @return array{application: string, args: list<string>}
     */
    public static function resolve(string $command, array $env): array
    {
        $extensions = array_values(array_filter(array_map(
            'strtolower', explode(';', self::lookup($env, 'pathext') ?? '.COM;.EXE;.BAT;.CMD'),
        ), fn (string $e) => $e !== ''));
        $hasPath = preg_match('#[\\\\/:]#', $command) === 1;
        if ($hasPath && ! self::isAbsolute($command)) {
            throw new ProcessTreeException('a provider binary given as a path must be absolute');
        }
        $directories = $hasPath ? [''] : array_values(array_filter(
            array_map(fn (string $d) => (string) preg_replace('/^"(.*)"$/', '$1', $d), explode(';', self::lookup($env, 'path') ?? '')),
            fn (string $d) => $d !== '' && self::isAbsolute($d),
        ));
        $own = strtolower(pathinfo($command, PATHINFO_EXTENSION));
        $names = $own !== '' && in_array('.'.$own, $extensions, true) ? [$command] : array_map(fn (string $e) => $command.$e, $extensions);

        foreach ($directories as $directory) {
            foreach ($names as $name) {
                $candidate = $directory === '' ? $name : rtrim($directory, '\\/').'\\'.$name;
                if (! is_file($candidate)) {
                    continue;
                }
                $extension = '.'.strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                if ($extension === '.exe' || $extension === '.com') {
                    return ['application' => $candidate, 'args' => []];
                }
                if ($extension === '.cmd' || $extension === '.bat') {
                    return self::npmShim($candidate, $env);
                }
                throw new ProcessTreeException("the provider resolves to a {$extension} file, which cannot be run without a shell");
            }
        }
        throw new ProcessTreeException('the provider binary was not found on PATH');
    }

    /**
     * @param  array<string, string>  $env
     * @return array{application: string, args: list<string>}
     */
    private static function npmShim(string $shim, array $env): array
    {
        $size = filesize($shim);
        $text = $size !== false && $size <= 65_536 ? (string) file_get_contents($shim) : '';
        if (preg_match(self::NPM_SHIM, $text, $match) !== 1) {
            throw new ProcessTreeException('the provider is a batch file that is not an npm shim; give the path of its .exe instead');
        }
        $dir = dirname($shim);
        $script = $dir.'\\'.$match[1];
        if (! is_file($script)) {
            throw new ProcessTreeException('the npm shim names a script that does not exist');
        }
        $local = $dir.'\\node.exe';
        if (is_file($local)) {
            return ['application' => $local, 'args' => [$script]];
        }

        return ['application' => self::resolve('node.exe', $env)['application'], 'args' => [$script]];
    }

    /** @param array<string, string> $env */
    private static function lookup(array $env, string $name): ?string
    {
        foreach ($env as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return $value;
            }
        }

        return null;
    }

    public static function isAbsolute(string $path): bool
    {
        return preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/]{2}[^\\\\/])#', $path) === 1;
    }
}

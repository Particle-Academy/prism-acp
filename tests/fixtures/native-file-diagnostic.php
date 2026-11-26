<?php

declare(strict_types=1);

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Support\PrivateDirectory;

function ownedNativeFileDiagnosticExecutable(): string
{
    static $executable = null;
    if ($executable !== null) {
        return $executable;
    }
    $root = PrivateDirectory::create('prism-acp-native-query-');
    $windows = getenv('SystemRoot') ?: 'C:\\Windows';
    $compiler = null;
    foreach (['Framework64', 'FrameworkArm64', 'Framework'] as $bits) {
        $candidate = $windows.'\\Microsoft.NET\\'.$bits.'\\v4.0.30319\\csc.exe';
        if (is_file($candidate)) {
            $compiler = $candidate;
            break;
        }
    }
    if ($compiler === null) {
        throw new RuntimeException('owned metadata compiler unavailable');
    }
    $executable = $root.DIRECTORY_SEPARATOR.'owned-file-diagnostic.exe';
    $source = realpath(__DIR__.'/native-file-diagnostic.cs');
    $process = proc_open([$compiler, '-nologo', '-target:exe', '-r:System.Web.Extensions.dll', '-out:'.$executable, $source],
        [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        env_vars: ChildEnv::build(getenv(), [], ['HOME' => $root, 'USERPROFILE' => $root])->env, options: ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('owned metadata compiler failed to start');
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || ! is_file($executable)) {
        throw new RuntimeException('owned metadata compilation failed: '.$output);
    }
    register_shutdown_function(function () use ($root, $executable): void {
        foreach ([$root.'/AppData/Roaming', $root.'/AppData'] as $empty) {
            if (is_dir($empty) && (scandir($empty) !== ['.', '..'] || ! rmdir($empty))) {
                throw new RuntimeException('owned compiler profile cleanup failed');
            }
        }
        if (! PrivateDirectory::remove($root, $executable)) {
            throw new RuntimeException('owned metadata executable cleanup failed');
        }
    });

    return $executable;
}

/**
 * Wait for the hold-empty-control holder's one ready line, and no longer than that: the wait ends as soon as a full
 * line arrives, or the holder exits, or $bound passes. When there is no ready line, the failure says which of those it
 * was, with the holder's exit code, its stdout and its stderr (G-384: CI once got only `json_decode('')`'s "Syntax
 * error", which could not tell a slow start from a refusal or a crash).
 *
 * @return array<string, mixed> the decoded ready line, plus `readySeconds` measured from this call
 */
function awaitOwnedHolderReady(WindowsProviderProcess $holder, float $bound): array
{
    $out = $err = '';
    $exit = null;
    $started = microtime(true);
    $deadline = $started + $bound;
    while (! str_contains($out, "\n") && $holder->isOpen() && microtime(true) < $deadline) {
        foreach ($holder->read(min(0.02, max(0.0, $deadline - microtime(true)))) as [$kind, $value]) {
            match ($kind) {
                'stdout' => $out .= $value,
                'stderr' => $err .= $value,
                'exited' => $exit = $value,
                default => null,
            };
        }
    }
    $elapsed = microtime(true) - $started;
    $line = strstr($out, "\n", true);
    $ready = $line === false ? null : json_decode(trim($line), true);
    if (is_array($ready) && ($ready['holderReady'] ?? null) === true) {
        return [...$ready, 'readySeconds' => $elapsed];
    }
    // Read what is still on its way (the exit code and stderr trail a refusal by a moment), within a short bound.
    $drain = microtime(true) + 1.0;
    while ($holder->isOpen() && microtime(true) < $drain) {
        foreach ($holder->read(0.02) as [$kind, $value]) {
            match ($kind) {
                'stdout' => $out .= $value,
                'stderr' => $err .= $value,
                'exited' => $exit = $value,
                default => null,
            };
        }
    }
    $exit ??= $holder->exitCode();
    $state = $exit !== null ? "exited with code {$exit}" : ($holder->isOpen() ? 'still running' : 'closed without an exit code');
    throw new RuntimeException(sprintf('the file holder printed no ready line after %.3f s of a %.1f s bound (%s); stdout: %s; stderr: %s',
        $elapsed, $bound, $state, json_encode(substr($out, 0, 2048)), substr(trim($err), 0, 2048)));
}

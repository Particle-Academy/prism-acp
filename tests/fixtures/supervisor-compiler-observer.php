<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

// Loaded only in the owned cold-cache worker. Forward the real compiler unchanged and observe its actual output
// before the immutable publisher continues. No production publisher or supervisor body is instrumented.
$GLOBALS['prismColdCompilerProcesses'] = [];
$GLOBALS['prismColdCompilerDigests'] = [];

function proc_open(array|string $command, array $descriptor_spec, mixed &$pipes, ?string $cwd = null, ?array $env_vars = null, ?array $options = null): mixed
{
    $process = \proc_open($command, $descriptor_spec, $pipes, $cwd, $env_vars, $options ?? []);
    if (is_resource($process) && is_array($command) && strtolower(basename($command[0])) === 'csc.exe') {
        foreach ($command as $argument) {
            if (str_starts_with($argument, '-out:')) {
                $GLOBALS['prismColdCompilerProcesses'][(int) $process] = substr($argument, 5);
            }
        }
    }

    return $process;
}

function proc_close(mixed $process): int
{
    $key = (int) $process;
    $path = $GLOBALS['prismColdCompilerProcesses'][$key] ?? null;
    $status = \proc_close($process);
    if ($path !== null && $status === 0 && is_file($path)) {
        $GLOBALS['prismColdCompilerDigests'][] = hash_file('sha256', $path);
    }
    unset($GLOBALS['prismColdCompilerProcesses'][$key]);

    return $status;
}

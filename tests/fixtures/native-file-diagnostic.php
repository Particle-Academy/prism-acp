<?php

declare(strict_types=1);

use Prism\Acp\Env\ChildEnv;
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

<?php

declare(strict_types=1);

// The publisher class is byte-for-byte old/current source in an owned package copy; only C# metadata varies.
require __DIR__.'/supervisor-compiler-observer.php';
require $argv[5].'/src/Process/WindowsSupervisorBuild.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Process\WindowsSupervisorBuild;

[$cache, $go, $ready, $directory] = array_slice($argv, 1);
if (! is_callable([WindowsSupervisorBuild::class, 'executable']) || ! is_callable([WindowsProviderProcess::class, 'launch'])) {
    throw new RuntimeException('cold worker API preflight failed');
}
WindowsSupervisorBuild::useDirectory($cache);
file_put_contents($ready, 'ready');
$barrier = microtime(true) + 30;
while (! is_file($go)) {
    clearstatcache(true, $go);
    if (microtime(true) > $barrier) {
        throw new RuntimeException('owned cold worker barrier deadline');
    }
    usleep(5000);
}
$provider = null;
$exit = 0;
try {
    $adapter = WindowsSupervisorBuild::executable();
    $digest = hash_file('sha256', $adapter);
    $recorded = preg_match('/prism-acp-supervisor-([0-9a-f]{64})\.exe$/', $adapter, $match) === 1
        ? $match[1] : trim((string) file_get_contents($adapter.'.sha256')); // Immutable old-source counterexample only.
    if ($digest !== $recorded) {
        throw new RuntimeException('adapter bytes did not match their complete generation receipt');
    }
    $outcomes = [];
    foreach (['allow' => 42, 'silent' => 0] as $name => $code) {
        $script = $directory.DIRECTORY_SEPARATOR.$name.'-'.getmypid().'.php';
        file_put_contents($script, '<?php '.($code === 42 ? 'echo \'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow","permissionDecisionReason":"owned"}}\';' : '').'exit('.$code.');');
        $process = proc_open([$adapter, 'approval', PHP_BINARY, $script, '2'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('owned approval adapter did not start');
        }
        fwrite($pipes[0], '{}');
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $outcomes[$name] = proc_close($process);
    }
    if ($outcomes !== ['allow' => 0, 'silent' => 2]) {
        throw new RuntimeException('native approval adapter outcomes were incorrect');
    }
    $provider = WindowsProviderProcess::launch(PHP_BINARY, ['-n', '-r', 'usleep(5000000);'], $directory, ChildEnv::build(getenv())->env);
    usleep(200000);
    $stop = $provider->stop(0);
    if (! $stop->verified || ! $stop->empty || $stop->survivors !== 0) {
        throw new RuntimeException('inner provider was not verified empty');
    }
    echo json_encode(['success' => true, 'approval' => $outcomes, 'hashVerified' => true, 'compiledDigests' => $GLOBALS['prismColdCompilerDigests'], 'generationDigest' => $digest, 'stop' => $stop->toArray()])."\n";
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'name' => $error::class, 'message' => $error->getMessage(), 'compiledDigests' => $GLOBALS['prismColdCompilerDigests']])."\n";
    $exit = 1;
} finally {
    $provider?->stop(0);
}
exit($exit);

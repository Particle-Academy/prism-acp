<?php

declare(strict_types=1);

// Inspect the installed source without Composer/development dependencies or a provider/model launch.
spl_autoload_register(function (string $class): void {
    $prefix = 'Prism\\Acp\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\Approvals;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Meta;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\WindowsSupervisorBuild;

function publicMethod(string $class, string $method): bool
{
    return method_exists($class, $method) && (new ReflectionMethod($class, $method))->isPublic();
}

// Surface additions force a review even if their implementation is still partial; this is not conformance proof.
$nativeTools = property_exists(CodexDriverOptions::class, 'nativeTools');
$startupFlags = false;
if ($nativeTools) {
    $loop = new Loop;
    $captured = [];
    $options = new CodexDriverOptions(...['cwd' => sys_get_temp_dir(), 'parentEnv' => [], 'model' => 'gpt-5.3-codex', 'nativeTools' => 'none']);
    $driver = new CodexDriver($options, new DriverEvents, function (CodexTransportOptions $options) use (&$captured): never {
        $captured = $options->binaryArgs;
        throw new RuntimeException('owned inventory interception before transport/provider startup');
    }, $loop);
    try {
        $driver->start();
        $loop->drain();
        $startupFlags = in_array('features.plugins=false', $captured, true) && in_array('features.remote_plugin=false', $captured, true);
    } finally {
        $driver->stop(0)->await();
    }
}

// Exercise the real cache reader with owned non-executable bytes; never launch those bytes.
$generations = false;
$reader = new ReflectionMethod(WindowsSupervisorBuild::class, 'verified');
if ($reader->getNumberOfParameters() === 1) {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-reference-cache-'.bin2hex(random_bytes(8));
    if (! mkdir($root, 0700)) {
        throw new RuntimeException('owned inventory cache could not be created');
    }
    $directory = $root.DIRECTORY_SEPARATOR.'cache';
    mkdir($directory, 0700);
    $bytes = 'Owned non-executable cache oracle';
    $hash = hash('sha256', $bytes);
    $executable = $directory.'\\prism-acp-supervisor-'.$hash.'.exe';
    $pointer = $directory.'\\prism-acp-supervisor.current.sha256';
    try {
        file_put_contents($executable, $bytes);
        file_put_contents($pointer, $hash);
        $valid = $reader->invoke(null, $directory);
        file_put_contents($executable, 'altered', FILE_APPEND);
        $altered = $reader->invoke(null, $directory);
        $generations = $valid === ['state' => 'ok', 'executable' => $executable] && $altered === ['state' => 'mismatch'];
    } finally {
        foreach ([$pointer, $executable] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
        rmdir($root);
    }
}

$providerParameters = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(Provider::class, 'launch'))->getParameters());
$guard = publicMethod(Approvals::class, 'cliVersion') && Approvals::cliVersion('2.1.294') === null && Approvals::cliVersion('2.1.295') === '2.1.295' && Approvals::cliVersion(null) === null;
echo json_encode([
    'readRateLimits' => publicMethod(CodexDriver::class, 'readRateLimits'),
    'mcpServerStatus' => publicMethod(CodexDriver::class, 'mcpServerStatus') || publicMethod(ClaudeDriver::class, 'mcpServerStatus'),
    'appContainerLocal' => property_exists(CodexDriverOptions::class, 'processTree') || in_array('processTree', $providerParameters, true) || in_array('options', $providerParameters, true),
    'nativeTools' => $nativeTools,
    'approvalGuard' => $guard,
    'replayMarkersAndOptOut' => property_exists(CodexDriverOptions::class, 'replayHistory') || in_array('particle.academy/replay', (new ReflectionClass(Meta::class))->getConstants(), true),
    'restrictedStartupFlags' => $startupFlags,
    'supervisorGenerations' => $generations,
], JSON_THROW_ON_ERROR)."\n";

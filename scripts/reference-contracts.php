<?php

declare(strict_types=1);

require_once __DIR__.'/reference-framing-probe.php';

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

use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\Approvals;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Meta;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\ReferenceFramingProbe;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\WindowsSupervisorBuild;
use Prism\Acp\Version;

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

/** Exercise actual initialize dispatch; this must never construct a provider. */
function initializedInfo(?array $override = null): object
{
    $loop = new Loop;
    $sent = [];
    $peer = new JsonRpcPeer(function (mixed $frame) use (&$sent): void {
        $sent[] = $frame;
    }, loop: $loop);
    new AcpAgent($peer, static function (): never {
        throw new RuntimeException('Inventory initialize unexpectedly requested a provider');
    }, agentInfo: $override, loop: $loop);
    $peer->receive(Json::decode('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":1}}'));
    $loop->drain();
    if (count($sent) !== 1) {
        throw new RuntimeException('Inventory initialize did not respond exactly once');
    }
    $response = Json::decode(Json::encode($sent[0]));
    if (! isset($response->result->agentInfo)) {
        throw new RuntimeException('Inventory initialize did not return agent info');
    }

    return $response->result->agentInfo;
}

$defaultInfo = initializedInfo();
$overrideInfo = initializedInfo(['name' => 'owned-inventory', 'version' => 'owned-override']);
$framing = ReferenceFramingProbe::observe();
echo json_encode([
    'readRateLimits' => publicMethod(CodexDriver::class, 'readRateLimits'),
    'mcpServerStatus' => publicMethod(CodexDriver::class, 'mcpServerStatus') || publicMethod(ClaudeDriver::class, 'mcpServerStatus'),
    'appContainerLocal' => property_exists(CodexDriverOptions::class, 'processTree') || in_array('processTree', $providerParameters, true) || in_array('options', $providerParameters, true),
    'nativeTools' => $nativeTools,
    'approvalGuard' => $guard,
    'replayMarkersAndOptOut' => property_exists(CodexDriverOptions::class, 'replayHistory') || in_array('particle.academy/replay', (new ReflectionClass(Meta::class))->getConstants(), true),
    'restrictedStartupFlags' => $startupFlags,
    'supervisorGenerations' => $generations,
    'claudeAppMcpOnly' => property_exists(ClaudeDriverOptions::class, 'nativeTools'),
    'claudeSettingSources' => property_exists(ClaudeDriverOptions::class, 'settingSources'),
    'claudeStrictMcpConfig' => property_exists(ClaudeDriverOptions::class, 'strictMcpConfig'),
    'claudeAppendSystemPromptFile' => property_exists(ClaudeDriverOptions::class, 'appendSystemPromptFile'),
    'claudePluginDirs' => property_exists(ClaudeDriverOptions::class, 'pluginDirs'),
    'agentVersion' => $defaultInfo->version === Version::VERSION && $overrideInfo->version === 'owned-override',
    'codexThreadConfig' => property_exists(CodexDriverOptions::class, 'config'),
    'ndjsonIncrementalSearch' => $framing['incremental'],
    'claudeQueuedCancelAccounting' => class_exists('Prism\\Acp\\Claude\\ClaudeCancelStateError'),
], JSON_THROW_ON_ERROR)."\n";

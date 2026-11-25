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
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\Approvals;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Claude\ClaudeRateLimit;
use Prism\Acp\Claude\ClaudeToAcp;
use Prism\Acp\Claude\ClaudeTurnFailures;
use Prism\Acp\Claude\RemoteControlRefused;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexRateLimit;
use Prism\Acp\Codex\CodexRemoteControl;
use Prism\Acp\Codex\CodexTransport;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Meta;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\ReferenceFramingProbe;
use Prism\Acp\Plan\PlanProjection;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\StopVerdict;
use Prism\Acp\Process\WindowsSupervisorBuild;
use Prism\Acp\TurnFailure;
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

/**
 * Feed a Codex RPC response whose id matches no request, carrying an owned sentinel, through the real driver frame
 * path, and stop startup before any provider. The reference (0.38.0) reports such a reply without echoing it; present
 * only when the gap is reported AND the sentinel never reaches an ACP update. No update at all is unmeasured.
 */
function uncorrelatedResponseRedacted(): bool
{
    $loop = new Loop;
    $updates = [];
    $sentinel = 'owned-uncorrelated-sentinel-'.bin2hex(random_bytes(8));
    $events = new DriverEvents(onUpdate: function (array $update) use (&$updates): void {
        $updates[] = Json::encode($update);
    });
    $factory = function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use ($loop, $sentinel): CodexTransport {
        return new class($handlers, $loop, $sentinel) implements CodexTransport
        {
            public function __construct(private readonly CodexTransportHandlers $handlers, private readonly Loop $loop, private readonly string $sentinel) {}

            public function start(): Deferred
            {
                ($this->handlers->onFrame)('{"jsonrpc":"2.0","id":987654321,"result":{"echo":"'.$this->sentinel.'"}}');
                $done = new Deferred($this->loop);
                $done->reject(new RuntimeException('owned inventory interception before provider startup'));

                return $done;
            }

            public function send(mixed $frame): void {}

            public function close(): void {}

            public function stop(int $graceMs = 5000): Deferred
            {
                $done = new Deferred($this->loop);
                $done->resolve(new StopVerdict(true, true, 0, true));

                return $done;
            }

            public function processTree(): ?ProcessTree
            {
                return null;
            }
        };
    };
    $driver = new CodexDriver(new CodexDriverOptions(cwd: sys_get_temp_dir(), parentEnv: []), $events, $factory, $loop);
    try {
        $driver->start();
        $loop->drain();
    } finally {
        $driver->stop(0)->await();
    }
    if ($updates === []) {
        throw new RuntimeException('Inventory uncorrelated-response probe observed no update');
    }
    foreach ($updates as $update) {
        if (str_contains($update, $sentinel)) {
            return false;
        }
    }

    return true;
}

/** Exercise the real argv builder: rule boundaries kept as separate values, option-shaped/control rules refused. */
function claudeToolRulesHardened(): bool
{
    $args = ClaudeDriver::args(new ClaudeDriverOptions(cwd: sys_get_temp_dir(), allowedTools: ['Bash(echo a, b)', 'Read']));
    $at = array_search('--allowed-tools', $args, true);
    if ($at === false || ($args[$at + 1] ?? null) !== 'Bash(echo a, b)' || ($args[$at + 2] ?? null) !== 'Read') {
        return false;
    }
    foreach ([['allowedTools' => ['--permission-mode']], ['allowedTools' => ["\u{00A0}-p"]], ['disallowedTools' => ["Read\nBash"]], ['disallowedTools' => ['']]] as $hostile) {
        try {
            ClaudeDriver::args(new ClaudeDriverOptions(...['cwd' => sys_get_temp_dir(), ...$hostile]));

            return false;
        } catch (InvalidArgumentException $refused) {
            if (get_class($refused) !== 'Prism\\Acp\\Claude\\ClaudeToolRuleError') {
                return false;
            }
        }
    }

    return true;
}

/** The reference ships pinned supervisor images and never invokes a compiler at runtime (0.41.0). */
function invokesRuntimeCompiler(): bool
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'csc.exe')) {
            return true;
        }
    }

    return false;
}

/** Whether any source file names a contract's own identifier: conservative, so a partial port still forces a review. */
function sourceMentions(string ...$needles): bool
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }
    }

    return false;
}

/** Map a real streamed reply through the Claude mapper: present only when every text chunk carries a message id (0.43.0). */
function claudeChunksCarryMessageId(): bool
{
    $mapper = new ClaudeToAcp;
    $updates = [];
    foreach ([
        '{"type":"stream_event","parent_tool_use_id":null,"event":{"type":"message_start","message":{"id":"msg_owned_inventory"}}}',
        '{"type":"stream_event","parent_tool_use_id":null,"event":{"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}}',
        '{"type":"stream_event","parent_tool_use_id":null,"event":{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"owned"}}}',
    ] as $line) {
        array_push($updates, ...$mapper->frame(Json::decode($line)));
    }
    $chunks = array_filter($updates, fn (array $update): bool => ($update['sessionUpdate'] ?? null) === 'agent_message_chunk');
    if ($chunks === []) {
        throw new RuntimeException('Inventory message-id probe observed no agent_message_chunk');
    }
    foreach ($chunks as $chunk) {
        if (! is_string($chunk['messageId'] ?? null) || $chunk['messageId'] === '') {
            return false;
        }
    }

    return true;
}

/** The child-environment credential list, compared by digest with the names reviewed at 0.45.0. */
function childEnvCredentialListCurrent(): bool
{
    $reviewed = [
        '00f8034c10c47732ae132d92673dfd09f170c1131bfa0794ff8f67bff4d78e07',
        '6a8710903233ff5d24f946a09d53941ae2922efc2a848a4610663cf68e60ee5d',
        '9625ac9a688c2752d8f8652ee70281adb79b32ae23b3f13dc38645e7f9d61e06',
        'ddf482682d55f49ff56ec999a991bbedce7ce81ab7714a690589f3ff42656284',
        '527c75332cc7453ecac9f4fddd55801b5601c3835e17923eec4f521491d9a94f',
    ];
    $listed = array_map(fn (string $name): string => hash('sha256', $name), ChildEnv::OUTRANKING_CREDENTIALS);

    return array_diff($reviewed, $listed) === [];
}

function claudeLaunchSettingsForced(): bool
{
    $args = ClaudeDriver::args(new ClaudeDriverOptions(cwd: sys_get_temp_dir()));
    $at = array_search('--settings', $args, true);
    if ($at === false || json_decode((string) ($args[$at + 1] ?? ''), true) !== ['disableRemoteControl' => true]) {
        return false;
    }
    try {
        new ClaudeDriver(new ClaudeDriverOptions(cwd: sys_get_temp_dir(), binaryArgs: [' --SETTINGS=x']));
    } catch (InvalidArgumentException) {
        try {
            new CodexDriver(new CodexDriverOptions(cwd: sys_get_temp_dir(), binaryArgs: [' DAEMON']));
        } catch (InvalidArgumentException) {
            try {
                CodexRemoteControl::checkedFrame(['method' => ' REMOTECONTROL/ENABLE']);
            } catch (InvalidArgumentException) {
                return true;
            }
        }
    }

    return false;
}

/** Claude Code 2.1.332's end of a 529 (0.48.0): `server_error` with the status and API error type beside it. */
function claudeOverloadedKind(): bool
{
    $failures = new ClaudeTurnFailures;
    $failures->observe(Json::decode('{"type":"assistant","parent_tool_use_id":null,"error":"server_error","api_error_status":529,"api_error_type":"overloaded_error"}'));

    return $failures->take()?->kind === 'overloaded';
}

/** codex-cli 0.163.0's HTTP-carrying failure variants (0.48.0): 529 is overloaded, 429 is a rate limit. */
function codexStatusKind(): bool
{
    $overloaded = TurnFailure::fromCodex(Json::decode('{"codexErrorInfo":{"httpConnectionFailed":{"httpStatusCode":529}}}'));
    $limited = TurnFailure::fromCodex(Json::decode('{"codexErrorInfo":{"responseTooManyFailedAttempts":{"httpStatusCode":429}}}'));

    return $overloaded?->kind === 'overloaded' && $limited?->kind === 'rate_limit';
}

/** The wire form of a failure keeps only documented values (0.48.0): an unknown kind is `other`, a reset is a JS date. */
function turnFailureWireBounded(): bool
{
    $wire = Json::decode(Json::encode(new TurnFailure('owned_inventory', 'owned-unknown-kind', 8_640_000_000_001_000)));

    return $wire->kind === 'other' && ! property_exists($wire, 'resetsAtMs');
}

/** A Codex reading with only a secondary window, and one whose secondary is the more spent (0.48.0). */
function codexRateLimitWindowsRead(): bool
{
    $secondaryOnly = CodexRateLimit::read(Json::decode('{"rateLimits":{"secondary":{"usedPercent":91,"windowDurationMins":10080,"resetsAt":1800000000}}}'));
    $both = CodexRateLimit::read(Json::decode('{"rateLimits":{"primary":{"usedPercent":7,"windowDurationMins":300,"resetsAt":1800000000},"secondary":{"usedPercent":92,"windowDurationMins":10080,"resetsAt":1800000000}}}'));

    return $secondaryOnly instanceof CodexRateLimit && str_contains($secondaryOnly->notice(), '91%')
        && $both instanceof CodexRateLimit && str_contains($both->notice(), '92%');
}

/** A Claude rejection that carries no unifiedWindows is still a reading (0.48.0). */
function claudeRejectionRead(): bool
{
    return ClaudeRateLimit::read(Json::decode('{"status":"rejected","rateLimitType":"five_hour","resetsAt":1800000000}')) instanceof ClaudeRateLimit;
}

/** The provider's window name is bounded and marked before it reaches the notice (0.48.0). */
function claudeNoticeWindowNameBounded(): bool
{
    $name = 'owned'.chr(27).'[31m'.str_repeat('w', 200);
    $read = ClaudeRateLimit::read(Json::decode(Json::encode(['status' => 'allowed', 'rateLimitType' => $name, 'resetsAt' => 1800000000, 'unifiedWindows' => new stdClass])));
    if (! $read instanceof ClaudeRateLimit) {
        throw new RuntimeException('Inventory window-name probe was not read');
    }
    $notice = $read->notice();

    return ! str_contains($notice, chr(27)) && ! str_contains($notice, str_repeat('w', 200));
}

/** Pass one update through AcpAgent's real plan gate, for a client that advertised plan updates. */
function planGateOf(array $update): ?array
{
    $loop = new Loop;
    $peer = new JsonRpcPeer(static function (): void {}, loop: $loop);
    $agent = new AcpAgent($peer, static function (): never {
        throw new RuntimeException('Inventory plan gate unexpectedly requested a provider');
    }, loop: $loop);
    (new ReflectionProperty(AcpAgent::class, 'clientAcceptsPlan'))->setValue($agent, true);

    return (new ReflectionMethod(AcpAgent::class, 'planGate'))->invoke($agent, 'owned-inventory', $update);
}

function planGateSends(array $update): bool
{
    return planGateOf($update) !== null;
}

/** 0.48.0 caps: more than 256 entries, or an entry over 1024 code points, refuses the whole update. */
function planGateCapped(): bool
{
    $entry = ['content' => 'owned', 'priority' => 'medium', 'status' => 'pending'];
    if (! planGateSends(['sessionUpdate' => 'plan', 'entries' => [$entry]])) {
        throw new RuntimeException('Inventory plan gate refused an ordinary plan');
    }

    return ! planGateSends(['sessionUpdate' => 'plan', 'entries' => array_fill(0, 257, $entry)])
        && ! planGateSends(['sessionUpdate' => 'plan', 'entries' => [[...$entry, 'content' => str_repeat('a', 1025)]]]);
}

/** 0.48.0 refusals: a planId holding a hidden character, and markdown content holding a control character. */
function planGateRefusesHidden(): bool
{
    return ! planGateSends(['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'owned'.mb_chr(0x202E, 'UTF-8').'plan', 'entries' => []]])
        && ! planGateSends(['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'markdown', 'planId' => 'owned-plan', 'content' => 'owned'.chr(7)]]);
}

/** G-342 (0.48.0): a list frame and a JsonSerializable remoteControl frame are refused; an ordinary frame passes. */
function codexListFrameRefused(): bool
{
    if (CodexRemoteControl::encodedFrame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'thread/start']) === '') {
        return false;
    }
    foreach ([
        [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'thread/start']],
        new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'remoteControl/enable'];
            }
        },
    ] as $frame) {
        try {
            CodexRemoteControl::encodedFrame($frame);

            return false;
        } catch (RemoteControlRefused) {
        }
    }

    return true;
}

/** G-362 (0.48.0): a projected entry carries priority, and the gate gives a driver's entry without one `medium`. */
function planEntryPriorityPresent(): bool
{
    return PlanProjection::entry(['id' => 'owned-step', 'title' => 'Owned', 'state' => 'todo'])['priority'] === 'medium'
        && (planGateOf(['sessionUpdate' => 'plan', 'entries' => [['content' => 'owned', 'status' => 'pending']]])['entries'][0]['priority'] ?? null) === 'medium';
}

$metaConstants = (new ReflectionClass(Meta::class))->getConstants();
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
    'codexSteer' => publicMethod(CodexDriver::class, 'steer'),
    'rpcRequestDeadline' => (new ReflectionMethod(JsonRpcPeer::class, 'request'))->getNumberOfParameters() > 2 || class_exists('Prism\\Acp\\JsonRpc\\RpcTimeoutError'),
    'codexUncorrelatedResponseRedaction' => uncorrelatedResponseRedacted(),
    'codexCommands' => publicMethod(CodexDriver::class, 'runCommand'),
    'codexToolNames' => in_array('particle.academy/native_item_type', $metaConstants, true),
    'fileEdits' => in_array('particle.academy/file_edits', $metaConstants, true),
    'stopVerdictObserver' => property_exists(DriverEvents::class, 'onStopVerdict'),
    'windowsShippedSupervisor' => ! invokesRuntimeCompiler(),
    'claudeToolRuleArgv' => claudeToolRulesHardened(),
    'claudeFoldedPromptSettlement' => property_exists(TurnOutcome::class, 'foldedInto'),
    'codexFailureProviderMessage' => property_exists(TurnFailure::class, 'providerMessage'),
    'claudeMessageId' => claudeChunksCarryMessageId(),
    'claudeUnsolicitedTurn' => property_exists(TurnOutcome::class, 'unsolicited'),
    'driverLiveness' => publicMethod(ClaudeDriver::class, 'ping') || publicMethod(CodexDriver::class, 'ping') || publicMethod(CodexDriver::class, 'running') || sourceMentions('lastFrameAt'),
    'claudeAccountSelection' => property_exists(ClaudeDriverOptions::class, 'configDir') || class_exists('Prism\\Acp\\Claude\\ClaudeAccounts'),
    'childEnvCredentialList' => childEnvCredentialListCurrent(),
    'claudeSharedSessions' => sourceMentions('sharedSessions'),
    'claudeLaunchSettings' => claudeLaunchSettingsForced(),
    'claudeBrowserSignIn' => class_exists('Prism\\Acp\\Claude\\ClaudeLoginError') || sourceMentions('auth login'),
    'claudeOverloadedKind' => claudeOverloadedKind(),
    'codexStatusKind' => codexStatusKind(),
    'turnFailureRetry' => property_exists(TurnFailure::class, 'retryable') || property_exists(TurnFailure::class, 'retryAfterMs') || property_exists(TurnFailure::class, 'resumable'),
    'sessionExitedCode' => sourceMentions('session_exited'),
    'turnFailureWireBounds' => turnFailureWireBounded() || sourceMentions('wireTurnFailure'),
    'rateLimitReading' => in_array('particle.academy/rate_limit_reading', $metaConstants, true) || sourceMentions('rate_limit_reading'),
    'codexRateLimitWindows' => codexRateLimitWindowsRead(),
    'claudeRateLimitRejected' => claudeRejectionRead(),
    'claudeNoticeWindowName' => claudeNoticeWindowNameBounded(),
    'planTitlePrefix' => str_starts_with(PlanProjection::title('Draft the owned plan'), 'Step: '),
    'planGateCaps' => planGateCapped(),
    'planHiddenCharRefusal' => planGateRefusesHidden(),
    'externalDriverContract' => sourceMentions('ALLOW_ONCE_OR_DENY', 'askPermission', 'isRetryableKind', 'MAX_RETRY_AFTER_MS'),
    'codexListFrameGuard' => codexListFrameRefused(),
    'planEntryPriority' => planEntryPriorityPresent(),
], JSON_THROW_ON_ERROR)."\n";

<?php

declare(strict_types=1);

// php tools/native/credentials.php CODEX_EXE CLAUDE_EXE [INSTALLED_AUTOLOAD]
// Native auth only: dummy keys, owned homes, no prompt/model inference and no user login changes.
require $argv[3] ?? dirname(__DIR__, 2).'/vendor/autoload.php';

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransport;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Codex\StdioCodexTransport;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Support\Js;

function nativeCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
nativeCheck(PHP_OS_FAMILY === 'Windows', 'native credentials currently require Windows containment');
$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-native-keys-'.bin2hex(random_bytes(6));
mkdir($root, 0700);
$subscription = $root.DIRECTORY_SEPARATOR.'subscription';
mkdir($subscription);
$sentinel = $subscription.DIRECTORY_SEPARATOR.'auth.json';
file_put_contents($sentinel, 'owned subscription sentinel');
$sentinelHash = hash_file('sha256', $sentinel);
$parent = [...getenv(), 'HOME' => $root, 'USERPROFILE' => $root, 'codex_home' => $subscription, 'claude_config_dir' => $subscription,
    'OPENAI_API_KEY' => 'dummy-inherited-wrong-key', 'ANTHROPIC_API_KEY' => 'dummy-inherited-wrong-key', 'CLAUDE_CODE_OAUTH_TOKEN' => 'dummy-inherited-wrong-token'];
$codexHome = $root.DIRECTORY_SEPARATOR.'codex-app';
$claudeHome = $root.DIRECTORY_SEPARATOR.'claude-app';
$codex = $claude = null;
$evidence = [];
try {
    $loop = new Loop;
    $account = new Deferred($loop);
    $problems = [];
    $transport = null;
    $codex = new CodexDriver(new CodexDriverOptions(cwd: $root, binary: $argv[1], parentEnv: $parent, model: 'fixture',
        credentials: new ProviderCredentials('apiKey', 'sk-prism-invalid-native-key', $codexHome)),
        new DriverEvents(onProtocolError: function (string $message) use (&$problems): void {
            $problems[] = $message;
        }),
        function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use (&$transport, $loop, $account, $codexHome): CodexTransport {
            nativeCheck(($options->env['CODEX_HOME'] ?? null) === $codexHome && ! isset($options->env['codex_home']), 'explicit Codex home must replace inherited casing');
            nativeCheck(! isset($options->env['OPENAI_API_KEY']), 'Codex key must use login RPC, not environment');
            $intercept = new CodexTransportHandlers(
                onFrame: function (mixed $frame) use ($handlers, $account): void {
                    if (Js::get($frame, 'id') === 'native-account-probe') {
                        if (Js::has($frame, 'error')) {
                            $account->reject(new RuntimeException('account read refused'));
                        } else {
                            $account->resolve(Js::get($frame, 'result'));
                        }
                    } else {
                        ($handlers->onFrame)($frame);
                    }
                }, onStderr: $handlers->onStderr, onProblem: $handlers->onProblem, onExit: $handlers->onExit);

            return $transport = new StdioCodexTransport($options, $intercept, $loop);
        }, $loop);
    $timer = $loop->delay(25, static function (): void {
        throw new RuntimeException('native Codex auth timeout');
    });
    try {
        $codex->start();
        $codex->ready()->await();
        nativeCheck($problems === [], 'native Codex startup must confirm key login');
        $transport->send(['jsonrpc' => '2.0', 'id' => 'native-account-probe', 'method' => 'account/read', 'params' => ['refreshToken' => false]]);
        $result = $account->await();
        nativeCheck(Js::string(Js::get($result, 'account'), 'type') === 'apiKey', 'native Codex account must use API key');
        $auth = Json::decode((string) file_get_contents($codexHome.DIRECTORY_SEPARATOR.'auth.json'));
        nativeCheck(Js::get($auth, 'OPENAI_API_KEY') === 'sk-prism-invalid-native-key', 'native key must persist only in app home');
        nativeCheck(hash_file('sha256', $sentinel) === $sentinelHash, 'Codex must preserve moved subscription login');
    } finally {
        $loop->cancel($timer);
        $stop = $codex->stop(0)->await();
    }
    nativeCheck($stop->verified && $stop->empty && $stop->survivors === 0, 'native Codex cleanup must be verified');
    $evidence['codex'] = ['apiKeyAccount' => true, 'appHome' => true, 'subscriptionPreserved' => true, 'stop' => $stop->toArray()];

    $loop = new Loop;
    $ended = false;
    $probe = $root.DIRECTORY_SEPARATOR.'claude-status.json';
    $claude = new ClaudeDriver(new ClaudeDriverOptions(cwd: $root, binary: PHP_BINARY,
        binaryArgs: [__DIR__.'/claude-auth-relay.php', $argv[2], $probe], parentEnv: $parent,
        credentials: new ProviderCredentials('apiKey', 'sk-ant-prism-invalid-native-key', $claudeHome)),
        new DriverEvents(onExit: function () use (&$ended): void {
            $ended = true;
        }), $loop);
    $timer = $loop->delay(25, static function (): void {
        throw new RuntimeException('native Claude auth timeout');
    });
    try {
        $claude->start();
        $loop->run(static function () use (&$ended): bool {
            return $ended;
        });
        $status = Json::decode((string) file_get_contents($probe));
        nativeCheck(Js::get($status, 'code') === 0 && Js::get($status, 'authMethod') === 'api_key', 'native Claude must select the explicit API key');
        $directory = Js::get($status, 'configDirectory');
        nativeCheck(is_string($directory) && strtolower(str_replace('\\', '/', $directory)) === strtolower(str_replace('\\', '/', $claudeHome)), 'native Claude must use the app home');
        nativeCheck(hash_file('sha256', $sentinel) === $sentinelHash, 'Claude must preserve moved subscription login');
    } finally {
        $loop->cancel($timer);
        $stop = $claude->stop(0)->await();
    }
    nativeCheck($stop->verified && $stop->empty && $stop->survivors === 0, 'native Claude cleanup must be verified');
    $evidence['claude'] = ['apiKeyAccount' => true, 'appHome' => true, 'subscriptionPreserved' => true, 'stop' => $stop->toArray()];
    fwrite(STDOUT, Json::encode(['passed' => true, 'inference' => false, 'evidence' => $evidence])."\n");
} finally {
    $codex?->kill();
    $claude?->kill();
    // Preserve native fixture files for explicit cleanup by the calling gate; never recursively delete provider paths.
    fwrite(STDOUT, Json::encode(['fixtureRoot' => $root])."\n");
}

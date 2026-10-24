<?php

declare(strict_types=1);

// Run with the INSTALLATION's autoloader, not this checkout's development vendor directory.
require $argv[1];

use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Claude\Approvals;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Mcp\McpServers;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\NdjsonFramer;
use Prism\Acp\Version;

function check(bool $passed, string $name): void
{
    if (! $passed) {
        throw new RuntimeException($name.' failed on the installed artifact');
    }
}

$frames = (new NdjsonFramer)->push("{\"ok\":true}\n{invalid-secret-payload}\n");
check(count($frames) === 2 && $frames[0]->ok && ! $frames[1]->ok, 'hostile framing');
check(! str_contains(Json::encode($frames[1]), 'secret-payload'), 'no payload in errors');
$environment = ChildEnv::build(['ANTHROPIC_API_KEY' => 'synthetic-secret', 'PATH' => 'synthetic-path'], ['ANTHROPIC_API_KEY']);
check(! isset($environment->env['ANTHROPIC_API_KEY']) && $environment->env['PATH'] === 'synthetic-path', 'credential withholding');
check(Approvals::decisionFor(PermissionOutcome::selected('unknown'))['permissionDecision'] === 'deny', 'unknown approval denies');
check(Approvals::decisionFor(PermissionOutcome::selected('allow_once', 'reviewed'))['permissionDecisionReason'] === 'reviewed', 'allow positive control');
check(Approvals::decisionFor(PermissionOutcome::cancelled())['permissionDecision'] === 'deny', 'cancel denies');
check(CodexDriver::CAPABILITIES['transcriptReplay'] && CodexDriver::CAPABILITIES['cancel'], 'Codex driver loads without dev dependencies');
$servers = McpServers::read([(object) ['type' => 'http', 'name' => 'fixture', 'url' => 'http://127.0.0.1:1',
    'headers' => [(object) ['name' => 'Authorization', 'value' => 'Bearer synthetic-secret']]]]);
$first = McpServers::codexSessionConfig($servers);
$second = McpServers::codexSessionConfig($servers);
$binding = array_key_first($first['env']);
check($binding !== null && $binding !== array_key_first($second['env']), 'fresh per-child bearer identities');
check($first['env'][$binding] === 'synthetic-secret' && $first['config']->mcp_servers->fixture->bearer_token_env_var === $binding, 'bearer bound to intended child variable');
check(! str_contains(Json::encode($first['config']), 'synthetic-secret') && ! isset($first['config']->mcp_servers->fixture->http_headers->Authorization), 'secret removed from RPC configuration');
check($first['config']->features->shell_snapshot === false, 'native snapshots disabled');
$policy = McpServers::codexShellPolicy($first['config'], [$binding], (object) ['exclude' => ['KEEP'], 'set' => (object) ['VISIBLE' => 'yes']]);
check($policy->shell_environment_policy->exclude === ['KEEP', $binding] && $policy->shell_environment_policy->set->VISIBLE === 'yes', 'shell exclusion and policy preservation');
try {
    McpServers::codexShellPolicy($first['config'], [$binding], (object) ['set' => (object) [strtolower($binding) => 'restore']]);
    check(false, 'colliding shell assignment must refuse');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'cannot safely exclude'), 'colliding shell assignment refuses');
}
$disabled = McpServers::codexDisabledConfig(null, ['fixture'], (object) ['fixture' => (object) ['command' => '/fixture/server', 'args' => []]]);
check($disabled->mcp_servers->fixture->enabled === false && ! isset($disabled->mcp_servers->fixture->url), 'disabled stdio retains a valid transport');
$host = new CodexDriverOptions('/fixture', disabledMcpServers: ['blocked'], approvalPolicy: 'on-request');
check($host->forSession(['cwd' => '/session', 'mcpServers' => [], 'disabledMcpServers' => [], 'approvalPolicy' => 'never'])->disabledMcpServers === ['blocked'], 'ACP cannot replace host denial');
check(! class_exists('Illuminate\\Support\\ServiceProvider'), 'core installs without Illuminate');
check(ApiKeyHome::check('codex', new ProviderCredentials, []) === null, 'subscription credential default');
$credentials = new ProviderCredentials('apiKey', 'synthetic-private-key', '/tenant');
check(! str_contains(Json::encode($credentials), 'synthetic-private-key'), 'credential object redaction');
$explicitHome = ChildEnv::build(['codex_home' => 'old'], ['CODEX_HOME'], ['CODEX_HOME' => 'new']);
check($explicitHome->env === ['CODEX_HOME' => 'new'], 'explicit home replaces inherited casing');
$keyedHost = new CodexDriverOptions('/fixture', credentials: $credentials);
check($keyedHost->forSession(['cwd' => '/session', 'mcpServers' => [], 'credentials' => new ProviderCredentials])->credentials === $credentials, 'ACP cannot replace credentials');
fwrite(STDOUT, 'Installed Prism ACP '.Version::VERSION." consumer checks passed\n");

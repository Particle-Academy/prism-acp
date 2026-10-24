<?php

declare(strict_types=1);

// Run with the INSTALLATION's autoloader, not this checkout's development vendor directory.
require $argv[1];

use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Claude\Approvals;
use Prism\Acp\Env\ChildEnv;
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
check(! class_exists('Illuminate\\Support\\ServiceProvider'), 'core installs without Illuminate');
fwrite(STDOUT, 'Installed Prism ACP '.Version::VERSION." consumer checks passed\n");

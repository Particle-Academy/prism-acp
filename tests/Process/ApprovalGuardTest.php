<?php

declare(strict_types=1);

use Prism\Acp\Claude\Approvals;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Process\WindowsSupervisorBuild;
use Prism\Acp\Support\PrivateDirectory;

/** @return array{code: int|null, stdout: string, stderr: string, expired: bool, contacted: bool} */
function approvalGuardRun(string $script, ?string $reply = null, string $input = '{}'): array
{
    $directory = PrivateDirectory::create('prism-php-guard-');
    $path = $directory.DIRECTORY_SEPARATOR."hook ' & % $.php";
    file_put_contents($path, $script);
    $server = null;
    $socket = null;
    $provider = null;
    $env = [];
    if ($reply !== null) {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            throw new RuntimeException('owned approval listener failed');
        }
        $env = ['PRISM_ACP_APPROVAL_SOCKET' => 'tcp://'.stream_socket_get_name($server, false), 'PRISM_ACP_APPROVAL_TOKEN' => bin2hex(random_bytes(32))];
    }
    $out = '';
    $err = '';
    $contacted = false;
    try {
        $provider = WindowsProviderProcess::launch(WindowsSupervisorBuild::executable(), ['approval', PHP_BINARY, $path, '1'], $directory, ChildEnv::build(getenv(), set: $env)->env);
        $provider->write($input);
        $provider->closeInput();
        if (is_resource($server)) {
            $read = [$server];
            $write = $except = null;
            $socket = stream_select($read, $write, $except, 1) > 0 ? stream_socket_accept($server, 0.0) : null;
            if (is_resource($socket)) {
                $contacted = true;
                stream_set_timeout($socket, 1);
                $request = stream_get_contents($socket, 1_000_100);
                expect($request)->toStartWith($env['PRISM_ACP_APPROVAL_TOKEN']."\n");
                if ($reply !== 'hang') {
                    fwrite($socket, (string) $reply);
                    fclose($socket);
                    $socket = null;
                }
            }
        }
        $deadline = microtime(true) + 6.0;
        while ($provider->isOpen() && microtime(true) < $deadline) {
            foreach ($provider->read(0.02) as [$kind, $bytes]) {
                if ($kind === 'stdout') {
                    $out .= (string) $bytes;
                } elseif ($kind === 'stderr') {
                    $err .= (string) $bytes;
                }
            }
        }

        return ['code' => $provider->exitCode(), 'stdout' => $out, 'stderr' => $err, 'expired' => $provider->isOpen(), 'contacted' => $contacted];
    } finally {
        if ($provider !== null) {
            $stop = $provider->stop(0);
            expect($stop->verified)->toBeTrue()->and($stop->empty)->toBeTrue()->and($stop->survivors)->toBe(0);
            $provider = null; // Close the supervisor resource before removing its Windows working directory.
        }
        if (is_resource($socket)) {
            fclose($socket);
        }
        if (is_resource($server)) {
            fclose($server);
        }
        expect(PrivateDirectory::remove($directory, $path))->toBeTrue();
    }
}

describe('Windows native PHP approval guard', function () {
    it('maps only the script explicit-allow status to success', function (string $script, int $expected) {
        $result = approvalGuardRun($script);
        expect($result['expired'])->toBeFalse()->and($result['code'])->toBe($expected);
        if ($expected === 2) {
            expect($result['stderr'])->toContain('approval hook failed or denied');
        }
        expect($result['stdout'].$result['stderr'])->not->toContain('SYNTHETIC_PRIVATE_ERROR');
    })->with([
        'explicit allow42' => ['<?php echo \'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow","permissionDecisionReason":"owned allow"}}\'; exit(42);', 0],
        'silent zero' => ['<?php exit(0);', 2],
        'garbage zero' => ['<?php echo "invalid output"; exit(0);', 2],
        'malformed JSON zero' => ['<?php echo "{invalid"; exit(0);', 2],
        'allow JSON zero' => ['<?php echo \'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow"}}\'; exit(0);', 2],
        'allow JSON blocking2' => ['<?php echo \'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow"}}\'; exit(2);', 2],
        'fatal runtime' => ['<?php throw new Exception("SYNTHETIC_PRIVATE_ERROR");', 2],
        'hung runtime' => ['<?php sleep(30);', 2],
    ]);

    it('validates the channel answer before producing the internal allow status', function (string $reply, bool $allows) {
        $result = approvalGuardRun(Approvals::HOOK_SCRIPT, $reply);
        expect($result['expired'])->toBeFalse()->and($result['code'])->toBe($allows ? 0 : 2);
        if ($reply === 'hang') {
            expect($result['stdout'])->toBe('')->and($result['stderr'])->toContain('approval hook failed or denied');

            return;
        }
        $answer = json_decode($result['stdout'], true);
        expect($answer['hookSpecificOutput']['permissionDecision'])->toBe($allows ? 'allow' : 'deny');
        if ($allows) {
            expect($answer['hookSpecificOutput']['permissionDecisionReason'])->toBe("Owned é ' & % $ reason");
        }
    })->with([
        'explicit allow' => ["200\n".'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow","permissionDecisionReason":"Owned é \' & % $ reason"}}', true],
        'explicit deny' => ["200\n".'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"owned denial"}}', false],
        'no answer' => ['', false],
        'wrong status' => ["401\n{}", false],
        'malformed' => ["200\n{invalid", false],
        'null' => ["200\nnull", false],
        'array' => ["200\n[]", false],
        'missing reason' => ["200\n".'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow"}}', false],
        'wrong event' => ["200\n".'{"hookSpecificOutput":{"hookEventName":"PostToolUse","permissionDecision":"allow","permissionDecisionReason":"x"}}', false],
        'wrong reason type' => ["200\n".'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow","permissionDecisionReason":42}}', false],
        'extra outer field' => ["200\n".'{"extra":true,"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow","permissionDecisionReason":"x"}}', false],
        'unknown decision' => ["200\n".'{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow_always","permissionDecisionReason":"x"}}', false],
        'live stalled channel' => ['hang', false],
    ]);

    it('refuses malformed hook input before contacting the approval channel', function (string $input) {
        $result = approvalGuardRun(Approvals::HOOK_SCRIPT, "200\n{}", $input);
        expect($result['expired'])->toBeFalse()->and($result['code'])->toBe(2)->and($result['contacted'])->toBeFalse();
    })->with(['null', '[]', '{invalid', "{\"tool_name\":\"\xff\"}"]);
})->skip(PHP_OS_FAMILY !== 'Windows', 'native Windows adapter only');

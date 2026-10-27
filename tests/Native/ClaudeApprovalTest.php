<?php

declare(strict_types=1);

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Support\PrivateDirectory;

/** @return array<string, mixed> */
function phpNativeApproval(string $scenario, string $mutation = '', string $versionFault = '', bool $wildcard = false): array
{
    $root = PrivateDirectory::create('prism-php-native-controller-');
    $outer = null;
    $out = '';
    $err = '';
    try {
        $outer = WindowsProviderProcess::launch('node', [dirname(__DIR__).'/fixtures/native-approval-probe.mjs', PHP_BINARY, (string) getenv('PRISM_ACP_CLAUDE_BINARY'), $scenario, $mutation, $versionFault, $wildcard ? '1' : '0'], $root, ChildEnv::build(getenv())->env);
        $outer->closeInput();
        $deadline = microtime(true) + 60;
        while ($outer->isOpen() && microtime(true) < $deadline) {
            foreach ($outer->read(0.02) as [$kind, $bytes]) {
                if ($kind === 'stdout') {
                    $out .= (string) $bytes;
                } elseif ($kind === 'stderr') {
                    $err .= (string) $bytes;
                }
            }
        }
        expect($outer->isOpen())->toBeFalse()->and($outer->exitCode())->toBe(0, $err);
        $row = json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);
        expect($row['stop']['verified'])->toBeTrue()->and($row['stop']['empty'])->toBeTrue()->and($row['stop']['survivors'])->toBe(0);
        echo json_encode(['phpNativeApproval' => $row], JSON_UNESCAPED_SLASHES)."\n";

        return $row;
    } finally {
        if ($outer !== null) {
            $stop = $outer->stop(0);
            expect($stop->verified)->toBeTrue()->and($stop->empty)->toBeTrue()->and($stop->survivors)->toBe(0);
            echo json_encode(['phpNativeApprovalOuterStop' => $stop->toArray()])."\n";
            $outer = null;
        }
        expect(PrivateDirectory::remove($root))->toBeTrue();
    }
}

describe('native Claude2.1.310 PHP approval enforcement', function () {
    it('enforces the approval outcome', function (string $scenario, bool $allows) {
        $row = phpNativeApproval($scenario);
        expect($row['nativeCode'])->toBe(0)->and($row['requests'])->toBe(2)
            ->and($row['sentUsers'])->toBe(1)->and($row['executed'])->toBe($allows)
            ->and($row['reportedVersions'][0])->toBe(['source' => 'control.initialize', 'version' => '2.1.310']);
        if ($allows) {
            expect($row['asked'])->toBe(1);
        }
    })->with([
        ['allow', true], ['deny', false], ['cancel', false], ['callback-crash', false], ['channel-hang', false],
        ['hook-empty', false], ['hook-invalid', false], ['hook-malformed', false], ['hook-crash', false], ['hook-hang', false],
        ['hook-allow-zero', false], ['hook-allow-two', false], ['guard-missing', false], ['guard-crash', false], ['guard-timeout', false],
    ]);

    it('keeps wildcard approvals under the same guard', function () {
        $row = phpNativeApproval('allow', wildcard: true);
        expect($row['executed'])->toBeTrue()->and($row['asked'])->toBe(1)->and($row['requests'])->toBe(2);
    });

    it('CONTROL removing the guard lets the real CLI run a silent-zero hook', function () {
        $row = phpNativeApproval('hook-empty', 'drop-wrapper');
        expect($row['executed'])->toBeTrue()->and($row['asked'])->toBe(0)->and($row['requests'])->toBe(2);
    });

    it('refuses a missing or unsupported initialize version before the first prompt or model request', function (string $fault) {
        $row = phpNativeApproval('allow', versionFault: $fault);
        expect($row['sentUsers'])->toBe(0)->and($row['requests'])->toBe(0)->and($row['asked'])->toBe(0)->and($row['errors'])->not->toBeEmpty();
    })->with(['missing', 'old', 'malformed', 'error', 'timeout']);

    it('closes a live native session when system/init omits or changes the verified version', function (string $fault) {
        $row = phpNativeApproval('allow', versionFault: $fault);
        expect($row['executed'])->toBeFalse()->and($row['asked'])->toBe(0)->and($row['errors'])->not->toBeEmpty();
    })->with(['init-missing', 'init-changed']);
})->skip(PHP_OS_FAMILY !== 'Windows' || getenv('PRISM_ACP_CLAUDE_APPROVAL') !== '1', 'opt-in native Windows approval regression');

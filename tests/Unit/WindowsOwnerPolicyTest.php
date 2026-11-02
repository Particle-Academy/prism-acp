<?php

declare(strict_types=1);

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsCommand;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Support\PrivateDirectory;

it('evaluates natural Administrators and foreign ownership with actual elevated or linked filtered tokens', function (string $mode): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('native Windows access tokens only');
    }
    $root = PrivateDirectory::create('prism-acp-owner-policy-');
    $exe = $root.DIRECTORY_SEPARATOR.'owned-token-runner.exe';
    $homes = [];
    $provider = null;
    $unavailable = null;
    try {
        foreach (['user-home', 'admin-home', 'foreign-home'] as $name) {
            $home = $root.DIRECTORY_SEPARATOR.$name;
            mkdir($home, 0700);
            $homes[] = $home;
            PrivateDirectory::protect($home);
            if ($name !== 'user-home') {
                $file = fopen($home.DIRECTORY_SEPARATOR.'empty-marker', 'x');
                fclose($file);
            }
        }
        $windows = getenv('SystemRoot') ?: 'C:\\Windows';
        $compiler = null;
        foreach (['Framework64', 'FrameworkArm64', 'Framework'] as $bits) {
            $candidate = $windows.'\\Microsoft.NET\\'.$bits.'\\v4.0.30319\\csc.exe';
            if (is_file($candidate)) {
                $compiler = $candidate;
                break;
            }
        }
        expect($compiler)->toBeString();
        $source = realpath(dirname(__DIR__).'/fixtures/owner-token-runner.cs');
        expect($source)->toBeString();
        $env = ChildEnv::build(getenv(), [], ['USERPROFILE' => $root, 'HOME' => $root])->env;
        $build = proc_open([$compiler, '-nologo', '-target:exe', '-out:'.$exe, $source],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: $env, options: ['bypass_shell' => true]);
        expect(is_resource($build))->toBeTrue();
        $compilerOutput = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($build))->toBe(0, $compilerOutput);
        $command = WindowsCommand::commandLine([PHP_BINARY, dirname(__DIR__).'/fixtures/owner-policy-probe.php', dirname(__DIR__, 2).'/vendor/autoload.php', $root]);
        $provider = WindowsProviderProcess::launch($exe, [$mode, $root, PHP_BINARY, $command], $root, $env);
        $provider->closeInput();
        $out = $err = '';
        $deadline = microtime(true) + 100;
        while ($provider->isOpen() && microtime(true) < $deadline) {
            foreach ($provider->read(0.02) as [$kind, $bytes]) {
                if ($kind === 'stdout') {
                    $out .= $bytes;
                } elseif ($kind === 'stderr') {
                    $err .= $bytes;
                }
            }
        }
        expect($provider->isOpen())->toBeFalse()->and($provider->exitCode())->toBe(0, $err)->and($err)->toBe('');
        $rows = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), preg_split('/\R/', trim($out)));
        $token = $rows[0];
        if (! $token['available']) {
            expect(count($rows))->toBe(1);
            expect($token['reason'])->toBeIn(['requires_elevated_administrator', 'restore_privilege_unavailable', 'process_token_privilege_unavailable', 'linked_filtered_token_unavailable', 'linked_filtered_token_state_unavailable']);
            $unavailable = $token['reason'];
        } else {
            expect(count($rows))->toBe(2);
            expect($token)->toBe(['available' => true, 'elevated' => $mode === 'elevated', 'administratorEnabled' => $mode === 'elevated', 'naturalAdministratorOwner' => true, 'tokenSource' => $mode === 'elevated' ? 'process' : 'linked_filtered']);
            expect($rows[1])->toBe(['ownerPolicy' => ['user-home' => true, 'admin-home' => $mode === 'elevated', 'foreign-home' => false], 'credentialContentsAccessed' => false]);
            echo json_encode(['nativeOwnerToken' => $token, 'ownerPolicy' => $rows[1]['ownerPolicy']], JSON_THROW_ON_ERROR)."\n";
        }
    } finally {
        if ($provider !== null) {
            $stop = $provider->stop(0);
            expect($stop->verified)->toBeTrue()->and($stop->empty)->toBeTrue()->and($stop->survivors)->toBe(0);
            $provider = null;
        }
        foreach (array_reverse($homes) as $home) {
            $marker = $home.DIRECTORY_SEPARATOR.'empty-marker';
            if (is_file($marker)) {
                unlink($marker);
            }
            rmdir($home);
        }
        if (is_file($exe)) {
            unlink($exe);
        }
        // The Framework compiler can create these EMPTY profile directories in the owned test home.
        foreach ([$root.'/AppData/Roaming', $root.'/AppData'] as $empty) {
            if (is_dir($empty)) {
                expect(is_link($empty))->toBeFalse()->and(scandir($empty))->toBe(['.', '..']);
                expect(rmdir($empty))->toBeTrue();
            }
        }
        expect(rmdir($root))->toBeTrue();
    }
    if ($unavailable !== null) {
        echo json_encode(['nativeOwnerToken' => ['available' => false, 'mode' => $mode, 'reason' => $unavailable]], JSON_THROW_ON_ERROR)."\n";
        $this->markTestSkipped('native owner token control unmeasured: '.$unavailable);
    }
})->with(['elevated', 'restricted']);

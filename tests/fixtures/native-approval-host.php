<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/native-unlink-observer.php';

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;

/** @return list<array{basename:string,type:string,size:int|null}>|null */
function ownedApprovalEntryMetadata(?string $directory): ?array
{
    if ($directory === null || ! is_dir($directory)) {
        return null;
    }
    $names = @scandir($directory);
    if ($names === false) {
        return null;
    }
    $entries = [];
    foreach (array_slice(array_values(array_diff($names, ['.', '..'])), 0, 16) as $name) {
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        clearstatcache(true, $path);
        $stat = @lstat($path); // Metadata only, never open an entry or follow a link.
        $type = $stat === false ? null : $stat['mode'] & 0170000;
        $entries[] = ['basename' => $name, 'type' => match ($type) {
            0040000 => 'directory', 0100000 => 'file', 0120000 => 'link', default => 'other',
        }, 'size' => $stat === false ? null : $stat['size']];
    }

    return $entries;
}

/** @return array<string,mixed> */
function ownedDiagnosticLine($stream): array
{
    $line = '';
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline && strlen($line) < 65536) {
        $bytes = fgets($stream);
        if ($bytes !== false) {
            $line .= $bytes;
            if (str_ends_with($line, "\n")) {
                return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        } elseif (feof($stream)) {
            throw new RuntimeException('owned metadata worker ended');
        }
        usleep(1000);
    }
    throw new RuntimeException('owned metadata worker response bound');
}

$config = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$loop = new Loop;
$asked = 0;
$errors = [];
$exited = false;
$turns = 0;
$approvalDirectory = null;
$nativeStartCompleted = false;
$approvalEntriesAtStart = null;
$metadataWorker = null;
$metadataPipes = [];
$metadataReady = null;
$pendingMetadata = null;
$GLOBALS['owned_native_unlink_observations'] = [];
$driver = new ClaudeDriver(new ClaudeDriverOptions(
    cwd: $config['root'], binary: $config['node'], binaryArgs: [$config['bridge'], $argv[1]],
    parentEnv: getenv(), model: 'claude-sonnet-4-6', permissionMode: 'bypassPermissions',
    permissionPrompts: ($config['wildcard'] ?? false) ? ['*'] : ['Bash'],
    credentials: new ProviderCredentials('apiKey', 'prism-fake-not-a-real-key', $config['home']),
    permissionTimeoutSeconds: 1,
), new DriverEvents(
    onProtocolError: function (string $reason) use (&$errors): void {
        $errors[] = $reason;
    },
    onExit: function () use (&$exited): void {
        $exited = true;
    },
    onTurnEnd: function () use (&$turns): void {
        $turns++;
    },
    onRequestPermission: function () use ($config, $loop, &$asked): Deferred {
        $asked++;
        if ($config['scenario'] === 'callback-crash') {
            throw new RuntimeException('owned callback failed');
        }
        $answer = new Deferred($loop);
        if ($config['scenario'] !== 'channel-hang') {
            $answer->resolve(match ($config['scenario']) {
                'deny' => PermissionOutcome::selected('reject_once', 'Owned application denial é.'),
                'cancel' => PermissionOutcome::cancelled(),
                default => PermissionOutcome::selected('allow_once', "Owned allow é ' & % $ reason"),
            });
        }

        return $answer;
    },
), $loop);
try {
    $driver->start();
    $nativeStartCompleted = true;
    $approvalDirectory = (new ReflectionProperty(ClaudeDriver::class, 'approvalDirectory'))->getValue($driver);
    $approvalEntriesAtStart = ownedApprovalEntryMetadata($approvalDirectory);
    $metadataWorker = proc_open([$config['diagnostic'], $approvalDirectory, 'serve'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $metadataPipes,
        env_vars: ChildEnv::build(getenv())->env, options: ['bypass_shell' => true]);
    if (! is_resource($metadataWorker)) {
        throw new RuntimeException('owned metadata worker did not start');
    }
    stream_set_blocking($metadataPipes[1], false);
    $metadataReady = ownedDiagnosticLine($metadataPipes[1]);
    if (($metadataReady['diagnosticReady'] ?? false) !== true) {
        throw new RuntimeException('owned metadata worker readiness failed');
    }
    $GLOBALS['owned_native_before_unlink'] = function (string $path) use ($driver, $approvalDirectory, $metadataPipes): void {
        if ($path !== $approvalDirectory.DIRECTORY_SEPARATOR.'approve.php' || count($GLOBALS['owned_native_unlink_observations']) >= 4) {
            return;
        }
        $provider = (new ReflectionProperty(ClaudeDriver::class, 'provider'))->getValue($driver);
        $verdict = $provider?->provider->verdict(false)->toArray();
        fwrite($metadataPipes[0], "users\n");
        $GLOBALS['owned_native_unlink_observations'][] = ['providerVerdictBeforeUnlink' => $verdict,
            'query' => ownedDiagnosticLine($metadataPipes[1])];
    };
    $driver->prompt('Run the owned Bash probe.'); // Must be held until the native initialize version is validated.
    $driver->endInput();
    $deadline = microtime(true) + 45;
    $loop->run(function () use (&$exited, $deadline): bool {
        return $exited || microtime(true) >= $deadline;
    });
    if (! $exited) {
        throw new RuntimeException('native approval host deadline');
    }
} catch (Throwable $error) {
    $errors[] = $error->getMessage();
} finally {
    $approvalEntriesBeforeStop = ownedApprovalEntryMetadata($approvalDirectory);
    $stop = $driver->stop(0)->await();
    if (is_string($approvalDirectory)) {
        clearstatcache(true, $approvalDirectory);
    }
    $cleanup = null;
    if (is_string($approvalDirectory) && is_dir($approvalDirectory)) {
        // Owned temporary directory metadata only; never open or print file contents or paths.
        $names = scandir($approvalDirectory);
        $known = (new ReflectionProperty(ClaudeDriver::class, 'approvalFiles'))->getValue($driver);
        $retained = (new ReflectionProperty(ClaudeDriver::class, 'approvalDirectory'))->getValue($driver);
        $cleanup = ['retained' => $retained === $approvalDirectory,
            'entries' => $names === false ? null : count(array_diff($names, ['.', '..'])),
            'knownFilesPresent' => count(array_filter($known, is_file(...)))];
    }
    // Freeze the original assertion before diagnostic work can let a pending deletion settle.
    $approvalEntriesAfterStop = ownedApprovalEntryMetadata($approvalDirectory);
    $approvalDirectoryRemoved = is_string($approvalDirectory) && ! is_dir($approvalDirectory);
    if (! $approvalDirectoryRemoved && is_resource($metadataWorker)) {
        try {
            fwrite($metadataPipes[0], "attributes\n");
            $pendingMetadata = ownedDiagnosticLine($metadataPipes[1]);
        } catch (Throwable $error) {
            $pendingMetadata = ['diagnosticUnavailable' => $error::class];
        }
    }
    $GLOBALS['owned_native_before_unlink'] = null;
    if (is_resource($metadataWorker)) {
        fclose($metadataPipes[0]);
        fclose($metadataPipes[1]);
        proc_close($metadataWorker);
    }
    echo json_encode(['asked' => $asked, 'errors' => $errors, 'turns' => $turns, 'stop' => $stop->toArray(),
        'nativeStartCompleted' => $nativeStartCompleted, 'approvalDirectoryCaptured' => is_string($approvalDirectory),
        'approvalEntriesAtStart' => $approvalEntriesAtStart, 'approvalEntriesBeforeStop' => $approvalEntriesBeforeStop,
        'approvalEntriesAfterStop' => $approvalEntriesAfterStop,
        'residualApprovalMetadata' => $cleanup,
        'diagnosticWorker' => $metadataReady, 'preUnlinkFileUsers' => $GLOBALS['owned_native_unlink_observations'],
        'pendingNativeMetadata' => $pendingMetadata,
        'approvalDirectoryRemoved' => $approvalDirectoryRemoved], JSON_UNESCAPED_SLASHES)."\n";
}

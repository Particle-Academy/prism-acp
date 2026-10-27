<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Credentials\ProviderCredentials;

$config = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$loop = new Loop;
$asked = 0;
$errors = [];
$exited = false;
$turns = 0;
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
    $stop = $driver->stop(0)->await();
    echo json_encode(['asked' => $asked, 'errors' => $errors, 'turns' => $turns, 'stop' => $stop->toArray()], JSON_UNESCAPED_SLASHES)."\n";
}

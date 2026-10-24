<?php

declare(strict_types=1);

// Called only by codex-mcp.mjs with its owned dummy-key configuration and loopback model fixture.
require $argv[2] ?? dirname(__DIR__, 2).'/vendor/autoload.php';

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Mcp\McpServers;
use Prism\Acp\Ndjson\Json;

$options = Json::decode((string) file_get_contents($argv[1]));
$loop = new Loop;
$done = new Deferred($loop);
$problems = [];
$driver = new CodexDriver(new CodexDriverOptions(
    cwd: $options->cwd, binary: $options->binary, model: 'fixture', sandbox: 'danger-full-access', approvalPolicy: 'never',
    parentEnv: [...getenv(), 'CODEX_HOME' => $options->home, 'OLD_SELECTOR' => 'dummy-old-selector',
        'PRISM_FIXTURE_HIDDEN' => 'must-be-excluded', 'PRISM_FIXTURE_VISIBLE' => 'visible'],
    allowEnv: ['OLD_SELECTOR', 'PRISM_FIXTURE_HIDDEN', 'PRISM_FIXTURE_VISIBLE'],
    mcpServers: McpServers::read($options->mcpServers), disabledMcpServers: ['blocked'],
    resumeSessionId: $options->resumeSessionId ?? null,
), new DriverEvents(onProtocolError: function (string $problem) use (&$problems): void {
    $problems[] = $problem;
}, onTurnEnd: fn (TurnOutcome $outcome) => $done->resolve($outcome),
    onExit: function () use ($done): void {
        if (! $done->isSettled()) {
            $done->reject(new RuntimeException('native provider exited before its turn'));
        }
    }), loop: $loop);
$timeout = $loop->delay(35, static function (): void {
    throw new RuntimeException('native fixture timeout');
});
try {
    $driver->start();
    $driver->ready()->await();
    if ($problems !== []) {
        throw new RuntimeException('native startup reported problems: '.Json::encode($problems));
    }
    $driver->prompt('Run the deterministic loopback fixture command.');
    $outcome = $done->await();
    fwrite(STDOUT, Json::encode(['outcome' => ['isError' => $outcome->isError, 'stopReason' => $outcome->stopReason],
        'sessionId' => $driver->cliSessionId(), 'problems' => $problems])."\n");
} finally {
    $loop->cancel($timeout);
    $verdict = $driver->stop(0)->await();
    fwrite(STDOUT, Json::encode(['stop' => $verdict->toArray()])."\n");
}

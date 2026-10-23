<?php

/*
 * An ACP agent host, as an embedder writes one: AcpAgent with the Claude driver, served on this process's stdin and
 * stdout. The CLI is tests/fixtures/fake-claude.php unless AGENT_HOST_REAL_CLAUDE is set. For StdioTest.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\Stdio;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\JsonRpc\JsonRpcPeer;

$base = getenv('AGENT_HOST_REAL_CLAUDE')
    ? new ClaudeDriverOptions(cwd: getcwd() ?: '.', model: 'haiku')
    : new ClaudeDriverOptions(cwd: getcwd() ?: '.', binary: PHP_BINARY, binaryArgs: [__DIR__.'/fake-claude.php'], allowEnv: ['FAKE_CLAUDE_LOG']);

$served = Stdio::serve(Stdio::stdin(), STDOUT, fn (JsonRpcPeer $peer) => new AcpAgent(
    peer: $peer,
    driverFactory: fn (array $session, DriverEvents $events) => new ClaudeDriver($base->forSession($session), $events),
    driverCapabilities: ClaudeDriver::CAPABILITIES,
    onStderr: fn (string $sessionId, string $line) => fwrite(STDERR, "[{$sessionId}] {$line}\n"),
    onProtocolError: fn (string $sessionId, string $problem) => fwrite(STDERR, "[{$sessionId}] problem: {$problem}\n"),
));
$served['closed']->await();

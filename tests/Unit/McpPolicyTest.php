<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Mcp\McpServers;
use Tests\Support\ScriptedCodexTransport;

it('rejects ambiguous trusted deny policies in both drivers before launch', function (array $names): void {
    expect(fn () => new CodexDriver(new CodexDriverOptions('/fixture', disabledMcpServers: $names)))
        ->toThrow(InvalidArgumentException::class, 'disabledMcpServers')
        ->and(fn () => new ClaudeDriver(new ClaudeDriverOptions('/fixture', disabledMcpServers: $names)))
        ->toThrow(InvalidArgumentException::class, 'disabledMcpServers');
})->with([[['']], [[' duplicate']], [['duplicate ']], [["bad\nname"]], [['same', 'same']], [[null]], [[1]]]);

it('rejects a host deny list that also names an explicit session server', function (): void {
    $server = [['name' => 'active', 'command' => '/fixture/server', 'args' => [], 'env' => []]];
    expect(fn () => new CodexDriver(new CodexDriverOptions('/fixture', mcpServers: $server, disabledMcpServers: ['active'])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClaudeDriver(new ClaudeDriverOptions('/fixture', mcpServers: $server, disabledMcpServers: ['active'])))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses whitespace-bearing and empty bearer tokens without reporting their values', function (string $header): void {
    $servers = McpServers::read([(object) ['type' => 'http', 'name' => 'fixture', 'url' => 'http://127.0.0.1:1',
        'headers' => [(object) ['name' => 'Authorization', 'value' => $header]]]]);
    expect(fn () => McpServers::codexSessionConfig($servers))->toThrow('Bearer Authorization must contain a non-empty token without whitespace');
})->with(['Bearer', 'Bearer ', 'Bearer private token', "Bearer\u{A0}private", "Bearer private\u{A0}token", "Bearer private\u{FEFF}token", "Bearer private\u{2028}token"]);

it('does not allow an effective set assignment to restore a credential after exclusion', function (string $name): void {
    $policy = (object) ['exclude' => ['EXISTING'], 'set' => (object) [$name => 'attempted restore']];
    expect(fn () => McpServers::codexShellPolicy(new stdClass, ['PRISM_ACP_MCP_TOKEN_FIXTURE'], $policy))
        ->toThrow(RuntimeException::class, 'cannot safely exclude');
})->with(['PRISM_ACP_MCP_TOKEN_FIXTURE', 'prism_acp_mcp_token_fixture']);

it('uses independent bearer identities across driver instances and preserves the parent environment', function (): void {
    $server = McpServers::read([(object) ['type' => 'http', 'name' => 'fixture', 'url' => 'http://127.0.0.1:1',
        'headers' => [(object) ['name' => 'Authorization', 'value' => 'Bearer dummy-session-token']]]]);
    $parent = ['OLD_SELECTOR' => 'dummy-old-token'];
    $all = [];
    for ($i = 0; $i < 2; $i++) {
        $loop = new Loop;
        $driver = new CodexDriver(new CodexDriverOptions('/fixture', parentEnv: $parent, allowEnv: ['OLD_SELECTOR'], mcpServers: $server), new DriverEvents,
            function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use (&$all, $loop): ScriptedCodexTransport {
                $all[] = $options->env;

                return new ScriptedCodexTransport($handlers, $loop, new stdClass);
            }, $loop);
        $driver->start();
        $driver->ready()->await();
        $driver->kill();
        $loop->drain();
    }
    $first = array_keys(array_diff_key($all[0], $parent));
    $second = array_keys(array_diff_key($all[1], $parent));
    expect($first)->toHaveCount(1)->and($second)->toHaveCount(1)->and($first[0])->not->toBe($second[0])
        ->and($all[0][$first[0]])->toBe('dummy-session-token')->and($all[1][$second[0]])->toBe('dummy-session-token')
        ->and($parent)->toBe(['OLD_SELECTOR' => 'dummy-old-token']);
});

it('retains constructor policy when untrusted session data tries to replace it', function (): void {
    $session = ['cwd' => '/session', 'mcpServers' => [], 'disabledMcpServers' => [], 'approvalPolicy' => 'never', 'sandbox' => 'danger-full-access'];
    $base = new CodexDriverOptions('/host', disabledMcpServers: ['inherited'], approvalPolicy: 'on-request', sandbox: 'read-only');
    $child = $base->forSession($session);
    expect($child->disabledMcpServers)->toBe(['inherited'])->and($child->approvalPolicy)->toBe('on-request')->and($child->sandbox)->toBe('read-only')
        ->and((new ClaudeDriverOptions('/host', disabledMcpServers: ['inherited']))->forSession($session)->disabledMcpServers)->toBe(['inherited']);
});

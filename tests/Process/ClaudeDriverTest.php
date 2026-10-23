<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Meta;
use Prism\Acp\Ndjson\Json;

/*
 * THE CLAUDE DRIVER, AGAINST A PROCESS. tests/fixtures/fake-claude.php stands in for the CLI (it speaks the real
 * frames and records what reached it), and runs under the same supervisor a real CLI does: these tests cover the
 * argv, the environment, the turn lifecycle, the control requests, cancel, a failed turn, the permission hook end to
 * end, the private MCP config, and the stop -- everything between ClaudeToAcp and the CLI.
 */

final class ClaudeHarness
{
    /** @var list<array<string, mixed>> */
    public array $updates = [];

    /** @var list<TurnOutcome> */
    public array $turns = [];

    /** @var list<string> */
    public array $problems = [];

    public ?int $exitCode = null;

    public bool $exited = false;

    public ClaudeDriver $driver;

    public string $log;

    /** @var list<array<string, mixed>> */
    public array $permissionRequests = [];

    /**
     * @param  array<string, mixed>  $options
     * @param  (Closure(array<string, mixed>): PermissionOutcome)|null  $decide
     */
    public function __construct(public readonly Loop $loop, array $options = [], ?Closure $decide = null)
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-claude-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->log = $dir.DIRECTORY_SEPARATOR.'cli.log';
        $parent = [...getenv(), 'FAKE_CLAUDE_LOG' => $this->log, 'ANTHROPIC_API_KEY' => 'sk-ant-must-not-reach-the-cli'];
        $this->driver = new ClaudeDriver(new ClaudeDriverOptions(...[
            'cwd' => $dir,
            'binary' => PHP_BINARY,
            'binaryArgs' => [dirname(__DIR__).'/fixtures/fake-claude.php'],
            'parentEnv' => $parent,
            'allowEnv' => ['FAKE_CLAUDE_LOG'],
            ...$options,
        ]), new DriverEvents(
            onUpdate: function (array $update): void {
                $this->updates[] = $update;
            },
            onRequestPermission: $decide === null ? null : function (array $request) use ($decide): Deferred {
                $this->permissionRequests[] = $request;
                $answer = new Deferred($this->loop);
                $this->loop->delay(0.05, fn () => $answer->resolve($decide($request)));

                return $answer;
            },
            onTurnEnd: function (TurnOutcome $outcome): void {
                $this->turns[] = $outcome;
            },
            onProtocolError: function (string $problem): void {
                $this->problems[] = $problem;
            },
            onExit: function (?int $code): void {
                $this->exited = true;
                $this->exitCode = $code;
            },
        ), $loop);
    }

    /** Run the loop until $done says so, or fail after $seconds. */
    public function until(Closure $done, float $seconds = 20.0): void
    {
        $end = microtime(true) + $seconds;
        $this->loop->run(fn (): bool => $done() || microtime(true) > $end);
        if (! $done()) {
            throw new RuntimeException('timed out waiting');
        }
    }

    public function turn(string $text): TurnOutcome
    {
        $before = count($this->turns);
        $this->driver->prompt($text);
        $this->until(fn () => count($this->turns) > $before);

        return $this->turns[$before];
    }

    /** The text the agent said in the updates so far. */
    public function said(): string
    {
        $text = '';
        foreach ($this->updates as $update) {
            if (($update['sessionUpdate'] ?? null) === 'agent_message_chunk') {
                $text .= $update['content']['text'] ?? '';
            }
        }

        return $text;
    }

    /** @return list<mixed> what the CLI recorded: its argv and environment, then each stdin frame */
    public function cli(): array
    {
        return array_map(fn (string $l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents($this->log)))));
    }

    public function finish(): void
    {
        $this->driver->stop(1000)->await();
    }
}

describe('ClaudeDriver against a process', function () {
    it('runs the CLI with exactly the documented flags, its model, and none of the outranking credentials', function () {
        $h = new ClaudeHarness(new Loop, ['model' => 'haiku', 'permissionMode' => 'dontAsk', 'allowedTools' => ['Read', 'Grep'], 'disallowedTools' => ['Bash']]);
        $h->driver->start();
        $outcome = $h->turn('hello');
        $first = $h->cli()[0];

        expect($first['argv'])->toBe(['--print', '--output-format', 'stream-json', '--input-format', 'stream-json', '--verbose', '--include-partial-messages',
            '--permission-mode', 'dontAsk', '--model', 'haiku', '--allowed-tools', 'Read,Grep', '--disallowed-tools', 'Bash'])
            ->and($first['env'])->not->toContain('ANTHROPIC_API_KEY')
            ->and($h->driver->withheldCredentials())->toContain('ANTHROPIC_API_KEY')
            ->and($outcome->stopReason)->toBe('end_turn')
            ->and($h->said())->toBe('echo: hello');
        $h->finish();
    });

    it('publishes the CLI\'s own session id first, then the turn\'s updates, and ends the turn after its usage', function () {
        $h = new ClaudeHarness(new Loop);
        $h->driver->start();
        $h->turn('hi');
        $kinds = array_map(fn (array $u) => $u['sessionUpdate'], $h->updates);

        expect($h->updates[0]['_meta'][Meta::CLI_SESSION_ID] ?? null)->toBe('9adbc891-a558-48e0-93b0-460d6500ead8')
            ->and($h->driver->cliSessionId())->toBe('9adbc891-a558-48e0-93b0-460d6500ead8')
            ->and($kinds)->toContain('agent_message_chunk')
            ->and(end($kinds))->toBe('usage_update')
            ->and(end($h->updates)['used'])->toBe(10 + 100 + 5 + 7);
        $h->finish();
    });

    it('lists the account\'s models from the control initialize answer, cleaned, and switches the live session\'s model', function () {
        $h = new ClaudeHarness(new Loop);
        $h->driver->start();
        $state = $h->driver->modelState()->await();

        expect(Json::encode($state))->toBe('{"availableModels":[{"modelId":"default","name":"Default (recommended)","description":"Opus","resolvedModel":"claude-opus-5-5","isDefault":true},{"modelId":"haiku","name":"Haiku","description":"fast"}],"currentModelId":"default"}');

        $h->driver->setModel('haiku')->await();
        expect($h->driver->modelState()->await()['currentModelId'])->toBe('haiku')
            ->and(fn () => $h->driver->setModel('refuse-me')->await())->toThrow(RuntimeException::class, 'refused to switch model')
            ->and(fn () => $h->driver->setModel('--yolo'))->toThrow(InvalidArgumentException::class);
        $h->finish();
    });

    it('cancels a turn in flight and keeps the session: the next prompt runs as normal', function () {
        $h = new ClaudeHarness(new Loop);
        $h->driver->start();
        $h->driver->prompt('slow');
        $h->until(fn () => str_contains($h->said(), 'working'));
        $h->driver->cancel()->await();
        $h->until(fn () => count($h->turns) === 1);

        expect($h->turns[0]->stopReason)->toBe('cancelled')
            ->and($h->turns[0]->raw)->toBe('aborted_streaming')
            ->and($h->turn('again')->stopReason)->toBe('end_turn')
            ->and($h->driver->running())->toBeTrue();
        $h->finish();
    });

    it('says why a turn failed, and when the limit resets', function () {
        $h = new ClaudeHarness(new Loop);
        $h->driver->start();
        $outcome = $h->turn('fail');

        expect($outcome->stopReason)->toBeNull()
            ->and($outcome->isError)->toBeTrue()
            ->and(Json::encode($outcome->failure))->toBe('{"reason":"rate_limit","kind":"rate_limit","resetsAtMs":1800000000000}')
            ->and($h->turn('next')->failure)->toBeNull();
        $h->finish();
    });

    it('refuses a resume id that is not a CLI session id, before anything starts', function () {
        $h = new ClaudeHarness(new Loop, ['resumeSessionId' => '--dangerously-skip-permissions']);
        $h->driver->start();

        expect($h->exited)->toBeTrue()->and($h->exitCode)->toBe(1)
            ->and($h->problems)->toBe(['invalid resumeSessionId: rejected CLI session id shape'])
            ->and($h->driver->processTree())->toBeNull();
    });

    it('reports the exit of a CLI that dies mid-turn', function () {
        $h = new ClaudeHarness(new Loop);
        $h->driver->start();
        $h->driver->prompt('exit');
        $h->until(fn () => $h->exited);

        expect($h->exitCode)->toBe(3)->and($h->said())->toBe('about to go');
    });

    it('writes the MCP config privately, hands the CLI its path, and removes it when the CLI exits', function () {
        $h = new ClaudeHarness(new Loop, ['mcpServers' => [['name' => 'tools', 'command' => '/bin/tools', 'args' => ['--x'], 'env' => [['name' => 'TOKEN', 'value' => 'secret']]]]]);
        $h->driver->start();
        $h->turn('file');
        $argv = $h->cli()[0]['argv'];
        $path = $argv[array_search('--mcp-config', $argv, true) + 1];

        expect(json_decode($h->said(), true))->toBe(['mcpServers' => ['tools' => ['type' => 'stdio', 'command' => '/bin/tools', 'args' => ['--x'], 'env' => ['TOKEN' => 'secret']]]])
            ->and(is_file($path))->toBeTrue();
        $h->finish();
        expect(is_file($path))->toBeFalse()->and(is_dir(dirname($path)))->toBeFalse();
    });

    it('ends the whole tree on stop and says so', function () {
        $h = new ClaudeHarness(new Loop);
        $h->driver->start();
        $h->turn('hi');
        $pid = $h->driver->processTree()?->providerPid;
        $verdict = $h->driver->stop(2000)->await();

        expect($verdict->empty)->toBeTrue()->and($verdict->verified)->toBeTrue()
            ->and($h->exited)->toBeTrue()
            ->and($pid)->toBeInt();
    });
})->skip(PHP_OS_FAMILY !== 'Windows', 'containment exists on Windows only so far');

describe('ClaudeDriver: permission prompts, end to end through the hook', function () {
    it('runs a tool only on an explicit allow once, and tells the agent the client\'s reason', function () {
        $h = new ClaudeHarness(new Loop, ['permissionPrompts' => ['Bash']],
            fn (array $request) => PermissionOutcome::selected('allow_once', 'a person said yes'));
        $h->driver->start();
        $h->turn('hook Bash');
        $answer = json_decode($h->said(), true);

        expect($answer['exit'])->toBe(0)
            ->and($answer['stdout']['hookSpecificOutput'])->toBe(['hookEventName' => 'PreToolUse', 'permissionDecision' => 'allow', 'permissionDecisionReason' => 'a person said yes'])
            ->and($h->permissionRequests[0]['toolCall']['title'])->toBe('Bash: rm -rf /tmp/x')
            ->and($h->permissionRequests[0]['toolCall']['toolCallId'])->toBe('toolu_1');
        $h->finish();
    });

    it('denies on anything else: a rejection, a cancellation, an unknown option', function (PermissionOutcome $given, string $reason) {
        $h = new ClaudeHarness(new Loop, ['permissionPrompts' => ['Bash']], fn () => $given);
        $h->driver->start();
        $h->turn('hook Bash');
        $answer = json_decode($h->said(), true);

        expect($answer['exit'])->toBe(0)
            ->and($answer['stdout']['hookSpecificOutput']['permissionDecision'])->toBe('deny')
            ->and($answer['stdout']['hookSpecificOutput']['permissionDecisionReason'])->toBe($reason);
        $h->finish();
    })->with([
        'reject once' => [PermissionOutcome::selected('reject_once'), 'The person denied this tool call.'],
        'cancelled' => [PermissionOutcome::cancelled(), 'The request was cancelled.'],
        'unknown option' => [PermissionOutcome::selected('allow_always'), 'The person denied this tool call.'],
    ]);

    it('denies when nobody handles permission requests', function () {
        $h = new ClaudeHarness(new Loop, ['permissionPrompts' => ['Bash']]);
        $h->driver->start();
        $h->turn('hook Bash');

        expect(json_decode($h->said(), true)['stdout']['hookSpecificOutput']['permissionDecision'])->toBe('deny');
        $h->finish();
    });

    it('blocks the call when the hook cannot reach the driver: the channel is gone', function () {
        $h = new ClaudeHarness(new Loop, ['permissionPrompts' => ['Bash']], fn () => PermissionOutcome::selected('allow_once'));
        $h->driver->start();
        $h->turn('hi');
        $settings = $h->cli()[0]['argv'];
        $settingsPath = $settings[array_search('--settings', $settings, true) + 1];
        $command = json_decode((string) file_get_contents($settingsPath), true)['hooks']['PreToolUse'][0]['hooks'][0]['command'];
        // The hook run with no channel in its environment, as a hook outside this session would be.
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SystemRoot' => getenv('SystemRoot') ?: '']);
        fwrite($pipes[0], '{"tool_name":"Bash"}');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        expect(proc_close($process))->toBe(2)->and($out)->toBe('')->and($err)->toContain('no approval channel');
        $h->finish();
    });

    it('refuses a hook that does not present the session\'s token', function () {
        $h = new ClaudeHarness(new Loop, ['permissionPrompts' => ['Bash']], fn () => PermissionOutcome::selected('allow_once'));
        $h->driver->start();
        $h->turn('hi');
        $settings = $h->cli()[0]['argv'];
        $settingsPath = $settings[array_search('--settings', $settings, true) + 1];
        $command = json_decode((string) file_get_contents($settingsPath), true)['hooks']['PreToolUse'][0]['hooks'][0]['command'];
        // The channel the CLI was given, with a different token presented on it.
        $env = getenv();
        $reflection = new ReflectionProperty(ClaudeDriver::class, 'approvals');
        $server = $reflection->getValue($h->driver);
        $listen = new ReflectionProperty($server, 'server');
        $address = 'tcp://'.stream_socket_get_name($listen->getValue($server), false);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            [...$env, 'PRISM_ACP_APPROVAL_SOCKET' => $address, 'PRISM_ACP_APPROVAL_TOKEN' => str_repeat('0', 64)]);
        fwrite($pipes[0], '{"tool_name":"Bash"}');
        fclose($pipes[0]);
        // The driver answers on the loop: run it until the hook has its answer.
        $code = null;
        $h->until(function () use ($process, &$code) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $code = $status['exitcode'];
            }

            return $code !== null;
        });
        $err = stream_get_contents($pipes[2]);
        proc_close($process);

        expect($code)->toBe(2)->and($err)->toContain('refused (401)')->and($h->permissionRequests)->toBe([]);
        $h->finish();
    });
})->skip(PHP_OS_FAMILY !== 'Windows', 'containment exists on Windows only so far');

<?php

declare(strict_types=1);

use Prism\Acp\Ndjson\Json;

/*
 * THE AGENT, AS A CLIENT SEES IT: a separate process speaking ACP on its stdin and stdout. tests/fixtures/agent-host.php
 * serves AcpAgent with the Claude driver (fake CLI); this test is the client. On Windows its stdin is a pipe, so this
 * also covers the stdin relay. When the client closes the agent's stdin, the agent's sessions end with it.
 */

final class AcpClientProcess
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private int $nextId = 1;

    /** @var list<mixed> */
    public array $notifications = [];

    /** @param list<string>|null $command */
    public function __construct(string $cwd, ?array $command = null, private readonly ?Closure $permission = null)
    {
        $process = proc_open($command ?? [PHP_BINARY, dirname(__DIR__).'/fixtures/agent-host.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $cwd.DIRECTORY_SEPARATOR.'host.err', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            throw new RuntimeException('the agent host did not start');
        }
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /** Send a request and read until its response, keeping the notifications that arrive before it. */
    public function request(string $method, mixed $params): stdClass
    {
        $id = $this->nextId++;
        fwrite($this->pipes[0], Json::encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params])."\n");
        fflush($this->pipes[0]);
        while (($line = fgets($this->pipes[1])) !== false) {
            $message = Json::decode($line);
            if (($message->id ?? null) === $id && ! isset($message->method)) {
                return $message;
            }
            if (($message->method ?? null) === 'session/request_permission') {
                $result = $this->permission === null
                    ? ['outcome' => ['outcome' => 'cancelled']]
                    : ($this->permission)($message->params);
                fwrite($this->pipes[0], Json::encode(['jsonrpc' => '2.0', 'id' => $message->id, 'result' => $result])."\n");
                fflush($this->pipes[0]);
            }
            $this->notifications[] = $message;
        }
        throw new RuntimeException('the agent closed its output before answering '.$method);
    }

    /** Close the agent's stdin and wait for it to exit. */
    public function close(): int
    {
        fclose($this->pipes[0]);
        $rest = stream_get_contents($this->pipes[1]);
        fclose($this->pipes[1]);

        return proc_close($this->process);
    }
}

describe('the agent over stdio', function () {
    it('initializes, opens a session, runs a turn, and ends its sessions when the client leaves', function () {
        $cwd = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-stdio-'.bin2hex(random_bytes(4));
        mkdir($cwd);
        $client = new AcpClientProcess($cwd);

        $init = $client->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new stdClass]);
        expect($init->result->protocolVersion)->toBe(1)
            ->and($init->result->agentCapabilities->promptCapabilities->image)->toBeTrue()
            ->and($init->result->_meta->{'particle.academy/driver_capabilities'}->cancel)->toBeTrue();

        $session = $client->request('session/new', ['cwd' => $cwd, 'mcpServers' => []]);
        $sessionId = $session->result->sessionId;
        expect($sessionId)->toStartWith('sess_')
            ->and(array_column($session->result->models->availableModels, 'modelId'))->toBe(['default', 'haiku']);

        $answer = $client->request('session/prompt', ['sessionId' => $sessionId, 'prompt' => [['type' => 'text', 'text' => 'hello']]]);
        $said = '';
        foreach ($client->notifications as $note) {
            if (($note->method ?? null) === 'session/update' && $note->params->update->sessionUpdate === 'agent_message_chunk') {
                $said .= $note->params->update->content->text;
            }
        }
        expect($answer->result->stopReason)->toBe('end_turn')
            ->and($said)->toBe('echo: hello')
            ->and($client->notifications[0]->params->sessionId)->toBe($sessionId);

        $start = microtime(true);
        expect($client->close())->toBe(0)
            ->and(microtime(true) - $start)->toBeLessThan(10.0);
    });

    it('the shipped CLI asks before every tool and carries deny and allow reasons through the actual hook', function () {
        $cwd = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-cli-'.bin2hex(random_bytes(4));
        mkdir($cwd);
        $fixture = dirname(__DIR__).'/fixtures/fake-claude.php';
        file_put_contents($cwd.'/fake.cjs', 'const {spawn} = require("node:child_process"); const child = spawn('
            .json_encode(PHP_BINARY).', ['.json_encode($fixture).', ...process.argv.slice(2)], {stdio:"inherit"}); child.on("error", () => process.exit(1)); child.on("exit", code => process.exit(code ?? 1));');
        file_put_contents($cwd.'/claude.cmd', '@ECHO off'."\n".'"%_prog%" "%dp0%\\fake.cjs" %*'."\n");
        $decisions = 0;
        $client = new AcpClientProcess($cwd,
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/prism-acp', '--cwd='.$cwd, '--claude-binary='.$cwd.'/claude.cmd'],
            function (stdClass $request) use (&$decisions): array {
                $decisions++;

                return ['outcome' => ['outcome' => 'selected', 'optionId' => $decisions === 1 ? 'reject_once' : 'allow_once'],
                    '_meta' => ['particle.academy/permission_reason' => $decisions === 1 ? 'Denied by the test client' : 'Reviewed by the test client']];
            });
        try {
            $init = $client->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new stdClass]);
            expect($init->result->_meta->{'particle.academy/driver_capabilities'}->permissionRequests)->toBeTrue();
            $session = $client->request('session/new', ['cwd' => $cwd, 'mcpServers' => []])->result->sessionId;
            foreach ([['Bash', 'deny', 'Denied'], ['FutureTool', 'allow', 'Reviewed']] as [$tool, $decision, $reason]) {
                $before = count($client->notifications);
                $answer = $client->request('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'hook '.$tool]]]);
                $said = '';
                foreach (array_slice($client->notifications, $before) as $note) {
                    if (($note->method ?? null) === 'session/update' && ($note->params->update->sessionUpdate ?? null) === 'agent_message_chunk') {
                        $said .= $note->params->update->content->text;
                    }
                }
                $hook = json_decode($said, true, flags: JSON_THROW_ON_ERROR);
                expect($answer->result->stopReason)->toBe('end_turn')
                    ->and($hook['exit'])->toBe(0)
                    ->and($hook['stdout']['hookSpecificOutput']['permissionDecision'])->toBe($decision)
                    ->and($hook['stdout']['hookSpecificOutput']['permissionDecisionReason'])->toStartWith($reason);
            }
            expect($decisions)->toBe(2);
        } finally {
            expect($client->close())->toBe(0);
        }
    })->skip(! array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')), fn (string $path) => is_file(rtrim($path, '\\/').'/node.exe')), 'node for the fake npm CLI');
    it('runs the shipped Codex CLI choice with replay, native approvals and provider-exit errors', function () {
        $cwd = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-codex-cli-'.bin2hex(random_bytes(4));
        mkdir($cwd);
        $fixture = dirname(__DIR__).'/fixtures/fake-codex.php';
        file_put_contents($cwd.'/fake.cjs', 'const {spawn} = require("node:child_process"); const child = spawn('
            .json_encode(PHP_BINARY).', ['.json_encode($fixture).', ...process.argv.slice(2)], {stdio:"inherit"}); child.on("error", () => process.exit(1)); child.on("exit", code => process.exit(code ?? 1));');
        file_put_contents($cwd.'/codex.cmd', '@ECHO off'."\n".'"%_prog%" "%dp0%\\fake.cjs" %*'."\n");
        $decisions = 0;
        $client = new AcpClientProcess($cwd,
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/prism-acp', '--provider=codex', '--cwd='.$cwd, '--codex-binary='.$cwd.'/codex.cmd'],
            function () use (&$decisions): array {
                return ['outcome' => ['outcome' => 'selected', 'optionId' => ++$decisions === 1 ? 'codex-decline' : 'codex-accept']];
            });
        try {
            $init = $client->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new stdClass]);
            expect($init->result->_meta->{'particle.academy/driver_capabilities'}->transcriptReplay)->toBeTrue();
            $session = $client->request('session/load', ['sessionId' => 'thread-existing', 'cwd' => $cwd, 'mcpServers' => [],
                'approvalPolicy' => 'never', 'disabledMcpServers' => []]);
            expect($session->result->models->currentModelId)->toBe('fixture-model')
                ->and(array_column($session->result->models->availableModels, 'modelId'))->toBe(['fixture-model', 'second']);
            foreach (['decline', 'accept'] as $native) {
                $before = count($client->notifications);
                $response = $client->request('session/prompt', ['sessionId' => 'thread-existing', 'prompt' => [['type' => 'text', 'text' => 'approval']]]);
                $said = '';
                foreach (array_slice($client->notifications, $before) as $note) {
                    if (($note->method ?? null) === 'session/update' && ($note->params->update->sessionUpdate ?? null) === 'agent_message_chunk') {
                        $said .= $note->params->update->content->text;
                    }
                }
                expect($response->result->stopReason)->toBe('end_turn')->and($said)->toBe('decision: '.$native);
            }
            expect($decisions)->toBe(2);
            $exit = $client->request('session/prompt', ['sessionId' => 'thread-existing', 'prompt' => [['type' => 'text', 'text' => 'exit']]]);
            expect($exit)->toHaveProperty('error');
        } finally {
            expect($client->close())->toBe(0);
        }
    })->skip(! array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')), fn (string $path) => is_file(rtrim($path, '\\/').'/node.exe')), 'node for the fake npm CLI');
})->skip(PHP_OS_FAMILY !== 'Windows', 'containment exists on Windows only so far');

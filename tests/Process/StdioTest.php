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

    public function __construct(string $cwd)
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/fixtures/agent-host.php'],
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
})->skip(PHP_OS_FAMILY !== 'Windows', 'containment exists on Windows only so far');

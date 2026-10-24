<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Agent\AgentDriver;
use Prism\Acp\Agent\CancelsTurns;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\OwnsProcessTree;
use Prism\Acp\Agent\ReportsModels;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Mcp\McpServers;
use Prism\Acp\Meta;
use Prism\Acp\Models\Models;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\StopVerdict;
use Prism\Acp\Prompt\Prompt;
use Prism\Acp\SessionId;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\PrivateDirectory;

/** Stateful App Server driver, ported from the published TS0.25.0 driver and checked by its protocol oracle. */
final class CodexDriver implements AgentDriver, CancelsTurns, OwnsProcessTree, ReportsModels
{
    use CodexItems;
    use CodexNotifications;
    use CodexPermissions;

    public const CAPABILITIES = ['permissionRequests' => true, 'transcriptReplay' => true,
        'mcpCapabilities' => ['http' => true, 'sse' => false], 'promptBlocks' => ['text', 'image', 'resource-text'], 'cancel' => true];

    private const APPROVAL_METHODS = ['item/commandExecution/requestApproval', 'item/fileChange/requestApproval',
        'item/permissions/requestApproval', 'applyPatchApproval', 'execCommandApproval'];

    private const KNOWN_NOTIFICATIONS = ['model/rerouted', 'thread/started', 'thread/status/changed', 'turn/started', 'turn/completed',
        'item/started', 'item/completed', 'item/commandExecution/outputDelta', 'serverRequest/resolved', 'item/agentMessage/delta',
        'item/reasoning/summaryTextDelta', 'item/reasoning/textDelta', 'turn/plan/updated', 'turn/plan/delta', 'thread/tokenUsage/updated',
        'account/rateLimits/updated', 'contextCompacted', 'thread/compaction/started', 'collabAgentSpawnBegin', 'collabAgentSpawnEnd',
        'collabAgentMessage', 'collabAgentToolCall', 'collabAgentToolResult'];

    private readonly Loop $loop;

    private readonly ?\stdClass $mcpConfig;

    /** @var array<string,string> */
    private readonly array $mcpEnv;

    /** @var list<string> */
    private readonly array $disabledMcpServers;

    /** @var \Closure(CodexTransportOptions, CodexTransportHandlers):CodexTransport */
    private readonly \Closure $transportFactory;

    private ?CodexTransport $transport = null;

    private ?JsonRpcPeer $peer = null;

    /** @var Deferred<mixed> */
    private Deferred $ready;

    private bool $started = false;

    private bool $closed = false;

    private bool $turnInFlight = false;

    private bool $sessionUpdateSent = false;

    private ?string $threadId = null;

    private ?string $activeTurn = null;

    private ?string $currentModelId = null;

    private ?string $modelOverride = null;

    /** @var list<array<string,mixed>>|null */
    private ?array $queuedPrompt = null;

    /** @var array<string,true> */
    private array $completedTurns = [];

    /** @var array<string, \stdClass> */
    private array $items = [];

    /** @var array<string,true> */
    private array $activeItemIds = [];

    /** @var array<string,true> */
    private array $messageDeltas = [];

    /** @var array<string,true> */
    private array $reasoningDeltas = [];

    /** @var array<string,array{text:string,truncated:bool}> */
    private array $commandOutputs = [];

    /** @var array<string,int> */
    private array $commandOutputTimers = [];

    /** @var array<string,array{cancel:\Closure():void,resolved:\Closure():void}> */
    private array $pendingApprovals = [];

    private int $itemSequence = 0;

    private int $approvalSequence = 0;

    private int $imageSequence = 0;

    private ?string $imageDirectory = null;

    /** @var list<string> */
    private array $imageFiles = [];

    /** @var list<string> */
    private array $withheld = [];

    /** @var Deferred<mixed>|null */
    private ?Deferred $pendingCancel = null;

    private ?int $turnDeadlineTimer = null;

    private float $turnDeadlineExpiresAt = 0;

    private float $turnDeadlineRemainingMs = 0;

    /** @param (\Closure(CodexTransportOptions,CodexTransportHandlers):CodexTransport)|null $transportFactory */
    public function __construct(private readonly CodexDriverOptions $options,
        private readonly DriverEvents $events = new DriverEvents,
        ?\Closure $transportFactory = null, ?Loop $loop = null)
    {
        $this->loop = $loop ?? Loop::get();
        $this->transportFactory = $transportFactory ?? fn (CodexTransportOptions $options, CodexTransportHandlers $handlers): CodexTransport => new StdioCodexTransport($options, $handlers, $this->loop);
        $this->ready = $this->resolved();
        if ($options->model !== null) {
            Meta::assertModel($options->model);
        }
        if ($options->approvalPolicy !== null && ! in_array($options->approvalPolicy, ['untrusted', 'on-request', 'never'], true)) {
            throw new \InvalidArgumentException('approvalPolicy must be one of: untrusted, on-request, never');
        }
        if ($options->sandbox !== null && ! in_array($options->sandbox, ['read-only', 'workspace-write', 'danger-full-access'], true)) {
            throw new \InvalidArgumentException('sandbox must be one of: read-only, workspace-write, danger-full-access');
        }
        if (! is_finite($options->turnInactivityTimeoutMs) || $options->turnInactivityTimeoutMs <= 0) {
            throw new \InvalidArgumentException('turnInactivityTimeoutMs must be a positive finite number');
        }
        // Revalidate trusted host values too, before asynchronous startup. Copy into value-only arrays.
        $servers = McpServers::read(Json::decode(Json::encode($options->mcpServers)));
        $this->disabledMcpServers = McpServers::readDisabled($options->disabledMcpServers, $servers);
        $mcp = McpServers::codexSessionConfig($servers, $options->mcpToolApproval);
        $this->mcpConfig = $mcp['config'];
        $this->mcpEnv = $mcp['env'];
    }

    /** @return Deferred<mixed> */
    public function ready(): Deferred
    {
        return $this->ready;
    }

    public function cliSessionId(): ?string
    {
        return $this->threadId;
    }

    /** @return list<string> */
    public function withheldCredentials(): array
    {
        return $this->withheld;
    }

    public function processTree(): ?ProcessTree
    {
        return $this->transport?->processTree();
    }

    public function start(): void
    {
        if ($this->started) {
            throw new \LogicException('driver already started');
        }
        $this->started = true;
        $resume = $this->options->resumeSessionId;
        if ($resume !== null && ! SessionId::isValidCliSessionId($resume)) {
            $this->events->onProtocolError?->__invoke('invalid resumeSessionId: rejected CLI session id shape', null);
            $this->closed = true;
            $this->events->onExit?->__invoke(1, null);

            return;
        }
        if ($resume !== null && preg_match('/\Asess_\d+_\d+\z/', $resume) === 1) {
            $this->events->onProtocolError?->__invoke('refusing an ACP-minted session id; resume with the Codex id carried as '.Meta::CLI_SESSION_ID, null);
            $this->events->onExit?->__invoke(1, null);

            return;
        }
        $this->ready = Deferred::async(function (): void {
            try {
                $this->connect();
            } catch (\Throwable $cause) {
                $this->events->onProtocolError?->__invoke(CodexPayload::safeError('Codex app-server startup failed', $cause), null);
                $this->finishTurn(new TurnOutcome(null, true, 'app-server startup failed'));
                $this->shutdown();
            }
        }, $this->loop);
    }

    private function connect(): void
    {
        $built = ChildEnv::build($this->options->parentEnv ?? getenv(),
            ['CODEX_HOME', 'CODEX_CONFIG_DIR', 'XDG_CONFIG_HOME', ...$this->options->allowEnv], $this->mcpEnv);
        $this->withheld = $built->withheld;
        $handlers = new CodexTransportHandlers(onFrame: fn (mixed $frame) => $this->receive($frame),
            onStderr: function (string $line): void {
                $this->events->onStderr?->__invoke($line);
            },
            onProblem: function (string $problem, ?bool $recoverable): void {
                $this->events->onProtocolError?->__invoke($problem, $recoverable);
            },
            onExit: fn (?int $code, ?string $signal) => $this->onExit($code, $signal));
        $transport = ($this->transportFactory)(new CodexTransportOptions($this->options->cwd, $built->env, $this->options->binary, $this->options->binaryArgs), $handlers);
        $this->transport = $transport;
        $this->peer = new JsonRpcPeer(send: fn (mixed $frame) => $transport->send($frame),
            onProtocolError: fn (string $problem, mixed $frame) => $this->unmapped($problem, $frame), loop: $this->loop);
        $transport->start()->await();
        $peer = $this->requirePeer();
        // Intentionally mirrors the pinned reference; G-100 owns package-derived identity in all entry points.
        $peer->request('initialize', ['clientInfo' => ['name' => '@particle-academy/prism-acp', 'version' => '0.4.1'], 'capabilities' => new \stdClass])->await();
        $peer->notify('initialized');
        $mcpConfig = $this->mcpConfig;
        $bindings = array_keys($this->mcpEnv);
        if ($this->disabledMcpServers !== [] || $bindings !== []) {
            $response = $peer->request('config/read', ['cwd' => $this->options->cwd, 'includeLayers' => false])->await();
            $effective = Js::get($response, 'config');
            if (! $effective instanceof \stdClass) {
                throw new \RuntimeException('Codex did not return effective MCP configuration');
            }
            if ($this->disabledMcpServers !== []) {
                $inherited = $effective->mcp_servers ?? new \stdClass;
                // An explicit null is invalid, unlike an absent dictionary.
                if (! $inherited instanceof \stdClass || (property_exists($effective, 'mcp_servers') && $effective->mcp_servers === null)) {
                    throw new \RuntimeException('Codex returned invalid inherited MCP configuration');
                }
                $mcpConfig = McpServers::codexDisabledConfig($mcpConfig, $this->disabledMcpServers, $inherited);
            }
            if ($bindings !== []) {
                $mcpConfig = McpServers::codexShellPolicy($mcpConfig ?? new \stdClass, $bindings, Js::get($effective, 'shell_environment_policy'));
            }
        }
        $params = ['cwd' => $this->options->cwd];
        if ($mcpConfig !== null) {
            $params['config'] = $mcpConfig;
        }
        if ($this->options->sandbox !== null) {
            $params['sandbox'] = $this->options->sandbox;
        }
        if ($this->options->model !== null) {
            $params['model'] = $this->options->model;
        }
        if ($this->options->resumeSessionId === null) {
            $params['approvalPolicy'] = $this->options->approvalPolicy ?? 'on-request';
            $result = $peer->request('thread/start', $params)->await();
            $id = Js::string(Js::get($result, 'thread'), 'id');
            if ($id === null || $id === '') {
                throw new \RuntimeException('thread/start response did not contain thread.id');
            }
        } else {
            $params['threadId'] = $this->options->resumeSessionId;
            $params['excludeTurns'] = true;
            if ($this->options->approvalPolicy !== null) {
                $params['approvalPolicy'] = $this->options->approvalPolicy;
            }
            $result = $peer->request('thread/resume', $params)->await();
            $id = Js::string(Js::get($result, 'thread'), 'id');
            if ($id === null || $id === '' || $id !== $this->options->resumeSessionId) {
                throw new \RuntimeException('thread/resume did not return the requested Codex thread id');
            }
        }
        $this->setThreadId($id);
        $this->reportModel(Js::string($result, 'model'));
        if ($this->options->resumeSessionId !== null) {
            $this->replayHistory();
        }
        try {
            $this->emitRateLimit($peer->request('account/rateLimits/read', new \stdClass)->await());
        } catch (\Throwable $cause) {
            $this->unmapped(CodexPayload::safeError('account/rateLimits/read failed', $cause), (object) ['method' => 'account/rateLimits/read']);
        }
        $queued = $this->queuedPrompt;
        $this->queuedPrompt = null;
        if ($queued !== null) {
            $this->startTurn($queued);
        }
    }

    /** @param string|list<\stdClass> $content */
    public function prompt(string|array $content): void
    {
        if (! $this->started || $this->closed) {
            throw new \LogicException('driver is not started, or has exited');
        }
        if ($this->turnInFlight || $this->queuedPrompt !== null) {
            throw new \LogicException('Codex turn already in flight');
        }
        $input = [];
        foreach (Prompt::blocks($content, self::CAPABILITIES['promptBlocks']) as $block) {
            if ($block->type === 'text') {
                $input[] = ['type' => 'text', 'text' => $block->text];
            } elseif ($block->type === 'image') {
                $this->imageDirectory ??= PrivateDirectory::create('prism-acp-codex-');
                $mime = Js::string($block, 'mimeType');
                $data = Js::string($block, 'data');
                if ($mime === null || $data === null) {
                    throw new \LogicException('checked image prompt lost its fields');
                }
                $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'img';
                $path = $this->imageDirectory.DIRECTORY_SEPARATOR.'prompt-'.(++$this->imageSequence).'.'.$extension;
                $bytes = base64_decode($data, false);
                if (file_put_contents($path, $bytes, LOCK_EX) === false || ! chmod($path, 0600)) {
                    throw new \RuntimeException('failed to create a private Codex prompt image');
                }
                $this->imageFiles[] = $path;
                $input[] = ['type' => 'localImage', 'path' => $path];
            } else {
                $resource = Js::get($block, 'resource');
                if (! $resource instanceof \stdClass) {
                    throw new \LogicException('checked resource prompt lost its fields');
                }
                $input[] = ['type' => 'text', 'text' => Prompt::fileAsText($resource)];
            }
        }
        $this->turnInFlight = true;
        if ($this->peer === null || $this->threadId === null) {
            $this->queuedPrompt = $input;

            return;
        }
        $this->startTurn($input);
    }

    /** @param list<array<string,mixed>> $input */
    private function startTurn(array $input): void
    {
        if ($this->threadId === null) {
            $this->queuedPrompt = $input;

            return;
        }
        $this->beginTurnDeadline();
        $params = ['threadId' => $this->threadId, 'cwd' => $this->options->cwd, 'input' => $input];
        if ($this->modelOverride !== null) {
            $params['model'] = $this->modelOverride;
        }
        $this->requirePeer()->request('turn/start', $params)->then(function (mixed $value): void {
            $id = Js::string(Js::get($value, 'turn'), 'id');
            if ($id !== null && ! isset($this->completedTurns[$id])) {
                $this->activeTurn = $id;
                $this->touchTurnDeadline();
                $this->sendPendingCancel($id);
            }
        }, function (\Throwable $cause): void {
            $this->events->onProtocolError?->__invoke(CodexPayload::safeError('Codex turn/start failed', $cause), null);
            $this->finishTurn(new TurnOutcome(null, true, 'turn/start failed'));
        });
    }

    public function cancel(): Deferred
    {
        if ($this->closed || ! $this->turnInFlight) {
            return $this->resolved();
        }
        if ($this->queuedPrompt !== null) {
            $this->queuedPrompt = null;
            $this->finishTurn(new TurnOutcome('cancelled', false, 'cancelled before start'));

            return $this->resolved();
        }
        if ($this->activeTurn !== null) {
            return $this->interrupt($this->activeTurn);
        }

        return $this->pendingCancel ??= new Deferred($this->loop);
    }

    /** @return Deferred<mixed> */
    private function interrupt(string $turnId): Deferred
    {
        return Deferred::async(function () use ($turnId): void {
            if ($this->threadId === null) {
                return;
            }
            try {
                $this->requirePeer()->request('turn/interrupt', ['threadId' => $this->threadId, 'turnId' => $turnId])->await();
            } catch (\Throwable $cause) {
                throw new \RuntimeException(CodexPayload::safeError('Codex turn/interrupt failed', $cause));
            }
        }, $this->loop);
    }

    private function sendPendingCancel(string $turnId): void
    {
        $pending = $this->pendingCancel;
        $this->pendingCancel = null;
        if ($pending !== null) {
            $this->interrupt($turnId)->then(fn () => $pending->resolve(null), fn (\Throwable $cause) => $pending->reject($cause));
        }
    }

    private function releasePendingCancel(): void
    {
        $pending = $this->pendingCancel;
        $this->pendingCancel = null;
        $pending?->resolve(null);
    }

    public function endInput(): void {}

    public function kill(): void
    {
        $this->shutdown();
    }

    public function stop(int $graceMs = 5000): Deferred
    {
        $verdict = $this->transport?->stop($graceMs) ?? $this->resolved(new StopVerdict(true, true, 0, true));
        $this->shutdown(false);

        return $verdict;
    }

    public function modelState(): Deferred
    {
        return Deferred::async(function (): array {
            if (! $this->started) {
                throw new \LogicException('driver is not started');
            }
            $this->ready->await();
            $models = [];
            $cursor = null;
            // Mirrors the pinned reference. G-99 tracks the catalog paging limitation across entry points.
            for ($page = 0; $page < 20; $page++) {
                $result = $this->requirePeer()->request('model/list', ['cursor' => $cursor, 'includeHidden' => false])->await();
                array_push($models, ...Models::codexModelsOf($result));
                $next = Js::string($result, 'nextCursor');
                $cursor = $next === null || $next === '' ? null : $next;
                if ($cursor === null) {
                    break;
                }
            }
            $current = $this->currentModelId;
            if ($current === null) {
                foreach ($models as $model) {
                    if (($model['isDefault'] ?? false) === true) {
                        $current = $model['modelId'];
                        break;
                    }
                }
            }

            return ['availableModels' => $models, 'currentModelId' => $current];
        }, $this->loop);
    }

    public function setModel(string $modelId): Deferred
    {
        return Deferred::async(function () use ($modelId): void {
            $this->modelOverride = Meta::assertModel($modelId);
            $this->currentModelId = $this->modelOverride;
        }, $this->loop);
    }

    private function replayHistory(): void
    {
        $cursor = null;
        $seen = [];
        do {
            if (count($seen) >= 10000) {
                $this->unmapped('thread/turns/list exceeded the 10000-page safety limit', (object) ['method' => 'thread/turns/list']);

                return;
            }
            $params = ['threadId' => $this->threadId, 'sortDirection' => 'asc', 'itemsView' => 'notLoaded', 'limit' => 100];
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }
            $response = $this->requirePeer()->request('thread/turns/list', $params)->await();
            $turns = Js::get($response, 'data');
            if (! is_array($turns)) {
                $this->unmapped('thread/turns/list response omitted data array', $response);

                return;
            }
            foreach ($turns as $turn) {
                $id = Js::string($turn, 'id');
                if ($id === null) {
                    $this->unmapped('thread/turns/list entry omitted id', $turn);

                    continue;
                }
                $this->replayTurnItems($id);
            }
            $cursor = $this->nextCursor($response, 'thread/turns/list', $seen);
        } while ($cursor !== null);
    }

    private function replayTurnItems(string $turnId): void
    {
        $cursor = null;
        $seen = [];
        do {
            if (count($seen) >= 10000) {
                $this->unmapped('thread/items/list exceeded the 10000-page safety limit', (object) ['method' => 'thread/items/list']);

                return;
            }
            $params = ['threadId' => $this->threadId, 'turnId' => $turnId, 'sortDirection' => 'asc', 'limit' => 100];
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }
            $response = $this->requirePeer()->request('thread/items/list', $params)->await();
            $entries = Js::get($response, 'data');
            if (! is_array($entries)) {
                $this->unmapped('thread/items/list response omitted data array', $response);

                return;
            }
            foreach ($entries as $entry) {
                if (! $entry instanceof \stdClass) {
                    $this->unmapped('thread/items/list entry was not an object', $entry);

                    continue;
                }
                $item = Js::get($entry, 'item');
                $this->mapItem($item instanceof \stdClass ? $item : $entry, true);
            }
            $cursor = $this->nextCursor($response, 'thread/items/list', $seen);
        } while ($cursor !== null);
    }

    /** @param array<string,true> $seen */
    private function nextCursor(mixed $response, string $method, array &$seen): ?string
    {
        $next = Js::get($response, 'nextCursor');
        if ($next === null) {
            return null;
        }
        if (is_string($next) && $next !== '') {
            if (isset($seen['cursor:'.$next])) {
                $this->unmapped($method.' repeated a page cursor', (object) ['method' => $method]);

                return null;
            }
            $seen['cursor:'.$next] = true;

            return $next;
        }
        $this->unmapped($method.' nextCursor expected a non-empty string or null', $response);

        return null;
    }

    private function setThreadId(mixed $id): void
    {
        if (! is_string($id) || ! SessionId::isValidCliSessionId($id)) {
            $this->events->onProtocolError?->__invoke('invalid thread.id: rejected Codex CLI session id shape', null);

            return;
        }
        if ($this->threadId !== null && $this->threadId !== $id) {
            $this->unmapped('Codex thread id changed during a session', (object) ['previous' => $this->threadId, 'current' => $id]);

            return;
        }
        $this->threadId = $id;
        if ($this->sessionUpdateSent) {
            return;
        }
        $this->sessionUpdateSent = true;
        $this->emit(Meta::with(['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'Codex session started']], [Meta::CLI_SESSION_ID => $id]));
    }

    private function reportModel(?string $model): void
    {
        if ($model === null || $model === '') {
            return;
        }
        $this->currentModelId = $model;
        $this->emit(Meta::with(['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => 'model']], [Meta::MODEL => $model]));
    }

    /** @param array<string,mixed> $update */
    private function emit(array $update): void
    {
        if (! $this->closed) {
            $this->events->onUpdate?->__invoke($update);
        }
    }

    private function unmapped(string $reason, mixed $frame): void
    {
        $this->emit(Meta::with(['sessionUpdate' => 'notice', 'notice' => ['level' => 'debug', 'message' => $reason]],
            [Meta::UNMAPPED_FRAME => ['reason' => $reason, 'frame' => CodexPayload::stripPrivateIdentifiers($frame)]]));
    }

    /** @param array<string,mixed> $frame */
    private function sendServerReply(array $frame): void
    {
        try {
            $this->transport?->send($frame);
        } catch (\Throwable) {
            $this->events->onProtocolError?->__invoke('could not answer Codex server request (transport unavailable)', null);
        }
    }

    private function finishTurn(TurnOutcome $outcome, ?string $turnId = null): void
    {
        $turnId ??= $this->activeTurn;
        if ($turnId !== null && isset($this->completedTurns[$turnId])) {
            return;
        }
        if ($turnId !== null) {
            $this->completedTurns[$turnId] = true;
            if (count($this->completedTurns) > 64) {
                unset($this->completedTurns[array_key_first($this->completedTurns)]);
            }
        }
        if (! $this->turnInFlight && $this->activeTurn === null) {
            return;
        }
        $this->clearTurnDeadline();
        $this->releasePendingCancel();
        $this->activeTurn = null;
        $this->turnInFlight = false;
        foreach (array_values($this->pendingApprovals) as $pending) {
            ($pending['resolved'])();
        }
        $this->items = [];
        $this->activeItemIds = [];
        $this->messageDeltas = [];
        $this->reasoningDeltas = [];
        $this->clearCommandOutputs();
        $this->events->onTurnEnd?->__invoke($outcome);
    }

    private function shutdown(bool $closeTransport = true): void
    {
        if ($this->closed) {
            return;
        } $this->closed = true;
        $this->clearTurnDeadline();
        $this->releasePendingCancel();
        $this->removeImages();
        $this->clearCommandOutputs();
        foreach (array_values($this->pendingApprovals) as $pending) {
            ($pending['cancel'])();
        }
        if ($closeTransport) {
            $this->transport?->close();
        }
        $this->peer?->fail(new \RuntimeException('Codex driver stopped'));
        $this->events->onExit?->__invoke(null, 'SIGTERM');
    }

    private function onExit(?int $code, ?string $signal): void
    {
        if ($this->closed) {
            return;
        } $this->closed = true;
        $this->clearTurnDeadline();
        $this->releasePendingCancel();
        $this->removeImages();
        $this->clearCommandOutputs();
        foreach (array_values($this->pendingApprovals) as $pending) {
            ($pending['cancel'])();
        }
        $this->peer?->fail(new \RuntimeException('Codex app-server exited'));
        $this->events->onExit?->__invoke($code, $signal);
    }

    private function removeImages(): void
    {
        $directory = $this->imageDirectory;
        $this->imageDirectory = null;
        if ($directory !== null && ! PrivateDirectory::remove($directory, ...$this->imageFiles)) {
            $this->events->onProtocolError?->__invoke('failed to remove the temporary Codex prompt-image directory', true);
        }
        $this->imageFiles = [];
    }

    private function requirePeer(): JsonRpcPeer
    {
        return $this->peer ?? throw new \LogicException('Codex App Server has not started');
    }

    /** @return Deferred<mixed> */
    private function resolved(mixed $value = null): Deferred
    {
        $done = new Deferred($this->loop);
        $done->resolve($value);

        return $done;
    }

    private function noteTurnActivity(?string $method, mixed $value): void
    {
        if (! $this->turnInFlight) {
            return;
        }
        $turnId = Js::string($value, 'turnId') ?? Js::string(Js::get($value, 'item'), 'turnId') ?? Js::string(Js::get($value, 'turn'), 'id');
        if ($turnId !== null) {
            if ($this->activeTurn === null || $turnId === $this->activeTurn) {
                $this->touchTurnDeadline();
            }

            return;
        }
        if ($method === 'thread/status/changed' || ($method !== null && (str_starts_with($method, 'turn/') || str_starts_with($method, 'item/')))) {
            $this->touchTurnDeadline();
        }
    }

    private function beginTurnDeadline(): void
    {
        $this->turnDeadlineRemainingMs = $this->options->turnInactivityTimeoutMs;
        $this->scheduleTurnDeadline();
    }

    private function touchTurnDeadline(): void
    {
        if ($this->turnInFlight && ! $this->hasOutstandingWork()) {
            $this->turnDeadlineRemainingMs = $this->options->turnInactivityTimeoutMs;
            $this->scheduleTurnDeadline();
        }
    }

    private function pauseTurnDeadline(): void
    {
        if ($this->turnDeadlineTimer === null) {
            return;
        }
        $this->turnDeadlineRemainingMs = max(0, $this->turnDeadlineExpiresAt - microtime(true) * 1000);
        $this->loop->cancel($this->turnDeadlineTimer);
        $this->turnDeadlineTimer = null;
    }

    private function resumeTurnDeadlineIfReady(bool $reset = false): void
    {
        if (! $this->turnInFlight || $this->hasOutstandingWork()) {
            return;
        }
        if ($reset) {
            $this->turnDeadlineRemainingMs = $this->options->turnInactivityTimeoutMs;
        } $this->scheduleTurnDeadline();
    }

    private function scheduleTurnDeadline(): void
    {
        if (! $this->turnInFlight || $this->hasOutstandingWork() || $this->closed) {
            return;
        }
        if ($this->turnDeadlineTimer !== null) {
            $this->loop->cancel($this->turnDeadlineTimer);
        }
        $this->turnDeadlineExpiresAt = microtime(true) * 1000 + $this->turnDeadlineRemainingMs;
        $this->turnDeadlineTimer = $this->loop->delay($this->turnDeadlineRemainingMs / 1000, function (): void {
            $this->turnDeadlineTimer = null;
            if (! $this->turnInFlight || $this->hasOutstandingWork()) {
                return;
            }
            $message = 'Codex turn inactivity deadline expired after '.Js::numberToString($this->options->turnInactivityTimeoutMs).' ms.';
            $this->emit(['sessionUpdate' => 'notice', 'notice' => ['level' => 'warning', 'message' => $message]]);
            $this->unmapped('Codex turn deadline expired', (object) ['method' => 'turn deadline', 'timeoutMs' => $this->options->turnInactivityTimeoutMs]);
            $this->finishTurn(new TurnOutcome(null, true, 'turn deadline'));
        });
    }

    private function clearTurnDeadline(): void
    {
        if ($this->turnDeadlineTimer !== null) {
            $this->loop->cancel($this->turnDeadlineTimer);
        }
        $this->turnDeadlineTimer = null;
        $this->turnDeadlineRemainingMs = 0;
        $this->turnDeadlineExpiresAt = 0;
    }

    private function hasOutstandingWork(): bool
    {
        return $this->pendingApprovals !== [] || $this->activeItemIds !== [];
    }
}

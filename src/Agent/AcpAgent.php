<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\JsonRpc\RpcError;
use Prism\Acp\Mcp\McpServers;
use Prism\Acp\Meta;
use Prism\Acp\Models\Models;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Plan\PlanProjection;
use Prism\Acp\Prompt\Prompt;
use Prism\Acp\Prompt\PromptRefused;
use Prism\Acp\Support\HiddenCharacters;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;
use Prism\Acp\Version;

/**
 * The ACP agent surface: `initialize`, `session/new`, `session/load`, `session/set_model`, `session/prompt`,
 * `session/cancel`. Ported from prism-acp-ts src/acp/agent.ts, whose comments carry the reasoning; the short of it:
 *
 * - Several sessions, each its own driver and its own agent process, from the start.
 * - Capabilities are claims, reported honestly: `loadSession` is true because it is proven, and prompt capabilities
 *   come from the driver's own declaration.
 * - A turn that did not finish is answered as an ERROR, never given the nearest-looking stop reason.
 * - `session/load` refuses, at the load, an id that cannot resume: one this server minted, one whose agent is still
 *   running, and one the session probe says does not exist.
 *
 * @phpstan-import-type Server from McpServers
 *
 * @phpstan-type DriverOptions array{cwd: string, mcpServers: list<Server>, resumeSessionId?: string}
 * @phpstan-type Capabilities array{permissionRequests: bool, transcriptReplay: bool, mcpCapabilities?: array{http: bool, sse: bool}, promptBlocks?: list<string>, cancel?: bool}
 */
final class AcpAgent
{
    /** The protocol version this agent speaks. */
    public const PROTOCOL_VERSION = 1;

    /** The shape session/new mints: `sess_<counter>_<epoch ms>`. Deliberately narrow; see #sessionLoad there. */
    private const MINTED_SESSION_ID = '/\Asess_\d+_\d+\z/';

    /** @var array<string, Session> */
    private array $sessions = [];

    private int $counter = 0;

    /** @var array<string, true> Ids this process handed out via session/new. */
    private array $minted = [];

    /** Whether the client advertised `clientCapabilities.plan` (G-362). False until it says so. */
    private bool $clientAcceptsPlan = false;

    /** ACP's plan entry priorities. */
    private const PRIORITIES = ['high', 'medium', 'low'];

    /** ACP's plan entry statuses. A failed or skipped step has none of its own; see Plan\PlanProjection. */
    private const PLAN_ENTRY_STATUSES = ['pending', 'in_progress', 'completed'];

    private const MALFORMED_ENTRIES = 'plan update was not sent: an entry was not an object with a string content, an ACP status and an object _meta';

    private const MALFORMED_META = 'plan update was not sent: its _meta was not an object that serializes as one';

    private const MALFORMED_READ = 'plan update was not sent: reading it threw';

    private const MALFORMED_SNAPSHOT = 'update was not sent: it does not serialize as an object of the kind it declares';

    private const OVERSIZE_CONTENT = 'plan update was not sent: an entry content is larger than '.PlanProjection::ENTRY_CONTENT_MAX.' code points';

    private const OVERSIZE_META = 'plan update was not sent: a _meta is larger than '.PlanProjection::META_MAX_BYTES.' bytes of JSON';

    private const OVERSIZE_ENTRIES = 'plan update was not sent: it has more than '.PlanProjection::ENTRIES_MAX.' entries';

    private const OVERSIZE_MARKDOWN = 'plan update was not sent: its markdown content is larger than '.PlanProjection::MARKDOWN_MAX.' code points or its path larger than '.PlanProjection::PATH_MAX;

    private const OVERSIZE_PLAN_ID = 'plan update was not sent: its planId is larger than '.PlanProjection::ID_MAX.' code points';

    private const HIDDEN_PLAN_ID = 'plan update was not sent: its planId holds a control, format or invisible character';

    private const HIDDEN_MARKDOWN = 'plan update was not sent: its markdown path holds a control, format or invisible character, or its content a control or bidi character';

    /**
     * @param  \Closure(DriverOptions, DriverEvents): AgentDriver  $driverFactory
     * @param  Capabilities|null  $driverCapabilities
     * @param  (\Closure(string): array{existence: 'present'|'absent'|'indeterminate', detail: string})|null  $probeSession
     * @param  array{name: string, title?: string, version: string}|null  $agentInfo
     * @param  (\Closure(): string)|null  $newSessionId
     * @param  (\Closure(string, string): void)|null  $onStderr
     * @param  (\Closure(string, string, ?bool): void)|null  $onProtocolError
     */
    public function __construct(
        private readonly JsonRpcPeer $peer,
        private readonly \Closure $driverFactory,
        private readonly ?array $driverCapabilities = null,
        private readonly ?\Closure $probeSession = null,
        private readonly ?array $agentInfo = null,
        private readonly ?\Closure $newSessionId = null,
        private readonly ?\Closure $onStderr = null,
        private readonly ?\Closure $onProtocolError = null,
        private readonly ?Loop $loop = null,
        /** How long session/new and session/load wait for the driver's model list before leaving it out. */
        private readonly float $modelWaitSeconds = 10.0,
    ) {
        $peer
            ->handle('initialize', fn (mixed $params) => $this->initialize($params))
            ->handle('authenticate', fn () => null)
            ->handle('session/new', fn (mixed $params) => $this->withModels($this->sessionNew($params)))
            ->handle('session/load', fn (mixed $params) => $this->withModels($this->sessionLoad($params), Js::string($params, 'sessionId')))
            ->handle('session/set_model', fn (mixed $params) => $this->sessionSetModel($params))
            ->handle('session/prompt', fn (mixed $params) => $this->sessionPrompt($params));
        $peer->onNotify('session/cancel', fn (mixed $params) => $this->sessionCancel($params));
    }

    /** @return list<string> session ids currently open */
    public function sessionIds(): array
    {
        return array_map(strval(...), array_keys($this->sessions));
    }

    /** Stop every session's process. */
    public function closeAll(): void
    {
        foreach ($this->sessions as $session) {
            $session->driver?->kill();
        }
        $this->sessions = [];
    }

    /** @return array<string, mixed> */
    private function initialize(mixed $params): array
    {
        // The spec negotiates: we speak 1, and say so whatever was asked.
        $this->clientAcceptsPlan = PlanProjection::clientAcceptsPlanUpdates(Js::get($params, 'clientCapabilities'));
        $capabilities = [];
        if (isset($this->driverCapabilities['mcpCapabilities'])) {
            $capabilities['mcpCapabilities'] = $this->driverCapabilities['mcpCapabilities'];
        }
        $accepts = $this->accepts();
        $capabilities['loadSession'] = true;
        $capabilities['promptCapabilities'] = [
            'image' => in_array('image', $accepts, true),
            'audio' => false,
            'embeddedContext' => in_array('resource-text', $accepts, true) || in_array('resource-blob', $accepts, true),
        ];
        $response = [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'agentInfo' => $this->agentInfo ?? ['name' => 'particle-academy/prism-acp', 'title' => 'Prism ACP', 'version' => Version::VERSION],
            // Empty because there is no authentication STEP: the CLI is already signed in, and no credential travels here.
            'authMethods' => [],
            'agentCapabilities' => $capabilities,
        ];

        // No declaration means the embedder did not identify a driver; inventing false values would be a claim.
        return $this->driverCapabilities === null
            ? $response
            : Meta::with($response, [Meta::DRIVER_CAPABILITIES => $this->driverCapabilities]);
    }

    /** @return array<string, mixed> */
    private function sessionNew(mixed $params): array
    {
        $cwd = self::requireAbsoluteCwd($params);
        $mcpServers = $this->mcpServers($params);
        $id = $this->newSessionId !== null ? ($this->newSessionId)() : 'sess_'.(++$this->counter).'_'.(int) floor(microtime(true) * 1000);
        $this->open($id, $cwd, null, $mcpServers);
        $this->minted[$id] = true;

        return ['sessionId' => $id];
    }

    /**
     * ACP's (unstable) session model state on a session/new or session/load result, from the provider itself. Waited
     * for at most modelWaitSeconds, and LEFT OUT -- never invented -- if the driver cannot say.
     *
     * @param  array<string, mixed>  $result
     */
    private function withModels(array $result, ?string $loadedId = null): mixed
    {
        $id = $loadedId ?? Js::string((object) $result, 'sessionId');
        $driver = $id === null ? null : ($this->sessions[$id]->driver ?? null);
        if ($id === null || ! $driver instanceof ReportsModels) {
            return self::object($result);
        }
        $models = $driver->modelState();
        // Settles true when the models arrive (or fail), false when the wait runs out: whichever is first.
        $ready = new Deferred($this->loop);
        $models->onSettle(fn () => $ready->isSettled() ? null : $ready->resolve(true));
        $timer = $this->loop()->delay($this->modelWaitSeconds, fn () => $ready->isSettled() ? null : $ready->resolve(false));
        try {
            if ($ready->await() !== true) {
                $this->protocolError($id, 'the agent did not report its models within '.Js::numberToString($this->modelWaitSeconds).' s', true);

                return self::object($result);
            }

            return [...$result, 'models' => Models::acp(Models::state($models->await()))];
        } catch (\Throwable $cause) {
            $this->protocolError($id, "the agent could not report its models ({$cause->getMessage()})", true);

            return self::object($result);
        } finally {
            $this->loop()->cancel($timer);
        }
    }

    private function sessionSetModel(mixed $params): \stdClass
    {
        $sessionId = Js::string($params, 'sessionId');
        $modelId = Js::string($params, 'modelId');
        if ($sessionId === null || $modelId === null) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'session/set_model requires a sessionId and a modelId');
        }
        $session = $this->sessions[$sessionId] ?? null;
        if ($session === null || $session->exited) {
            throw new RpcError(RpcError::INVALID_PARAMS, "no live session: {$sessionId}");
        }
        if (! $session->driver instanceof ReportsModels) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'this agent cannot switch models');
        }
        try {
            $session->driver->setModel($modelId)->await();
        } catch (\Throwable $cause) {
            throw new RpcError(RpcError::INVALID_PARAMS, $cause->getMessage());
        }

        return new \stdClass;
    }

    /** @return array<string, mixed> */
    private function sessionLoad(mixed $params): array
    {
        $cwd = self::requireAbsoluteCwd($params);
        $mcpServers = $this->mcpServers($params);
        $sessionId = Js::string($params, 'sessionId');
        if ($sessionId === null) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'session/load requires a sessionId');
        }

        // Refused only while the session is genuinely LIVE: an exited session is the main thing anyone resumes.
        $existing = $this->sessions[$sessionId] ?? null;
        if ($existing !== null && ! $existing->exited) {
            throw new RpcError(RpcError::INVALID_PARAMS, "session {$sessionId} is already open and its agent is still running");
        }
        // The same refusal, by the CLI's id rather than ACP's.
        foreach ($this->sessions as $session) {
            if (! $session->exited && $session->driver?->cliSessionId() === $sessionId) {
                throw new RpcError(RpcError::INVALID_PARAMS, "session {$sessionId} is already open as {$session->id} and its agent is still running");
            }
        }
        // An id this server minted: the provider's resume cannot take it, and the client is told what to pass instead.
        if (preg_match(self::MINTED_SESSION_ID, $sessionId) === 1 || isset($this->minted[$sessionId])) {
            throw new RpcError(
                RpcError::INVALID_PARAMS,
                "{$sessionId} is an ACP session id minted by this server and cannot be resumed by the provider. "
                    ."Resume with the CLI's own session id, sent as '".Meta::CLI_SESSION_ID."' in the _meta of the first "
                    .'session/update of the original session.',
            );
        }
        // An id that names no conversation, refused here rather than a turn later. `indeterminate` proceeds.
        $probe = $this->probeSession === null ? null : ($this->probeSession)($sessionId);
        if ($probe !== null && $probe['existence'] === 'absent') {
            throw new RpcError(RpcError::INVALID_PARAMS, "cannot resume {$sessionId}: {$probe['detail']}");
        }

        $this->open($sessionId, $cwd, $sessionId, $mcpServers);

        // The spec's result is an empty object; history arrives as session/update notifications.
        return [];
    }

    /** @return list<Server> */
    private function mcpServers(mixed $params): array
    {
        $servers = McpServers::read(Undefined::get($params, 'mcpServers'));
        if (isset($this->driverCapabilities['mcpCapabilities'])) {
            McpServers::assertSupport($servers, $this->driverCapabilities['mcpCapabilities']);
        }

        return $servers;
    }

    /** @param list<Server> $mcpServers */
    private function open(string $id, string $cwd, ?string $resumeSessionId, array $mcpServers): Session
    {
        $session = new Session($id, $cwd);
        // clientCapabilities: what the client said it can receive. `plan: true` means plan_update and plan_removed may
        // be sent (see Plan\PlanProjection); planGate enforces it on every update either way.
        $options = ['cwd' => $cwd, 'mcpServers' => $mcpServers, 'clientCapabilities' => ['plan' => $this->clientAcceptsPlan]];
        if ($resumeSessionId !== null) {
            $options['resumeSessionId'] = $resumeSessionId;
        }

        $driver = ($this->driverFactory)($options, new DriverEvents(
            onUpdate: function (array $update) use ($id): void {
                $sent = $this->planGate($id, $update);
                if ($sent !== null) {
                    $this->peer->notify('session/update', ['sessionId' => $id, 'update' => $sent]);
                }
            },
            onRequestPermission: fn (array $request) => Deferred::async(function () use ($id, $request): PermissionOutcome {
                $result = $this->peer->request('session/request_permission', ['sessionId' => $id, ...$request])->await();
                $outcome = Js::get($result, 'outcome');
                // Built fresh, so nothing else on the client's object travels inward.
                $reason = Js::string(Js::get($result, '_meta'), Meta::PERMISSION_REASON);
                if (Js::get($outcome, 'outcome') === 'cancelled') {
                    return PermissionOutcome::cancelled($reason);
                }
                $optionId = Js::string($outcome, 'optionId');
                if (Js::get($outcome, 'outcome') === 'selected' && $optionId !== null) {
                    return PermissionOutcome::selected($optionId, $reason);
                }

                return PermissionOutcome::cancelled();
            }, $this->loop),
            onTurnEnd: function (TurnOutcome $outcome) use ($session): void {
                $turn = $session->turn;
                $session->turn = null;
                if ($turn !== null) {
                    // Once the client has cancelled, the prompt is answered `cancelled`, even if the turn won the race.
                    $turn->settle->resolve($turn->cancelled ? new TurnOutcome('cancelled', false, $outcome->raw) : $outcome);
                }
            },
            onProtocolError: fn (string $problem, ?bool $recoverable) => $this->protocolError($id, $problem, $recoverable),
            onStderr: function (string $line) use ($id): void {
                $this->onStderr?->__invoke($id, $line);
            },
            onExit: function (?int $code) use ($session): void {
                $session->exited = true;
                // A turn still in flight when the process dies must be settled, or session/prompt never returns.
                $turn = $session->turn;
                $session->turn = null;
                $turn?->settle->resolve(new TurnOutcome(null, true, 'agent exited with code '.($code === null ? 'null' : (string) $code)));
            },
        ));

        $session->driver = $driver;
        $this->sessions[$id] = $session;
        $driver->start();

        return $session;
    }

    /** @return array{stopReason: string} */
    private function sessionPrompt(mixed $params): array
    {
        $sessionId = Js::string($params, 'sessionId');
        if ($sessionId === null) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'session/prompt requires a sessionId');
        }
        $session = $this->sessions[$sessionId] ?? null;
        if ($session === null) {
            throw new RpcError(RpcError::INVALID_PARAMS, "no such session: {$sessionId}");
        }
        if ($session->exited) {
            throw new RpcError(RpcError::INTERNAL_ERROR, "session {$sessionId} has exited");
        }
        if ($session->turn !== null) {
            // One turn at a time per session: two would mix their updates on one stream.
            throw new RpcError(RpcError::INVALID_PARAMS, "session {$sessionId} already has a turn in flight");
        }

        $content = $this->promptContent(Js::get($params, 'prompt'));

        $settle = new Deferred($this->loop);
        $session->turn = new Turn($settle);
        try {
            $session->driver?->prompt($content);
        } catch (PromptRefused $refused) {
            $session->turn = null;

            // A block the driver would not send is the client's request to fix, not a turn that failed.
            throw new RpcError(RpcError::INVALID_PARAMS, $refused->getMessage());
        } catch (\Throwable $cause) {
            $session->turn = null;
            $settle->resolve(new TurnOutcome(null, true, $cause->getMessage()));
        }
        $outcome = $settle->await();
        if (! $outcome instanceof TurnOutcome) {
            throw new RpcError(RpcError::INTERNAL_ERROR, 'turn did not complete');
        }

        if ($outcome->stopReason === null) {
            // The reason, when the provider gave one, is structured in `data`, so a client need not parse the message.
            // It is the failure's wire form, rebuilt from its documented fields, never the driver's values as given.
            $failure = $outcome->failure?->wire();
            throw new RpcError(
                RpcError::INTERNAL_ERROR,
                'turn did not complete'.($outcome->raw === null ? '' : ": {$outcome->raw}"),
                $failure === null ? Undefined::Value : [Meta::TURN_FAILURE => $failure],
            );
        }

        return ['stopReason' => $outcome->stopReason];
    }

    /** @return list<string> the prompt block kinds the driver declared; text only when it declared none */
    private function accepts(): array
    {
        return $this->driverCapabilities['promptBlocks'] ?? ['text'];
    }

    /**
     * session/prompt's blocks, checked against the driver's declaration. Text blocks alone are joined into the text
     * every driver takes; a block the driver does not take refuses the WHOLE prompt, naming it.
     *
     * @return string|list<\stdClass>
     */
    private function promptContent(mixed $prompt): string|array
    {
        if (! is_array($prompt) || $prompt === []) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'session/prompt requires a prompt of content blocks');
        }
        try {
            $blocks = Prompt::blocks(array_values($prompt), $this->accepts());
        } catch (\Throwable $cause) {
            throw new RpcError(RpcError::INVALID_PARAMS, $cause->getMessage());
        }
        foreach ($blocks as $block) {
            if (Prompt::blockKind($block) !== 'text') {
                return $blocks;
            }
        }

        return implode("\n", array_map(fn (\stdClass $block): string => (string) Js::string($block, 'text'), $blocks));
    }

    private function sessionCancel(mixed $params): void
    {
        $sessionId = Js::string($params, 'sessionId');
        $session = $sessionId === null ? null : ($this->sessions[$sessionId] ?? null);
        if ($session === null) {
            return;
        }
        // Nothing in flight, nothing to stop: cancel ends a TURN.
        $turn = $session->turn;
        if ($turn === null || $session->exited) {
            return;
        }
        $turn->cancelled = true;

        if (! $session->driver instanceof CancelsTurns) {
            $this->stopForCancel($session, $turn);

            return;
        }
        $session->driver->cancel()->then(null, function (\Throwable $cause) use ($session, $turn): void {
            // An interrupt the agent refused must still stop what the client asked to stop.
            $this->protocolError($session->id, "turn cancel failed, stopping the agent: {$cause->getMessage()}", null);
            if ($session->turn === $turn) {
                $this->stopForCancel($session, $turn);
            }
        });
    }

    /** Stop a turn by stopping its agent: the only cancel a driver without CancelsTurns has. */
    private function stopForCancel(Session $session, Turn $turn): void
    {
        $session->turn = null;
        // `cancelled` is a real stop reason, so a cancelled turn RESOLVES rather than erroring.
        $turn->settle->resolve(new TurnOutcome('cancelled', false, 'cancelled'));
        $session->driver?->kill();
        $session->exited = true;
    }

    private function protocolError(string $sessionId, string $problem, ?bool $recoverable): void
    {
        $this->onProtocolError?->__invoke($sessionId, $problem, $recoverable);
    }

    /**
     * Every update a driver sends, held to the protocol at the one place every driver's updates leave (G-362, G-376,
     * G-377). Ported from prism-acp-ts's #planGate; its comments carry the reasoning.
     *
     * - plan_update and plan_removed MUST NOT reach a client that did not advertise `clientCapabilities.plan`: an items
     *   update becomes the full-replacement `plan` it is equivalent to, and anything else is not sent. A plan_update
     *   that is not ACP's shape is never sent.
     * - Caps (PlanProjection): ENTRIES_MAX entries, counted on the list itself before any entry is read; an entry
     *   `content` over ENTRY_CONTENT_MAX code points; a `_meta` over META_MAX_BYTES of JSON; markdown over MARKDOWN_MAX,
     *   a path over PATH_MAX and a planId over ID_MAX code points. Each refuses the whole update.
     * - A planId or markdown path holding a control, format or invisible character, and markdown content holding a
     *   control or bidi character, is refused (HiddenCharacters). So is text that is not valid UTF-8.
     * - The entries list must be a PHP list. An object that iterates, counts or answers offsets is not one, so its own
     *   count cannot pass the cap. An entry is an array or a plain object (stdClass); any other object is refused, so
     *   no getter, ArrayAccess or jsonSerialize decides what is checked.
     * - What is sent is built here from the checked fields, never a copy of the driver's value. A `_meta` is sent as it
     *   serialized when checked, read back, so a JsonSerializable inside it is asked once. Every other update is sent as
     *   its serialization read back, and only if that is an object of the kind that was checked.
     * - A plan entry with no valid priority is given `medium`, because a client built from the schema drops it
     *   otherwise.
     *
     * A read or serialization that throws refuses the update; nothing is thrown into the driver's event path. Each
     * refusal and correction is reported, so a driver that needs one is visible.
     *
     * @param  array<mixed>  $update
     * @return array<string, mixed>|\stdClass|null
     */
    private function planGate(string $sessionId, array $update): array|\stdClass|null
    {
        try {
            return $this->checkPlan($sessionId, $update);
        } catch (\Throwable) {
            $this->reportPlan($sessionId, self::MALFORMED_READ);

            return null;
        }
    }

    /** Report a plan gate problem. A host reporter that throws is contained here, so it never reaches the driver. */
    private function reportPlan(string $sessionId, string $problem): void
    {
        try {
            $this->protocolError($sessionId, $problem, true);
        } catch (\Throwable) {
            // Nothing left to report it to.
        }
    }

    /**
     * @param  array<mixed>  $update
     * @return array<string, mixed>|\stdClass|null
     */
    private function checkPlan(string $sessionId, array $update): array|\stdClass|null
    {
        $refuse = function (string $problem) use ($sessionId): null {
            $this->reportPlan($sessionId, $problem);

            return null;
        };
        $kind = $update['sessionUpdate'] ?? null;
        if (! is_string($kind)) {
            return $refuse('update without a string sessionUpdate of its own was not sent');
        }
        switch ($kind) {
            case 'plan':
                $list = $update['entries'] ?? null;
                if (! is_array($list) || ! array_is_list($list)) {
                    return $refuse('plan update without an entries list was not sent');
                }
                $checked = self::checkedPlanEntries($list);
                if (is_string($checked)) {
                    return $refuse($checked);
                }
                $meta = self::planMeta($update['_meta'] ?? null);
                if (is_string($meta)) {
                    return $refuse($meta);
                }

                return self::withMeta(['sessionUpdate' => 'plan', 'entries' => $this->planEntries($sessionId, $checked)], $meta);
            case 'plan_update':
                $plan = self::fields($update['plan'] ?? null);
                $type = $plan['type'] ?? null;
                $planId = $plan['planId'] ?? null;
                $list = $plan['entries'] ?? null;
                $content = $plan['content'] ?? null;
                $path = $plan['path'] ?? null;
                $items = $type === 'items' && is_array($list) && array_is_list($list);
                $markdown = $type === 'markdown' && is_string($content) && ($path === null || is_string($path));
                if ($plan === null || ! is_string($planId) || $planId === '' || (! $items && ! $markdown)) {
                    return $refuse('plan_update that is not an ACP plan update was not sent');
                }
                if (! $this->clientAcceptsPlan && ! $items) {
                    return $refuse('plan_update was not sent: the client did not advertise plan updates');
                }
                $idProblem = self::planIdProblem($planId);
                if ($idProblem !== null) {
                    return $refuse($idProblem);
                }
                if ($markdown && (self::longerThan($content, PlanProjection::MARKDOWN_MAX) || (is_string($path) && self::longerThan($path, PlanProjection::PATH_MAX)))) {
                    return $refuse(self::OVERSIZE_MARKDOWN);
                }
                if ($markdown && (HiddenCharacters::inMarkdown($content) || (is_string($path) && HiddenCharacters::inPlanText($path)))) {
                    return $refuse(self::HIDDEN_MARKDOWN);
                }
                $checked = $items ? self::checkedPlanEntries($list) : null;
                if (is_string($checked)) {
                    return $refuse($checked);
                }
                $planMeta = self::planMeta($plan['_meta'] ?? null);
                if (is_string($planMeta)) {
                    return $refuse($planMeta);
                }
                $updateMeta = self::planMeta($update['_meta'] ?? null);
                if (is_string($updateMeta)) {
                    return $refuse($updateMeta);
                }
                $entries = $checked === null ? null : $this->planEntries($sessionId, $checked);
                if (! $this->clientAcceptsPlan) {
                    return self::withMeta(['sessionUpdate' => 'plan', 'entries' => $entries], $planMeta);
                }
                $body = $items
                    ? ['type' => 'items', 'planId' => $planId, 'entries' => $entries]
                    : ['type' => 'markdown', 'planId' => $planId, 'content' => $content, ...(is_string($path) ? ['path' => $path] : [])];

                return self::withMeta(['sessionUpdate' => 'plan_update', 'plan' => self::withMeta($body, $planMeta)], $updateMeta);
            case 'plan_removed':
                $planId = $update['planId'] ?? null;
                if (! is_string($planId) || $planId === '') {
                    return $refuse('plan_removed without a planId was not sent');
                }
                if (! $this->clientAcceptsPlan) {
                    return $refuse('plan_removed was not sent: the client did not advertise plan updates');
                }
                $idProblem = self::planIdProblem($planId);
                if ($idProblem !== null) {
                    return $refuse($idProblem);
                }
                $meta = self::planMeta($update['_meta'] ?? null);
                if (is_string($meta)) {
                    return $refuse($meta);
                }

                return self::withMeta(['sessionUpdate' => 'plan_removed', 'planId' => $planId], $meta);
            default:
                // Sent as it serializes, read back: the transport serializes it, so a JsonSerializable inside decides
                // what goes on the wire. The snapshot is what is sent, so it is asked once, and one that cannot be
                // serialized (text that is not UTF-8, a jsonSerialize that throws) is refused here, not at the write.
                try {
                    $snapshot = Json::decode(Json::encode($update));
                } catch (\Throwable) {
                    return $refuse(self::MALFORMED_SNAPSHOT);
                }
                if (! $snapshot instanceof \stdClass || Js::get($snapshot, 'sessionUpdate') !== $kind) {
                    return $refuse(self::MALFORMED_SNAPSHOT);
                }

                return $snapshot;
        }
    }

    /**
     * Each entry as ACP can carry it (priority aside, which planEntries supplies) and within the caps, or the problem
     * with the first that is not. Counted before any entry is read: the list is a PHP list, so its count is its length.
     *
     * @param  list<mixed>  $entries
     * @return list<array{content: string, status: string, priority: ?string, meta: ?\stdClass}>|string
     */
    private static function checkedPlanEntries(array $entries): array|string
    {
        if (count($entries) > PlanProjection::ENTRIES_MAX) {
            return self::OVERSIZE_ENTRIES;
        }
        $out = [];
        foreach ($entries as $entry) {
            $fields = self::fields($entry);
            if ($fields === null) {
                return self::MALFORMED_ENTRIES;
            }
            $content = $fields['content'] ?? null;
            $status = $fields['status'] ?? null;
            $priority = $fields['priority'] ?? null;
            $meta = self::planMeta($fields['_meta'] ?? null);
            // Content that is not UTF-8 could not be written to the client at all.
            if (! is_string($content) || ! mb_check_encoding($content, 'UTF-8') || ! in_array($status, self::PLAN_ENTRY_STATUSES, true)) {
                return self::MALFORMED_ENTRIES;
            }
            if (is_string($meta)) {
                return $meta === self::OVERSIZE_META ? self::OVERSIZE_META : self::MALFORMED_ENTRIES;
            }
            if (self::longerThan($content, PlanProjection::ENTRY_CONTENT_MAX)) {
                return self::OVERSIZE_CONTENT;
            }
            $out[] = ['content' => $content, 'status' => $status, 'priority' => is_string($priority) ? $priority : null, 'meta' => $meta];
        }

        return $out;
    }

    /**
     * Each checked entry as it is sent, built from its checked fields. An entry with no valid priority is given
     * `medium`, and that is reported.
     *
     * @param  list<array{content: string, status: string, priority: ?string, meta: ?\stdClass}>  $entries
     * @return list<array<string, mixed>>
     */
    private function planEntries(string $sessionId, array $entries): array
    {
        $fixed = 0;
        $out = [];
        foreach ($entries as $entry) {
            $valid = in_array($entry['priority'], self::PRIORITIES, true);
            if (! $valid) {
                $fixed++;
            }
            $out[] = self::withMeta(['content' => $entry['content'], 'priority' => $valid ? $entry['priority'] : PlanProjection::PRIORITY, 'status' => $entry['status']], $entry['meta']);
        }
        if ($fixed > 0) {
            $this->reportPlan($sessionId, $fixed.' plan entr'.($fixed === 1 ? 'y' : 'ies').' had no valid priority and were sent as medium');
        }

        return $out;
    }

    /**
     * `_meta` as ACP allows it: null for none (absent and null both mean none), the checked serialization read back,
     * or the problem. It must be an array or a plain object (stdClass) that serializes as an object within
     * META_MAX_BYTES. The serialization read back is what is sent, so a JsonSerializable inside is asked once and
     * cannot answer the check and the write differently. An empty PHP array is the empty object: PHP has one value for
     * both, and it carries nothing.
     */
    private static function planMeta(mixed $value): \stdClass|string|null
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) && ! $value instanceof \stdClass) {
            return self::MALFORMED_META;
        }
        try {
            $text = Json::encode($value === [] ? new \stdClass : $value);
            $wire = Json::decode($text);
        } catch (\Throwable) {
            return self::MALFORMED_META;
        }
        if (! $wire instanceof \stdClass) {
            return self::MALFORMED_META;
        }

        return strlen($text) > PlanProjection::META_MAX_BYTES ? self::OVERSIZE_META : $wire;
    }

    /** Why a planId may not be sent, or null: over the cap, or holding a character a client would not show. */
    private static function planIdProblem(string $planId): ?string
    {
        if (self::longerThan($planId, PlanProjection::ID_MAX)) {
            return self::OVERSIZE_PLAN_ID;
        }

        return HiddenCharacters::inPlanText($planId) ? self::HIDDEN_PLAN_ID : null;
    }

    /**
     * A value's fields when it is an array or a plain object (stdClass); null for anything else.
     *
     * @return array<mixed>|null
     */
    private static function fields(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        return $value instanceof \stdClass ? get_object_vars($value) : null;
    }

    /** Whether text holds more than $max code points. A code point is at most four bytes, so long text is not counted. */
    private static function longerThan(string $text, int $max): bool
    {
        $bytes = strlen($text);
        if ($bytes <= $max) {
            return false;
        }

        return $bytes > 4 * $max || mb_strlen($text, 'UTF-8') > $max;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withMeta(array $body, ?\stdClass $meta): array
    {
        return $meta === null ? $body : [...$body, '_meta' => $meta];
    }

    private function loop(): Loop
    {
        return $this->loop ?? Loop::get();
    }

    /** ACP requires an absolute cwd, and says so with a MUST. Enforced, never resolved against our own cwd. */
    private static function requireAbsoluteCwd(mixed $params): string
    {
        $cwd = Js::string($params, 'cwd');
        if ($cwd === null) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'cwd is required and MUST be an absolute path');
        }
        if (! str_starts_with($cwd, '/') && preg_match('#\A[A-Za-z]:[\\\\/]#', $cwd) !== 1) {
            throw new RpcError(RpcError::INVALID_PARAMS, "cwd MUST be an absolute path: {$cwd}");
        }

        return $cwd;
    }

    /**
     * A result object: an empty one must still go out as `{}`.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|\stdClass
     */
    private static function object(array $result): array|\stdClass
    {
        return $result === [] ? new \stdClass : $result;
    }
}

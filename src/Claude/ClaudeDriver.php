<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Agent\AgentDriver;
use Prism\Acp\Agent\CancelsTurns;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\OwnsProcessTree;
use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Agent\ReportsModels;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Mcp\McpServers;
use Prism\Acp\Meta;
use Prism\Acp\Models\ModelListError;
use Prism\Acp\Models\Models;
use Prism\Acp\Ndjson\Frame;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\NdjsonFramer;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\ProcessTreeException;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\ProviderOnLoop;
use Prism\Acp\Process\StopVerdict;
use Prism\Acp\Prompt\Prompt;
use Prism\Acp\SessionId;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\PrivateDirectory;
use Prism\Acp\Support\Utf8Stream;

/**
 * Run the Claude CLI and turn its `stream-json` output into ACP updates. Ported from prism-acp-ts
 * src/claude/driver.ts: ChildEnv builds the environment, NdjsonFramer splits the output, ClaudeToAcp translates it,
 * and the CLI runs under a supervisor that owns its whole process tree (Provider). What is left here is process
 * handling, kept small.
 *
 * How it runs the CLI is a ClaudeDriverOptions.
 *
 * @phpstan-import-type Server from McpServers
 * @phpstan-import-type ModelState from Models
 */
final class ClaudeDriver implements AgentDriver, CancelsTurns, OwnsProcessTree, ReportsModels
{
    /** The driver's behaviour claim, published by initialize for clients. */
    public const CAPABILITIES = [
        'permissionRequests' => false,
        'transcriptReplay' => false,
        'mcpCapabilities' => ['http' => true, 'sse' => true],
        // A binary file has no documented stream-json block, so it is refused rather than sent in an undocumented one.
        'promptBlocks' => ['text', 'image', 'resource-text'],
        'cancel' => true,
    ];

    /** The CLI's own permission modes. */
    public const PERMISSION_MODES = ['acceptEdits', 'auto', 'bypassPermissions', 'manual', 'dontAsk', 'plan'];

    /** ACP stop reasons the CLI reports under the same names: a map, never a pass-through into a closed enum. */
    private const STOP_REASONS = [
        'end_turn' => 'end_turn',
        'max_tokens' => 'max_tokens',
        'max_turn_requests' => 'max_turn_requests',
        'refusal' => 'refusal',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
    ];

    /** Result terminal reasons that mean the agent loop was ABORTED (documented; `aborted_streaming` measured). */
    private const ABORTED_TERMINAL_REASONS = ['aborted_streaming', 'aborted_tools'];

    private readonly NdjsonFramer $framer;

    private readonly ClaudeToAcp $mapper;

    private readonly ClaudeTurnFailures $failures;

    private readonly Utf8Stream $stderrText;

    private readonly Loop $loop;

    private ?ProviderOnLoop $provider = null;

    private bool $started = false;

    private string $stderr = '';

    private ?TurnOutcome $pendingOutcome = null;

    /** Prompts written whose `result` has not arrived yet. */
    private int $turnsInFlight = 0;

    /** An interrupt was sent and the turn it targets has not ended yet. */
    private bool $interruptPending = false;

    private int $controlSequence = 0;

    /** @var array<string, Deferred<mixed>> this driver's own control requests awaiting the CLI's answer */
    private array $controlWaiters = [];

    /** @var array<string, int> each control request's timeout timer */
    private array $controlTimers = [];

    /** @var Deferred<mixed>|null the account's models (a list), from the control `initialize` answer */
    private ?Deferred $models = null;

    private ?string $currentModelId = null;

    private ?string $mcpConfigDirectory = null;

    private ?string $mcpConfigPath = null;

    private ?string $approvalDirectory = null;

    /** @var list<string> */
    private array $approvalFiles = [];

    private ?ApprovalServer $approvals = null;

    /** @var list<string> */
    private array $withheld = [];

    /** The CLI's own session id, once announced. Not ACP's: `--resume` wants the CLI's. */
    private ?string $cliSessionId = null;

    public function __construct(
        private readonly ClaudeDriverOptions $options,
        private readonly DriverEvents $events = new DriverEvents,
        ?Loop $loop = null,
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->framer = new NdjsonFramer;
        $this->mapper = new ClaudeToAcp;
        $this->failures = new ClaudeTurnFailures;
        $this->stderrText = new Utf8Stream;
    }

    /**
     * The CLI arguments. Every flag is load-bearing: `--print` with stream-json in and out is the structured stream,
     * `--verbose` is required for stream-json output, and `--include-partial-messages` produces the deltas.
     *
     * @return list<string>
     */
    public static function args(ClaudeDriverOptions $options, ?string $mcpConfigPath = null, ?string $settingsPath = null): array
    {
        $args = ['--print', '--output-format', 'stream-json', '--input-format', 'stream-json', '--verbose', '--include-partial-messages'];
        if ($options->resumeSessionId !== null) {
            $args[] = '--resume';
            $args[] = $options->resumeSessionId;
        }
        if ($options->permissionMode !== null) {
            // Checked here, where the reference relies on its type: a value shaped like a flag would reach the CLI as one.
            if (! in_array($options->permissionMode, self::PERMISSION_MODES, true)) {
                throw new \InvalidArgumentException('unknown permission mode '.Json::encode($options->permissionMode));
            }
            $args[] = '--permission-mode';
            $args[] = $options->permissionMode;
        }
        if ($options->model !== null) {
            $args[] = '--model';
            $args[] = Meta::assertModel($options->model);
        }
        if ($options->allowedTools !== []) {
            $args[] = '--allowed-tools';
            $args[] = implode(',', $options->allowedTools);
        }
        if ($options->disallowedTools !== []) {
            $args[] = '--disallowed-tools';
            $args[] = implode(',', $options->disallowedTools);
        }
        if ($options->mcpServers !== [] && $mcpConfigPath === null) {
            throw new \InvalidArgumentException('ClaudeDriver::args requires an MCP config file path when mcpServers is non-empty');
        }
        if ($mcpConfigPath !== null) {
            $args[] = '--mcp-config';
            $args[] = $mcpConfigPath;
        }
        if ($settingsPath !== null) {
            $args[] = '--settings';
            $args[] = $settingsPath;
        }

        return $args;
    }

    /**
     * One user turn for `--input-format stream-json`: the CLI's own message envelope, with the documented user-message
     * content -- text blocks, an image as a base64 source, a text file as a text block naming it.
     *
     * @param  string|list<\stdClass>  $content
     */
    public static function promptLine(string|array $content): string
    {
        $blocks = Prompt::blocks($content, self::CAPABILITIES['promptBlocks']);

        return NdjsonFramer::encodeLine([
            'type' => 'user',
            'message' => ['role' => 'user', 'content' => array_map(self::claudeContent(...), $blocks)],
        ]);
    }

    /**
     * A turn's outcome from a `result` frame, or null when it is not one. stopReason is null when the CLI reported an
     * error or a reason ACP has no literal for: none of ACP's reasons describes a crash.
     */
    public static function turnOutcomeOf(mixed $frame): ?TurnOutcome
    {
        if (Js::get($frame, 'type') !== 'result') {
            return null;
        }
        $raw = Js::string($frame, 'stop_reason');
        $isError = Js::get($frame, 'is_error') === true || Js::get($frame, 'subtype') === 'error';
        $mapped = $raw === null ? null : (self::STOP_REASONS[$raw] ?? null);
        // A built-in command runs no model turn: a successful result with no stop reason finished, cleanly.
        if (! $isError && $raw === null && Js::get($frame, 'subtype') === 'success') {
            return new TurnOutcome('end_turn', false, null);
        }

        return new TurnOutcome($isError || $mapped === null ? null : $mapped, $isError, $raw);
    }

    /** The CLI's own session id, from its `init` frame. */
    public static function cliSessionIdOf(mixed $frame): ?string
    {
        if (Js::get($frame, 'type') !== 'system' || Js::get($frame, 'subtype') !== 'init') {
            return null;
        }

        return Js::string($frame, 'session_id');
    }

    /**
     * Frames the mapper could not place.
     *
     * @return list<mixed>
     */
    public function unmapped(): array
    {
        return $this->mapper->unmapped;
    }

    public function running(): bool
    {
        return $this->provider !== null && ! $this->provider->isClosed();
    }

    public function cliSessionId(): ?string
    {
        return $this->cliSessionId;
    }

    public function withheldCredentials(): array
    {
        return $this->withheld;
    }

    /** Everything the CLI has written to stderr so far. */
    public function stderr(): string
    {
        return $this->stderr;
    }

    public function start(): void
    {
        if ($this->started) {
            throw new \LogicException('driver already started');
        }
        $this->started = true;

        $resume = $this->options->resumeSessionId;
        if ($resume !== null && ! SessionId::isValidCliSessionId($resume)) {
            $this->problem('invalid resumeSessionId: rejected CLI session id shape', null);
            $this->events->onExit?->__invoke(1, null);

            return;
        }

        // The CLI finds the user's own login through these, so they are allowed explicitly rather than inherited.
        $built = ChildEnv::build($this->options->parentEnv ?? getenv(), ['CLAUDE_CONFIG_DIR', 'XDG_CONFIG_HOME', ...$this->options->allowEnv]);
        $env = $built->env;
        $this->withheld = $built->withheld;

        try {
            if ($this->options->mcpServers !== []) {
                $this->mcpConfigDirectory = PrivateDirectory::create('prism-acp-mcp-');
                $this->mcpConfigPath = $this->mcpConfigDirectory.DIRECTORY_SEPARATOR.'mcp.json';
                self::writeNew($this->mcpConfigPath, Json::encode(McpServers::claudeConfig($this->options->mcpServers)));
            }
            $settingsPath = null;
            $tools = $this->options->permissionPrompts;
            if ($tools !== null) {
                $directory = PrivateDirectory::create('prism-acp-approve-');
                $this->approvalDirectory = $directory;
                $hookPath = $directory.DIRECTORY_SEPARATOR.'approve.php';
                $settingsPath = $directory.DIRECTORY_SEPARATOR.'settings.json';
                self::writeNew($hookPath, Approvals::HOOK_SCRIPT);
                $this->approvalFiles[] = $hookPath;
                self::writeNew($settingsPath, Json::encode(Approvals::settings(self::phpBinary($this->options->phpBinary), $hookPath, $tools)));
                $this->approvalFiles[] = $settingsPath;
                $ask = $this->events->onRequestPermission;
                $this->approvals = ApprovalServer::forDirectory($directory, function (\stdClass $input) use ($ask): Deferred {
                    if ($ask !== null) {
                        return $ask(Approvals::permissionRequestFor($input));
                    }
                    $nobody = new Deferred($this->loop);
                    $nobody->resolve(PermissionOutcome::cancelled());

                    return $nobody;
                }, $this->loop);
                $channel = $this->approvals->listen();
                // The token travels in the environment, never in argv.
                $env['PRISM_ACP_APPROVAL_SOCKET'] = $channel['socket'];
                $env['PRISM_ACP_APPROVAL_TOKEN'] = $channel['token'];
            }
            $process = Provider::launch(
                $this->options->binary,
                [...$this->options->binaryArgs, ...self::args($this->options, $this->mcpConfigPath, $settingsPath)],
                $this->options->cwd,
                $env,
            );
        } catch (\Throwable $cause) {
            $this->removeMcpConfig();
            $this->removeApprovals();
            // Its message names no path, and says why the CLI could not be run.
            if ($cause instanceof ProcessTreeException) {
                throw $cause;
            }

            // A filesystem or spawn error can contain a sensitive path: keep the report structural.
            throw new \RuntimeException('failed to prepare Claude MCP configuration or spawn CLI');
        }

        $this->provider = new ProviderOnLoop($process, $this->loop,
            onStdout: fn (string $chunk) => $this->emit($this->framer->push($chunk)),
            onStderr: fn (string $chunk) => $this->onStderr($chunk),
            onExited: fn (int $code) => null,
            onClosed: fn () => $this->onClosed(),
        );

        $this->currentModelId = $this->options->model;
        $models = new Deferred($this->loop);
        $this->control(['subtype' => 'initialize'])->then(
            function (mixed $response) use ($models): void {
                // The control_response's `response` is the envelope ({ subtype, request_id, response }).
                if (Js::get($response, 'subtype') !== 'success') {
                    $models->reject(new ModelListError('the Claude CLI refused the initialize request'));

                    return;
                }
                $models->resolve(Models::claudeModelsOf(Js::get($response, 'response')));
            },
            fn (\Throwable $error) => $models->reject($error),
        );
        $this->models = $models;
    }

    /**
     * Send a user turn: text, or content blocks. A block it cannot send refuses the whole turn, naming it.
     *
     * @param  string|list<\stdClass>  $content
     */
    public function prompt(string|array $content): void
    {
        // Encoded first, so a refused prompt throws PromptRefused whether or not the CLI is running.
        $line = self::promptLine($content);
        if ($this->provider === null || $this->provider->isClosed()) {
            throw new \RuntimeException('driver is not started, or its stdin has closed');
        }
        $this->provider->provider->write($line);
        $this->turnsInFlight++;
    }

    /**
     * Interrupt the turn in flight and keep the CLI and its session: the control `interrupt` request (measured, see
     * the reference). Settles once written; the turn ends through onTurnEnd. No turn running: nothing is sent.
     */
    public function cancel(): Deferred
    {
        $done = new Deferred($this->loop);
        if ($this->turnsInFlight === 0 || $this->provider === null || $this->provider->isClosed()) {
            $done->resolve(null);

            return $done;
        }
        $this->interruptPending = true;
        $this->controlSequence++;
        try {
            $this->provider->provider->write(NdjsonFramer::encodeLine([
                'type' => 'control_request',
                'request_id' => "prism-acp-interrupt-{$this->controlSequence}",
                'request' => ['subtype' => 'interrupt'],
            ]));
            $done->resolve(null);
        } catch (\Throwable) {
            $done->reject(new \RuntimeException('failed to write the Claude interrupt request'));
        }

        return $done;
    }

    public function modelState(): Deferred
    {
        if ($this->models === null) {
            throw new \LogicException('driver is not started');
        }

        $models = $this->models;

        return Deferred::async(function () use ($models): array {
            $state = Models::state(['availableModels' => $models->await(), 'currentModelId' => $this->currentModelId]);
            if ($state['currentModelId'] === null) {
                foreach ($state['availableModels'] as $model) {
                    if (isset($model['isDefault'])) {
                        return ['availableModels' => $state['availableModels'], 'currentModelId' => $model['modelId']];
                    }
                }
            }

            return $state;
        }, $this->loop);
    }

    /** Switch the live session's model; the next turn uses it. The control `set_model` request, measured. */
    public function setModel(string $modelId): Deferred
    {
        $model = Meta::assertModel($modelId);

        return Deferred::async(function () use ($model): null {
            $response = $this->control(['subtype' => 'set_model', 'model' => $model])->await();
            if (Js::get($response, 'subtype') !== 'success') {
                throw new \RuntimeException('the Claude CLI refused to switch model to '.Json::encode($model));
            }
            $this->currentModelId = $model;

            return null;
        }, $this->loop);
    }

    /** Close stdin, which tells the CLI no more turns are coming. */
    public function endInput(): void
    {
        $this->provider?->provider->closeInput();
    }

    /** End the CLI and every process it started, now. */
    public function kill(): void
    {
        $this->provider?->kill();
    }

    public function stop(int $graceMs = 5000): Deferred
    {
        if ($this->provider === null) {
            $done = new Deferred($this->loop);
            $done->resolve(new StopVerdict(true, true, 0, true));

            return $done;
        }

        return $this->provider->stop($graceMs);
    }

    public function processTree(): ?ProcessTree
    {
        return $this->provider?->provider->tree();
    }

    /**
     * Send one control request; settles with the CLI's control_response `response` object.
     *
     * @param  array<string, mixed>  $request
     * @return Deferred<mixed>
     */
    private function control(array $request, float $timeoutSeconds = 30.0): Deferred
    {
        $answer = new Deferred($this->loop);
        if ($this->provider === null || $this->provider->isClosed()) {
            $answer->reject(new \RuntimeException('driver is not started, or its stdin has closed'));

            return $answer;
        }
        $this->controlSequence++;
        $id = "prism-acp-control-{$this->controlSequence}";
        $this->controlWaiters[$id] = $answer;
        $this->controlTimers[$id] = $this->loop->delay($timeoutSeconds, function () use ($id, $request, $timeoutSeconds): void {
            $waiter = $this->controlWaiters[$id] ?? null;
            unset($this->controlWaiters[$id], $this->controlTimers[$id]);
            $waiter?->reject(new ModelListError('the Claude CLI did not answer '.(is_string($request['subtype'] ?? null) ? $request['subtype'] : 'undefined').' within '.Js::numberToString($timeoutSeconds * 1000).' ms'));
        });
        try {
            $this->provider->provider->write(NdjsonFramer::encodeLine(['type' => 'control_request', 'request_id' => $id, 'request' => $request]));
        } catch (\Throwable $cause) {
            $this->settleControl($id, null, $cause);
        }

        return $answer;
    }

    private function settleControl(string $id, mixed $response, ?\Throwable $error = null): void
    {
        $waiter = $this->controlWaiters[$id] ?? null;
        if (isset($this->controlTimers[$id])) {
            $this->loop->cancel($this->controlTimers[$id]);
        }
        unset($this->controlWaiters[$id], $this->controlTimers[$id]);
        $error === null ? $waiter?->resolve($response) : $waiter?->reject($error);
    }

    private function onClosed(): void
    {
        foreach (array_keys($this->controlWaiters) as $id) {
            $this->settleControl((string) $id, null, new ModelListError('the Claude CLI exited before answering'));
        }
        $this->removeMcpConfig();
        $this->removeApprovals();
        // Flush first: the LAST frame of a turn is the `result` that carries the outcome, newline or not.
        $this->emit($this->framer->end());
        $this->events->onExit?->__invoke($this->provider?->provider->exitCode(), null);
    }

    /** @param list<Frame> $frames */
    private function emit(array $frames): void
    {
        $accepted = [];
        // Raw frames are inspected for turn boundaries and the CLI's session id BEFORE mapping: neither survives it.
        foreach ($frames as $frame) {
            if (! $frame->ok) {
                $accepted[] = $frame;

                continue;
            }
            $value = $frame->value;
            $cliSessionId = self::cliSessionIdOf($value);
            if (Js::get($value, 'type') === 'system' && Js::get($value, 'subtype') === 'init'
                && Js::has($value, 'session_id') && ! SessionId::isValidCliSessionId($cliSessionId)) {
                $this->problem('invalid session_id: rejected Claude CLI session id shape', null);

                continue;
            }
            if ($cliSessionId !== null) {
                $this->cliSessionId = $cliSessionId;
            }
            // The CLI's answer to this driver's own control request: neither mapped nor unmapped.
            if (Js::get($value, 'type') === 'control_response') {
                $response = Js::get($value, 'response');
                $id = Js::string($response, 'request_id');
                if ($response instanceof \stdClass && $id !== null && isset($this->controlWaiters[$id])) {
                    $this->settleControl($id, $response);
                }

                continue;
            }
            $accepted[] = $frame;
            $this->failures->observe($value);

            $outcome = self::turnOutcomeOf($value);
            if ($outcome !== null) {
                $this->turnsInFlight = max(0, $this->turnsInFlight - 1);
                $interrupted = $this->interruptPending ? self::interruptedOutcomeOf($value) : null;
                $this->interruptPending = false;
                // Taken for every turn, so a reason never leaks into the next one; attached only to a turn that failed.
                $failure = $this->failures->take();
                $settled = $interrupted ?? $outcome;
                $this->pendingOutcome = $settled->isError && $failure !== null ? $settled->withFailure($failure) : $settled;
            }
        }

        $updates = [];
        $problems = [];
        foreach ($accepted as $frame) {
            if (! $frame->ok) {
                $problems[] = $frame->error === null ? 'NDJSON frame could not be read' : $frame->error->message;

                continue;
            }
            array_push($updates, ...$this->mapper->frame($frame->value));
        }
        // A frame that could not be read is ONE lost frame; the stream after it is mapped as normal.
        foreach ($problems as $problem) {
            $this->problem($problem, true);
        }
        foreach ($updates as $update) {
            $this->events->onUpdate?->__invoke($update);
        }
        // AFTER the updates, so a caller resolving a turn here has everything the turn produced, its usage included.
        if ($this->pendingOutcome !== null) {
            $outcome = $this->pendingOutcome;
            $this->pendingOutcome = null;
            $this->events->onTurnEnd?->__invoke($outcome);
        }
    }

    private function onStderr(string $chunk): void
    {
        $text = $this->stderrText->push($chunk);
        $this->stderr .= $text;
        // Line-wise within a chunk, as the reference splits each chunk it receives.
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (Js::trim($line) !== '') {
                $this->events->onStderr?->__invoke($line);
            }
        }
    }

    private function problem(string $problem, ?bool $recoverable): void
    {
        $this->events->onProtocolError?->__invoke($problem, $recoverable);
    }

    private function removeMcpConfig(): void
    {
        if ($this->mcpConfigDirectory === null) {
            return;
        }
        $directory = $this->mcpConfigDirectory;
        $file = $this->mcpConfigPath;
        $this->mcpConfigDirectory = $this->mcpConfigPath = null;
        if (! PrivateDirectory::remove($directory, ...($file === null ? [] : [$file]))) {
            $this->problem('failed to remove temporary Claude MCP configuration', true);
        }
    }

    private function removeApprovals(): void
    {
        $this->approvals?->close();
        $this->approvals = null;
        $directory = $this->approvalDirectory;
        $files = $this->approvalFiles;
        $this->approvalDirectory = null;
        $this->approvalFiles = [];
        if ($directory !== null && ! PrivateDirectory::remove($directory, ...$files)) {
            $this->problem('failed to remove the temporary Claude permission-prompt directory', true);
        }
    }

    /** A cancelled turn, when THIS driver interrupted it: the CLI reports it as an error result. */
    private static function interruptedOutcomeOf(mixed $frame): ?TurnOutcome
    {
        $reason = Js::string($frame, 'terminal_reason');
        if (Js::get($frame, 'type') !== 'result' || $reason === null || ! in_array($reason, self::ABORTED_TERMINAL_REASONS, true)) {
            return null;
        }

        return new TurnOutcome('cancelled', false, $reason);
    }

    /** @return array<string, mixed> */
    private static function claudeContent(\stdClass $block): array
    {
        $type = Js::get($block, 'type');
        if ($type === 'text') {
            return ['type' => 'text', 'text' => Js::string($block, 'text')];
        }
        if ($type === 'image') {
            return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => Js::string($block, 'mimeType'), 'data' => Js::string($block, 'data')]];
        }
        $resource = Js::get($block, 'resource');

        // Prompt::blocks admitted only a text resource here.
        return ['type' => 'text', 'text' => Prompt::fileAsText($resource instanceof \stdClass ? $resource : new \stdClass)];
    }

    /** The PHP CLI that runs the permission hook: the one given, else this one when it is a CLI, else `php` on PATH. */
    private static function phpBinary(?string $given): string
    {
        if ($given !== null && $given !== '') {
            return $given;
        }
        if (in_array(PHP_SAPI, ['cli', 'phpdbg', 'cli-server'], true)) {
            return PHP_BINARY;
        }

        return 'php';
    }

    /** Create a file that must not exist yet, readable by this user only. */
    private static function writeNew(string $path, string $content): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('could not create a private file');
        }
        @chmod($path, 0600);
        fwrite($handle, $content);
        fclose($handle);
    }
}

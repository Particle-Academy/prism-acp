<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Mcp\McpServers;

/**
 * How a ClaudeDriver runs the CLI. Ported from prism-acp-ts's `ClaudeDriverOptions`.
 *
 * @phpstan-import-type Server from McpServers
 */
final readonly class ClaudeDriverOptions
{
    /**
     * @param  list<string>  $binaryArgs  placed before the provider's own: for a binary that is an interpreter
     * @param  array<string, string|null>|null  $parentEnv  the environment to build the child's from; this process's when null
     * @param  list<string>  $allowEnv  extra environment names to pass through to the child
     * @param  list<Server>  $mcpServers  explicit per-session MCP servers, as McpServers::read returns them
     * @param  list<string>|null  $permissionPrompts  ask before these tools run (single identifiers), or ['*'] for every tool
     * @param  list<string>  $allowedTools
     * @param  list<string>  $disallowedTools
     * @param  list<string>  $disabledMcpServers  trusted host policy, never ACP input
     */
    public function __construct(
        /** The agent's working directory: absolute. */
        public string $cwd,
        public string $binary = 'claude',
        public array $binaryArgs = [],
        public ?array $parentEnv = null,
        public array $allowEnv = [],
        /** Resume an existing CLI session instead of starting a new one. */
        public ?string $resumeSessionId = null,
        public array $mcpServers = [],
        /** One of ClaudeDriver::PERMISSION_MODES. */
        public ?string $permissionMode = null,
        /** An alias (`sonnet`) or a full id; null is the CLI's own default. */
        public ?string $model = null,
        public ?array $permissionPrompts = null,
        /** The PHP CLI that runs the permission hook; this one when it is a CLI, else `php` on PATH. */
        public ?string $phpBinary = null,
        public array $allowedTools = [],
        public array $disallowedTools = [],
        public array $disabledMcpServers = [],
        public ?ProviderCredentials $credentials = null,
    ) {}

    /**
     * These options for one ACP session: its cwd, its MCP servers and the session it resumes, as AcpAgent's driver
     * factory receives them.
     *
     * @param  array{cwd: string, mcpServers: list<Server>, resumeSessionId?: string}  $session
     */
    public function forSession(array $session): self
    {
        return new self(
            cwd: $session['cwd'],
            binary: $this->binary,
            binaryArgs: $this->binaryArgs,
            parentEnv: $this->parentEnv,
            allowEnv: $this->allowEnv,
            resumeSessionId: $session['resumeSessionId'] ?? null,
            mcpServers: $session['mcpServers'],
            permissionMode: $this->permissionMode,
            model: $this->model,
            permissionPrompts: $this->permissionPrompts,
            phpBinary: $this->phpBinary,
            allowedTools: $this->allowedTools,
            disallowedTools: $this->disallowedTools,
            disabledMcpServers: $this->disabledMcpServers,
            credentials: $this->credentials,
        );
    }
}

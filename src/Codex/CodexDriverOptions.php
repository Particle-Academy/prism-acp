<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Mcp\McpServers;

/** Trusted host options. Per-session ACP values enter only through forSession.
 * @phpstan-import-type Server from McpServers
 */
final readonly class CodexDriverOptions
{
    /** @param list<string> $binaryArgs
     * @param  array<string, string|null>|null  $parentEnv
     * @param  list<string>  $allowEnv
     * @param  list<Server>  $mcpServers
     * @param  list<string>  $disabledMcpServers
     * @param  array<string, string>  $mcpToolApproval
     */
    public function __construct(
        public string $cwd,
        public string $binary = 'codex',
        public array $binaryArgs = [],
        public ?array $parentEnv = null,
        public array $allowEnv = [],
        public ?string $resumeSessionId = null,
        public array $mcpServers = [],
        public array $disabledMcpServers = [],
        public float $turnInactivityTimeoutMs = 600000,
        public ?string $model = null,
        public ?string $approvalPolicy = null,
        public ?string $sandbox = null,
        public array $mcpToolApproval = [],
        public ?ProviderCredentials $credentials = null,
    ) {}

    /** @param array{cwd: string, mcpServers: list<Server>, resumeSessionId?: string} $session */
    public function forSession(array $session): self
    {
        return new self(cwd: $session['cwd'], binary: $this->binary, binaryArgs: $this->binaryArgs,
            parentEnv: $this->parentEnv, allowEnv: $this->allowEnv, resumeSessionId: $session['resumeSessionId'] ?? null,
            mcpServers: $session['mcpServers'], disabledMcpServers: $this->disabledMcpServers,
            turnInactivityTimeoutMs: $this->turnInactivityTimeoutMs, model: $this->model,
            approvalPolicy: $this->approvalPolicy, sandbox: $this->sandbox, mcpToolApproval: $this->mcpToolApproval, credentials: $this->credentials);
    }
}

<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

/**
 * What a driver must offer the agent surface, whichever CLI it drives. Ported from prism-acp-ts's `AgentDriver`.
 *
 * The reference's optional methods are separate interfaces here, and a driver declares one by implementing it:
 * CancelsTurns (`cancel`), ReportsModels (`modelState`, `setModel`) and OwnsProcessTree (`stop`, `processTree`). Both
 * shipped drivers implement all three.
 */
interface AgentDriver
{
    public function start(): void;

    /**
     * One user turn. Text blocks alone arrive as their text, joined; content blocks arrive only when the driver
     * declares their kinds in its capabilities. A driver may still throw PromptRefused for a block it cannot send.
     *
     * @param  string|list<\stdClass>  $content
     */
    public function prompt(string|array $content): void;

    /** Close the agent's input: no more turns are coming. */
    public function endInput(): void;

    /** End the agent and every process it started. */
    public function kill(): void;

    /** The provider's own session id, once it announced one; what `session/load` resumes with. */
    public function cliSessionId(): ?string;

    /** @return list<string> names of credentials withheld from the agent's environment */
    public function withheldCredentials(): array;
}

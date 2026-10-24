<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\TurnFailure;

/** How a turn ended. Ported from prism-acp-ts's `TurnOutcome`. */
final readonly class TurnOutcome
{
    /** ACP's five stop reasons. No sixth, and no "unknown". */
    public const STOP_REASONS = ['end_turn', 'max_tokens', 'max_turn_requests', 'refusal', 'cancelled'];

    public function __construct(
        /** One of STOP_REASONS; null when the turn did not finish cleanly, which session/prompt answers as an error. */
        public ?string $stopReason,
        public bool $isError,
        /** The provider's own reason string, kept even when it maps to nothing. */
        public ?string $raw,
        /** Why a failed turn failed, when the provider said. Null on a turn that completed. */
        public ?TurnFailure $failure = null,
    ) {}

    public function withFailure(TurnFailure $failure): self
    {
        return new self($this->stopReason, $this->isError, $this->raw, $failure);
    }
}

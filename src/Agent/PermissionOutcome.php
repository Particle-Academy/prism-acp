<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

/**
 * The client's answer to a permission request. `reason` is what the agent is told about the answer instead of the
 * default text: for a decision that is not the person's (a limit the client enforces, an allow it made without
 * asking). It never changes the decision. An empty string counts as absent.
 */
final readonly class PermissionOutcome
{
    private function __construct(
        /** 'selected' or 'cancelled' */
        public string $outcome,
        public ?string $optionId,
        public ?string $reason,
    ) {}

    public static function selected(string $optionId, ?string $reason = null): self
    {
        return new self('selected', $optionId, $reason === '' ? null : $reason);
    }

    public static function cancelled(?string $reason = null): self
    {
        return new self('cancelled', null, $reason === '' ? null : $reason);
    }

    /** Only an explicit "allow once" allows. */
    public function allowsOnce(): bool
    {
        return $this->outcome === 'selected' && $this->optionId === 'allow_once';
    }
}

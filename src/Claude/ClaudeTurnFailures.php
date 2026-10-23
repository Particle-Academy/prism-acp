<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Support\Js;
use Prism\Acp\TurnFailure;

/**
 * One Claude turn's failure, read from the frames as they pass. Fed every frame the driver accepts; take() at the
 * turn's `result` returns the failure (if any) and starts the next turn clean.
 */
final class ClaudeTurnFailures
{
    private ?string $reason = null;

    private int|float|null $resetsAtMs = null;

    public function observe(mixed $frame): void
    {
        if (! $frame instanceof \stdClass) {
            return;
        }
        $type = Js::get($frame, 'type');

        // The main thread's error only: a subagent's failure is reported inside its own tool result.
        $error = Js::get($frame, 'error');
        if ($type === 'assistant' && is_string($error) && Js::get($frame, 'parent_tool_use_id') === null) {
            $this->reason = $error;
        }

        // A breached window's reset: the only reset this failure may report. An `allowed` event is not a breach.
        if ($type === 'rate_limit_event') {
            $read = ClaudeRateLimit::read(Js::get($frame, 'rate_limit_info') ?? $frame);
            if ($read instanceof ClaudeRateLimit && $read->status !== 'allowed') {
                $this->resetsAtMs = $read->binding()['resetsAtMs'] ?? $read->resetsAtMs;
            }
        }
    }

    /** The turn's failure, or null; clears for the next turn either way. */
    public function take(): ?TurnFailure
    {
        $reason = $this->reason;
        $resetsAtMs = $this->resetsAtMs;
        $this->reason = null;
        $this->resetsAtMs = null;
        if ($reason === null) {
            return null;
        }
        $kind = TurnFailure::CLAUDE_KINDS[$reason] ?? 'other';

        return new TurnFailure($reason, $kind, $kind === 'rate_limit' ? $resetsAtMs : null);
    }
}

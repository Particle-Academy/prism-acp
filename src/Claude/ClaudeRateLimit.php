<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Support\Js;

/**
 * The rate-limit payload the Claude CLI reports, declared and narrowed. Ported from prism-acp-ts src/claude/rate-limit.ts;
 * the reasoning lives there, and the short of it is: a renamed field must be LOUD. A gauge built from a payload this
 * cannot read is absent and explained, never zero and plausible.
 *
 * - `status` is open: every captured frame says "allowed" and no breach has been seen, so test `!== 'allowed'`.
 * - The provider sends SECONDS in `resetsAt`; `resetsAtMs` is converted here, exactly once, with no unit sniffing.
 * - One malformed window refuses the whole payload, so a bad `five_hour` never quietly shows `seven_day` in its place.
 * - `raw` carries the provider's object verbatim, so a field the provider adds is never dropped.
 */
final class ClaudeRateLimit implements \JsonSerializable
{
    /**
     * A reset past JavaScript's last date (8.64e15 ms) is refused like any other malformed field. The reference accepted
     * one and then threw building the notice, ending the agent (G-97); its Codex reader already refused it.
     */
    private const REPRESENTABLE = 'valid epoch seconds representable as a JavaScript date';

    /** @param array<string, array{utilization: int|float, resetsAtMs: int|float}> $windows */
    public function __construct(
        public readonly string $status,
        public readonly int|float $resetsAtMs,
        public readonly string $rateLimitType,
        public readonly ?string $overageStatus,
        public readonly ?string $overageDisabledReason,
        public readonly ?bool $isUsingOverage,
        public readonly array $windows,
        public readonly \stdClass $raw,
    ) {}

    /** The value, or null when the payload is refused. */
    public static function parse(mixed $value): ?self
    {
        $read = self::read($value);

        return $read instanceof self ? $read : null;
    }

    /** The value, or WHY it was refused: the explanation is the only thing a human gets when the provider changes shape. */
    public static function read(mixed $value): self|string
    {
        if (! $value instanceof \stdClass) {
            return self::refuse('payload', 'an object', $value, false);
        }
        $status = Js::get($value, 'status');
        if (! is_string($status)) {
            return self::refuse('status', 'string', $status, ! Js::has($value, 'status'));
        }
        $rateLimitType = Js::get($value, 'rateLimitType');
        if (! is_string($rateLimitType)) {
            return self::refuse('rateLimitType', 'string', $rateLimitType, ! Js::has($value, 'rateLimitType'));
        }
        $resetsAt = Js::get($value, 'resetsAt');
        if (! Js::finite($resetsAt, 5e-324)) {
            return self::refuse('resetsAt', 'finite number > 0 (epoch seconds)', $resetsAt, ! Js::has($value, 'resetsAt'));
        }
        if (! self::representable($resetsAt)) {
            return self::refuse('resetsAt', self::REPRESENTABLE, $resetsAt, false);
        }
        // An absent unifiedWindows is refused, not read as "no windows": it is the only part that says how much is left.
        $unified = Js::get($value, 'unifiedWindows');
        if (! $unified instanceof \stdClass) {
            return self::refuse('unifiedWindows', 'an object', $unified, ! Js::has($value, 'unifiedWindows'));
        }

        $windows = [];
        foreach (get_object_vars($unified) as $name => $window) {
            $name = (string) $name;
            if (! $window instanceof \stdClass) {
                return self::refuse("unifiedWindows.{$name}", 'an object', $window, false);
            }
            $utilization = Js::get($window, 'utilization');
            if (! Js::finite($utilization, 0)) {
                return self::refuse("unifiedWindows.{$name}.utilization", 'finite number >= 0', $utilization, ! Js::has($window, 'utilization'));
            }
            $windowResetsAt = Js::get($window, 'resetsAt');
            if (! Js::finite($windowResetsAt, 5e-324)) {
                return self::refuse("unifiedWindows.{$name}.resetsAt", 'finite number > 0 (epoch seconds)', $windowResetsAt, ! Js::has($window, 'resetsAt'));
            }
            if (! self::representable($windowResetsAt)) {
                return self::refuse("unifiedWindows.{$name}.resetsAt", self::REPRESENTABLE, $windowResetsAt, false);
            }
            $windows[$name] = ['utilization' => $utilization, 'resetsAtMs' => $windowResetsAt * 1000];
        }

        $overageStatus = Js::get($value, 'overageStatus');
        $overageDisabledReason = Js::get($value, 'overageDisabledReason');
        $isUsingOverage = Js::get($value, 'isUsingOverage');

        return new self(
            $status,
            $resetsAt * 1000,
            $rateLimitType,
            is_string($overageStatus) ? $overageStatus : null,
            is_string($overageDisabledReason) ? $overageDisabledReason : null,
            is_bool($isUsingOverage) ? $isUsingOverage : null,
            $windows,
            $value,
        );
    }

    /**
     * The binding window, when the frame reported it.
     *
     * @return array{utilization: int|float, resetsAtMs: int|float}|null
     */
    public function binding(): ?array
    {
        return $this->windows[$this->rateLimitType] ?? null;
    }

    /** The sentence a human reads, carrying the two numbers they would otherwise open a debugger to see. */
    public function notice(): string
    {
        $binding = $this->binding();
        $resetsAtMs = $binding['resetsAtMs'] ?? $this->resetsAtMs;
        $used = $binding === null ? '' : ' at '.Js::numberToString(Js::round($binding['utilization'] * 100)).'% used';

        return "Rate limit: {$this->rateLimitType} window{$used}, resets ".Js::isoString($resetsAtMs).'.';
    }

    public function jsonSerialize(): \stdClass
    {
        $out = new \stdClass;
        $out->status = $this->status;
        $out->resetsAtMs = $this->resetsAtMs;
        $out->rateLimitType = $this->rateLimitType;
        if ($this->overageStatus !== null) {
            $out->overageStatus = $this->overageStatus;
        }
        if ($this->overageDisabledReason !== null) {
            $out->overageDisabledReason = $this->overageDisabledReason;
        }
        if ($this->isUsingOverage !== null) {
            $out->isUsingOverage = $this->isUsingOverage;
        }
        $windows = new \stdClass;
        foreach ($this->windows as $name => $window) {
            $windows->{$name} = (object) $window;
        }
        $out->windows = $windows;
        $out->raw = $this->raw;

        return $out;
    }

    private static function representable(int|float $seconds): bool
    {
        return $seconds * 1000 <= 8.64e15;
    }

    private static function refuse(string $path, string $expected, mixed $got, bool $absent): string
    {
        return "rate_limit: {$path} expected {$expected}, got ".Js::describe($got, $absent);
    }
}

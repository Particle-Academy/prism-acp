<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;

/**
 * Codex's percentage-based rate limit, read from an allow-list of fields: identifiers and unknown fields never pass
 * through. Ported from prism-acp-ts src/codex/rate-limit.ts. `resetsAtMs` is converted from Codex's epoch SECONDS.
 */
final readonly class CodexRateLimit implements \JsonSerializable
{
    /**
     * @param  array{usedPercent: int|float, windowDurationMins: int|float, resetsAtMs: int|float}|null  $primary
     * @param  array{usedPercent: int|float, windowDurationMins: int|float, resetsAtMs: int|float}|null  $secondary
     * @param  array{hasCredits: bool, unlimited: bool, balance: int|float|null}|null  $credits  null when absent
     * @param  mixed  $spendControlReached  bool, null, or Undefined when the payload did not carry it
     * @param  mixed  $rateLimitReachedType  string, null, or Undefined when the payload did not carry it
     */
    public function __construct(
        public ?bool $ordinaryUsageAllowed,
        public ?string $planType,
        public ?array $primary,
        public ?array $secondary,
        public ?array $credits,
        private mixed $spendControlReached,
        private mixed $rateLimitReachedType,
        public int|float|null $availableResetCredits,
    ) {}

    public static function parse(mixed $value): ?self
    {
        $read = self::read($value);

        return $read instanceof self ? $read : null;
    }

    public static function read(mixed $value): self|string
    {
        if (! $value instanceof \stdClass) {
            return self::refuse('payload', 'an object', $value, false);
        }
        $outer = $value;
        $inner = Js::get($value, 'rateLimits');
        $rates = $inner instanceof \stdClass ? $inner : $value;

        // `outer.ordinaryUsageAllowed ?? rates.ordinaryUsageAllowed ?? null`
        $ordinaryUsageAllowed = Js::get($outer, 'ordinaryUsageAllowed') ?? Js::get($rates, 'ordinaryUsageAllowed');
        if ($ordinaryUsageAllowed !== null && ! is_bool($ordinaryUsageAllowed)) {
            return self::refuse('ordinaryUsageAllowed', 'boolean or null', $ordinaryUsageAllowed, false);
        }

        $primary = self::window($rates, 'primary');
        if (is_string($primary)) {
            return $primary;
        }
        $secondary = self::window($rates, 'secondary');
        if (is_string($secondary)) {
            return $secondary;
        }

        $credits = null;
        $rawCredits = Js::get($rates, 'credits');
        if ($rawCredits !== null) {
            if (! $rawCredits instanceof \stdClass) {
                return self::refuse('rateLimits.credits', 'an object or null', $rawCredits, false);
            }
            $hasCredits = Js::get($rawCredits, 'hasCredits');
            if (! is_bool($hasCredits)) {
                return self::refuse('rateLimits.credits.hasCredits', 'boolean', $hasCredits, ! Js::has($rawCredits, 'hasCredits'));
            }
            $unlimited = Js::get($rawCredits, 'unlimited');
            if (! is_bool($unlimited)) {
                return self::refuse('rateLimits.credits.unlimited', 'boolean', $unlimited, ! Js::has($rawCredits, 'unlimited'));
            }
            $balance = Js::get($rawCredits, 'balance');
            // Codex's current CreditsSnapshot uses decimal strings. Keep the reference's numeric public value;
            // whitespace, exponents, signs, booleans, infinity and trailing junk must never become valid credits.
            $numericBalance = is_string($balance) && preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $balance) === 1
                ? (float) $balance : $balance;
            if ($numericBalance !== null && ! Js::finite($numericBalance, 0)) {
                return self::refuse('rateLimits.credits.balance', 'finite non-negative decimal string, number or null', $balance, false);
            }
            $credits = ['hasCredits' => $hasCredits, 'unlimited' => $unlimited, 'balance' => $numericBalance];
        }

        $planType = Js::get($rates, 'planType');
        if (Js::has($rates, 'planType') && ! is_string($planType)) {
            return self::refuse('rateLimits.planType', 'string', $planType, false);
        }

        $spend = Undefined::get($rates, 'spendControlReached');
        if ($spend !== Undefined::Value && $spend !== null && ! is_bool($spend)) {
            return self::refuse('rateLimits.spendControlReached', 'boolean or null', $spend, false);
        }
        $reachedType = Undefined::get($rates, 'rateLimitReachedType');
        if ($reachedType !== Undefined::Value && $reachedType !== null && ! is_string($reachedType)) {
            return self::refuse('rateLimits.rateLimitReachedType', 'string or null', $reachedType, false);
        }

        $resetCredits = null;
        $resetCreditsObject = Js::get($outer, 'rateLimitResetCredits');
        if ($resetCreditsObject instanceof \stdClass && Js::has($resetCreditsObject, 'availableCount')) {
            $resetCredits = $resetCreditsObject->availableCount;
            if (! Js::isSafeInteger($resetCredits) || $resetCredits < 0) {
                return self::refuse('rateLimitResetCredits.availableCount', 'integer >= 0', $resetCredits, false);
            }
        }

        return new self($ordinaryUsageAllowed, is_string($planType) ? $planType : null, $primary, $secondary, $credits, $spend, $reachedType, $resetCredits);
    }

    public function notice(): string
    {
        if ($this->primary === null) {
            return 'Codex rate limit usage is unavailable.';
        }

        return 'Codex rate limit: '.Js::numberToString($this->primary['usedPercent']).'% used in '
            .Js::numberToString($this->primary['windowDurationMins']).' minutes, resets '.Js::isoString($this->primary['resetsAtMs']).'.';
    }

    public function jsonSerialize(): \stdClass
    {
        $out = new \stdClass;
        $out->ordinaryUsageAllowed = $this->ordinaryUsageAllowed;
        if ($this->planType !== null) {
            $out->planType = $this->planType;
        }
        $out->primary = $this->primary === null ? null : (object) $this->primary;
        $out->secondary = $this->secondary === null ? null : (object) $this->secondary;
        if ($this->credits !== null) {
            $out->credits = (object) $this->credits;
        }
        if ($this->spendControlReached !== Undefined::Value) {
            $out->spendControlReached = $this->spendControlReached;
        }
        if ($this->rateLimitReachedType !== Undefined::Value) {
            $out->rateLimitReachedType = $this->rateLimitReachedType;
        }
        if ($this->availableResetCredits !== null) {
            $out->availableResetCredits = $this->availableResetCredits;
        }

        return $out;
    }

    /** @return array{usedPercent: int|float, windowDurationMins: int|float, resetsAtMs: int|float}|string|null */
    private static function window(\stdClass $rates, string $key): array|string|null
    {
        $path = "rateLimits.{$key}";
        $absent = ! Js::has($rates, $key);
        $value = Js::get($rates, $key);
        // An absent window is refused (JS: undefined is not null and not an object); only an explicit null is "none".
        if ($value === null && ! $absent) {
            return null;
        }
        if (! $value instanceof \stdClass) {
            return self::refuse($path, 'an object or null', $value, $absent);
        }
        $used = Js::get($value, 'usedPercent');
        if (! Js::finite($used, 0)) {
            return self::refuse("{$path}.usedPercent", 'finite number >= 0', $used, ! Js::has($value, 'usedPercent'));
        }
        $minutes = Js::get($value, 'windowDurationMins');
        if (! Js::isSafeInteger($minutes) || $minutes < 1) {
            return self::refuse("{$path}.windowDurationMins", 'safe integer >= 1', $minutes, ! Js::has($value, 'windowDurationMins'));
        }
        $resetsAt = Js::get($value, 'resetsAt');
        if (! Js::finite($resetsAt, 5e-324)) {
            return self::refuse("{$path}.resetsAt", 'finite number > 0 (epoch seconds)', $resetsAt, ! Js::has($value, 'resetsAt'));
        }
        $resetsAtMs = $resetsAt * 1000;
        if (! is_finite((float) $resetsAtMs) || $resetsAtMs > 8.64e15) {
            return self::refuse("{$path}.resetsAt", 'valid epoch seconds representable as a JavaScript date', $resetsAt, false);
        }

        return ['usedPercent' => $used, 'windowDurationMins' => $minutes, 'resetsAtMs' => $resetsAtMs];
    }

    private static function refuse(string $path, string $expected, mixed $got, bool $absent): string
    {
        return "codex_rate_limit: {$path} expected {$expected}, got ".Js::describe($got, $absent);
    }
}

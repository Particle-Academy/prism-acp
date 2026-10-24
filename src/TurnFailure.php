<?php

declare(strict_types=1);

namespace Prism\Acp;

use Prism\Acp\Support\Js;

/**
 * Why a turn failed, when the provider said: carried on a TurnOutcome and on the error a failed `session/prompt`
 * answers with (`_meta[particle.academy/turn_failure]`). A client holding a turn "until the limit resets" needs to know
 * the turn failed ON the limit, not infer it from a rate-limit event that happened to arrive in the same turn.
 */
final readonly class TurnFailure implements \JsonSerializable
{
    /**
     * Claude Code's assistant `error` values (claude-code 2.1.301). An unknown value is `other`, never dropped: the
     * provider adds reasons, and a new one must still reach the client.
     */
    public const CLAUDE_KINDS = [
        'rate_limit' => 'rate_limit',
        'overloaded' => 'overloaded',
        'authentication_failed' => 'auth',
        'oauth_org_not_allowed' => 'auth',
        'cloud_credential_error' => 'auth',
        'billing_error' => 'billing',
        'account_on_hold' => 'billing',
        'verification_required' => 'billing',
    ];

    /** Codex's `codexErrorInfo` values (codex-cli 0.156.1). A usage limit is a quota window, held like a rate limit. */
    public const CODEX_KINDS = [
        'usageLimitExceeded' => 'rate_limit',
        'rateLimitExceeded' => 'rate_limit',
        'serverOverloaded' => 'overloaded',
        'unauthorized' => 'auth',
    ];

    public function __construct(
        /** The provider's own reason, verbatim. */
        public string $reason,
        /** 'rate_limit' | 'overloaded' | 'auth' | 'billing' | 'other' */
        public string $kind,
        /** When the limit resets, in epoch milliseconds, ONLY when a rate-limit event in the same turn said so. */
        public int|float|null $resetsAtMs = null,
    ) {}

    /** A failed Codex turn's `error` (TurnError), as a failure; null when it names no reason. */
    public static function fromCodex(mixed $error): ?self
    {
        if (! $error instanceof \stdClass) {
            return null;
        }
        $info = Js::get($error, 'codexErrorInfo');
        $reason = null;
        if (is_string($info)) {
            $reason = $info;
        } elseif ($info instanceof \stdClass) {
            $keys = array_keys(get_object_vars($info));
            $reason = $keys === [] ? null : (string) $keys[0];
        }
        if ($reason === null || $reason === '') {
            return null;
        }

        return new self($reason, self::CODEX_KINDS[$reason] ?? 'other');
    }

    public function jsonSerialize(): \stdClass
    {
        $out = new \stdClass;
        $out->reason = $this->reason;
        $out->kind = $this->kind;
        if ($this->resetsAtMs !== null) {
            $out->resetsAtMs = $this->resetsAtMs;
        }

        return $out;
    }
}

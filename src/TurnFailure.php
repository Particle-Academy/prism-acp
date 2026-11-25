<?php

declare(strict_types=1);

namespace Prism\Acp;

use Prism\Acp\Support\Js;
use Prism\Acp\Support\ProviderText;

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

    /** The five kinds a client acts on. A failure constructed with any other is sent as `other`. */
    public const KINDS = ['rate_limit', 'overloaded', 'auth', 'billing', 'other'];

    /** The last instant a JavaScript Date can hold, in epoch milliseconds. */
    public const MAX_DATE_MS = 8_640_000_000_000_000;

    /** The bound on the reason the wire carries: provider text's bound plus its marker, so bounded text is unchanged. */
    private const WIRE_TEXT_MAX = ProviderText::MAX + 12;

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

    /**
     * This failure as the wire carries it, or null when it names no reason: then no failure data is sent, as for a
     * turn the provider gave no reason for. Ported from prism-acp-ts's wireTurnFailure; see jsonSerialize.
     */
    public function wire(): ?\stdClass
    {
        return $this->reason === '' ? null : $this->jsonSerialize();
    }

    /**
     * The wire form, rebuilt from the documented fields and nothing else, each checked: the reason marked and bounded
     * as provider text (ProviderText::bound), the kind one of KINDS (any other is `other`), and the reset only when
     * it is a date JavaScript can hold (0 to MAX_DATE_MS), rounded up to a whole millisecond, so a client's
     * `new Date(resetsAtMs).toISOString()` never throws. Whoever constructed the failure, this is what is sent.
     */
    public function jsonSerialize(): \stdClass
    {
        $out = new \stdClass;
        $out->reason = ProviderText::bound($this->reason, self::WIRE_TEXT_MAX);
        $out->kind = in_array($this->kind, self::KINDS, true) ? $this->kind : 'other';
        $reset = $this->resetsAtMs;
        if ($reset !== null && is_finite((float) $reset) && $reset >= 0 && $reset <= self::MAX_DATE_MS) {
            $out->resetsAtMs = is_int($reset) ? $reset : (int) ceil($reset);
        }

        return $out;
    }
}

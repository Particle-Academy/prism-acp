# Reference review through 0.48.0

The executed golden baseline stays at 0.25.1. This review classifies the contracts added by TypeScript release 0.48.0.
It does not claim that PHP implements the latest TypeScript release. The inventory was recorded from main at bff4312.
Earlier releases are in the [review through 0.47.0](reference-review-0.47.0.md).

Each row was classified by reading the TypeScript diff between `v0.47.0` and `v0.48.0` and the PHP source. Every
contract in this release applies to the port. Two were already present in PHP. The other thirteen were recorded as gaps,
and none was left out on purpose. Three of those are now present: G-372, G-376 and G-377 were ported from 0.48.0 and
the probes below run against the ported code. Ten remain.

| TS release | Contract | PHP classification | Evidence / gap |
| --- | --- | --- | --- |
| [0.48.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.48.0) | `claudeOverloadedKind`: Claude's `server_error` with `api_error_status` 529 or `api_error_type` `overloaded_error` is kind `overloaded`, and 429 / `rate_limit_error` is `rate_limit` | Applies and missing | `src/Claude/ClaudeTurnFailures.php` reads `error` alone, so this is `other` (executed probe); G-367 |
| 0.48.0 | `codexStatusKind`: Codex `httpConnectionFailed` 529 is `overloaded`, and `responseTooManyFailedAttempts` 429 is `rate_limit` | Applies and missing | `TurnFailure::fromCodex` maps by variant name only (executed probe); G-367 |
| 0.48.0 | `turnFailureRetry`: `retryable`, `retryAfterMs` (an integer from 0 to 3600000, only when the provider stated it) and `resumable` on a turn failure | Applies and missing | `src/TurnFailure.php` has reason, kind and reset time only; G-367 |
| 0.48.0 | `sessionExitedCode`: a prompt sent to an exited session fails with `error.data.code: "session_exited"` | Applies and missing | `AcpAgent` raises the error with no data; G-367 |
| 0.48.0 | `turnFailureWireBounds`: the failure sent to the client is rebuilt from its documented fields. An unknown kind becomes `other`, and a reset must be a date JavaScript can represent | Applies and present | `TurnFailure::wire` and `jsonSerialize` rebuild the documented fields: an unknown kind is `other`, a reset outside 0 to 8.64e15 is dropped and one inside is rounded up, and the reason is marked and bounded as provider text (`Support\ProviderText`). `AcpAgent` sends the rebuilt form (executed probe; `tests/Unit/TurnFailureWireTest.php`); G-372 |
| 0.48.0 | `rateLimitReading`: provider-neutral `particle.academy/rate_limit_reading` on rate-limit notices | Applies and missing | No such meta key; G-373 |
| 0.48.0 | `codexRateLimitWindows`: Codex readings with an omitted window slot, and a notice that names the most-used window | Applies and missing | `src/Codex/CodexRateLimit.php` refuses an absent slot, and its notice reads only `primary` (executed probe); G-374 |
| 0.48.0 | `claudeRateLimitRejected`: a Claude rejection without `unifiedWindows` is still read | Applies and missing | `src/Claude/ClaudeRateLimit.php` refuses it (executed probe); G-374 |
| 0.48.0 | `claudeNoticeWindowName`: the provider's window name is bounded and marked in the notice text | Applies and missing | The notice puts the name in as sent (executed probe); G-374 |
| 0.48.0 | `planTitlePrefix`: every model-written plan title starts with `Step: `, including Codex plan entries | Applies and missing | `PlanProjection::title` has no prefix, and Codex plan entries use the step text as sent (executed probe); G-375 |
| 0.48.0 | `planGateCaps`: a plan update has at most 256 entries, 1024 code points per entry, 16384 bytes of JSON per `_meta`, 65536 code points of markdown, a 4096 path and a 256 plan id | Applies and present | `AcpAgent`'s plan gate, with the caps in `Plan\PlanProjection`. The entries list must be a PHP list, so its count is its length (executed through the gate; `tests/Unit/AgentPlanGateBoundsTest.php`); G-376 |
| 0.48.0 | `planHiddenCharRefusal`: hidden and control characters are refused in a plan id and a markdown path, and control and bidi characters in markdown content | Applies and present | `AcpAgent`'s plan gate, with the sets spelled out code point by code point in `Support\HiddenCharacters` and checked against the reference's own sets on every code point (executed through the gate; `tests/Unit/AgentPlanGateBoundsTest.php`, `tests/Unit/HiddenCharactersTest.php`); G-377 |
| 0.48.0 | `externalDriverContract`: exports for external drivers (`permissionDecision`, `askPermission`, `ALLOW_ONCE_OR_DENY`, `wireTurnFailure`, `isRetryableKind`, `boundRetryAfterMs`, `MAX_RETRY_AFTER_MS`, `PLAN_ENTRIES_MAX`) and a driver that declares `loadSession: false` | Applies and missing; parts depend on G-367 and G-376 | There is no shared permission rule for drivers, and `AcpAgent` always advertises `loadSession`; G-378 |
| 0.48.0 | `codexListFrameGuard`: remote-control frames are refused inside lists and through a serializing object | Applies and present | `CodexRemoteControl::encodedFrame` checks the serialized frame and refuses lists (executed probe); G-342 |
| 0.48.0 | `planEntryPriority`: every plan entry carries `priority`, and Codex entries default to `medium` | Applies and present | `PlanProjection` and the plan gate (executed probe); G-362 |

Changes outside `src/` in this release are TypeScript tests, the vendored ACP schema fixture, release workflows and
signing-bundle tooling. They are not applicable. The plan `_meta` keys `particle.academy/plan` and
`particle.academy/plan_step` came with G-360 and are covered by the plan projection.

The gaps recorded were G-372 to G-378; G-372, G-376 and G-377 are now closed in the port. The four G-367 rows share the overloaded-provider gap, and the three G-374 rows share
one gap. The checker pins each number to its contract. The turn-failure, rate-limit, title, plan-gate and frame-guard
rows run real PHP code. `turnFailureRetry`, `sessionExitedCode`, `rateLimitReading` and `externalDriverContract` are
conservative surface probes, so a surface addition forces another review. None of this is behavioural conformance.

Advancing the reviewed version never advances the golden baseline, source pin or fixtures. No provider, model call or
credential is used.

# Reference review through 0.42.0

The executed golden baseline stays at 0.25.1. This review classifies the contracts added by TypeScript releases 0.38.0
through 0.42.0, including the 0.41.1 and 0.41.2 patches. It does not claim that PHP implements the latest TypeScript
release. The inventory was recorded from main at 2ba6dcd. Earlier releases are in the
[review through 0.37.0](reference-review-0.37.0.md), which still holds for 0.26.0 to 0.37.0.

Each row was classified by reading the TypeScript diff between tags and the PHP source, not by matching method names.
A row is PRESENT only when the PHP behaviour matches. When first reviewed, no row was present; G-299 and G-305 have since been fixed.

| TS release | Contract | PHP classification | Evidence / gap |
| --- | --- | --- | --- |
| [0.38.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.38.0) | `CodexDriver.steer()`, `steerState()`, `acknowledgeSteer()`, `steeringState`, the bounded 128-record receipt ledger, `SteerUnsupported`/`SteerInputError`, `_particle.academy/session/steer`, `steer_state` and `acknowledge_steer` ACP extensions, `particle.academy/steer` and `active_turn` metadata | Applies and missing | `src/Codex/CodexDriver.php` has no steer method and `src/Agent/AcpAgent.php` registers no steer extension; G-297 |
| 0.38.0 | `JsonRpcPeer.request(method, params, {timeoutMs})` and `RpcTimeoutError`, where a deadline means unknown and never "not delivered" | Applies and missing | `src/JsonRpc/JsonRpcPeer.php` `request(string $method, mixed $params)` takes no deadline; G-298 |
| 0.38.0 | Uncorrelated Codex RPC responses are reported without their body | Applies to PHP and is present | `src/Codex/CodexDriver.php` (`UNCORRELATED_RESPONSE`); `tests/Unit/CodexUncorrelatedResponseTest.php`; G-299 fixed |
| [0.39.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.39.0) | Codex `runCommand('compact' \| 'review')`, the exact whole-prompt `/compact` or `/review` form, `available_commands_update` for Codex, `CommandUnsupported` (refused under `nativeTools: 'none'`, on an unmeasured CLI, when busy), and shutdown when a command acknowledgement is lost or malformed | Applies and missing | No `runCommand` in `src/Codex/CodexDriver.php`. PHP sends `/compact` to the model as text; G-300 |
| 0.39.0 | Codex tool calls carry `name` (`shell`, `<server>.<tool>`, `edit`), plus `native_item_type` and `native_item` metadata | Applies and missing | `src/Codex/CodexItems.php` emits no `name`, and `src/Meta.php` has no native item keys; G-301 |
| 0.39.0 | Shared `FileEdit` at `particle.academy/file_edits` for both drivers: workspace-bound lexical paths (refusing escapes, absolute paths outside cwd, NUL and lone surrogates), git-applicable diffs, a warning notice when an edit cannot be represented | Applies and missing (security: path binding) | `src/Meta.php` has no `file_edits` key; `src/Claude/ClaudeToAcp.php` and `src/Codex/CodexItems.php` produce no FileEdit; G-302 |
| 0.39.0 | Frame-budget projection: additive metadata is dropped whole (native item first, then file edits) and recorded under `particle.academy/omitted`; existing fields never change | Applies and missing; recorded with the FileEdit gap | It budgets only the metadata above, so it has no PHP subject yet. Tracked under G-302 |
| [0.40.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.40.0) | `onStopVerdict` on both drivers' events and on `AcpAgentOptions` (with sessionId), idempotent `stop()` returning one frozen verdict, observer throws and rejections isolated from cleanup | Applies and missing | `src/Agent/DriverEvents.php` has no `onStopVerdict`. `CodexDriver::stop` and `ClaudeDriver::stop` start a new stop and return a new Deferred on every call rather than one memoized verdict; G-303 |
| 0.40.0 | `ClaudeCancelStateError.stopVerdict` snapshot | Applies and missing; part of G-238 | The error class does not exist in PHP (G-238) |
| [0.41.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.41.0) | Shipped x64 and ARM64 Windows supervisor checked against a pinned digest; no runtime compiler or executable cache; `supervisorPath()` and `prepareProcessTree({executable})`; `directory` deprecated | Applies and missing: security gap, fix in progress | `src/Process/WindowsSupervisorBuild.php`; G-304 |
| 0.41.0 | `supervisorGenerations` (0.32.0 row) | Still PRESENT as a 0.32.0 contract, superseded | The cache-reader probe still passes. That behaviour is what 0.41.0 replaced, so it is not a reason to call the supervisor current |
| [0.41.1](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.41.1) | Azure Artifact Signing of the shipped supervisors in the release pipeline, `REQUIRE_SUPERVISOR_SIGNING` publish gate | Not applicable | Release tooling for binaries only the TS package ships; runtime behaviour is unchanged (only a source comment). It becomes applicable if PHP ships binaries under G-304 |
| [0.41.2](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.41.2) | `allowedTools`/`disallowedTools` passed as separate argv values; malformed rules refused before launch with `ClaudeToolRuleError` | Applies to PHP and is present | `src/Claude/ClaudeToolRuleError.php`, `src/Claude/ClaudeDriver.php`; `tests/Unit/ClaudeToolRulesTest.php`; G-305 fixed |
| 0.41.2 | Claude Code 2.1.203 documented as the base minimum; CI matrix and AppContainer fixture build bounds | Not applicable | Documentation and TS test infrastructure; no runtime contract |
| [0.42.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.42.0) | `ClaudeDriver.prompt()` returns a turn id; every outcome carries `turnId`; a prompt Claude folds into a running turn settles from `user_message_uuids` with `foldedInto` and never on a timeout; foreign ids settle nothing; a list missing its own prompt goes to `onProtocolError`; cancel during a fold ends both prompts cancelled and keeps the CLI up, and still fails closed if a started prompt is left unaccounted for | Applies and missing (depends on G-238) | `ClaudeDriver::prompt` returns `void` and writes no `uuid` (`promptLine`). The result handler settles by count (`turnsInFlight - 1`, one `pendingOutcome`), so a folded prompt cannot be identified. `src/Agent/TurnOutcome.php` has no `turnId` or `foldedInto`. PHP behaviour under a real fold is not measured here. G-306 |

The ten new gaps are G-297 to G-306. The checker pins each number to its contract, so a missing or different number
refuses. G-299 and G-305 are fixed and enforced as present; the other eight remain open. The thirteen older gaps (G-141, G-140, G-233 through
G-238) are unchanged and remain open.

G-304 is a security gap with a fix in progress. G-302 and G-306 also carry security logic a port must
keep. "PHP has a method of that name" would not have counted as PRESENT for any of them.

The G-299 and G-305 probes run real PHP code (the Codex frame path and `ClaudeDriver::args`); the G-304 probe checks
`src/` for a runtime compiler. See `scripts/reference-contracts.php`. The other new rows are conservative surface
probes (a public method, an option property or a meta key), so a surface addition forces another review. They are not
behavioural conformance.

Advancing the reviewed version never advances the golden baseline, source pin or fixtures. No provider, model call
or credential is used.

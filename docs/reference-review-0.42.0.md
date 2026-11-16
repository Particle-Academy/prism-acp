# Reference review through 0.42.0

The executed golden baseline stays at 0.25.1. This review classifies the contracts added by TypeScript releases 0.38.0
through 0.42.0, including the 0.41.1 and 0.41.2 patches. It does not claim that PHP implements the latest TypeScript
release. The inventory was recorded from main at 2ba6dcd. Earlier releases are in the
[review through 0.37.0](reference-review-0.37.0.md), which still holds for 0.26.0 to 0.37.0.

Each row was classified by reading the TypeScript diff between tags and the PHP source, not by matching method names.
A row is PRESENT only when the PHP behaviour matches. This review found no PRESENT row.

| TS release | Contract | PHP classification | Evidence / gap |
| --- | --- | --- | --- |
| [0.38.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.38.0) | `CodexDriver.steer()`, `steerState()`, `acknowledgeSteer()`, `steeringState`, the bounded 128-record receipt ledger, `SteerUnsupported`/`SteerInputError`, `_particle.academy/session/steer`, `steer_state` and `acknowledge_steer` ACP extensions, `particle.academy/steer` and `active_turn` metadata | Applies and missing | `src/Codex/CodexDriver.php` has no steer method and `src/Agent/AcpAgent.php` registers no steer extension; G-TBD-codex-steer |
| 0.38.0 | `JsonRpcPeer.request(method, params, {timeoutMs})` and `RpcTimeoutError`, where a deadline means unknown and never "not delivered" | Applies and missing | `src/JsonRpc/JsonRpcPeer.php` `request(string $method, mixed $params)` takes no deadline; G-TBD-rpc-request-deadline |
| 0.38.0 | An RPC response that matches no request is reported as `Codex RPC response could not be correlated` with `{type: 'rpc_response_gap'}`, without echoing its body | Applies and missing (security: redaction) | `JsonRpcPeer::onResponse` reports `response for unknown id` with the frame, `CodexDriver::connect` forwards it to `unmapped()`, and `unmapped()` copies it into `particle.academy/unmapped_frame`. `CodexPayload::stripPrivateIdentifiers` removes only account, credit and limit ids. Measured: the new probe's sentinel in an uncorrelated `result` reaches the ACP update. G-TBD-codex-uncorrelated-redaction |
| [0.39.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.39.0) | Codex `runCommand('compact' \| 'review')`, the exact whole-prompt `/compact` or `/review` form, `available_commands_update` for Codex, `CommandUnsupported` (refused under `nativeTools: 'none'`, on an unmeasured CLI, when busy), and shutdown when a command acknowledgement is lost or malformed | Applies and missing | No `runCommand` in `src/Codex/CodexDriver.php`. PHP sends `/compact` to the model as text; G-TBD-codex-commands |
| 0.39.0 | Codex tool calls carry `name` (`shell`, `<server>.<tool>`, `edit`), plus `native_item_type` and `native_item` metadata | Applies and missing | `src/Codex/CodexItems.php` emits no `name`, and `src/Meta.php` has no native item keys; G-TBD-codex-tool-names |
| 0.39.0 | Shared `FileEdit` at `particle.academy/file_edits` for both drivers: workspace-bound lexical paths (refusing escapes, absolute paths outside cwd, NUL and lone surrogates), git-applicable diffs, a warning notice when an edit cannot be represented | Applies and missing (security: path binding) | `src/Meta.php` has no `file_edits` key; `src/Claude/ClaudeToAcp.php` and `src/Codex/CodexItems.php` produce no FileEdit; G-TBD-file-edits |
| 0.39.0 | Frame-budget projection: additive metadata is dropped whole (native item first, then file edits) and recorded under `particle.academy/omitted`; existing fields never change | Applies and missing; recorded with the FileEdit gap | It budgets only the metadata above, so it has no PHP subject yet. Tracked under G-TBD-file-edits |
| [0.40.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.40.0) | `onStopVerdict` on both drivers' events and on `AcpAgentOptions` (with sessionId), idempotent `stop()` returning one frozen verdict, observer throws and rejections isolated from cleanup | Applies and missing | `src/Agent/DriverEvents.php` has no `onStopVerdict`. `CodexDriver::stop` and `ClaudeDriver::stop` start a new stop and return a new Deferred on every call rather than one memoized verdict; G-TBD-stop-verdict-observer |
| 0.40.0 | `ClaudeCancelStateError.stopVerdict` snapshot | Applies and missing; part of G-238 | The error class does not exist in PHP (G-238) |
| [0.41.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.41.0) | A shipped, reproducible x64 and native ARM64 Windows supervisor checked against a pinned Authenticode program-image digest before every use. No runtime compiler, no executable cache, no uncontained fallback. Adds `supervisorPath()`, `prepareProcessTree({executable})` for a host-signed copy, and a deprecation warning for `directory`. Unsupported hosts refuse before provider startup. | Applies and missing (security: supervisor integrity) | `src/Process/WindowsSupervisorBuild.php` still compiles `resources/windows/supervisor.cs` with `csc.exe` per install and trusts the hash it recorded itself (the 0.32.0 generation cache, which TS has now removed). No pinned digest, no ARM64 choice, no host-supplied executable. G-TBD-shipped-supervisor |
| 0.41.0 | `supervisorGenerations` (0.32.0 row) | Still PRESENT as a 0.32.0 contract, superseded | The cache-reader probe still passes. That behaviour is what 0.41.0 replaced, so it is not a reason to call the supervisor current |
| [0.41.1](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.41.1) | Azure Artifact Signing of the shipped supervisors in the release pipeline, `REQUIRE_SUPERVISOR_SIGNING` publish gate | Not applicable | Release tooling for binaries only the TS package ships; runtime behaviour is unchanged (only a source comment). It becomes applicable if PHP ships binaries under G-TBD-shipped-supervisor |
| [0.41.2](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.41.2) | `allowedTools`/`disallowedTools` passed as separate argv values after one flag; empty, option-shaped, control-bearing and malformed-Unicode rules refused before launch with `ClaudeToolRuleError`; arrays snapshotted | Applies and missing (security: argv hardening) | `ClaudeDriver::args` in `src/Claude/ClaudeDriver.php` joins each list with `implode(',', ...)` and validates nothing. Measured by the new argv probe: rule boundaries are merged, and an option-shaped, newline-bearing or empty rule is not refused. PHP arrays are value-copied, so the snapshot part is already inherent. G-TBD-claude-tool-rule-argv |
| 0.41.2 | Claude Code 2.1.203 documented as the base minimum; CI matrix and AppContainer fixture build bounds | Not applicable | Documentation and TS test infrastructure; no runtime contract |
| [0.42.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.42.0) | `ClaudeDriver.prompt()` returns a turn id; every outcome carries `turnId`; a prompt Claude folds into a running turn settles from `user_message_uuids` with `foldedInto` and never on a timeout; foreign ids settle nothing; a list missing its own prompt goes to `onProtocolError`; cancel during a fold ends both prompts cancelled and keeps the CLI up, and still fails closed if a started prompt is left unaccounted for | Applies and missing (depends on G-238) | `ClaudeDriver::prompt` returns `void` and writes no `uuid` (`promptLine`). The result handler settles by count (`turnsInFlight - 1`, one `pendingOutcome`), so a folded prompt cannot be identified. `src/Agent/TurnOutcome.php` has no `turnId` or `foldedInto`. PHP behaviour under a real fold is not measured here. G-TBD-claude-fold-settlement |

The ten new gaps carry `G-TBD-<name>` placeholders until the port-gaps register assigns numbers. The checker pins each
placeholder exactly, so a different name refuses as well as a missing one. None of the ten is implemented by this
inventory change. The thirteen older gaps (G-141, G-140, G-233 through G-238) are unchanged and remain open.

Three of the new gaps are security behaviour, not features: the uncorrelated-response echo, tool-rule argv validation,
and supervisor integrity. Two others carry security logic that a port must keep: FileEdit's workspace path binding,
and the fold settlement's fail-closed cancel path. "PHP has a method of that name" would not have counted as PRESENT
for any of them.

Two new probes exercise real PHP code rather than its surface. The uncorrelated-response probe feeds an RPC response
with an unknown id and an owned sentinel through `CodexDriver`'s real frame path from an in-process transport, then
stops startup before any provider. It is PRESENT only if a notice is emitted and the sentinel appears in no update. No
update at all refuses as unmeasured. The tool-rule probe calls the real `ClaudeDriver::args`. It is PRESENT only if
rules stay separate argv values and option-shaped, newline-bearing and empty rules throw `InvalidArgumentException`.
The supervisor probe is PRESENT only when no file under `src/` references `csc.exe`. The other new rows are
conservative surface probes (a public method, an option property or a meta key), so a surface addition forces
another review. They are not behavioural conformance.

Advancing the reviewed version never advances the golden baseline, source pin or fixtures. No provider, model call
or credential is used.

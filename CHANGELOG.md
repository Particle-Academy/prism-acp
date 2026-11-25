# Changelog

## 0.2.0

### Security

- `AcpAgent` caps every plan update a driver sends, as the TypeScript reference does: 256 entries (counted on the list before any entry is read), 1024 code points per entry `content`, 16384 bytes of JSON per `_meta`, 65536 code points of markdown, a 4096 `path` and a 256 `planId`. Each refusal is reported through `onProtocolError` (G-376).
- The plan gate refuses control characters, matching the TypeScript reference: a `planId` or markdown `path` with a control, format or invisible character, and markdown `content` with a control or bidi character or a bare CR. Text that is not valid UTF-8 is refused. The sets are spelled out in `Support\HiddenCharacters` and checked on every code point against the reference's own (G-377).
- Plan updates are sent as built from their checked fields. A `_meta` is sent as it serialized when it was checked. Every other update is sent as its serialization read back. A read or serialization that throws refuses the update and is reported, and nothing is thrown into the driver (G-376).
- The turn failure a client receives on `session/prompt` is rebuilt from its documented fields (`TurnFailure::wire`). The kind is one of the five or `other`, a reset is sent only when it is a JavaScript date (rounded up), and the reason is marked and bounded as provider text (`Support\ProviderText`) (G-372).
- Claude launches pass exactly one `--settings` value, with `disableRemoteControl: true`. Claude and Codex refuse the `binaryArgs` the reference refuses, with `RemoteControlRefused`, before any process starts. Every Codex frame passes a `remoteControl/*` check before it is written.
- Codex transports refuse list frames. A frame is serialized once, and the bytes that were checked are the bytes written.
- `ChildEnv` withholds the reference's full credential list, and compares names with the reference's Unicode case mapping.

### Breaking changes

- A host that passes its own `--settings`, or another argument the reference refuses, through `binaryArgs` must stop doing so.
- An embedder that sends list frames to a Codex transport must send single frames.
- Plan entries carry only `content`, `priority`, `status` and `_meta`, and plan updates only their documented fields. A plan update over a cap, or holding the characters above, is refused and reported. A non-plan update without a string `sessionUpdate`, or one that cannot be serialized, is refused and reported. Drivers that send ACP-shaped plans within the caps are unaffected.

### Fixed

- Codex plan entries now carry the `priority` field ACP requires. Before, a client built from the ACP schema dropped every Codex plan entry (G-362).
- Codex `turn/plan/delta` is no longer sent as `plan_update` with a `delta` field, which is not an ACP shape. It is reported as an unmapped frame. Codex sends its plan whole, through `turn/plan/updated` (G-362).

### Added

- `Plan\PlanProjection` shows a `proposed` step, one a pending change would add, as `pending` with its real state in `_meta`. Before, it refused the whole plan while a change waited on the person (G-360).
- `Plan\PlanProjection` turns a plan snapshot into `plan`, or into `plan_update` for a client that advertised `clientCapabilities.plan`. Failed and skipped steps are shown unchecked and labelled `[failed]` or `[skipped]`, never `completed`. Each step's real state is in `_meta["particle.academy/plan_step"]` (G-360).
- A driver's options now include `clientCapabilities: {plan: bool}`, read from the client's `initialize`.
- `AcpAgent` checks every plan update a driver sends. It never sends `plan_update` or `plan_removed` to a client that did not advertise `plan`; an items `plan_update` is sent as `plan` instead, and anything else is dropped and reported. An entry without a valid priority is sent as `medium`, and that is reported.

## 0.1.2

### Security

- Codex: a JSON-RPC response that matches no pending request is no longer forwarded. The client receives only the fixed notice "Codex RPC response could not be correlated" (`rpc_response_gap`); the response body, id and error text are dropped (G-299).
- Claude: `allowedTools` and `disallowedTools` are passed as separate argument values, and malformed rules (empty, a leading dash, control characters, invalid UTF-8, non-list arrays) are refused with `ClaudeToolRuleError` (`invalid_claude_tool_rule`) before any process starts (G-305).

### Other

- The PHP NDJSON framer no longer rescans pending lines.
- The reference review now covers the TypeScript package through 0.42.0.

No breaking changes for valid tool rules. A malformed rule that was previously sent now refuses.

## 0.1.1

- Fixed: the package version constant reported `0.1.0-alpha.5` in the 0.1.0 tag. No other changes. Use this release rather than 0.1.0.

## 0.1.0

First stable release.

### Security

- Verify dedicated API-key homes and every descendant's ownership, permissions and file type at admission and before
  credential/provider handoffs. Existing unsafe or unverifiable homes now refuse with an actionable error; the adapter
  does not rewrite their permissions. Windows permits the current user, SYSTEM and Administrators as allow principals,
  with every entry owned by the current process SID, or Administrators only under an elevated token with that group enabled.
  POSIX requires current ownership and no group/other or special bits.
- Protect new homes, independently verify them, and report failed removal as a typed `cleanup_failed` error.
- Protect fresh Windows homes by writing access rules only, preserving their natural owner. This also supports
  app-owned Modify-only parents where rewriting the same owner would require unavailable `WRITE_OWNER` rights.
  Retry never trusts a residual home merely because it exists. Stored file contents are never opened, read, copied or hashed.
- Retain temporary Claude configuration and approval-directory cleanup state after a failed removal. After process stop,
  make a deferred retry. On Windows only known, unstat-able approval entries permit non-blocking retries within a fixed
  three-second ceiling; unknown entries fail immediately. Exhaustion reports a terminal protocol problem without changing
  the process-stop verdict or resetting the budget on repeated stops.
- Bound metadata verification to depth 16, 16,384 descendants and 30 seconds, with explicit safe limit reasons. Symlinks
  and reparse points refuse the entire tree. See [credential-home protection](docs/credential-home-protection.md) for
  operator limits and manual permission repair. Subscription authentication remains the default.

## 0.1.0-alpha.5

BREAKING CHANGE: permission prompts from non-CLI SAPIs now require an explicit absolute trusted `phpBinary`
verified as a PHP CLI. The implicit `php` lookup on PATH and implicit phpdbg/CLI-server selection are removed.
Claude permission prompts require a stable CLI version of at least 2.1.295; 2.1.310 is the native regression pin.

- Use an exec-form native approval adapter and internal explicit-allow status42. Silent zero exits, malformed
  responses, runtime failures and timeouts block the call. Hook paths are arguments, and inherited PHP ini files
  cannot inject hook code. Permission-channel deadlines deny late decisions and release their connections.
- Hold prompts and EOF until the initialize version is verified; a missing/changed later system/init version
  refuses the session. Trusted timeout/interpreter policy survives `forSession()` and is not ACP configuration.
- Publish the Windows supervisor and approval adapter as independently hash-verified immutable generations with
  an atomic digest pointer. Preserve private cache ACL inheritance and bad-cache/no-compiler refusal.
- Add actual native-Claude approval controls, CLI/CGI/phpdbg/FPM runtime measurements, and parallel cold-cache
  comparisons against the released old cache producer. Remaining port gaps and Windows-only containment remain.

## 0.1.0-alpha.4

Additive host-configured API-key mode for both Windows drivers; subscription authentication remains the default.

- `ProviderCredentials` selects app/tenant API keys and a separate absolute native configuration home. Default,
  moved and canonical aliases of subscription homes are refused; newly created homes have private permissions.
- Claude receives only the explicit Anthropic key and app home. Codex confirms `account/login/start` before starting
  or resuming a thread; refused or unconfirmed login never falls back. Keys stay out of argv and reported errors.
- Explicit environment settings replace alternate-case inherited names, so a Windows child cannot choose the old home.
- New Windows homes and temporary directories clear prior explicit ACL grants as well as inherited grants before
  receiving credentials or approval material; a protected DACL alone does not establish exclusive access (G-118).
- Redact the explicit Claude key from stderr callbacks/storage even across chunk boundaries, including a dangling
  prefix at stream end; preserve other diagnostic text. Match the published TS0.25.1 fix for G-116.
- Credentials remain trusted embedder configuration and survive `forSession()` unchanged; ACP cannot supply them.
  Native no-inference authentication controls and published-reference credential/driver goldens cover both modes.

No CLI key arguments, POSIX containment or full G-98 parity is added. The app owns existing homes and their access
policy. Codex persists API keys in its native home; separate homes do not share subscription settings or sessions.

## 0.1.0-alpha.3

Additive subscription Codex support for the Windows preview; pre-release APIs may change.

- Contained Codex app-server transport and driver: start/resume, paged transcript replay, typed prompts, model
  selection, tool and reasoning updates, usage, five approval families and cancellation that preserves the session.
- Per-child MCP bearer bindings, shell environment exclusions and snapshot disabling; constructor-only selective
  denial of inherited MCP servers for both drivers, preserving Claude's permission hooks in one private settings file.
- Explicit `--provider=codex` and `--codex-binary`; Claude remains the default with wildcard tool approval.
- Golden protocol comparisons against published TypeScript 0.25.0, native fixture controls and process tests.

Scope: Windows with signed-in subscriptions. API-key mode follows separately. Linux/macOS containment, Laravel
auto-discovery and standalone session-store helpers remain deferred. Known reference model paging and hardcoded
client metadata limitations (G-99/G-100) remain shared rather than silently diverging in this port.

## 0.1.0-alpha.2

Non-breaking hardening for the Windows / Claude preview.

- Force stdin-relay PHP diagnostics to stderr, with duplicate error logging disabled, so a notice while reading
  cannot enter the client's ACP input. A real-supervisor regression forces a read notice and checks byte-exact input.

## 0.1.0-alpha.1

Initial pre-release. No previous published API exists; pre-release APIs may change.

- Plain-PHP ACP agent and Claude driver for Windows: sessions, prompts, cancellation, model selection,
  permission round trips, rate limits and explicit failure outcomes.
- Job Object ownership of providers, descendants and the stdin relay; detached-grandchild and blocked-reader
  host-death regression tests.
- No third-party runtime dependencies; child environment allowlist and subscription-credential withholding.
- Golden comparisons against the published TypeScript reference and CI on Windows and Ubuntu.

Scope: Claude on Windows. Codex, Linux/macOS containment, Laravel auto-discovery and transcript replay are deferred.

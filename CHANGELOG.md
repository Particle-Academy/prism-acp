# Changelog

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

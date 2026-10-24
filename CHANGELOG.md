# Changelog

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

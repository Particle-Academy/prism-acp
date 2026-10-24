# Changelog

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

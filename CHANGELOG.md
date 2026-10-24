# Changelog

## 0.1.0-alpha.1

Initial pre-release. No previous published API exists; pre-release APIs may change.

- Plain-PHP ACP agent and Claude driver for Windows: sessions, prompts, cancellation, model selection,
  permission round trips, rate limits and explicit failure outcomes.
- Job Object ownership of providers, descendants and the stdin relay; detached-grandchild and blocked-reader
  host-death regression tests.
- No third-party runtime dependencies; child environment allowlist and subscription-credential withholding.
- Golden comparisons against the published TypeScript reference and CI on Windows and Ubuntu.

Scope: Claude on Windows. Codex, Linux/macOS containment, Laravel auto-discovery and transcript replay are deferred.

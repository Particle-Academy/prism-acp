# Reference review through 0.37.0

The executed golden baseline stays at 0.25.1. This review classifies later contracts; it does not claim that PHP implements the latest TypeScript release. The PHP source reviewed is main at 7aabb123, version 0.1.1.

| TS release | Contract | PHP classification | Evidence / gap |
| --- | --- | --- | --- |
| [0.34.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.34.0) | Claude settingSources | Applies and missing | No option property; G-233 |
| 0.34.0 | Claude strictMcpConfig | Applies and missing | No option property; G-234 |
| [0.35.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.35.0) | Claude appendSystemPromptFile | Applies and missing | No option property; G-235 |
| 0.35.0 | Claude pluginDirs | Applies and missing | No option property; G-236 |
| 0.35.0 | Default agent version and caller override | Applies to PHP and is present | Actual initialize dispatch reports Version::VERSION; explicit caller info wins; no provider is constructed |
| 0.35.0 | Reading package.json with an unknown fallback | TS-only packaging detail | PHP uses its own Version constant; the public version-reporting behavior is checked above |
| [0.36.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.36.0) | Bounded per-thread Codex config | Applies and missing | No config option property; G-237 |
| [0.36.1](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.36.1) | Search new NDJSON bytes without rescanning the pending line | Applies and missing on main | Bounded native search-work probe observes repeated pending-byte searches; G-232. PR #13 contains the measured fix and remains unmerged |
| [0.37.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.37.0) | cancel_queued, UUID receipt accounting, terminal typed uncertainty refusal | Applies and missing | No ClaudeCancelStateError surface, UUID receipt accounting or terminal guard; G-238 |

G-233 through G-238 are separately registered gaps. None is implemented by this inventory change. The seven older gaps remain listed independently; the broader G-141 catch-up remains open. G-231 is closed for TS and is not reopened by the PHP gap.

The option/class probes are conservative surface inventory, not native behavioral conformance. Surface additions force another review. Default and custom agent versions are exercised through real initialize dispatch with a driver factory that throws if invoked. The framing probe intercepts only the native strpos function in its own CLI process, forwards it unchanged, counts the search ranges for a small synthetic line split into chunks, and validates the completed JSON value. It uses no timing threshold, provider, model call or credential. Zero observed searches refuse as unmeasured.

When PR #13 lands, its framing observation must become present and the G-232 missing entry/count must be removed in that PR. Other missing rows must remain. Advancing the reviewed version never advances the golden baseline, source pin or fixtures.

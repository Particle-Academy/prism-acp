# Reference review through 0.47.0

The executed golden baseline stays at 0.25.1. This review classifies the contracts added by TypeScript releases 0.43.0
through 0.47.0. It does not claim that PHP implements the latest TypeScript release. The inventory was recorded from
main at 9d4cc47. Earlier releases are in the [review through 0.42.0](reference-review-0.42.0.md).

Each row was classified by reading the TypeScript diff between tags and the PHP source. Two contracts in this range are
present in PHP (the child-environment credential list and Claude launch settings). None was deliberately left out: each
other one applies to the port and is recorded as a gap.

| TS release | Contract | PHP classification | Evidence / gap |
| --- | --- | --- | --- |
| [0.43.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.43.0) | Codex turn failures carry a bounded, marked `providerMessage` and allowlisted `providerDetails` | Applies and missing | `src/TurnFailure.php` has reason, kind and reset time only; G-329 |
| 0.43.0 | Claude `agent_message_chunk` carries a stable root `messageId` | Applies and missing | `src/Claude/ClaudeToAcp.php` emits none (executed probe); G-330 |
| [0.44.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.44.0) | A turn the Claude CLI starts itself ends with `unsolicited` and never answers a pending prompt | Applies and missing | `src/Agent/TurnOutcome.php` has no such field; `ClaudeDriver` settles results by count. Behaviour under a real self-started turn is not measured here; G-331 |
| 0.44.0 | `ping()`, `lastFrameAt` on both drivers, and `running` on Codex | Applies and missing | Only `ClaudeDriver::running()` exists; G-332 |
| [0.45.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.45.0) | Claude account selection: `claudeAccounts` and `configDir` | Applies and missing | No account API or option; G-333 |
| 0.45.0 | Child-environment credential list | Present | `src/Env/ChildEnv.php` |
| [0.46.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.46.0) | Shared Claude sessions across account directories | Applies and missing; depends on G-333 | No `prepare` equivalent; G-335 |
| 0.46.0 | Launch settings and remote-control refusal | Present for Claude and Codex | `src/Claude/ClaudeLaunchSettings.php`, `src/Codex/CodexRemoteControl.php` |
| [0.47.0](https://github.com/Particle-Academy/prism-acp-ts/releases/tag/v0.47.0) | Browser-only Claude sign-in and sign-out | Applies and missing; depends on G-333 | No login API; G-337 |

Changes outside `src/` in this range (release workflows, signing-bundle tooling, an account probe script) are TypeScript
release and test infrastructure, so they are not applicable. `listModels` account options are part of G-333.

The seven remaining gaps in this range are G-329 to G-333, G-335 and G-337, and the checker pins each number to its contract. `claudeMessageId`,
`childEnvCredentialList` and `claudeLaunchSettings` run real PHP code; the other rows are conservative surface probes,
so a surface addition forces another review. They are not behavioural conformance.

Advancing the reviewed version never advances the golden baseline, source pin or fixtures. No provider, model call or
credential is used.

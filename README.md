# Prism ACP for PHP

Speak the Agent Client Protocol to the Claude CLI you have already signed in to, from plain PHP or an application
that uses PHP. The core has no third-party runtime dependencies and does not require Laravel or an API key.

**Initial Windows / Claude preview.** This release does not implement the Codex driver or Linux/macOS process
containment. Those platforms are refused before a provider starts. Laravel applications can use the core classes;
automatic service-provider discovery and an artisan command are not included. The pre-release API may change.

## Install

```sh
composer require particle-academy/prism-acp:0.1.0-alpha.1
```

Requires PHP 8.2 or newer, `ext-ctype`, `ext-json`, `ext-mbstring`, Windows with the .NET Framework 4 C# compiler, and an installed,
signed-in Claude CLI. The supervisor is compiled locally from the included C# source and hash-checked on every
launch. Node is needed only if the chosen Claude installation is an npm shim; it is not a PHP runtime dependency.

## Run as an ACP agent

Configure your ACP client to start this command with an absolute working directory:

```sh
php vendor/bin/prism-acp --cwd=C:/Projects/my-app --model=haiku
```

The client exchanges newline-delimited JSON on stdin/stdout. Diagnostics use stderr. `--help` and `--version` do
not launch Claude. `--claude-binary=C:/absolute/path/claude.exe` selects a particular installation. Unknown options
are refused. The server supports `initialize`, `session/new`, `session/load`, `session/prompt`, `session/cancel`
and `session/set_model`; model choices come from the CLI's initialize response.

The CLI requests permission through the ACP client before every tool call. Only an explicit `allow_once` answer
allows the tool; cancellation, a missing handler, malformed replies and hook failures block it. Reasons accompany
both allow and deny. This is a tool-approval mechanism, not an operating-system sandbox: a permitted command has
the signed-in user's permissions. Use a trusted ACP client and working directory.

## Embed the agent

```php
use Prism\Acp\Agent\{AcpAgent, DriverEvents, Stdio};
use Prism\Acp\Claude\{ClaudeDriver, ClaudeDriverOptions};
use Prism\Acp\JsonRpc\JsonRpcPeer;

$base = new ClaudeDriverOptions(
    cwd: 'C:/Projects/my-app',
    model: 'haiku',
    permissionPrompts: ['*'], // every tool, including new tools and MCP tools
);
$served = Stdio::serve(Stdio::stdin(), STDOUT, fn (JsonRpcPeer $peer) => new AcpAgent(
    peer: $peer,
    driverFactory: fn (array $session, DriverEvents $events) =>
        new ClaudeDriver($base->forSession($session), $events),
    driverCapabilities: [...ClaudeDriver::CAPABILITIES, 'permissionRequests' => true],
));
$served['closed']->await();
```

Load your application's Composer autoloader before this example. `permissionPrompts` can instead list individual
tool identifiers, such as `['Bash', 'Write']`. Omitting it leaves permission handling to Claude's own configuration;
an embedding application must choose its policy deliberately. Explicit per-session MCP configuration is supported.

## Process ownership and environment

Every provider and the Windows stdin relay enter a Job Object before their first instruction. Kill-on-close and
no breakaway cover descendants, including detached grandchildren. Losing the host's control connection terminates
the job even when the relay is blocked reading stdin. A stop returns `{graceful, empty, survivors, verified}`;
`verified: false` means no supervisor observation was obtained and must not be reported as proven cleanup.

Commands use argument arrays and never a shell. Relative binary paths and arbitrary batch files are refused;
recognized npm shims resolve to Node plus their script. Child environments use an allowlist. Credentials that
override a CLI subscription, including `ANTHROPIC_API_KEY` and `OPENAI_API_KEY`, are withheld by default.
Temporary approval/MCP files have restricted permissions. The package emits no telemetry.

## Verification and limits

Tests compare the PHP mapper's bytes against published `@particle-academy/prism-acp` 0.24.1, using real capture
fixtures from its corresponding source commit, hostile mutations and 648 reader cases. Agent scenarios compare
requests, permissions and failures against that reference. CI regenerates these expectations and fails on drift.
The reference version is explicit; this is not a claim of complete TypeScript feature parity.

Windows CI runs fake-provider protocol and real process-tree tests, including a host hard kill while relay input
stays open. Ubuntu checks the portable primitives and verifies that unsupported containment refuses before spawn.
No CI job signs in to a provider or runs a live model turn. A prior local Windows Claude session and resume were
dogfooded in Prism Labs; native Linux live verification has not been performed.

Three JSON differences from JavaScript are intentional: lone UTF-16 surrogates are rejected; NUL-led object keys
are rejected; nesting is limited to 512. Binary resource prompts and transcript replay are not supported.
Codex rate-limit/failure readers exist as primitives, but there is no runnable Codex driver in this release.

Development checks:

```sh
composer install
composer test
composer types
php vendor/bin/pint --test
composer rector
composer audit
```

See [the release procedure](docs/releasing.md), [transport details](docs/windows-supervisor-protocol.md) and
[the changelog](CHANGELOG.md). Report vulnerabilities privately through GitHub's security advisory mechanism.

## License

MIT. See [LICENSE](LICENSE).

# Prism ACP for PHP

Speak the Agent Client Protocol to Claude or Codex from plain PHP or an application that uses PHP, through a signed-in
subscription or an API key supplied by the app. The core has no third-party runtime dependencies and does not require Laravel.

**Windows preview for Claude and Codex.** Linux/macOS process containment is not implemented;
those platforms are refused before a provider starts. Laravel applications can use the core classes;
automatic service-provider discovery and an artisan command are not included. The pre-release API may change.

## Install

```sh
composer require particle-academy/prism-acp:0.1.0-alpha.5
```

Requires PHP 8.2 or newer, `ext-ctype`, `ext-json`, `ext-mbstring`, Windows with the .NET Framework 4 C# compiler, and an installed,
Claude or Codex CLI. The shipped command uses its signed-in subscription; embedders can supply API keys as shown below.
The supervisor is compiled locally from the included C# source and hash-checked on every
launch. Node is needed only if the chosen installation is an npm shim; it is not a PHP runtime dependency.

## Run as an ACP agent

Configure your ACP client to start this command with an absolute working directory:

```sh
php vendor/bin/prism-acp --cwd=C:/Projects/my-app --model=haiku
php vendor/bin/prism-acp --provider=codex --cwd=C:/Projects/my-app
```

The client exchanges newline-delimited JSON on stdin/stdout. Diagnostics use stderr. `--help` and `--version` do
not launch a provider. Claude is the default. `--claude-binary=C:/absolute/path/claude.exe` and
`--codex-binary=C:/absolute/path/codex.exe` select particular installations. Unknown options
are refused. The server supports `initialize`, `session/new`, `session/load`, `session/prompt`, `session/cancel`
and `session/set_model`; model choices come from Claude's initialize response or Codex's model catalog.

The Claude CLI requests permission through the ACP client before every tool call. Only an explicit `allow_once` answer
allows the tool; cancellation, a missing handler, malformed replies and hook failures block it. Reasons accompany
both allow and deny. This is a tool-approval mechanism, not an operating-system sandbox: a permitted command has
the signed-in user's permissions. Use a trusted ACP client and working directory.

With permission prompts enabled, a stable Claude Code version of at least 2.1.295 is required; the native test pin
is 2.1.310. Prompts and EOF wait for its initialize version, and a missing or changed later system/init version
closes the session. The native exec-form hook allows only its script's validated explicit allow, and blocks silent
zero exits, invalid responses, crashes and timeouts. Its PHP child uses `-n` and fixed diagnostic settings, so hook
execution does not load the application's ini files or require optional extensions.

Codex uses `on-request` approval and its own sandbox default. Its five native command/file/permission approval
families map to the choices shown by the ACP client; only an offered choice can allow. Cancellation and missing,
invalid or failed handlers deny. Unsupported MCP elicitation is explicitly declined. Cancelling a turn interrupts
that turn and keeps the app-server and native thread available for another prompt. Loading a native thread replays
its paged transcript; Claude does not provide transcript replay through this driver.

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

The hook interpreter defaults to `PHP_BINARY` only when the embedding SAPI is `cli`. For CGI, FPM, phpdbg,
CLI-server or another embedded SAPI, supply `phpBinary: 'C:/absolute/path/php.exe'` in trusted constructor options.
An implicit PATH lookup is refused. The package probes that executable as a working PHP CLI under containment
before creating the hook configuration. An explicit CGI/debugger executable is refused too. This changes the
previous non-CLI fallback; it does not add Linux/macOS provider containment. The optional trusted
`permissionTimeoutSeconds` ranges from 1 to 86400 (default 86400); the channel and native guard expire before the
CLI's hook timeout. Both options survive `forSession()` and cannot be supplied through ACP.

Concurrent Windows first launches publish digest-named supervisor generations before atomically replacing a small
digest pointer. Every reader validates the pointer, filename and actual bytes. Existing generations remain available
to sessions that selected them; a damaged cache is rebuilt once or refused. The cache filesystem must support hard
links and atomic replacement; no uncontained fallback is offered.

For Codex, use `CodexDriverOptions` and `CodexDriver` from `Prism\Acp\Codex` in the same factory, with
`driverCapabilities: CodexDriver::CAPABILITIES`. The constructor also accepts trusted `approvalPolicy`, `sandbox`,
`mcpToolApproval` and `turnInactivityTimeoutMs` options. `never` with `danger-full-access` removes approvals and
Codex's sandbox; choose those options only as host policy. The default ten-minute inactivity timer pauses while
native work or an approval is outstanding. Typed prompts accept text, images and text resources; images use private
temporary files removed when the driver stops.

Both option classes accept `disabledMcpServers: ['inherited-name']` as trusted host policy. `forSession()` preserves
that policy and copies only the ACP cwd, explicit servers and resume id. Other inherited servers remain available.
Claude combines this deny list and its approval hooks in one private settings file. Codex reads effective inherited
transports before setting `enabled: false`; malformed configuration refuses startup rather than silently dropping policy.

Codex HTTP MCP bearer headers are rebound to a fresh variable in each child's environment and removed from thread
configuration. The same config disables shell snapshots and preserves effective shell policy while excluding precisely
those variables from model-run commands. Conflicting shell `set` assignments and malformed or mixed filter policies
refuse startup. This protects against an inherited bearer selector overriding an explicit session token. It is not
an operating-system credential vault: trusted native providers and code with the same user's access remain in the boundary.

## App or tenant API keys

A system uses subscriptions or API keys. Choose this as trusted app/tenant configuration, independently of what an
ACP client sends. Omit `credentials` (or use `new ProviderCredentials()`) for the existing subscription behavior.
Both driver option classes accept the same credential object:

```php
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Codex\CodexDriverOptions;

$base = new CodexDriverOptions(
    cwd: 'C:/Projects/my-app',
    credentials: new ProviderCredentials(
        mode: 'apiKey',
        apiKey: $tenantOpenAiKey,
        home: 'C:/AppState/tenant-42/codex',
    ),
);
```

For Claude, pass the same object to `ClaudeDriverOptions` with the tenant's Anthropic key and its own Claude home.
`forSession()` preserves credentials and host policy; `session/new`, `session/load` and model output cannot replace
them. The shipped CLI has no key arguments. No environment variable in the PHP application is changed.

Keys must be non-empty and contain no ASCII control characters; the adapter imposes no prefix or length format.
The home must be an absolute app/tenant path, separate from the user's default or moved subscription home, including
case and filesystem aliases. A new home is restricted to the current user before launch; an existing home is accepted
with the application's chosen permissions. Use the same home to resume its sessions. Separate homes have separate
native settings, MCP servers, skills and history.

Claude receives the explicit key through `ANTHROPIC_API_KEY` and its home through `CLAUDE_CONFIG_DIR`. Codex receives
only `CODEX_HOME` in its environment, then the key through `account/login/start`; the adapter requires an API-key
acknowledgment before any thread starts or resumes. Refusal does not fall back to a subscription. Alternate-case
inherited home variables are replaced, and other inherited subscription-overriding credentials remain withheld.
Keys are absent from argv, default credential-object JSON/debug output and login diagnostics. The explicit Claude
key is redacted from stderr callbacks/storage across chunk boundaries, preserving other diagnostic text and withholding
a dangling key prefix at stream end. This follows the published TS0.25.1 fix for G-116; subscription diagnostics remain
unchanged. Native Codex stores
the key in `auth.json` inside that home; the native provider and code running as the same OS user can access it.

The optional source-checkout command `php tools/native/credentials.php CODEX_EXE CLAUDE_EXE [INSTALLED_AUTOLOAD]`
uses dummy keys and owned temporary homes with no model inference. It confirms native Codex account/key persistence
and Claude's [authentication status and selected configuration directory](https://code.claude.com/docs/en/cli-reference),
preserved subscription sentinels and verified process cleanup. Measured versions are Codex0.163.0 and Claude2.1.308.
Claude's read-only status command runs inside the driver's Job Object through a fixture relay that inherits its exact
child environment. The script leaves its dummy fixture root for explicit cleanup by the invoking gate.

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

Tests compare the PHP mapper's bytes against published `@particle-academy/prism-acp` 0.25.1, using real Claude capture
fixtures from its corresponding source commit, hostile mutations and 648 reader cases. Agent scenarios compare
requests, permissions and failures against that reference. CI regenerates these expectations and fails on drift.
Codex scenarios exercise the published driver's actual state machine through synthetic protocol frames, including
outbound RPC, updates, approvals, failures and MCP policy. They are synthetic scenarios, not native turn captures.
The reference version is explicit; this is not a claim of complete TypeScript feature parity.

Windows CI runs fake-provider protocol and real process-tree tests, including a host hard kill while relay input
stays open. Ubuntu checks the portable primitives and verifies that unsupported containment refuses before spawn.
No CI job signs in to a provider or runs a live model turn. A prior local Windows Claude session and resume were
dogfooded in Prism Labs; native Linux live verification has not been performed.

From a source checkout, the optional `node tools/native/codex-mcp.mjs PHP_EXE CODEX_EXE [INSTALLED_AUTOLOAD]`
control uses native Codex 0.163.0 with an owned
temporary home and deterministic loopback model/MCP responses. It checks start and resume, explicit bearer selection,
actual shell environment exclusion, selective denial, unrelated policy retention and verified process cleanup.
It uses no real credentials or paid inference. Snapshot disabling is asserted in the protocol oracle; PHP does not
claim native Linux snapshot verification. A newer native CLI requires reviewing this measured control version.
Pass a consumer installation's autoloader to exercise its installed classes rather than the checkout's classes.

Three JSON differences from JavaScript are intentional: lone UTF-16 surrogates become U+FFFD; NUL-led object keys
are rejected; nesting is limited to 512. Binary resource prompts are not supported. The pinned reference's model
catalog stops after twenty pages (G-99), and its initialize client metadata is hardcoded (G-100); this port preserves
those shared limitations pending their coordinated fixes. Standalone session-store/model helper APIs and full
TypeScript feature parity remain outside this cut.

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

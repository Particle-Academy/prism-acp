<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;
use Tests\Support\ScriptedCodexTransport;

function credentialScratch(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-key-test-'.bin2hex(random_bytes(5));
    mkdir($path, 0700);

    return $path;
}

it('keeps subscription mode unchanged and does not require a home or a key', function (): void {
    expect(ApiKeyHome::check('claude', null, []))->toBeNull()
        ->and(ApiKeyHome::check('codex', new ProviderCredentials, []))->toBeNull();
});

it('creates a nested app home privately, accepts existing homes and imposes no key prefix policy', function (): void {
    $root = credentialScratch();
    $home = $root.DIRECTORY_SEPARATOR.'tenant'.DIRECTORY_SEPARATOR.'provider';
    try {
        $checked = ApiKeyHome::check('claude', new ProviderCredentials('apiKey', 'arbitrary dummy token', $home), []);
        // The returned home preserves the normalized input, including Windows 8.3 spellings.
        // Canonical identity is used for subscription-home refusal, not for rewriting the app's path.
        expect($checked->home)->toBe($home)->and($checked->key())->toBe('arbitrary dummy token')
            ->and(ApiKeyHome::check('claude', new ProviderCredentials('apiKey', 'replacement dummy', $home), [])->home)->toBe($checked->home);
        if (PHP_OS_FAMILY !== 'Windows') {
            expect(fileperms($home) & 0777)->toBe(0700);
        } else {
            $template = <<<'PS'
            $acl = Get-Acl -LiteralPath 'FIXTURE_HOME';
            $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value;
            $ok = $acl.AreAccessRulesProtected -and $acl.Access.Count -gt 0;
            foreach ($rule in $acl.Access) {
                if ($rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value -ne $sid -or $rule.AccessControlType -ne 'Allow') { $ok = $false }
            }
            if ($ok) { exit 0 } else { exit 1 }
            PS;
            $command = str_replace('FIXTURE_HOME', str_replace("'", "''", $home), $template);
            $powershell = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
            $process = proc_open([$powershell, '-NoProfile', '-NonInteractive', '-Command', $command],
                [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, options: ['bypass_shell' => true]);
            expect(is_resource($process) ? proc_close($process) : -1, 'new home must have only the current identity, with inheritance removed')->toBe(0);
        }
    } finally {
        @rmdir($home);
        @rmdir(dirname($home));
        @rmdir($root);
    }
});

it('refuses malformed keys and modes with field-only messages', function (ProviderCredentials $credentials, string $field): void {
    try {
        ApiKeyHome::check('codex', $credentials, []);
        throw new LogicException('invalid credentials were accepted');
    } catch (InvalidArgumentException $error) {
        expect($error->getMessage())->toContain($field)->not->toContain('dummy-private-key');
    }
})->with([
    [new ProviderCredentials('unknown', 'dummy-private-key', 'relative'), 'credentials.mode'],
    [new ProviderCredentials('apiKey', '', 'relative'), 'credentials.apiKey'],
    [new ProviderCredentials('apiKey', " \u{FEFF}\u{A0}", 'relative'), 'credentials.apiKey'],
    [new ProviderCredentials('apiKey', "dummy-private-key\n", 'relative'), 'credentials.apiKey'],
    [new ProviderCredentials('apiKey', "dummy-private-key\0", 'relative'), 'credentials.apiKey'],
    [new ProviderCredentials('apiKey', 'dummy-private-key', 'relative'), 'credentials.home'],
]);

it('rejects an existing file and leaves its contents untouched', function (): void {
    $root = credentialScratch();
    $file = $root.DIRECTORY_SEPARATOR.'file';
    file_put_contents($file, 'existing sentinel');
    try {
        expect(fn () => ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'dummy', $file), []))->toThrow('not a directory')
            ->and(file_get_contents($file))->toBe('existing sentinel');
    } finally {
        unlink($file);
        rmdir($root);
    }
});

it('removes its empty new home after a real Windows permission setup failure before any provider starts', function (): void {
    $root = credentialScratch();
    $home = $root.DIRECTORY_SEPARATOR.'new-home';
    $code = <<<'PHP'
    require $argv[1];
    try {
        \Prism\Acp\Credentials\ApiKeyHome::check('codex', new \Prism\Acp\Credentials\ProviderCredentials('apiKey', 'dummy', $argv[2]), []);
        echo json_encode(['failed' => false, 'removed' => false]);
    } catch (Throwable) {
        echo json_encode(['failed' => true, 'removed' => !is_dir($argv[2])]);
    }
    PHP;
    // Only this isolated host receives the broken system-tool path; the test runner's environment stays intact.
    $env = ChildEnv::build(getenv(), [], ['SystemRoot' => $root.DIRECTORY_SEPARATOR.'missing-system-tools'])->env;
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $code, dirname(__DIR__, 2).'/vendor/autoload.php', $home],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: $env, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('permission-failure host did not start');
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and(json_decode($out, true, flags: JSON_THROW_ON_ERROR))->toBe(['failed' => true, 'removed' => true]);
    } finally {
        if (is_dir($home)) {
            rmdir($home);
        } rmdir($root);
    }
})->skip(PHP_OS_FAMILY !== 'Windows', 'the Windows private-home setup uses native identity and ACL tools');

it('rejects default subscription homes before creating or changing anything', function (string $provider): void {
    $nativeHome = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
    $home = $nativeHome.DIRECTORY_SEPARATOR.'.'.$provider;
    expect(fn () => ApiKeyHome::check($provider, new ProviderCredentials('apiKey', 'dummy', $home), []))->toThrow('own');
})->with(['claude', 'codex']);

it('rejects moved subscription homes including casing, dot segments and trailing separators', function (string $provider, string $variable): void {
    $root = credentialScratch();
    $home = $root.DIRECTORY_SEPARATOR.'subscription';
    $candidate = $home.DIRECTORY_SEPARATOR.'x'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
    if (PHP_OS_FAMILY === 'Windows') {
        $candidate = strtoupper($candidate);
    }
    try {
        expect(fn () => ApiKeyHome::check($provider, new ProviderCredentials('apiKey', 'dummy', $candidate), [strtolower($variable) => $home]))
            ->toThrow('own')->and(file_exists($home))->toBeFalse();
    } finally {
        if (is_dir($home)) {
            rmdir($home);
        }
        rmdir($root);
    }
})->with([['claude', 'CLAUDE_CONFIG_DIR'], ['codex', 'CODEX_HOME']]);

it('rejects a junction or symlink alias of a moved subscription home', function (): void {
    $root = credentialScratch();
    $home = $root.DIRECTORY_SEPARATOR.'subscription';
    $alias = $root.DIRECTORY_SEPARATOR.'alias';
    mkdir($home);
    try {
        if (PHP_OS_FAMILY === 'Windows') {
            // Junctions need no symlink privilege. Quote these exact owned paths as PowerShell literals.
            $command = "New-Item -ItemType Junction -Path '".str_replace("'", "''", $alias)."' -Target '".str_replace("'", "''", $home)."' -ErrorAction Stop | Out-Null";
            $powershell = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
            $process = proc_open([$powershell, '-NoProfile', '-NonInteractive', '-Command', $command],
                [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('junction fixture did not start');
            }
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if ($code !== 0) {
                throw new RuntimeException('junction fixture failed: '.$out.$err);
            }
        } else {
            expect(symlink($home, $alias))->toBeTrue();
        }
        expect(fn () => ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'dummy', $alias), ['CODEX_HOME' => $home]))->toThrow('own');
    } finally {
        if (PHP_OS_FAMILY === 'Windows') {
            if (is_dir($alias)) {
                rmdir($alias);
            }
        } else {
            if (is_link($alias)) {
                unlink($alias);
            }
        }
        rmdir($home);
        rmdir($root);
    }
});

it('replaces alternate-case inherited homes rather than emitting duplicate Windows variables', function (): void {
    $parent = ['codex_home' => 'old', 'CoDeX_HoMe' => 'also-old', 'anthropic_api_key' => 'inherited'];
    $built = ChildEnv::build($parent, ['CODEX_HOME'], ['CODEX_HOME' => 'new', 'ANTHROPIC_API_KEY' => 'explicit']);
    expect($built->env)->toBe(['CODEX_HOME' => 'new', 'ANTHROPIC_API_KEY' => 'explicit'])
        ->and($parent)->toBe(['codex_home' => 'old', 'CoDeX_HoMe' => 'also-old', 'anthropic_api_key' => 'inherited'])
        ->and($built->withheld)->toBe(['anthropic_api_key']);
});

it('keeps keys out of default JSON and debug output and ignores ACP credential injection', function (): void {
    $credentials = new ProviderCredentials('apiKey', 'dummy-private-key', '/tenant');
    ob_start();
    var_dump($credentials);
    $debug = ob_get_clean();
    expect(json_encode($credentials).$debug)->not->toContain('dummy-private-key');
    $session = ['cwd' => '/session', 'mcpServers' => [], 'credentials' => new ProviderCredentials('apiKey', 'untrusted', '/attacker')];
    expect((new CodexDriverOptions('/host', credentials: $credentials))->forSession($session)->credentials)->toBe($credentials)
        ->and((new ClaudeDriverOptions('/host', credentials: $credentials))->forSession($session)->credentials)->toBe($credentials)
        ->and((new CodexDriverOptions('/host'))->forSession($session)->credentials)->toBeNull();
});

it('never starts a Codex thread after an unconfirmed or refused login and never surfaces its echoed key', function (mixed $result, bool $rpcError): void {
    $root = credentialScratch();
    $home = $root.DIRECTORY_SEPARATOR.'app';
    $loop = new Loop;
    $transport = null;
    $problems = [];
    $reply = $rpcError ? (object) ['error' => (object) ['code' => -1, 'message' => 'dummy-private-key echoed by provider']]
        : (object) ['result' => $result];
    $script = (object) ['replies' => (object) ['account/login/start' => [$reply]]];
    $driver = new CodexDriver(new CodexDriverOptions($root, parentEnv: [], credentials: new ProviderCredentials('apiKey', 'dummy-private-key', $home)),
        new DriverEvents(onProtocolError: function (string $problem) use (&$problems): void {
            $problems[] = $problem;
        }),
        function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use (&$transport, $loop, $script): ScriptedCodexTransport {
            return $transport = new ScriptedCodexTransport($handlers, $loop, $script);
        }, $loop);
    try {
        $driver->start();
        $driver->ready()->await();
        $loop->drain();
        $methods = array_column($transport->sent, 'method');
        expect($methods)->toContain('account/login/start')->not->toContain('thread/start', 'thread/resume')
            ->and($problems)->not->toBe([])->and(implode(' ', $problems))->not->toContain('dummy-private-key');
    } finally {
        $driver->kill();
        $loop->drain();
        @rmdir($home);
        rmdir($root);
    }
})->with([[new stdClass, false], [null, false], [[], false], [(object) ['type' => 'chatgpt'], false], [null, true]]);

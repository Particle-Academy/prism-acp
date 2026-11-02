<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Support\DirectoryCleanupException;
use Prism\Acp\Support\DirectoryProtectionException;
use Prism\Acp\Support\PrivateDirectory;
use Tests\Support\CredentialPaths;
use Tests\Support\ScriptedCodexTransport;

function protectionScratch(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-protection-'.bin2hex(random_bytes(6));
    if (! mkdir($root, 0700)) {
        throw new RuntimeException('owned fixture creation failed');
    }

    return $root;
}

function makeProtectionFixtureUnsafe(string $home): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        if (! chmod($home, 0777)) {
            throw new RuntimeException('owned fixture permission change failed');
        }

        return;
    }
    $tool = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\icacls.exe';
    $process = proc_open([$tool, $home, '/grant', '*S-1-1-0:(OI)(CI)RX'],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, options: ['bypass_shell' => true]);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('owned fixture ACL change failed');
    }
}

it('refuses an existing unsafe credential home without rewriting its permissions or unrelated content', function (): void {
    $root = protectionScratch();
    $home = $root.DIRECTORY_SEPARATOR.'existing';
    mkdir($home, 0700);
    makeProtectionFixtureUnsafe($home);
    $unrelated = $home.DIRECTORY_SEPARATOR.'unrelated-fixture';
    file_put_contents($unrelated, '');
    $before = lstat($unrelated);
    try {
        expect(fn () => ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []))
            ->toThrow(RuntimeException::class, 'credentials.home');
        clearstatcache(true, $unrelated);
        expect(lstat($unrelated))->toBe($before);
        // Metadata only: no credential file is read, copied or hashed.
        expect(fn () => PrivateDirectory::assertProtected($home))->toThrow(RuntimeException::class);
    } finally {
        unlink($unrelated);
        rmdir($home);
        rmdir($root);
    }
});

it('rechecks home protection before returning a checked key or provider environment', function (string $handoff): void {
    $root = protectionScratch();
    $home = $root.DIRECTORY_SEPARATOR.'new';
    try {
        $checked = ApiKeyHome::check('claude', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []);
        makeProtectionFixtureUnsafe($home);
        expect(fn () => $handoff === 'key' ? $checked->key() : $checked->environment('claude'))
            ->toThrow(RuntimeException::class, 'credentials.home');
    } finally {
        rmdir($home);
        rmdir($root);
    }
})->with(['key', 'environment']);

it('admits a verified existing private home without changing its metadata', function (): void {
    $root = protectionScratch();
    $home = $root.DIRECTORY_SEPARATOR.'new';
    try {
        $first = ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []);
        $before = lstat($home);
        $second = ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []);
        clearstatcache(true, $home);
        expect($second->home)->toBe($first->home)->and(lstat($home))->toBe($before);
        PrivateDirectory::assertProtected($home);
    } finally {
        rmdir($home);
        rmdir($root);
    }
});

it('refuses a symlink or junction leaf without changing its private target', function (): void {
    $root = protectionScratch();
    $target = $root.DIRECTORY_SEPARATOR.'target';
    $alias = $root.DIRECTORY_SEPARATOR.'alias';
    mkdir($target, 0700);
    PrivateDirectory::protect($target);
    CredentialPaths::link($target, $alias);
    $before = lstat($target);
    try {
        expect(fn () => ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $alias), []))
            ->toThrow(DirectoryProtectionException::class);
        clearstatcache(true, $target);
        expect(lstat($target))->toBe($before);
        PrivateDirectory::assertProtected($target);
    } finally {
        CredentialPaths::unlink($alias);
        rmdir($target);
        rmdir($root);
    }
});

it('reports failed new-home cleanup distinctly and rechecks an unsafe residual path', function (string $entry, string $fault, bool $denyCleanup): void {
    $root = protectionScratch();
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $out = null;
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__).'/fixtures/private-directory-failure.php', dirname(__DIR__, 2).'/vendor/autoload.php', $root, $entry, $fault, $denyCleanup ? 'deny' : 'allow'],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: ChildEnv::build(getenv())->env, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('isolated permission fault host did not start');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        $out = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        expect($out['kind'])->toBe($denyCleanup ? 'cleanup_failed' : 'unsafe_directory')
            ->and($out['class'])->toBe($denyCleanup ? DirectoryCleanupException::class : DirectoryProtectionException::class)
            ->and($out['safe_message'])->toBeTrue()->and($out['has_previous'])->toBeFalse()
            ->and($out['residual'])->toBe($denyCleanup)
            ->and($out['retry'])->toBe($denyCleanup ? 'unsafe_directory' : 'not-needed');
    } finally {
        if (is_array($out) && preg_match('/^(?:new-home|private-[a-f0-9]{12})$/D', $out['leaf']) === 1) {
            $residual = $root.DIRECTORY_SEPARATOR.$out['leaf'];
            if (is_dir($residual)) {
                rmdir($residual);
            }
        }
        rmdir($root);
    }
})->with([
    ['api', 'protect', false], ['api', 'protect', true], ['api', 'verify', false],
    ['temporary', 'protect', false], ['temporary', 'protect', true], ['temporary', 'verify', false],
]);

it('never hands an unsafe existing home to either provider', function (string $provider): void {
    $root = protectionScratch();
    $home = $root.DIRECTORY_SEPARATOR.'existing';
    mkdir($home, 0700);
    makeProtectionFixtureUnsafe($home);
    $credentials = new ProviderCredentials('apiKey', 'owned-dummy-key', $home);
    $loop = new Loop;
    $starts = 0;
    $problems = [];
    $driver = null;
    try {
        if ($provider === 'claude') {
            $driver = new ClaudeDriver(new ClaudeDriverOptions($root, binary: PHP_BINARY, parentEnv: [], credentials: $credentials), new DriverEvents, $loop);
            expect(fn () => $driver->start())->toThrow(DirectoryProtectionException::class, 'credentials.home');
        } else {
            $driver = new CodexDriver(new CodexDriverOptions($root, parentEnv: [], credentials: $credentials),
                new DriverEvents(onProtocolError: function (string $problem) use (&$problems): void {
                    $problems[] = $problem;
                }),
                function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use (&$starts, $loop): ScriptedCodexTransport {
                    $starts++;

                    return new ScriptedCodexTransport($handlers, $loop, new stdClass);
                }, $loop);
            $driver->start();
            $driver->ready()->await();
            $loop->drain();
            expect($starts)->toBe(0)->and(implode(' ', $problems))->toContain('unsafe_directory')->toContain('owner-only')
                ->not->toContain($home)->not->toContain('owned-dummy-key');
        }
    } finally {
        $driver?->kill();
        $loop->drain();
        rmdir($home);
        rmdir($root);
    }
})->with(['claude', 'codex']);

it('rechecks protection after a Codex transport starts and before handing it the API key', function (): void {
    $root = protectionScratch();
    $home = $root.DIRECTORY_SEPARATOR.'new';
    $loop = new Loop;
    $transport = null;
    $problems = [];
    $driver = new CodexDriver(new CodexDriverOptions($root, parentEnv: [], credentials: new ProviderCredentials('apiKey', 'owned-dummy-key', $home)),
        new DriverEvents(onProtocolError: function (string $problem) use (&$problems): void {
            $problems[] = $problem;
        }),
        function (CodexTransportOptions $options, CodexTransportHandlers $handlers) use (&$transport, $loop, $home): ScriptedCodexTransport {
            makeProtectionFixtureUnsafe($home);

            return $transport = new ScriptedCodexTransport($handlers, $loop, new stdClass);
        }, $loop);
    try {
        $driver->start();
        $driver->ready()->await();
        $loop->drain();
        expect(array_column($transport->sent, 'method'))->toContain('initialize')->not->toContain('account/login/start', 'thread/start', 'thread/resume')
            ->and(implode(' ', $problems))->toContain('unsafe_directory')->not->toContain($home)->not->toContain('owned-dummy-key');
    } finally {
        $driver->kill();
        $loop->drain();
        rmdir($home);
        rmdir($root);
    }
});

it('runs byte-identical readable PowerShell for hostile names with literal encoded child-only path data', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('the native PowerShell command boundary is Windows-specific');
    }
    $root = protectionScratch();
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__).'/fixtures/private-directory-literal.php', dirname(__DIR__, 2).'/vendor/autoload.php', $root],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: ChildEnv::build(getenv())->env, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('literal-path host did not start');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        expect($result['admitted'])->toBe(2)->and($result['calls'])->toBeGreaterThanOrEqual(8)
            ->and($result['same_script'])->toBeTrue()->and($result['flags'])->toBeTrue()
            ->and($result['absolute'])->toBeTrue()->and($result['literal'])->toBeTrue()->and($result['no_credentials'])->toBeTrue();
    } finally {
        rmdir($root);
    }
});

it('rechecks the Claude home after configuration setup and before the provider handoff', function (): void {
    $root = protectionScratch();
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__).'/fixtures/private-directory-claude-handoff.php', dirname(__DIR__, 2).'/vendor/autoload.php', $root],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: ChildEnv::build(getenv())->env, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('isolated Claude handoff host did not start');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        expect(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'changed_after_admission' => true, 'refused' => true, 'safe_message' => true,
            'provider_not_reached' => true, 'config_removed' => true,
        ]);
    } finally {
        rmdir($root);
    }
});

it('refuses a native root or descendant whose owner is a different identity', function (string $node): void {
    $root = protectionScratch();
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__).'/fixtures/private-directory-owner.php', dirname(__DIR__, 2).'/vendor/autoload.php', $root.DIRECTORY_SEPARATOR.'wrong-owner', $node],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: ChildEnv::build(getenv(), ['GITHUB_ACTIONS'])->env, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('native owner fixture did not start');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        if (! $result['available'] && getenv('GITHUB_ACTIONS') !== 'true') {
            $this->markTestSkipped('local account cannot assign another owner; hosted native owner fixture is mandatory');
        }
        expect($result['available'])->toBeTrue()->and($result['refused'])->toBeTrue()->and($result['restored'])->toBeTrue();
    } finally {
        rmdir($root);
    }
})->with(['root', 'descendant']);

it('requires exact POSIX owner-only mode including absence of special bits', function (int $mode): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('POSIX modes are tested on native POSIX hosts');
    }
    $root = protectionScratch();
    $home = $root.DIRECTORY_SEPARATOR.'mode';
    mkdir($home, 0700);
    chmod($home, $mode);
    try {
        expect(fn () => ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []))
            ->toThrow(DirectoryProtectionException::class);
        clearstatcache(true, $home);
        expect(fileperms($home) & 07777)->toBe($mode);
    } finally {
        rmdir($home);
        rmdir($root);
    }
})->with([0755, 0777, 01700, 02700, 04700]);

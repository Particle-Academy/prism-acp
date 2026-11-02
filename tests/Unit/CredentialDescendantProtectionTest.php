<?php

declare(strict_types=1);

use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Support\DirectoryProtectionException;
use Prism\Acp\Support\PrivateDirectory;
use Tests\Support\CredentialPaths;
use Tests\Support\CredentialTree;

it('accepts a clean tree and evaluates an unreadable EMPTY marker from metadata alone', function (): void {
    $tree = new CredentialTree;
    try {
        $tree->directory('nested');
        $marker = $tree->emptyFile('nested'.DIRECTORY_SEPARATOR.'empty-marker');
        $tree->permissions($marker, 'deny');
        $checked = ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-argument-key', $tree->home), []);
        expect($checked->home)->toBe($tree->home);
        $tree->permissions($marker, 'deny-world');
        expect(fn () => ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-argument-key', $tree->home), []))
            ->toThrow(DirectoryProtectionException::class);
        expect(fn () => $checked->key())->toThrow(DirectoryProtectionException::class);
        expect(fn () => $checked->environment('codex'))->toThrow(DirectoryProtectionException::class);
    } finally {
        $tree->remove();
    }
});

it('checks a descendant exactly at the maximum depth and refuses the next level', function (): void {
    $tree = new CredentialTree;
    try {
        $relative = '';
        for ($depth = 1; $depth < 16; $depth++) {
            $relative = $relative === '' ? 'd' : $relative.DIRECTORY_SEPARATOR.'d';
            $tree->directory($relative);
        }
        $marker = $tree->emptyFile($relative.DIRECTORY_SEPARATOR.'at-depth-16');
        PrivateDirectory::assertProtected($tree->home);
        $tree->permissions($marker, 'world');
        expect(fn () => PrivateDirectory::assertProtected($tree->home))->toThrow(DirectoryProtectionException::class);
        $tree->permissions($marker, 'private');
        $tree->directory($relative.DIRECTORY_SEPARATOR.'d');
        $tree->emptyFile($relative.DIRECTORY_SEPARATOR.'d'.DIRECTORY_SEPARATOR.'over-depth-16');
        $caught = null;
        try {
            ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-argument-key', $tree->home), []);
        } catch (DirectoryProtectionException $error) {
            $caught = $error;
        }
        expect($caught)->toBeInstanceOf(DirectoryProtectionException::class)
            ->and($caught->reason)->toBe('depth_limit')->and($caught->getMessage())->toContain('depth limit');
    } finally {
        $tree->remove();
    }
});

it('refuses a descendant symlink or reparse point without following its private target', function (): void {
    $tree = new CredentialTree;
    $target = PrivateDirectory::create('prism-acp-tree-target-');
    $alias = $tree->home.DIRECTORY_SEPARATOR.'link';
    CredentialPaths::link($target, $alias);
    $before = lstat($target);
    try {
        expect(fn () => PrivateDirectory::assertProtected($tree->home))->toThrow(DirectoryProtectionException::class);
        clearstatcache(true, $target);
        expect(lstat($target))->toBe($before);
    } finally {
        CredentialPaths::unlink($alias);
        $tree->remove();
        expect(PrivateDirectory::remove($target))->toBeTrue();
    }
});

it('accepts the explicitly approved privileged Windows allow principals on an owned descendant', function (): void {
    $tree = new CredentialTree;
    try {
        $marker = $tree->emptyFile('empty-privileged-marker');
        $tree->permissions($marker, 'privileged');
        PrivateDirectory::assertProtected($tree->home);
        expect(file_exists($marker))->toBeTrue();
    } finally {
        $tree->remove();
    }
});

it('admits65536 descendants and refuses the next with an explicit entry-limit remedy', function (): void {
    $tree = new CredentialTree;
    try {
        for ($i = 0; $i < 65536; $i++) {
            $tree->emptyFile('empty-count-marker-'.$i);
        }
        PrivateDirectory::assertProtected($tree->home);
        $tree->emptyFile('empty-count-marker-over-limit');
        $caught = null;
        try {
            ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-argument-key', $tree->home), []);
        } catch (DirectoryProtectionException $error) {
            $caught = $error;
        }
        expect($caught)->toBeInstanceOf(DirectoryProtectionException::class)
            ->and($caught->kind)->toBe('unsafe_directory')->and($caught->reason)->toBe('entry_limit')
            ->and($caught->getMessage())->toContain('entry count limit')->not->toContain($tree->home);
    } finally {
        $tree->remove();
    }
});

it('refuses an expired verification time budget with an explicit safe reason', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-owned-time-budget-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $home = $root.DIRECTORY_SEPARATOR.'new-home';
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__).'/fixtures/private-directory-time-limit.php', dirname(__DIR__, 2).'/vendor/autoload.php', $home],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: ChildEnv::build(getenv())->env, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('time-budget host did not start');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        expect($result)->toBe(['kind' => 'unsafe_directory', 'reason' => 'time_limit', 'message_names_limit' => true, 'safe_message' => true, 'new_home_removed' => true]);
    } finally {
        if (is_dir($home)) {
            rmdir($home);
        }
        rmdir($root);
    }
});

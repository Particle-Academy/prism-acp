<?php

declare(strict_types=1);

use Prism\Acp\Env\ChildEnv;

it('retains failed private cleanup for one bounded post-stop retry and reports exhaustion', function (string $kind, string $fault): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-owned-cleanup-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    try {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__).'/fixtures/private-directory-cleanup.php', dirname(__DIR__, 2).'/vendor/autoload.php', $root, $kind, $fault],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: ChildEnv::build(getenv())->env, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('isolated cleanup control did not start');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($err)->toBe('');
        expect(json_decode($out, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'attempts' => 2, 'retained_during_failure' => true, 'removed' => $fault !== 'persistent',
            'retained_after_terminal_failure' => $fault === 'persistent',
            'verdict' => ['graceful' => true, 'empty' => true, 'survivors' => 0, 'verified' => true],
            'terminal_failures' => $fault === 'persistent' ? 1 : 0, 'terminal_not_recoverable' => true, 'safe_message' => true,
        ]);
    } finally {
        rmdir($root);
    }
})->with([['approvals', 'transient'], ['approvals', 'persistent'], ['mcp', 'transient'], ['mcp', 'persistent']]);

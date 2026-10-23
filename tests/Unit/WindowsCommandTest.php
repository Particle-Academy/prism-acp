<?php

declare(strict_types=1);

use Prism\Acp\Process\ProcessTreeException;
use Prism\Acp\Process\WindowsCommand;

/*
 * Ported from prism-acp-ts test/process-tree.test.ts (quoting and resolution). The provider is never run through a
 * shell, so its arguments must survive CreateProcess exactly, shell metacharacters included.
 */

it('quotes only what needs it', function () {
    expect(WindowsCommand::commandLine(['C:\\a\\node.exe', 'x', 'a b', '']))->toBe('C:\\a\\node.exe x "a b" ""')
        ->and(WindowsCommand::commandLine(['say "hi"']))->toBe('"say \\"hi\\""')
        ->and(WindowsCommand::commandLine(['dir\\']))->toBe('dir\\')
        ->and(WindowsCommand::commandLine(['a dir\\']))->toBe('"a dir\\\\"');
});

it('arguments arrive EXACTLY in a real child, shell metacharacters included (no shell reads the line)', function () {
    $script = tempnam(sys_get_temp_dir(), 'argv').'.php';
    file_put_contents($script, '<?php echo json_encode(array_slice($argv, 1));');
    $args = ['plain', 'with space', 'quote"inside', 'trailing\\', 'trailing space\\ ', '', 'a&b|c>d %PATH% ^x', 'ünïcödé', '\\\\host\\share\\"x"', '$(echo hi)'];
    $line = WindowsCommand::commandLine([PHP_BINARY, $script, ...$args]);
    // bypass_shell: CreateProcess gets the line verbatim; cmd.exe never sees it.
    $process = proc_open($line, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]);
    proc_close($process);
    unlink($script);
    expect(json_decode((string) $out, true))->toBe($args);
})->skip(PHP_OS_FAMILY !== 'Windows', 'Windows command-line quoting');

describe('what a command name resolves to', function () {
    $shim = implode("\r\n", [
        '@ECHO off', 'GOTO start', ':find_dp0', 'SET dp0=%~dp0', 'EXIT /b', ':start', 'SETLOCAL', 'CALL :find_dp0', '',
        'IF EXIST "%dp0%\\node.exe" (', '  SET "_prog=%dp0%\\node.exe"', ') ELSE (', '  SET "_prog=node"',
        '  SET PATHEXT=%PATHEXT:;.JS;=;%', ')', '',
        'endLocal & goto #_undefined_# 2>NUL || title %COMSPEC% & "%_prog%"  "%dp0%\\node_modules\\@openai\\codex\\bin\\codex.js" %*', '',
    ]);
    $bin = function () use ($shim): string {
        $dir = sys_get_temp_dir().'\\prism-acp-bin-'.bin2hex(random_bytes(4));
        mkdir($dir.'\\node_modules\\@openai\\codex\\bin', 0777, true);
        file_put_contents($dir.'\\node_modules\\@openai\\codex\\bin\\codex.js', '');
        file_put_contents($dir.'\\codex.cmd', $shim);

        return $dir;
    };

    it('runs an npm shim as node + its script, with the shim\'s own node.exe when it has one', function () use ($bin) {
        $dir = $bin();
        file_put_contents($dir.'\\node.exe', '');
        $resolved = WindowsCommand::resolve('codex', ['PATH' => $dir, 'PATHEXT' => '.COM;.EXE;.BAT;.CMD']);
        expect($resolved)->toBe(['application' => $dir.'\\node.exe', 'args' => [$dir.'\\node_modules\\@openai\\codex\\bin\\codex.js']]);
    });

    it('refuses a batch file that is not an npm shim: it would need cmd.exe', function () {
        $dir = sys_get_temp_dir().'\\prism-acp-bin-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'\\claude.cmd', "@echo off\r\nclaude-real.exe %*\r\n");
        expect(fn () => WindowsCommand::resolve('claude', ['PATH' => $dir]))->toThrow(ProcessTreeException::class, 'not an npm shim');
    });

    it('runs an .exe as itself, never searches the current directory, and refuses a relative path', function () {
        $dir = sys_get_temp_dir().'\\prism-acp-bin-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'\\claude.exe', '');
        expect(WindowsCommand::resolve('claude', ['PATH' => $dir])['application'])->toBe($dir.'\\claude.exe');
        $cwd = getcwd();
        try {
            chdir($dir);
            expect(fn () => WindowsCommand::resolve('claude', ['PATH' => '']))->toThrow(ProcessTreeException::class, 'not found')
                ->and(fn () => WindowsCommand::resolve('.\\claude.exe', ['PATH' => '']))->toThrow(ProcessTreeException::class, 'absolute');
        } finally {
            chdir((string) $cwd);
        }
    });
})->skip(PHP_OS_FAMILY !== 'Windows', 'Windows command resolution');

<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

use Prism\Acp\Env\ChildEnv;

/**
 * A temporary directory only this process's user can read, made BEFORE a credential is written into it. On Windows a
 * file mode means nothing, so inherited grants are removed and the current identity alone is granted access, as the
 * intended policy requires. Admission inspects ownership and permissions only, never stored credentials.
 */
final class PrivateDirectory
{
    private const MAX_DEPTH = 16;

    private const MAX_ENTRIES = 65536;

    private const VERIFY_SECONDS = 30;

    public static function create(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(6));
        if (! @mkdir($directory, 0700)) {
            throw new \RuntimeException('failed to create a private temporary directory');
        }
        try {
            self::protect($directory);
            self::assertProtected($directory);
        } catch (\Throwable $error) {
            if (! @rmdir($directory)) {
                throw new DirectoryCleanupException;
            }
            throw $error instanceof DirectoryProtectionException ? $error : new DirectoryProtectionException;
        }

        return $directory;
    }

    /** Restrict a newly-created host-chosen directory BEFORE the native CLI can write credentials into it. */
    public static function protect(#[\SensitiveParameter] string $directory): void
    {
        try {
            clearstatcache(true, $directory);
            if (is_link($directory) || ! is_dir($directory)) {
                throw new DirectoryProtectionException;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                self::windowsPermissions($directory, true, microtime(true) + self::VERIFY_SECONDS);
            } elseif (! @chmod($directory, 0700)) {
                throw new DirectoryProtectionException;
            }
        } catch (DirectoryProtectionException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new DirectoryProtectionException;
        }
    }

    /** Query only. Existing caller-owned directories are never chmodded or re-ACL'd here. */
    public static function assertProtected(#[\SensitiveParameter] string $directory): void
    {
        try {
            $deadline = microtime(true) + self::VERIFY_SECONDS;
            clearstatcache(true, $directory);
            $stat = @lstat($directory);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || is_link($directory)) {
                throw new DirectoryProtectionException;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                self::windowsPermissions($directory, false, $deadline);
            } else {
                $id = is_executable('/usr/bin/id') ? '/usr/bin/id' : '/bin/id';
                $result = self::run([$id, '-u'], ChildEnv::build(getenv())->env, $deadline);
                $effective = trim($result['output']);
                if ($result['exit'] !== 0) {
                    throw new DirectoryProtectionException;
                }
                if (! ctype_digit($effective) || (string) (int) $effective !== $effective) {
                    throw new DirectoryProtectionException;
                }
                $entries = 0;
                self::verifyPosixNode($directory, (int) $effective, 0, $entries, $deadline);
            }
        } catch (DirectoryProtectionException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new DirectoryProtectionException;
        }
    }

    /** Remove the files named and then the directory. Returns false when something could not be removed. */
    public static function remove(string $directory, string ...$files): bool
    {
        $ok = true;
        foreach ($files as $file) {
            if (is_file($file) && ! @unlink($file)) {
                $ok = false;
            }
        }
        if (is_dir($directory) && ! @rmdir($directory)) {
            $ok = false;
        }

        return $ok;
    }

    /** Metadata only: directory enumeration and lstat never open file contents or follow links. */
    private static function verifyPosixNode(#[\SensitiveParameter] string $path, int $owner, int $depth, int &$entries, float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new DirectoryProtectionException(reason: 'time_limit');
        }
        if ($depth > self::MAX_DEPTH) {
            throw new DirectoryProtectionException(reason: 'depth_limit');
        }
        if ($depth > 0 && ++$entries > self::MAX_ENTRIES) {
            throw new DirectoryProtectionException(reason: 'entry_limit');
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || $stat['uid'] !== $owner || ($stat['mode'] & 07077) !== 0) {
            throw new DirectoryProtectionException;
        }
        $type = $stat['mode'] & 0170000;
        if (! in_array($type, [0040000, 0100000], true) || ($depth === 0 && ($type !== 0040000 || ($stat['mode'] & 07777) !== 0700))) {
            throw new DirectoryProtectionException;
        }
        if ($type === 0040000) {
            $directory = @opendir($path);
            if ($directory === false) {
                throw new DirectoryProtectionException;
            }
            try {
                while (($name = readdir($directory)) !== false) {
                    if ($name !== '.' && $name !== '..') {
                        self::verifyPosixNode($path.DIRECTORY_SEPARATOR.$name, $owner, $depth + 1, $entries, $deadline);
                    }
                }
            } finally {
                closedir($directory);
            }
        }
    }

    // Readable source, encoded at runtime. No path or key is interpolated into this script.
    private const WINDOWS_PERMISSION_SCRIPT = <<<'PS'
        $ErrorActionPreference = 'Stop'
        $ProgressPreference = 'SilentlyContinue'
        try {
            $path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:PRISM_ACP_DIRECTORY_DATA))
            $attributes = [IO.File]::GetAttributes($path)
            if (($attributes -band [IO.FileAttributes]::Directory) -eq 0 -or ($attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 1 }
            $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
            if ($null -eq $sid) { exit 1 }
            $maxDepth = [int]$env:PRISM_ACP_DIRECTORY_DEPTH
            $maxEntries = [int]$env:PRISM_ACP_DIRECTORY_ENTRIES
            if ($maxDepth -lt 1 -or $maxEntries -lt 1) { exit 1 }
            if ($env:PRISM_ACP_DIRECTORY_MODE -ceq 'protect') {
                $acl = New-Object Security.AccessControl.DirectorySecurity
                $acl.SetOwner($sid)
                $acl.SetAccessRuleProtection($true, $false)
                $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, [Security.AccessControl.FileSystemRights]::FullControl, [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit', [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow)
                $acl.AddAccessRule($rule)
                [IO.Directory]::SetAccessControl($path, $acl)
            } elseif ($env:PRISM_ACP_DIRECTORY_MODE -cne 'verify') { exit 1 }
            $allowed = @($sid.Value, 'S-1-5-18', 'S-1-5-32-544')
            $script:privateEntries = 0
            function Assert-PrivateNode([string]$node, [int]$depth) {
                if ($depth -gt $maxDepth) { throw 'depth-limit' }
                if ($depth -gt 0) {
                    $script:privateEntries++
                    if ($script:privateEntries -gt $maxEntries) { throw 'entry-limit' }
                }
                $attributes = [IO.File]::GetAttributes($node)
                if (($attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'unsafe-directory' }
                $directory = ($attributes -band [IO.FileAttributes]::Directory) -ne 0
                if ($depth -eq 0 -and -not $directory) { throw 'unsafe-directory' }
                $sections = [Security.AccessControl.AccessControlSections]::Access -bor [Security.AccessControl.AccessControlSections]::Owner
                $verified = if ($directory) { [IO.Directory]::GetAccessControl($node, $sections) } else { [IO.File]::GetAccessControl($node, $sections) }
                if ($verified.GetOwner([Security.Principal.SecurityIdentifier]).Value -cne $sid.Value) { throw 'unsafe-directory' }
                $ownerFullControl = $false
                foreach ($r in $verified.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
                    if ($r.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow) {
                        if ($allowed -cnotcontains $r.IdentityReference.Value) { throw 'unsafe-directory' }
                        if ($r.IdentityReference.Value -ceq $sid.Value -and ($r.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -eq [Security.AccessControl.FileSystemRights]::FullControl -and $r.PropagationFlags -eq [Security.AccessControl.PropagationFlags]::None) { $ownerFullControl = $true }
                    } elseif ($depth -eq 0) { throw 'unsafe-directory' }
                }
                if ($depth -eq 0 -and (-not $verified.AreAccessRulesProtected -or -not $ownerFullControl)) { throw 'unsafe-directory' }
                if ($directory) {
                    $children = [IO.Directory]::EnumerateFileSystemEntries($node).GetEnumerator()
                    try {
                        while ($children.MoveNext()) { Assert-PrivateNode $children.Current ($depth + 1) }
                    } finally { $children.Dispose() }
                }
            }
            Assert-PrivateNode $path 0
            [Console]::Out.Write('private-directory-v1')
            exit 0
        } catch {
            if ($_.Exception.Message -ceq 'depth-limit') { exit 2 }
            if ($_.Exception.Message -ceq 'entry-limit') { exit 3 }
            exit 1
        }
        PS;

    private static function windowsPermissions(#[\SensitiveParameter] string $directory, bool $protect, float $deadline): void
    {
        $system32 = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32';
        $env = ChildEnv::build(getenv(), [], [
            'PRISM_ACP_DIRECTORY_DATA' => base64_encode($directory),
            'PRISM_ACP_DIRECTORY_MODE' => $protect ? 'protect' : 'verify',
            'PRISM_ACP_DIRECTORY_DEPTH' => (string) self::MAX_DEPTH,
            'PRISM_ACP_DIRECTORY_ENTRIES' => (string) self::MAX_ENTRIES,
        ])->env;
        $encoded = base64_encode(mb_convert_encoding(self::WINDOWS_PERMISSION_SCRIPT, 'UTF-16LE', 'UTF-8'));
        $result = self::run([$system32.'\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded], $env, $deadline);
        if ($result['exit'] === 2 || $result['exit'] === 3) {
            throw new DirectoryProtectionException(reason: $result['exit'] === 2 ? 'depth_limit' : 'entry_limit');
        }
        if ($result['exit'] !== 0 || $result['output'] !== 'private-directory-v1') {
            throw new DirectoryProtectionException;
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{exit:int,output:string}
     */
    private static function run(#[\SensitiveParameter] array $command, #[\SensitiveParameter] array $env, float $deadline): array
    {
        if (microtime(true) >= $deadline) {
            throw new DirectoryProtectionException(reason: 'time_limit');
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new DirectoryProtectionException;
        }
        $status = null;
        try {
            do {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($status['running'] || microtime(true) >= $deadline) {
                throw new DirectoryProtectionException(reason: 'time_limit');
            }
            if ($status['exitcode'] < 0) {
                throw new DirectoryProtectionException;
            }
            // These fixed tools emit only one bounded proof token or an effective UID.
            $out = stream_get_contents($pipes[1], 256);
            if ($out === false || strlen($out) >= 256) {
                throw new DirectoryProtectionException;
            }
        } finally {
            if ($status === null || $status['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            // Use the first observed native exit code above. PHP8.2 may consume it
            // in proc_get_status; no negative or unknown code is treated as success.
            proc_close($process);
        }

        return ['exit' => $status['exitcode'], 'output' => $out];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Support\PrivateDirectory;

/** Owned EMPTY metadata fixtures only; existing file contents are never opened. */
final class CredentialTree
{
    public readonly string $home;

    /** @var list<array{string,bool}> */
    private array $created = [];

    public function __construct()
    {
        $this->home = PrivateDirectory::create('prism-acp-tree-fixture-');
    }

    public function directory(string $relative): string
    {
        $path = $this->home.DIRECTORY_SEPARATOR.$relative;
        if (! mkdir($path, 0700)) {
            throw new \RuntimeException('owned tree directory creation failed');
        }
        $this->created[] = [$path, true];

        return $path;
    }

    public function emptyFile(string $relative): string
    {
        $path = $this->home.DIRECTORY_SEPARATOR.$relative;
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new \RuntimeException('owned EMPTY marker creation failed');
        }
        fclose($file);
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }
        $this->created[] = [$path, false];

        return $path;
    }

    public function permissions(string $path, string $mode): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $bits = match ($mode) {
                'private', 'privileged' => 0600,
                'world' => 0604,
                'deny' => 0000,
                'deny-world' => 0004,
                default => throw new \LogicException('unknown fixture mode'),
            };
            if (! chmod($path, $bits)) {
                throw new \RuntimeException('owned marker mode change failed');
            }

            return;
        }
        $script = <<<'PS'
        $ErrorActionPreference = 'Stop'
        $ProgressPreference = 'SilentlyContinue'
        try {
            $path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:PRISM_TREE_MARKER_DATA))
            $mode = $env:PRISM_TREE_MARKER_MODE
            if (@('private','privileged','world','deny','deny-world') -cnotcontains $mode) { exit 1 }
            $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
            $acl = New-Object Security.AccessControl.FileSecurity
            $acl.SetOwner($sid)
            $acl.SetAccessRuleProtection($true, $false)
            $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid, [Security.AccessControl.FileSystemRights]::FullControl, [Security.AccessControl.AccessControlType]::Allow)))
            if ($mode -eq 'privileged') {
                foreach ($value in @('S-1-5-18','S-1-5-32-544')) {
                    $principal = New-Object Security.Principal.SecurityIdentifier($value)
                    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($principal, [Security.AccessControl.FileSystemRights]::FullControl, [Security.AccessControl.AccessControlType]::Allow)))
                }
            }
            if ($mode -eq 'deny' -or $mode -eq 'deny-world') {
                $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid, [Security.AccessControl.FileSystemRights]::ReadData, [Security.AccessControl.AccessControlType]::Deny)))
            }
            if ($mode -eq 'world' -or $mode -eq 'deny-world') {
                $everyone = New-Object Security.Principal.SecurityIdentifier('S-1-1-0')
                $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($everyone, [Security.AccessControl.FileSystemRights]::Read, [Security.AccessControl.AccessControlType]::Allow)))
            }
            [IO.File]::SetAccessControl($path, $acl)
            $check = [IO.File]::GetAccessControl($path)
            $denied = $false
            $world = $false
            foreach ($r in $check.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])) {
                if ($r.IdentityReference.Value -ceq $sid.Value -and $r.AccessControlType -eq [Security.AccessControl.AccessControlType]::Deny -and ($r.FileSystemRights -band [Security.AccessControl.FileSystemRights]::ReadData) -ne 0) { $denied = $true }
                if ($r.IdentityReference.Value -ceq 'S-1-1-0' -and $r.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow -and ($r.FileSystemRights -band [Security.AccessControl.FileSystemRights]::ReadData) -ne 0) { $world = $true }
            }
            if ($denied -ne ($mode -eq 'deny' -or $mode -eq 'deny-world') -or $world -ne ($mode -eq 'world' -or $mode -eq 'deny-world')) { exit 1 }
            [Console]::Out.Write('marker-metadata-verified')
            exit 0
        } catch {
            $failure = $_.Exception.GetBaseException()
            [Console]::Out.Write('metadata-error:' + $failure.GetType().FullName + ':' + $failure.HResult)
            exit 1
        }
        PS;
        $process = proc_open([(getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            env_vars: ChildEnv::build(getenv(), [], ['PRISM_TREE_MARKER_DATA' => base64_encode($path), 'PRISM_TREE_MARKER_MODE' => $mode])->env,
            options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new \RuntimeException('owned marker metadata tool did not start');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || $out !== 'marker-metadata-verified' || $err !== '') {
            $structural = preg_match('/^[A-Za-z0-9_.:-]+$/D', $out) === 1 ? $out : 'unexpected-output';
            throw new \RuntimeException('owned marker metadata was not verified (exit '.$code.', '.$structural.', stderr '.($err === '' ? 'empty' : 'present').')');
        }
    }

    public function remove(): void
    {
        foreach (array_reverse($this->created) as [$path, $directory]) {
            if (! ($directory ? rmdir($path) : unlink($path))) {
                throw new \RuntimeException('owned tree cleanup failed');
            }
        }
        if (! rmdir($this->home)) {
            throw new \RuntimeException('owned tree root cleanup failed');
        }
    }
}

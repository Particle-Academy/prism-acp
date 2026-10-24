<?php

declare(strict_types=1);

namespace Tests\Support;

final class WindowsPermissions
{
    /** @return array{code:int,evidence:string} */
    public static function inspect(string $directory): array
    {
        $template = <<<'PS'
        $ErrorActionPreference = 'Stop';
        $acl = [System.IO.Directory]::GetAccessControl('FIXTURE_HOME');
        $rules = $acl.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]);
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value;
        $ok = $acl.AreAccessRulesProtected -and $rules.Count -gt 0;
        $evidence = "protected=$($acl.AreAccessRulesProtected); currentSid=$sid";
        foreach ($rule in $rules) {
            if ($rule.IdentityReference.Value -ne $sid -or $rule.AccessControlType -ne 'Allow') { $ok = $false }
            $evidence += "; sid=$($rule.IdentityReference.Value), type=$($rule.AccessControlType), inherited=$($rule.IsInherited), rights=$($rule.FileSystemRights)";
        }
        [Console]::Out.WriteLine($evidence);
        if ($ok) { exit 0 } else { exit 1 }
        PS;
        // Query the OS ACL without importing PowerShell 7 modules into Windows PowerShell 5.
        $command = str_replace('FIXTURE_HOME', str_replace("'", "''", $directory), $template);
        $powershell = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $process = proc_open([$powershell, '-NoProfile', '-NonInteractive', '-Command', $command],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            return ['code' => -1, 'evidence' => 'ACL inspector did not start'];
        }
        $evidence = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'evidence' => $evidence];
    }
}

<?php

declare(strict_types=1);

use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Support\PrivateDirectory;

/** @return array<string,mixed> */
function modifyParentMetadata(string $path, string $mode): array
{
    $script = <<<'PS'
    $PSModuleAutoLoadingPreference = 'None'
    $env:PSModulePath = ''
    $ErrorActionPreference = 'Stop'
    $path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:PRISM_ACP_MODIFY_PARENT_DATA))
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    try {
        if ($env:PRISM_ACP_MODIFY_PARENT_MODE -ceq 'prepare') {
            $acl = [Security.AccessControl.DirectorySecurity]::new()
            $acl.SetAccessRuleProtection($true, $false)
            $inherit = [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
            $none = [Security.AccessControl.PropagationFlags]::None
            # CI is elevated: DENY enforces the same absent WRITE_OWNER while retaining SYSTEM/Admin grants.
            $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'TakeOwnership', $inherit, $none, 'Deny'))
            $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'Modify', $inherit, $none, 'Allow'))
            foreach ($value in @('S-1-5-18','S-1-5-32-544')) {
                $principal = [Security.Principal.SecurityIdentifier]::new($value)
                $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($principal, 'FullControl', $inherit, $none, 'Allow'))
            }
            [IO.Directory]::SetAccessControl($path, $acl)
        } elseif ($env:PRISM_ACP_MODIFY_PARENT_MODE -cne 'inspect') { throw 'unknown-mode' }
        $sections = [Security.AccessControl.AccessControlSections]::Access -bor [Security.AccessControl.AccessControlSections]::Owner
        $current = [IO.Directory]::GetAccessControl($path, $sections)
        $denied = $false
        foreach ($rule in $current.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
            if ($rule.IdentityReference.Value -ceq $sid.Value -and $rule.AccessControlType -eq [Security.AccessControl.AccessControlType]::Deny -and ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::TakeOwnership) -ne 0) { $denied = $true }
        }
        [Console]::Out.Write('{"ok":true,"owner":"' + $current.GetOwner([Security.Principal.SecurityIdentifier]).Value + '","writeOwnerDenied":' + $denied.ToString().ToLowerInvariant() + ',"protected":' + $current.AreAccessRulesProtected.ToString().ToLowerInvariant() + '}')
    } catch {
        [Console]::Out.Write('{"ok":false,"nativeError":' + ($_.Exception.HResult -band 65535) + '}')
    }
    PS;
    if ($mode === 'repair') {
        $document = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2).'/docs/credential-home-protection.md'));
        expect(preg_match('/```powershell\R(.*?)\R```/s', $document, $match))->toBe(1);
        $script = preg_replace_callback('/^\$credentialHome = [^\r\n]+$/m',
            fn (): string => '$credentialHome = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:PRISM_ACP_MODIFY_PARENT_DATA))', $match[1], 1, $replaced);
        expect($replaced)->toBe(1);
        $script .= "\n[Console]::Out.Write('{\"ok\":true}')\n";
    }
    $executable = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    $env = ChildEnv::build(getenv(), [], ['PSModulePath' => '', 'PRISM_ACP_MODIFY_PARENT_DATA' => base64_encode($path), 'PRISM_ACP_MODIFY_PARENT_MODE' => $mode])->env;
    $process = WindowsProviderProcess::launch($executable, ['-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))], dirname(__DIR__, 2), $env);
    $process->closeInput();
    $out = $err = '';
    try {
        $deadline = microtime(true) + 10;
        while ($process->isOpen() && microtime(true) < $deadline) {
            foreach ($process->read(0.02) as [$kind, $bytes]) {
                if ($kind === 'stdout') {
                    $out .= $bytes;
                } elseif ($kind === 'stderr') {
                    $err .= $bytes;
                }
            }
        }
        expect($process->isOpen())->toBeFalse()->and($err)->toBe('');

        return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    } finally {
        $stop = $process->stop(0);
        expect($stop->verified)->toBeTrue()->and($stop->empty)->toBeTrue()->and($stop->survivors)->toBe(0);
    }
}

it('protects new homes under Modify-only parents without changing natural ownership', function (string $operation): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('native Windows WRITE_OWNER/DACL rights');
    }
    $parent = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-modify-parent-'.bin2hex(random_bytes(6));
    mkdir($parent, 0700);
    $home = $parent.DIRECTORY_SEPARATOR.'new-home';
    $marker = $home.DIRECTORY_SEPARATOR.'empty-marker';
    try {
        $prepared = modifyParentMetadata($parent, 'prepare');
        expect($prepared['ok'])->toBeTrue()->and($prepared['writeOwnerDenied'])->toBeTrue();
        $before = null;
        if ($operation === 'protect') {
            mkdir($home, 0700);
            $before = modifyParentMetadata($home, 'inspect');
            expect($before['ok'])->toBeTrue()->and($before['writeOwnerDenied'])->toBeTrue();
            PrivateDirectory::protect($home);
        } else {
            $checked = ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []);
            expect($checked->home)->toBe($home);
        }
        PrivateDirectory::assertProtected($home);
        $after = modifyParentMetadata($home, 'inspect');
        expect($after['ok'])->toBeTrue()->and($after['protected'])->toBeTrue()->and($after['writeOwnerDenied'])->toBeFalse();
        if ($before !== null) {
            expect($after['owner'])->toBe($before['owner']);
        }
        fclose(fopen($marker, 'x')); // EMPTY, then verify its inherited metadata without reading it.
        PrivateDirectory::assertProtected($home);
        echo json_encode(['modifyParentProtection' => ['operation' => $operation, 'accepted' => true, 'naturalOwnerPreserved' => $before === null || $after['owner'] === $before['owner']]], JSON_THROW_ON_ERROR)."\n";
    } finally {
        clearstatcache();
        expect(PrivateDirectory::remove($home, $marker))->toBeTrue();
        expect(rmdir($parent))->toBeTrue();
    }
})->with(['admit', 'protect']);

it('executes the documented access-only repair under the same Modify-only parent', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('native Windows WRITE_OWNER/DACL repair');
    }
    $parent = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-modify-parent-'.bin2hex(random_bytes(6));
    mkdir($parent, 0700);
    $home = $parent.DIRECTORY_SEPARATOR.'repair-home';
    $child = $home.DIRECTORY_SEPARATOR.'child';
    $marker = $child.DIRECTORY_SEPARATOR.'empty-marker';
    try {
        expect(modifyParentMetadata($parent, 'prepare')['writeOwnerDenied'])->toBeTrue();
        mkdir($home, 0700);
        mkdir($child, 0700);
        fclose(fopen($marker, 'x')); // EMPTY; the actual documented commands query metadata only.
        $before = modifyParentMetadata($home, 'inspect');
        $childBefore = modifyParentMetadata($child, 'inspect');
        expect($before['writeOwnerDenied'])->toBeTrue()->and($childBefore['writeOwnerDenied'])->toBeTrue();
        expect(modifyParentMetadata($home, 'repair'))->toBe(['ok' => true]);
        PrivateDirectory::assertProtected($home);
        expect(modifyParentMetadata($home, 'inspect')['owner'])->toBe($before['owner'])
            ->and(modifyParentMetadata($child, 'inspect')['owner'])->toBe($childBefore['owner']);
    } finally {
        expect(PrivateDirectory::remove($child, $marker))->toBeTrue();
        expect(PrivateDirectory::remove($home))->toBeTrue();
        expect(rmdir($parent))->toBeTrue();
    }
});

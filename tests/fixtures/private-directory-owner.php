<?php

declare(strict_types=1);

require $argv[1];

use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Support\DirectoryProtectionException;
use Prism\Acp\Support\PrivateDirectory;

/** @param list<string> $command @return array{int,string} */
function ownerFixtureCommand(array $command, array $extra = []): array
{
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes,
        env_vars: ChildEnv::build(getenv(), [], $extra)->env, options: ['bypass_shell' => true]);
    if (! is_resource($process)) {
        return [-1, ''];
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $stdout];
}

$home = $argv[2];
mkdir($home, 0700);
PrivateDirectory::protect($home);
$descendant = ($argv[3] ?? 'root') === 'descendant';
$node = $descendant ? $home.DIRECTORY_SEPARATOR.'empty-foreign-owner-marker' : $home;
if ($descendant) {
    $marker = fopen($node, 'x');
    if ($marker === false) {
        throw new RuntimeException('empty owner marker could not be created');
    }
    fclose($marker);
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($node, 0600);
    }
}
$restore = null;
$available = false;
$refused = false;
$expectedRefusal = true;
$restored = true;
try {
    if (PHP_OS_FAMILY === 'Windows') {
        $script = <<<'PS'
        $ErrorActionPreference = 'Stop'
        $ProgressPreference = 'SilentlyContinue'
        try {
            $path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:PRISM_OWNER_FIXTURE_PATH))
            $current = [Security.Principal.WindowsIdentity]::GetCurrent().User
            $admin = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
            $target = if ($env:PRISM_OWNER_FIXTURE_MODE -ceq 'restore') { $current } else { $admin }
            $directory = ([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::Directory) -ne 0
            $acl = if ($directory) { [IO.Directory]::GetAccessControl($path) } else { [IO.File]::GetAccessControl($path) }
            $acl.SetOwner($target)
            if ($directory) { [IO.Directory]::SetAccessControl($path, $acl) } else { [IO.File]::SetAccessControl($path, $acl) }
            $afterAcl = if ($directory) { [IO.Directory]::GetAccessControl($path) } else { [IO.File]::GetAccessControl($path) }
            $after = $afterAcl.GetOwner([Security.Principal.SecurityIdentifier])
            if ($after.Value -cne $target.Value) { exit 1 }
            $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
            $enabled = $identity.Groups.Contains($admin) -and [Security.Principal.WindowsPrincipal]::new($identity).IsInRole($admin)
            [Console]::Out.Write($(if ($enabled) { 'owner-set-enabled' } else { 'owner-set-standard' }))
            exit 0
        } catch { exit 1 }
        PS;
        $command = [(getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))];
        $extra = ['PRISM_OWNER_FIXTURE_PATH' => base64_encode($node), 'PRISM_OWNER_FIXTURE_MODE' => 'wrong'];
        [$code, $out] = ownerFixtureCommand($command, $extra);
        $available = $code === 0 && in_array($out, ['owner-set-enabled', 'owner-set-standard'], true);
        $expectedRefusal = $out !== 'owner-set-enabled';
        $restore = fn (): array => ownerFixtureCommand($command, [...$extra, 'PRISM_OWNER_FIXTURE_MODE' => 'restore']);
    } else {
        [$code, $uid] = ownerFixtureCommand(['/usr/bin/id', '-u']);
        if ($code === 0 && ctype_digit(trim($uid))) {
            $uid = trim($uid);
            $prefix = $uid === '0' ? [] : ['/usr/bin/sudo', '-n', '--'];
            $other = $uid === '65534' ? '65533' : '65534';
            [$changed] = ownerFixtureCommand([...$prefix, '/usr/bin/chown', $other, $node]);
            clearstatcache(true, $node);
            $available = $changed === 0 && fileowner($node) === (int) $other;
            $restore = fn (): array => ownerFixtureCommand([...$prefix, '/usr/bin/chown', $uid, $node]);
        }
    }
    if ($available) {
        try {
            ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []);
        } catch (DirectoryProtectionException $error) {
            $refused = $error->kind === 'unsafe_directory' && ! str_contains($error->getMessage(), $home);
        }
    }
} finally {
    if ($restore !== null) {
        [$code] = $restore();
        $restored = $code === 0;
    }
    if ($descendant) {
        unlink($node);
    }
    rmdir($home);
}
echo json_encode(['available' => $available, 'refused' => $refused, 'expected_refusal' => $expectedRefusal, 'restored' => $restored], JSON_THROW_ON_ERROR);

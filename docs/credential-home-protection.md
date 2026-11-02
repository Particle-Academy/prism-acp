# Credential home permissions

Use a dedicated app-owned home for API-key mode. Admission checks the home and every descendant's ownership, permissions and file type. A later key/environment handoff checks them again. The verifier enumerates directory metadata and queries ACLs or lstat; it never opens, reads, copies or hashes stored file contents.

On Windows, every entry must be owned by the current process SID, or by Administrators when the process has an elevated token with that group enabled. Allow ACEs may name the current user, SYSTEM or Administrators. The root needs protected inheritance and current-user FullControl. Descendants may inherit private permissions or have private explicit ACLs. A protected parent alone is insufficient: an explicit permissive descendant ACL can still grant access under Windows bypass-traverse behavior.

An elevated Windows process can create files owned by `BUILTIN\Administrators` instead of its user SID. Those files work under an elevated process only when its Administrators group is enabled. A standard/filtered token keeps the current-SID-only ownership rule, even when the account belongs to Administrators. [WindowsPrincipal.IsInRole](https://learn.microsoft.com/en-us/dotnet/api/system.security.principal.windowsprincipal.isinrole?view=netframework-4.8.1) checks the enabled token role without displaying an elevation prompt. For a refusal, repair the dedicated tree's ownership using the procedure below or select a fresh app-owned home; the adapter never elevates or rewrites existing permissions automatically.

On POSIX, the effective user must own every entry; no entry may grant group/other permissions or carry special mode bits. The root must be exactly `0700`. Owner-only files may be unreadable: the verifier queries metadata rather than opening them. Symlinks, Windows reparse points and unsupported special entries refuse the entire tree.

Existing directories are inspected without rewriting their permissions. New homes are protected and independently verified before admission. Failed cleanup of a newly created directory raises DirectoryCleanupException with kind cleanup_failed, rather than promoting the residual directory into a trusted home on retry. Existing homes are never removed.

Temporary Claude MCP configuration and permission-prompt directories retain their cleanup state until removal succeeds. A failed removal gets a deferred retry after stop. On Windows, only remaining known `approve.php` or `settings.json` entries with unavailable stat metadata permit further event-loop retries, with a three-second ceiling measured from the initial attempt. An unknown entry or any other failure stops that wait immediately. Exhaustion emits a terminal protocol problem with a fixed path-free message; repeated stop calls do not reset the budget. The process-stop verdict continues to describe process containment separately from directory cleanup. No retry widens permissions, recursively deletes entries or removes an existing credential home.

## Fixed scan limits

Verification permits at most 16,384 descendants and depth 16, with the home at depth 0. It has a 30-second budget per verification. A limit or failed metadata query refuses the home; no unexamined entry becomes trusted. `DirectoryProtectionException` has kind `unsafe_directory` and a safe reason: `permissions`, `depth_limit`, `entry_limit` or `time_limit`. Its message identifies a limit hit. Entry-limit refusals name the 16,384-entry limit and the remedy: prune old app-owned session/log files, or use a fresh app-owned home. No path, key, diagnostic body or previous exception is included.

Session transcripts, logs and caches can grow. These limits are security bounds, not a guarantee that every long-lived home will fit or complete within the budget. On limit refusal, move app-owned old session history according to the app's retention policy, or select a fresh dedicated home. The library changes no provider retention setting and deletes no history.

## Repair a dedicated app-owned tree

Selecting a fresh app-owned home is the simplest remedy. For an existing home, first verify that the chosen path belongs to the app and contains no symlinks/reparse points or special entries. Restore the existing tree's ownership and private permissions using an account authorized to do so. The adapter does not run these repair commands automatically. Re-admission performs the full check again.

For a POSIX shell, substitute the exact dedicated app path:

```sh
credential_home=/absolute/app/tenant-home
chown -hR "$(id -u)" "$credential_home"
find "$credential_home" -type d -exec chmod 700 {} +
find "$credential_home" -type f -exec chmod 600 {} +
```

On Windows, stop the provider and use PowerShell with the authority to repair that app-owned tree. Remove links first; `/L` keeps icacls operating on a link itself if one remains. The following replaces each entry's descriptor with a private descriptor without first resetting it to potentially permissive parent defaults. It touches ownership and ACL metadata only:

```powershell
$credentialHome = 'C:\AppState\tenant-42\codex'
$PSModuleAutoLoadingPreference = 'None'
$env:PSModulePath = ''
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$credentialOwnerSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$credentialAclTool = $env:SystemRoot + '\System32\icacls.exe'
& $credentialAclTool $credentialHome /setowner "*$($credentialOwnerSid.Value)" /T /L
if ($LASTEXITCODE -ne 0) { throw 'Ownership repair failed; select a fresh app-owned home.' }
function Repair-AppHomePermissions([string] $node) {
    $attributes = [IO.File]::GetAttributes($node)
    if (($attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Remove links before repair.' }
    $directory = ($attributes -band [IO.FileAttributes]::Directory) -ne 0
    $acl = if ($directory) { [Security.AccessControl.DirectorySecurity]::new() } else { [Security.AccessControl.FileSecurity]::new() }
    $acl.SetOwner($credentialOwnerSid)
    $acl.SetAccessRuleProtection($true, $false)
    $rule = if ($directory) {
        [Security.AccessControl.FileSystemAccessRule]::new($credentialOwnerSid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
    } else {
        [Security.AccessControl.FileSystemAccessRule]::new($credentialOwnerSid, 'FullControl', 'Allow')
    }
    $acl.AddAccessRule($rule)
    if ($directory) {
        [IO.Directory]::SetAccessControl($node, $acl)
        foreach ($child in [IO.Directory]::EnumerateFileSystemEntries($node)) { Repair-AppHomePermissions $child }
    } else {
        [IO.File]::SetAccessControl($node, $acl)
    }
}
Repair-AppHomePermissions $credentialHome
```

These commands reset permissions throughout the selected dedicated tree. Select the app's exact home, then retry admission. The root and all descendants must satisfy the policy; fixing only the parent descriptor is insufficient.

The adapter checks a metadata snapshot at each handoff. The app must keep ownership of the dedicated path and its parent directories; it cannot prevent the same OS identity or an administrator from changing permissions after a check. Windows command semantics are documented in [Microsoft's icacls reference](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/icacls).

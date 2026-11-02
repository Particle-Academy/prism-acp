// Test-only launcher: metadata on fresh EMPTY fixtures, then a fixed PHP probe inside the caller's existing Job.
using System;
using System.ComponentModel;
using System.IO;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text;

static class OwnerTokenRunner
{
    sealed class PrivilegeUnavailable : Exception
    {
        public readonly string Reason;
        public PrivilegeUnavailable(string reason) { Reason = reason; }
    }
    [StructLayout(LayoutKind.Sequential)] struct LinkedToken { public IntPtr Token; }
    [StructLayout(LayoutKind.Sequential)] struct Luid { public uint Low; public int High; }
    [StructLayout(LayoutKind.Sequential)] struct Privileges { public uint Count; public Luid Id; public uint Attributes; }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] struct Startup
    {
        public int cb; public string reserved, desktop, title;
        public uint x, y, xSize, ySize, xChars, yChars, fill, flags;
        public ushort show, reservedSize; public IntPtr reservedData, input, output, error;
    }
    [StructLayout(LayoutKind.Sequential)] struct ProcessInfo { public IntPtr process, thread; public uint pid, tid; }
    [DllImport("kernel32.dll")] static extern IntPtr GetCurrentProcess();
    [DllImport("kernel32.dll")] static extern IntPtr GetStdHandle(int id);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool SetHandleInformation(IntPtr handle, uint mask, uint flags);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll")] static extern uint WaitForSingleObject(IntPtr handle, uint ms);
    [DllImport("kernel32.dll")] static extern bool TerminateProcess(IntPtr handle, uint exit);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool GetExitCodeProcess(IntPtr process, out uint code);
    [DllImport("kernel32.dll", SetLastError = true)] static extern uint ResumeThread(IntPtr thread);
    [DllImport("advapi32.dll", SetLastError = true)] static extern bool OpenProcessToken(IntPtr process, uint rights, out IntPtr token);
    [DllImport("advapi32.dll", SetLastError = true)] static extern bool GetTokenInformation(IntPtr token, int kind, out int value, uint size, out uint returned);
    [DllImport("advapi32.dll", EntryPoint = "GetTokenInformation", SetLastError = true)] static extern bool GetLinkedToken(IntPtr token, int kind, out LinkedToken value, uint size, out uint returned);
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] static extern bool LookupPrivilegeValue(string system, string name, out Luid id);
    [DllImport("advapi32.dll", SetLastError = true)] static extern bool AdjustTokenPrivileges(IntPtr token, bool disableAll, ref Privileges state, uint size, out Privileges previous, out uint returned);
    [DllImport("advapi32.dll", EntryPoint = "AdjustTokenPrivileges", SetLastError = true)] static extern bool RestorePrivileges(IntPtr token, bool disableAll, ref Privileges state, uint size, IntPtr previous, IntPtr returned);
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] static extern bool CreateProcessAsUser(IntPtr token, string application, StringBuilder command, IntPtr processAttributes, IntPtr threadAttributes, bool inheritHandles, uint flags, IntPtr environment, string cwd, ref Startup startup, out ProcessInfo info);

    static bool Elevated(IntPtr token)
    {
        int value; uint size;
        if (!GetTokenInformation(token, 20, out value, 4, out size) || size != 4) throw new Win32Exception();
        return value != 0;
    }
    static bool EnabledAdministrator(IntPtr token)
    {
        using (var identity = new WindowsIdentity(token))
        {
            var admin = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
            return identity.Groups.Contains(admin) && new WindowsPrincipal(identity).IsInRole(admin);
        }
    }
    static string Boolean(bool value) { return value ? "true" : "false"; }
    static void ArrangeForeignOwner(IntPtr token, string marker)
    {
        if ((File.GetAttributes(marker) & FileAttributes.ReparsePoint) != 0) throw new InvalidOperationException();
        var requested = new Privileges(); requested.Count = 1; requested.Attributes = 2;
        if (!LookupPrivilegeValue(null, "SeRestorePrivilege", out requested.Id)) throw new Win32Exception();
        Privileges previous; uint returned;
        bool adjusted = AdjustTokenPrivileges(token, false, ref requested, (uint)Marshal.SizeOf(typeof(Privileges)), out previous, out returned);
        int adjustmentError = Marshal.GetLastWin32Error();
        if (adjustmentError == 1300 || adjustmentError == 1314) throw new PrivilegeUnavailable("restore_privilege_unavailable");
        if (!adjusted || adjustmentError != 0) throw new Win32Exception(adjustmentError);
        try
        {
            var system = new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null);
            var acl = File.GetAccessControl(marker, AccessControlSections.Access | AccessControlSections.Owner);
            var access = acl.GetSecurityDescriptorSddlForm(AccessControlSections.Access);
            acl.SetOwner(system); File.SetAccessControl(marker, acl);
            var after = File.GetAccessControl(marker, AccessControlSections.Access | AccessControlSections.Owner);
            if (!after.GetOwner(typeof(SecurityIdentifier)).Equals(system) || after.GetSecurityDescriptorSddlForm(AccessControlSections.Access) != access) throw new InvalidOperationException();
        }
        finally { if (!RestorePrivileges(token, false, ref previous, 0, IntPtr.Zero, IntPtr.Zero)) throw new Win32Exception(); }
    }
    static int Main(string[] args)
    {
        IntPtr token = IntPtr.Zero, filtered = IntPtr.Zero;
        ProcessInfo child = new ProcessInfo();
        string stage = "arguments";
        try
        {
            if (args.Length != 4 || (args[0] != "elevated" && args[0] != "restricted")) throw new InvalidOperationException();
            var root = Path.GetFullPath(args[1]).TrimEnd(Path.DirectorySeparatorChar);
            var temporary = Path.GetFullPath(Path.GetTempPath()).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (!root.StartsWith(temporary, StringComparison.OrdinalIgnoreCase) || !Path.GetFileName(root).StartsWith("prism-acp-owner-policy-", StringComparison.Ordinal)) throw new InvalidOperationException();
            stage = "process-token";
            if (!OpenProcessToken(GetCurrentProcess(), 0x2b, out token)) throw new Win32Exception();
            if (!Elevated(token) || !EnabledAdministrator(token))
            {
                Console.WriteLine("{\"available\":false,\"reason\":\"requires_elevated_administrator\"}");
                return 0;
            }
            stage = "natural-owner";
            var admin = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
            var adminMarker = Path.Combine(root, "admin-home", "empty-marker");
            if ((File.GetAttributes(adminMarker) & FileAttributes.ReparsePoint) != 0 || !File.GetAccessControl(adminMarker).GetOwner(typeof(SecurityIdentifier)).Equals(admin)) throw new InvalidOperationException("natural elevated ownership was not observed");
            stage = "foreign-owner";
            ArrangeForeignOwner(token, Path.Combine(root, "foreign-home", "empty-marker"));
            var selected = token;
            if (args[0] == "restricted")
            {
                stage = "linked-filtered-token";
                LinkedToken linked; uint returned;
                if (!GetLinkedToken(token, 19, out linked, (uint)Marshal.SizeOf(typeof(LinkedToken)), out returned))
                {
                    int linkedError = Marshal.GetLastWin32Error();
                    if (linkedError == 1312 || linkedError == 1008) throw new PrivilegeUnavailable("linked_filtered_token_unavailable");
                    throw new Win32Exception(linkedError);
                }
                filtered = linked.Token;
                if (returned != Marshal.SizeOf(typeof(LinkedToken)) || filtered == IntPtr.Zero) throw new InvalidOperationException();
                selected = filtered;
                if (Elevated(selected) || EnabledAdministrator(selected)) throw new PrivilegeUnavailable("linked_filtered_token_state_unavailable");
            }
            stage = "child-launch";
            var startup = new Startup(); startup.cb = Marshal.SizeOf(typeof(Startup)); startup.flags = 0x100;
            startup.input = GetStdHandle(-10); startup.output = GetStdHandle(-11); startup.error = GetStdHandle(-12);
            if (!SetHandleInformation(startup.input, 1, 1) || !SetHandleInformation(startup.output, 1, 1) || !SetHandleInformation(startup.error, 1, 1)) throw new Win32Exception();
            // No breakaway/new console. The fixed metadata probe inherits this launcher's existing supervisor Job.
            if (!CreateProcessAsUser(selected, args[2], new StringBuilder(args[3]), IntPtr.Zero, IntPtr.Zero, true, 0x08000004, IntPtr.Zero, root, ref startup, out child))
            {
                int launchError = Marshal.GetLastWin32Error();
                if (launchError == 1314) throw new PrivilegeUnavailable("process_token_privilege_unavailable");
                throw new Win32Exception(launchError);
            }
            Console.WriteLine("{\"available\":true,\"elevated\":" + Boolean(Elevated(selected)) + ",\"administratorEnabled\":" + Boolean(EnabledAdministrator(selected)) + ",\"naturalAdministratorOwner\":true,\"tokenSource\":\"" + (args[0] == "elevated" ? "process" : "linked_filtered") + "\"}"); Console.Out.Flush();
            if (ResumeThread(child.thread) == uint.MaxValue) throw new Win32Exception();
            stage = "child-wait";
            if (WaitForSingleObject(child.process, 90000) != 0) { TerminateProcess(child.process, 1); WaitForSingleObject(child.process, 5000); throw new InvalidOperationException("metadata child deadline"); }
            uint code; if (!GetExitCodeProcess(child.process, out code)) throw new Win32Exception();
            return unchecked((int)code);
        }
        catch (PrivilegeUnavailable error) { Console.WriteLine("{\"available\":false,\"reason\":\"" + error.Reason + "\"}"); return 0; }
        catch (Exception error) { Console.Error.WriteLine("owned-token-control:" + stage + ":" + error.GetType().Name + ":" + Marshal.GetLastWin32Error()); return 1; }
        finally
        {
            if (child.process != IntPtr.Zero && WaitForSingleObject(child.process, 0) == 258) { TerminateProcess(child.process, 1); WaitForSingleObject(child.process, 5000); }
            if (child.thread != IntPtr.Zero) CloseHandle(child.thread);
            if (child.process != IntPtr.Zero) CloseHandle(child.process);
            if (filtered != IntPtr.Zero) CloseHandle(filtered);
            if (token != IntPtr.Zero) CloseHandle(token);
        }
    }
}

// Test-only metadata for one owned, credential-free approve.php. No file contents, shutdown or privileges.
using System;
using System.Collections.Generic;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;
using System.Web.Script.Serialization;

static class OwnedFileDiagnostic
{
    [StructLayout(LayoutKind.Sequential)] struct UnicodeString { public ushort Length, MaximumLength; public IntPtr Buffer; }
    [StructLayout(LayoutKind.Sequential)] struct ObjectAttributes { public uint Length; public IntPtr RootDirectory, ObjectName; public uint Attributes; public IntPtr SecurityDescriptor, SecurityQualityOfService; }
    [StructLayout(LayoutKind.Sequential)] struct FileBasic { public long Creation, Access, Write, Change; public uint Attributes; }
    [StructLayout(LayoutKind.Sequential)] struct FileTime { public uint Low, High; public ulong Value { get { return ((ulong)High << 32) | Low; } } }
    [StructLayout(LayoutKind.Sequential)] struct IoStatus { public IntPtr Status, Information; }
    [StructLayout(LayoutKind.Sequential)] struct ProcessBasic { public IntPtr Reserved1, Peb, Reserved2, Reserved3, Pid, Parent; }
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)] static extern uint GetFileAttributesW(string path);
    [DllImport("ntdll.dll")] static extern uint NtQueryAttributesFile(ref ObjectAttributes attributes, out FileBasic info);
    [DllImport("ntdll.dll")] static extern uint NtQueryInformationProcess(IntPtr process, uint type, out ProcessBasic info, uint length, out uint returned);
    [DllImport("ntdll.dll")] static extern uint NtQueryInformationFile(IntPtr file, out IoStatus io, IntPtr output, uint length, uint informationClass);
    [DllImport("kernel32.dll", SetLastError = true)] static extern IntPtr OpenProcess(uint access, bool inherit, uint pid);
    [DllImport("kernel32.dll")] static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool GetProcessTimes(IntPtr process, out FileTime created, out FileTime exited, out FileTime kernel, out FileTime user);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)] static extern bool QueryFullProcessImageNameW(IntPtr process, uint flags, StringBuilder image, ref uint size);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)] static extern IntPtr CreateFileW(string path, uint access, uint share, IntPtr security, uint disposition, uint flags, IntPtr template);

    static void Emit(object value) { Console.WriteLine(new JavaScriptSerializer().Serialize(value)); Console.Out.Flush(); }
    static string OwnedPath(string root)
    {
        string full = Path.GetFullPath(root), temporary = Path.GetFullPath(Path.GetTempPath()).TrimEnd('\\', '/');
        if (!String.Equals(Path.GetDirectoryName(full), temporary, StringComparison.OrdinalIgnoreCase) ||
            !System.Text.RegularExpressions.Regex.IsMatch(Path.GetFileName(full), "^prism-acp-approve-[a-f0-9]{12}$") ||
            full.Length < 3 || full[1] != ':') throw new ArgumentException();
        uint attributes = GetFileAttributesW(full);
        if (attributes != uint.MaxValue && (attributes & 0x400) != 0) throw new ArgumentException();
        return Path.Combine(full, "approve.php");
    }
    static uint NativeStatus(string path)
    {
        string name = "\\??\\" + path;
        var text = new UnicodeString { Length = checked((ushort)(name.Length * 2)), MaximumLength = checked((ushort)((name.Length + 1) * 2)), Buffer = Marshal.StringToHGlobalUni(name) };
        IntPtr pointer = Marshal.AllocHGlobal(Marshal.SizeOf(typeof(UnicodeString)));
        try
        {
            Marshal.StructureToPtr(text, pointer, false);
            var attributes = new ObjectAttributes { Length = (uint)Marshal.SizeOf(typeof(ObjectAttributes)), ObjectName = pointer, Attributes = 0x40 };
            FileBasic info;
            return NtQueryAttributesFile(ref attributes, out info);
        }
        finally { Marshal.FreeHGlobal(pointer); Marshal.FreeHGlobal(text.Buffer); }
    }
    static object Holder(uint pid)
    {
        var row = new Dictionary<string, object> { { "pid", pid } };
        // Query/synchronize only. No process command line, token, memory, termination or privilege changes.
        IntPtr process = OpenProcess(0x100000 | 0x1000, false, pid);
        if (process == IntPtr.Zero) { row["queryError"] = Marshal.GetLastWin32Error(); return row; }
        try
        {
            FileTime created, exited, kernel, user;
            if (GetProcessTimes(process, out created, out exited, out kernel, out user))
            {
                row["observedStartTime"] = created.Value.ToString();
            }
            else row["timeError"] = Marshal.GetLastWin32Error();
            row["waitResult"] = WaitForSingleObject(process, 0);
            uint size = 32768; var image = new StringBuilder((int)size);
            if (QueryFullProcessImageNameW(process, 0, image, ref size)) row["imageBasename"] = Path.GetFileName(image.ToString());
            else row["imageError"] = Marshal.GetLastWin32Error();
            ProcessBasic basic; uint returned;
            uint status = NtQueryInformationProcess(process, 0, out basic, (uint)Marshal.SizeOf(typeof(ProcessBasic)), out returned);
            row["parentQueryStatus"] = status.ToString("x8");
            if (status == 0) row["parentPid"] = basic.Parent.ToInt64();
        }
        finally { CloseHandle(process); }
        return row;
    }
    static void Attributes(string path)
    {
        uint attributes = GetFileAttributesW(path);
        int error = attributes == uint.MaxValue ? Marshal.GetLastWin32Error() : 0;
        Emit(new { nativeFileMetadata = new { win32Error = error, attributes = attributes, ntStatus = NativeStatus(path).ToString("x8") } });
    }
    static void FileUsers(string path)
    {
        IntPtr file = CreateFileW(path, 0x80 | 0x100000, 7, IntPtr.Zero, 3, 0x00200000, IntPtr.Zero);
        if (file == IntPtr.Zero || file == new IntPtr(-1)) { Emit(new { holderMetadata = new { stage = "open", win32Error = Marshal.GetLastWin32Error() } }); return; }
        IntPtr buffer = Marshal.AllocHGlobal(8 + 128 * IntPtr.Size);
        object result;
        try
        {
            IoStatus io;
            uint status = NtQueryInformationFile(file, out io, buffer, (uint)(8 + 128 * IntPtr.Size), 47);
            var holders = new List<object>();
            int count = status == 0 ? Marshal.ReadInt32(buffer) : -1;
            if (count >= 0 && count <= 128)
            {
                for (int i = 0; i < count; i++)
                {
                    long pid = Marshal.ReadIntPtr(buffer, (IntPtr.Size == 8 ? 8 : 4) + i * IntPtr.Size).ToInt64();
                    if (pid > 0 && pid <= uint.MaxValue && pid != System.Diagnostics.Process.GetCurrentProcess().Id) holders.Add(Holder((uint)pid));
                }
            }
            result = new { holderMetadata = new { stage = "file-process-query", ntStatus = status.ToString("x8"), count = count, holders = holders, absenceDoesNotProveNoHolder = true } };
        }
        finally { Marshal.FreeHGlobal(buffer); CloseHandle(file); }
        // The diagnostic's metadata handle is CLOSED before the caller may unlink the file.
        Emit(result);
    }
    static int Main(string[] args)
    {
        try
        {
            if (args.Length < 1 || args.Length > 2) return 2;
            string path = OwnedPath(args[0]);
            if (args.Length == 2)
            {
                if (args[1] == "serve")
                {
                    Emit(new { diagnosticReady = true, pid = System.Diagnostics.Process.GetCurrentProcess().Id });
                    string command;
                    while ((command = Console.ReadLine()) != null)
                    {
                        if (command == "attributes") Attributes(path);
                        else if (command == "users") FileUsers(path);
                        else return 2;
                    }
                    return 0;
                }
                if (args[1] != "hold-empty-control" || new FileInfo(path).Length != 0) return 2;
                IntPtr handle = CreateFileW(path, 0x80, 7, IntPtr.Zero, 3, 0x00200000, IntPtr.Zero);
                if (handle == IntPtr.Zero || handle == new IntPtr(-1)) return 3;
                try { Emit(new { holderReady = true, pid = System.Diagnostics.Process.GetCurrentProcess().Id }); Console.ReadLine(); }
                finally { CloseHandle(handle); }
            }
            else { Attributes(path); FileUsers(path); }
            return 0;
        }
        catch (Exception error) { Emit(new { diagnosticFailure = error.GetType().Name }); return 1; }
    }
}

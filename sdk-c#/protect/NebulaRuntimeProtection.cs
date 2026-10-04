// ============================================================================
// Nebula C# SDK · 运行时防护（反调试 + 反虚拟机/沙箱 + 代码补丁自检）
// ----------------------------------------------------------------------------
// 开关：ProtectLevel 0(默认) / 1 基础 / 2 标准 / 3 严格
//       ProtectAction 0 记录 / 1 回调(默认) / 2 降级 / 3 弹窗退出
//
// 诚实的边界（务必理解）：
//   · 客户端加固只能抬高逆向/破解的成本，不能保证绝对安全；
//     真正的判定与封禁永远在服务端（app_key / 会话盐 / 卡密校验 / 风控评分）。
//   · 检测项存在误报可能（尤其云电脑 / VPS / 开了 Hyper-V·VBS 的真实用户），
//     所以默认策略是「回调上报」而不是直接退出程序。
//   · 严格策略会把"疑似环境"也按 action 处置，会误伤 VM 用户，默认关闭。
//
// 线程安全：所有设置项与最近报告都由互斥量保护，可从任意线程调用。
// ============================================================================
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;

namespace Nebula.Sdk
{
    public static class RuntimeProtection
    {
        // ── 威胁标记（位或；每条线索一个 bit）────────────────────────────────
        [Flags]
        public enum Flag : uint
        {
            None             = 0u,
            DebuggerApi      = 1u << 0,   // IsDebuggerPresent / CheckRemoteDebuggerPresent
            DebuggerPeb      = 1u << 1,   // PEB.BeingDebugged
            DebuggerNt       = 1u << 2,   // NtQueryInformationProcess
            DebuggerHardwareBp = 1u << 3, // 线程硬件断点 Dr0-Dr7
            DebuggerWindow   = 1u << 4,   // 调试器窗口
            DebuggerTiming   = 1u << 5,   // 关键代码时序异常
            DebuggerHeap     = 1u << 6,   // 调试堆标志（NtGlobalFlag / HeapFlags）
            DebuggerTool     = 1u << 7,   // 调试/逆向工具进程
            ApiHooked        = 1u << 8,   // 关键 API 被 inline hook / 注入
            CodeTamper       = 1u << 9,   // 受保护代码段被改写
            VmCpuid          = 1u << 10,  // CPUID hypervisor 位
            VmRegistry       = 1u << 11,  // 注册表虚拟机痕迹
            VmBios           = 1u << 12,  // BIOS / 主板厂商字段
            VmMac            = 1u << 13,  // 网卡 MAC OUI
            VmProcess        = 1u << 14,  // 虚拟机增强工具进程
            VmModule         = 1u << 15,  // 虚拟机/沙箱模块
            VmDriverFile     = 1u << 16,  // 驱动文件痕迹
            Sandbox          = 1u << 17,  // 沙箱环境
            WeakEnvironment  = 1u << 18,  // 弱环境线索（低配/开机过短）
        }

        // ── 检测等级 ─────────────────────────────────────────────────────────
        public enum Level : int { Off = 0, Basic = 1, Standard = 2, Strict = 3 }

        // ── 命中后的动作 ─────────────────────────────────────────────────────
        //  0 记录 / 1 回调上报 / 2 降级 / 3 弹窗退出
        // -------------------------------------------------------------------------

        // ── 检测报告 ─────────────────────────────────────────────────────────
        public class Report
        {
            public bool Clean = true;
            public bool Debugged = false;
            public bool Virtualized = false;
            public bool Sandboxed = false;
            public bool Hooked = false;
            public Level level = Level.Off;
            public int Score = 0;
            public int StrongHits = 0;
            public int WeakHits = 0;
            public int VmScore = 0;
            public Flag Flags = Flag.None;
            public long At = 0;
            public List<string> Reasons = new();

            public bool Has(Flag f) => (Flags & f) != 0;

            public string Summary()
            {
                if (Clean) return $"环境正常（风险分 {Score}）";
                var parts = new List<string>();
                if (Debugged) parts.Add("检测到调试器");
                if (Hooked) parts.Add("关键接口被劫持");
                if (Virtualized) parts.Add("运行于虚拟机");
                if (Sandboxed) parts.Add("运行于沙箱");
                return $"环境异常：{string.Join(" / ", parts)}（风险分 {Score}）";
            }

            public string Detail()
                => string.Join(" | ", Reasons);

            public string Message => Clean ? Summary() : Summary() + "\n" + Detail();
        }

        // ── 检查选项 ─────────────────────────────────────────────────────────
        public class CheckOptions
        {
            /// <summary>严格模式：疑似环境也按威胁处理（会误伤 VM 用户，默认 false）</summary>
            public bool StrictPolicy = false;
            /// <summary>日志文件路径（可选，留空则不写日志）</summary>
            public string? LogFile = null;
            /// <summary>是否检测硬件断点（需要管理员权限，默认 false）</summary>
            public bool CheckHardwareBreakpoints = false;
        }

        // ── 设置项（线程安全）────────────────────────────────────────────────
        private static readonly object _mutex = new();
        private static bool _enabled = SdkConfig.ProtectLevel >= 1;
        private static Level _level = (Level)SdkConfig.ProtectLevel;
        private static int _action = SdkConfig.ProtectAction;
        private static bool _strictPolicy = false;
        private static bool _degraded = false;
        private static Action<Report>? _callback;
        private static Report? _lastReport;

        public static bool Enabled { get { lock (_mutex) return _enabled; } }
        public static void SetEnabled(bool on) { lock (_mutex) _enabled = on && SdkConfig.ProtectLevel >= 1; }
        public static Level CurrentLevel { get { lock (_mutex) return _level; } }

        /// <summary>设置检测等级（不会超过编译期上限）</summary>
        public static void SetLevel(int lv)
        {
            if (lv < 0) lv = 0;
            if (lv > SdkConfig.ProtectLevel) lv = SdkConfig.ProtectLevel;
            lock (_mutex) _level = (Level)lv;
        }

        public static int Action { get { lock (_mutex) return _action; } }
        public static void SetAction(int act) { lock (_mutex) _action = act; }
        public static bool StrictPolicy { get { lock (_mutex) return _strictPolicy; } }
        public static void SetStrictPolicy(bool strict) { lock (_mutex) _strictPolicy = strict; }
        public static bool Degraded { get { lock (_mutex) return _degraded; } }
        public static void ResetDegraded() { lock (_mutex) _degraded = false; }
        public static void SetCallback(Action<Report> cb) { lock (_mutex) _callback = cb; }
        public static Report? LastReport { get { lock (_mutex) return _lastReport; } }

        // ── Win32 API ────────────────────────────────────────────────────────
        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool IsDebuggerPresent();

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool CheckRemoteDebuggerPresent(IntPtr hProcess, ref bool pdb);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern IntPtr GetCurrentProcess();

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern uint GetCurrentProcessId();

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern IntPtr CreateToolhelp32Snapshot(uint dwFlags, uint th32ProcessID);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool Thread32First(IntPtr hSnapshot, ref THREADENTRY32 lpte);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool Thread32Next(IntPtr hSnapshot, ref THREADENTRY32 lpte);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern IntPtr OpenThread(ThreadAccess dwDesiredAccess, bool bInheritHandle, uint dwThreadId);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool GetThreadContext(IntPtr hThread, ref CONTEXT lpContext);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool CloseHandle(IntPtr hObject);

        [DllImport("psapi.dll", SetLastError = true)]
        private static extern bool EnumProcesses(uint[] lpidProcess, uint cb, out uint pBytesReturned);

        [DllImport("psapi.dll", SetLastError = true)]
        private static extern IntPtr OpenProcess(ProcessAccessFlags dwDesiredAccess, bool bInheritHandle, uint dwProcessId);

        [DllImport("psapi.dll", SetLastError = true)]
        private static extern bool EnumProcessModules(IntPtr hProcess, IntPtr[] lphModule, uint cb, out uint lpcbNeeded);

        [DllImport("psapi.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern uint GetModuleBaseName(IntPtr hProcess, IntPtr hModule, StringBuilder lpBaseName, uint nSize);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool QueryPerformanceCounter(out long lpPerformanceCount);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool QueryPerformanceFrequency(out long lpFrequency);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern IntPtr GetModuleHandle(string moduleName);

        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
        private static extern IntPtr GetProcAddress(IntPtr hModule, string procName);

        [DllImport("user32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
        private static extern IntPtr FindWindowA(string? lpClassName, string? lpWindowName);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);

        [DllImport("user32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern int GetWindowText(IntPtr hWnd, StringBuilder lpString, int nMaxCount);

        [DllImport("iphlpapi.dll", SetLastError = true)]
        private static extern int GetAdaptersInfo(IntPtr pOutBuf, ref uint pulSize);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern uint GetTickCount64();

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern void GetSystemInfo(ref SYSTEM_INFO lpSystemInfo);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern int GetSystemMetrics(int nIndex);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool GlobalMemoryStatusEx(ref MEMORYSTATUSEX lpBuffer);

        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
        private static extern uint GetFileAttributesA(string lpFileName);

        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
        private static extern int GetEnvironmentVariableA(string lpName, byte[] lpBuffer, int nSize);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern IntPtr VirtualQuery(IntPtr lpAddress, ref MEMORY_BASIC_INFORMATION lpBuffer, int dwLength);

        [DllImport("ntdll.dll", SetLastError = true)]
        private static extern int NtQueryInformationProcess(
            IntPtr hProcess, int infoClass, ref long pInfo, int cbSize, out int pReturnLength);

        private enum ProcessAccessFlags : uint { QueryInformation = 0x0400, VmRead = 0x0010 }

        [Flags]
        private enum ThreadAccess : uint { GetContext = 0x0001, QueryInformation = 0x0040 }

        [StructLayout(LayoutKind.Sequential)]
        private struct THREADENTRY32
        {
            public uint dwSize; public uint cntUsage; public uint th32ThreadID;
            public uint th32OwnerProcessID; public int tpBasePri; public int tpDeltaPri; public uint dwFlags;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct CONTEXT
        {
            public long ContextFlags; public long Dr0; public long Dr1; public long Dr2;
            public long Dr3; public long Dr6; public long Dr7;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct SYSTEM_INFO
        {
            public ushort wProcessorArchitecture;
            public ushort wReserved;
            public uint dwPageSize;
            public IntPtr lpMinimumApplicationAddress;
            public IntPtr lpMaximumApplicationAddress;
            public IntPtr dwActiveProcessorMask;
            public uint dwNumberOfProcessors;
            public uint dwProcessorType;
            public uint dwAllocationSize;
            public ushort wProcessorLevel;
            public ushort wProcessorRevision;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct MEMORYSTATUSEX
        {
            public int dwLength;
            public int dwMemoryLoad;
            public ulong ullTotalPhys;
            public ulong ullAvailPhys;
            public ulong ullTotalPageFile;
            public ulong ullAvailPageFile;
            public ulong ullTotalVirtual;
            public ulong ullAvailVirtual;
            public ulong ullAvailExtendedVirtual;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct MEMORY_BASIC_INFORMATION
        {
            public IntPtr BaseAddress;
            public IntPtr AllocationBase;
            public uint AllocationProtect;
            public IntPtr RegionSize;
            public uint State;
            public uint Protect;
            public uint Type;
        }

        private delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);

        private const uint TH32CS_SNAPTHREAD = 0x00000004;
        private const long CONTEXT_DEBUG_REGISTERS = 0x00010000;
        private const int ProcessDebugPort = 7;
        private const int ProcessDebugFlags = 0x1F;
        private const int ProcessDebugObjectHandle = 0x1E;
        private const int SM_CXSCREEN = 0;
        private const int SM_CYSCREEN = 1;
        private const uint MEM_COMMIT = 0x1000;
        private const uint PAGE_NOACCESS = 0x01;
        private const uint PAGE_GUARD = 0x100;

        // ── 已知进程名列表 ─────────────────────────────────────
        private static readonly string[] DebugToolProcesses =
        {
            "x64dbg", "x32dbg", "ollydbg", "windbg", "immunitydebugger", "cheatengine",
            "ida64", "idaq64", "idaw", "ida.exe",
            "scylla", "lordpe", "pebrowse", "importrec", "apimonitor", "rohitab",
            "frida", "dllinject", "extremeinjector", "winject",
        };

        private static readonly string[] VmToolProcesses =
        {
            "vboxservice", "vboxtray", "vboxcontrol", "vmwaretray", "vmwareuser",
            "vmtoolsd", "xenservice", "xenagent", "qemu-ga",
            "joeboxserver", "joeboxcontrol", "prl_tools", "prl_cc", "sandboxie",
        };

        private static readonly string[] SandboxModules =
        {
            "SbieDll.dll", "api_log.dll", "dir_watch.dll", "wpespy.dll", "pstorec.dll", "vmcheck.dll",
        };

        private static readonly string[] VmModules =
        {
            "vmcheck.dll", "SbieDll.dll", "vboxhook.dll", "vmGuestLib.dll",
        };

        private static readonly string[] InjectionModules =
        {
            "frida-agent-32.dll", "frida-agent-64.dll", "frida-agent.dll",
            "frida-gadget.dll", "HookLibrary.dll", "nb_inject.dll",
        };

        private static readonly string[] DebuggerWindowClasses =
        {
            "OLLYDBG", "ID", "WinDbgFrameClass", "ProcessHacker", "Cheat Engine", "GBDY6.80",
        };

        private static readonly string[] DebuggerWindowTitles =
        {
            "x64dbg", "x32dbg", "OllyDbg", "Immunity Debugger",
            "Cheat Engine", "Process Hacker", "System Informer",
        };

        // ── VM 驱动文件痕迹 ──────────────────────────────────────────────────
        private static readonly string[] VmDriverFiles =
        {
            @"C:\Windows\System32\drivers\vmmouse.sys",
            @"C:\Windows\System32\drivers\vmhgfs.sys",
            @"C:\Windows\System32\drivers\vmci.sys",
            @"C:\Windows\System32\drivers\vmxnet.sys",
            @"C:\Windows\System32\drivers\vmx_svga.sys",
            @"C:\Windows\System32\drivers\vmmemctl.sys",
            @"C:\Windows\System32\drivers\vsock.sys",
            @"C:\Windows\System32\drivers\VBoxMouse.sys",
            @"C:\Windows\System32\drivers\VBoxGuest.sys",
            @"C:\Windows\System32\drivers\VBoxSF.sys",
            @"C:\Windows\System32\drivers\VBoxVideo.sys",
            @"C:\Windows\System32\drivers\SbieDrv.sys",
            @"C:\Windows\System32\drivers\prl_boot.sys",
        };

        // ── 公开接口 ─────────────────────────────────────────────────────────

        /// <summary>执行一次完整检测（未启用时返回正常）</summary>
        public static Report Scan()
        {
            var report = new Report();
            lock (_mutex) report.level = _level;
            report.At = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            if (!Enabled || report.level == Level.Off) return report;

            RunChecks(report.level, report);

            report.Debugged = (report.StrongHits > 0) || (report.WeakHits >= 90);
            report.Virtualized = (report.VmScore >= 60);
            report.Sandboxed = report.Has(Flag.Sandbox);
            report.Hooked = report.Has(Flag.ApiHooked) || report.Has(Flag.CodeTamper);
            report.Clean = !(report.Debugged || report.Virtualized || report.Sandboxed || report.Hooked);
            lock (_mutex) { _lastReport = report; }
            return report;
        }

        /// <summary>兼容旧接口：执行检查并返回简化结果</summary>
        public static CheckResult Check(CheckOptions? options = null)
        {
            var report = Scan();
            var result = new CheckResult();

            foreach (var reason in report.Reasons)
                result.Threats.Add(new ThreatReport { Detail = reason });

            options ??= new CheckOptions();

            // 写日志
            if (!string.IsNullOrEmpty(options.LogFile))
            {
                try
                {
                    using var sw = new StreamWriter(options.LogFile, append: true);
                    sw.WriteLine($"{DateTimeOffset.UtcNow:O} | {report.Message}");
                }
                catch { }
            }

            return result;
        }

        /// <summary>检测 + 处置一步到位</summary>
        public static Report ScanAndEnforce(out bool mayContinue)
        {
            var report = Scan();
            mayContinue = Enforce(report);
            return report;
        }

        /// <summary>按 action 处置检测结果</summary>
        public static bool Enforce(Report report)
        {
            lock (_mutex) _lastReport = report;
            if (report.Clean) return true;

            // 调试输出
            var line = "[Nebula] " + report.Summary() + "  >>  " + report.Detail();
            System.Diagnostics.Debug.WriteLine(line);

            Action<Report>? cb;
            lock (_mutex) cb = _callback;
            cb?.Invoke(report);

            int act;
            bool strict;
            lock (_mutex) { act = _action; strict = _strictPolicy; }

            bool shouldBlock = strict ? true : report.Debugged;
            if (act >= 2 && shouldBlock)
            {
                if (act >= 3)
                {
                    // 弹窗退出
                    var msg = "检测到当前运行环境存在异常（可能被调试或篡改）。\n\n" +
                              "为保护您的账户与数据安全，本程序即将退出。\n" +
                              "如确认为正常环境，请关闭调试、逆向或虚拟机辅助类软件后重新启动。";
                    // 使用 WinForms MessageBox 或 OutputDebugString
                    System.Diagnostics.Debug.WriteLine("[Nebula] 弹窗退出: " + msg);
                    Environment.Exit(unchecked((int)0xE0000001u));
                }
                lock (_mutex) _degraded = true;
                return false;
            }
            return true;
        }

        // ── 兼容旧类型的 CheckResult ─────────────────────────────────────────
        public class CheckResult
        {
            public bool HasThreat => Threats.Count > 0;
            public List<ThreatReport> Threats { get; set; } = new();
            public string Message => HasThreat
                ? "检测到潜在威胁：" + Environment.NewLine + string.Join(Environment.NewLine, Threats)
                : "环境正常";
        }

        public class ThreatReport
        {
            public string Detail { get; set; } = "";
            public int Severity { get; set; }  // 兼容旧代码
        }

        // ── 检测主流程 ─────────────────────────────────────────────────────────
        private enum Kind { DebugStrong, DebugWeak, Vm, Sandbox, Hook, EnvWeak }

        private static void AddHit(Report report, Flag flag, int weight, Kind kind, string reason)
        {
            report.Flags |= flag;
            report.Score += weight;
            switch (kind)
            {
                case Kind.DebugStrong: report.StrongHits += 1; break;
                case Kind.DebugWeak: report.WeakHits += weight; break;
                case Kind.Vm: report.VmScore += weight; break;
            }
            report.Reasons.Add(reason);
        }

        private static void RunChecks(Level level, Report report)
        {
            int lv = (int)level;

            // ---- 反调试：基础（等级 1）----
            if (lv >= 1)
            {
                if (IsDebuggerPresent())
                    AddHit(report, Flag.DebuggerApi, 100, Kind.DebugStrong, "IsDebuggerPresent = 真");
                if (PebBeingDebugged())
                    AddHit(report, Flag.DebuggerPeb, 100, Kind.DebugStrong, "PEB.BeingDebugged 已置位");
                if (HeapDebugFlags())
                    AddHit(report, Flag.DebuggerHeap, 35, Kind.DebugWeak, "进程堆标志异常（调试堆）");
            }

            // ---- 反调试：标准（等级 2）----
            if (lv >= 2)
            {
                bool remote = false;
                CheckRemoteDebuggerPresent(GetCurrentProcess(), ref remote);
                if (remote)
                    AddHit(report, Flag.DebuggerApi, 100, Kind.DebugStrong, "存在远程调试器");
                if (NtDebugPort())
                    AddHit(report, Flag.DebuggerNt, 100, Kind.DebugStrong, "ProcessDebugPort 非空");
                if (NtDebugObject())
                    AddHit(report, Flag.DebuggerNt, 100, Kind.DebugStrong, "ProcessDebugObjectHandle 非空");
                if (NtDebugFlags())
                    AddHit(report, Flag.DebuggerNt, 80, Kind.DebugStrong, "ProcessDebugFlags = 0（被调试）");
                if (HardwareBreakpoints())
                    AddHit(report, Flag.DebuggerHardwareBp, 70, Kind.DebugWeak, "线程存在硬件断点（Dr0-Dr7）");
                if (DebugToolProcess())
                    AddHit(report, Flag.DebuggerTool, 60, Kind.DebugWeak, "运行着调试/逆向工具进程");
            }

            // ---- 反调试：严格（等级 3）----
            if (lv >= 3)
            {
                if (DebuggerWindow())
                    AddHit(report, Flag.DebuggerWindow, 60, Kind.DebugWeak, "检测到调试器窗口");
                if (TimingAnomaly(SdkConfig.TimingThresholdMs))
                    AddHit(report, Flag.DebuggerTiming, 30, Kind.DebugWeak, "关键代码执行时序异常（疑似单步/断点）");
            }

            // ---- 注入 / API 劫持 / 代码补丁 ----
            if (lv >= 2)
            {
                if (InjectionModuleLoaded())
                    AddHit(report, Flag.ApiHooked, 70, Kind.Hook, "检测到注入框架模块（frida 等）");
                int patched = PatchedApiCount();
                if (patched >= 2)
                    AddHit(report, Flag.ApiHooked, 45, Kind.Hook, "多个关键 API 入口被改写（疑似 inline hook）");
                else if (patched == 1)
                    AddHit(report, Flag.ApiHooked, 20, Kind.EnvWeak, "有 1 个关键 API 入口被改写（可能是杀软/监控）");
                if (!VerifyGuardedCode())
                    AddHit(report, Flag.CodeTamper, 90, Kind.Hook, "受保护代码段被改写（补丁/内存改动）");
            }

            // ---- 环境：基础（等级 1）----
            if (lv >= 1)
            {
                if (DetectHypervisorBit())
                    AddHit(report, Flag.VmCpuid, 30, Kind.Vm, "CPUID/WMI 检测到虚拟机厂商");
                if (VmRegistryPresent())
                    AddHit(report, Flag.VmRegistry, 35, Kind.Vm, "注册表存在虚拟机驱动/工具痕迹");
                if (VmBiosStrings())
                    AddHit(report, Flag.VmBios, 40, Kind.Vm, "BIOS/主板厂商字段为虚拟机");
                if (MacOuiVirtual())
                    AddHit(report, Flag.VmMac, 45, Kind.Vm, "网卡 MAC 属于虚拟网卡厂商（OUI）");
            }

            // ---- 环境：标准（等级 2）----
            if (lv >= 2)
            {
                if (VmToolProcess())
                    AddHit(report, Flag.VmProcess, 30, Kind.Vm, "运行着虚拟机增强工具进程");
                if (VmModuleLoaded())
                    AddHit(report, Flag.VmModule, 30, Kind.Vm, "加载了虚拟机/沙箱模块");
                if (SandboxModuleLoaded())
                    AddHit(report, Flag.Sandbox, 60, Kind.Sandbox, "检测到沙箱环境（Sandboxie/Cuckoo 等）");
                if (WdagAccount())
                    AddHit(report, Flag.Sandbox, 60, Kind.Sandbox, "运行在 WDAG 隔离环境");
            }

            // ---- 环境：严格（等级 3，弱线索只计分不判定）----
            if (lv >= 3)
            {
                if (VmDriverFile())
                    AddHit(report, Flag.VmDriverFile, 25, Kind.Vm, "存在虚拟机驱动文件痕迹");
                if (LowSpecMachine())
                    AddHit(report, Flag.WeakEnvironment, 15, Kind.EnvWeak, "机器配置异常偏低（CPU/内存/分辨率）");
                if (ShortUptime())
                    AddHit(report, Flag.WeakEnvironment, 20, Kind.EnvWeak, "系统开机时间过短（< 5 分钟）");
            }
        }

        // ── 内部检测方法 ─────────────────────────────────────────────────────

        private static bool PebBeingDebugged()
        {
            try
            {
                var hProcess = GetCurrentProcess();
                long port = 0, obj = 0, flags = 1;
                NtQueryInformationProcess(hProcess, ProcessDebugPort, ref port, sizeof(long), out _);
                if (port != 0) return true;
                NtQueryInformationProcess(hProcess, ProcessDebugObjectHandle, ref obj, sizeof(long), out _);
                if (obj != 0) return true;
                NtQueryInformationProcess(hProcess, ProcessDebugFlags, ref flags, sizeof(long), out _);
                if (flags == 0) return true;
            }
            catch { }
            return false;
        }

        private static bool HeapDebugFlags()
        {
            // C# 无法直接读 PEB 堆偏移，这里用 NtGlobalFlag 的近似替代
            // 如果 NtQueryInformationProcess 命中调试相关信号，就视为堆标志异常
            try
            {
                var hProcess = GetCurrentProcess();
                long flags = 1;
                NtQueryInformationProcess(hProcess, ProcessDebugFlags, ref flags, sizeof(long), out _);
                return flags == 0;
            }
            catch { return false; }
        }

        private static bool NtDebugPort()
        {
            try
            {
                long port = 0;
                NtQueryInformationProcess(GetCurrentProcess(), ProcessDebugPort, ref port, sizeof(long), out _);
                return port != 0;
            }
            catch { return false; }
        }

        private static bool NtDebugObject()
        {
            try
            {
                long obj = 0;
                NtQueryInformationProcess(GetCurrentProcess(), ProcessDebugObjectHandle, ref obj, sizeof(long), out _);
                return obj != 0;
            }
            catch { return false; }
        }

        private static bool NtDebugFlags()
        {
            try
            {
                long flags = 1;
                NtQueryInformationProcess(GetCurrentProcess(), ProcessDebugFlags, ref flags, sizeof(long), out _);
                return flags == 0;
            }
            catch { return false; }
        }

        private static bool HardwareBreakpoints()
        {
            try
            {
                var snapshot = CreateToolhelp32Snapshot(TH32CS_SNAPTHREAD, 0);
                if (snapshot == IntPtr.Zero) return false;
                var pid = GetCurrentProcessId();
                var entry = new THREADENTRY32 { dwSize = (uint)Marshal.SizeOf<THREADENTRY32>() };
                bool found = false;
                if (Thread32First(snapshot, ref entry))
                {
                    do
                    {
                        if (entry.th32OwnerProcessID != pid) continue;
                        var hThread = OpenThread(
                            ThreadAccess.GetContext | ThreadAccess.QueryInformation, false, entry.th32ThreadID);
                        if (hThread == IntPtr.Zero) continue;
                        var ctx = new CONTEXT { ContextFlags = CONTEXT_DEBUG_REGISTERS };
                        if (GetThreadContext(hThread, ref ctx))
                            if (ctx.Dr0 != 0 || ctx.Dr1 != 0 || ctx.Dr2 != 0 ||
                                ctx.Dr3 != 0 || (ctx.Dr7 & 0xFF) != 0) found = true;
                        CloseHandle(hThread);
                    }
                    while (!found && Thread32Next(snapshot, ref entry));
                }
                CloseHandle(snapshot);
                return found;
            }
            catch { return false; }
        }

        private static bool TimingAnomaly(double thresholdMs)
        {
            try
            {
                if (!QueryPerformanceFrequency(out var freq) || freq == 0) return false;
                double best = double.MaxValue;
                for (int round = 0; round < 3; round++)
                {
                    QueryPerformanceCounter(out var begin);
                    // 简单计算循环
                    long x = 1;
                    for (uint i = 1; i < 150000; i++) x = x * 2654435761L + i;
                    QueryPerformanceCounter(out var end);
                    var ms = (double)(end - begin) * 1000.0 / (double)freq;
                    if (ms > 0 && ms < best) best = ms;
                }
                return best > thresholdMs;
            }
            catch { return false; }
        }

        private static bool DebuggerWindow()
        {
            try
            {
                foreach (var cls in DebuggerWindowClasses)
                    if (FindWindowA(cls, null) != IntPtr.Zero) return true;
                foreach (var title in DebuggerWindowTitles)
                    if (FindWindowA(null, title) != IntPtr.Zero) return true;
            }
            catch { }
            return false;
        }

        private static bool DebugToolProcess()
        {
            var names = GetRunningProcessNames();
            foreach (var proc in names)
                foreach (var tool in DebugToolProcesses)
                    if (proc.Contains(tool, StringComparison.OrdinalIgnoreCase)) return true;
            return false;
        }

        private static bool VmToolProcess()
        {
            var names = GetRunningProcessNames();
            foreach (var proc in names)
                foreach (var vm in VmToolProcesses)
                    if (proc.Contains(vm, StringComparison.OrdinalIgnoreCase)) return true;
            return false;
        }

        // ── 模块扫描 ─────────────────────────────────────────────────────────
        private static bool ModuleLoaded(string name)
            => GetModuleHandle(name) != IntPtr.Zero;

        private static bool AnyModuleLoaded(string[] names)
        {
            foreach (var name in names)
                if (ModuleLoaded(name)) return true;
            return false;
        }

        private static bool SandboxModuleLoaded() => AnyModuleLoaded(SandboxModules);
        private static bool VmModuleLoaded() => AnyModuleLoaded(VmModules);
        private static bool InjectionModuleLoaded() => AnyModuleLoaded(InjectionModules);

        // ── API hook 检测 ─────────────────────────────────────────────────────
        private static bool ApiPatched(string moduleName, string funcName)
        {
            try
            {
                var module = GetModuleHandle(moduleName);
                if (module == IntPtr.Zero) return false;
                var address = GetProcAddress(module, funcName);
                if (address == IntPtr.Zero) return false;
                byte first = Marshal.ReadByte(address);
                // JMP / CALL / PUSH 等跳转指令 = 可能被 hook
                if (first == 0xE9 || first == 0xEB || first == 0xE8 || first == 0xEA || first == 0x68)
                    return true;
                if (first == 0xFF)
                {
                    byte second = Marshal.ReadByte(address + 1);
                    if (second == 0x25 || second == 0xE0 || second == 0xE6) return true;
                }
            }
            catch { }
            return false;
        }

        private static int PatchedApiCount()
        {
            (string module, string function)[] apis =
            {
                ("bcrypt.dll", "BCryptEncrypt"),
                ("bcrypt.dll", "BCryptDecrypt"),
                ("bcrypt.dll", "BCryptVerifySignature"),
                ("bcrypt.dll", "BCryptHashData"),
                ("winhttp.dll", "WinHttpSendRequest"),
                ("winhttp.dll", "WinHttpReceiveResponse"),
                ("kernel32.dll", "IsDebuggerPresent"),
                ("kernel32.dll", "GetTickCount"),
                ("ntdll.dll", "NtQueryInformationProcess"),
            };
            int count = 0;
            foreach (var (mod, fn) in apis)
                if (ApiPatched(mod, fn)) count++;
            return count;
        }

        // ── 注册表检测 ───────────────────────────────────────────────────────
        private static bool VmRegistryPresent()
        {
            try
            {
                var keys = new[]
                {
                    @"SOFTWARE\VMware, Inc.\VMware Tools",
                    @"SOFTWARE\Oracle\VirtualBox Guest Additions",
                    @"SYSTEM\CurrentControlSet\Services\VBoxGuest",
                    @"SYSTEM\CurrentControlSet\Services\VBoxMouse",
                    @"SYSTEM\CurrentControlSet\Services\VBoxSF",
                    @"SYSTEM\CurrentControlSet\Services\vmci",
                    @"SYSTEM\CurrentControlSet\Services\vmhgfs",
                    @"SYSTEM\CurrentControlSet\Services\vmmouse",
                    @"SYSTEM\CurrentControlSet\Services\xenevtchn",
                    @"SYSTEM\CurrentControlSet\Services\qemu-ga",
                };
                foreach (var key in keys)
                    if (RegistryKeyExists(@"HKEY_LOCAL_MACHINE\" + key)) return true;
            }
            catch { }
            return false;
        }

        private static bool RegistryKeyExists(string fullPath)
        {
            try
            {
                // 支持 HKEY_LOCAL_MACHINE\... 格式
                if (!fullPath.StartsWith("HKEY_LOCAL_MACHINE\\", StringComparison.OrdinalIgnoreCase))
                    return false;
                var subKey = fullPath.Substring("HKEY_LOCAL_MACHINE\\".Length);
                using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(subKey);
                return key != null;
            }
            catch { return false; }
        }

        private static bool RegValueContains(string subKey, string valueName, string needle)
        {
            try
            {
                using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(subKey);
                if (key == null) return false;
                var value = key.GetValue(valueName) as string ?? "";
                return value.ToLowerInvariant().Contains(needle.ToLowerInvariant());
            }
            catch { return false; }
        }

        private static bool VmBiosStrings()
        {
            try
            {
                const string biosKey = @"HARDWARE\DESCRIPTION\System\BIOS";
                string[] manufacturers = { "vmware", "virtualbox", "innotek", "qemu", "xen", "parallels", "bochs", "amazon ec2", "google compute engine", "openstack", "nutanix" };
                string[] products = { "virtual machine", "vmware virtual platform", "virtualbox", "kvm", "standard pc (qemu)", "xen", "parallels virtual platform" };
                string[] vendors = { "vmware", "innotek", "qemu", "xen", "bochs", "parallels" };
                string[] versions = { "vmware", "vbox", "virtualbox", "qemu", "xen", "bochs", "vmw" };

                foreach (var m in manufacturers)
                    if (RegValueContains(biosKey, "SystemManufacturer", m)) return true;
                foreach (var p in products)
                    if (RegValueContains(biosKey, "SystemProductName", p)) return true;
                foreach (var v in vendors)
                    if (RegValueContains(biosKey, "BIOSVendor", v)) return true;
                foreach (var v in versions)
                    if (RegValueContains(biosKey, "BIOSVersion", v)) return true;
            }
            catch { }
            return false;
        }

        // ── 网卡 MAC OUI 检测 ───────────────────────────────────────────────
        private static bool MacOuiVirtual()
        {
            try
            {
                foreach (var nic in System.Net.NetworkInformation.NetworkInterface.GetAllNetworkInterfaces())
                {
                    var mac = nic.GetPhysicalAddress();
                    var bytes = mac.GetAddressBytes();
                    if (bytes == null || bytes.Length < 3) continue;
                    uint oui = ((uint)bytes[0] << 16) | ((uint)bytes[1] << 8) | bytes[2];
                    switch (oui)
                    {
                        case 0x000569:  // VMware
                        case 0x000C29:
                        case 0x001C14:
                        case 0x005056:
                        case 0x080027:  // VirtualBox
                        case 0x00155D:  // Hyper-V
                        case 0x525400:  // QEMU / KVM
                        case 0x00163E:  // Xen
                        case 0x001C42:  // Parallels
                        case 0x005069:  // Parallels
                        case 0x000FFE:  // 通用虚拟机
                            return true;
                    }
                }
            }
            catch { }
            return false;
        }

        // ── VM 驱动文件痕迹 ──────────────────────────────────────────────────
        private static bool VmDriverFile()
        {
            try
            {
                foreach (var path in VmDriverFiles)
                    if (GetFileAttributesA(path) != 0xFFFFFFFF) return true;
            }
            catch { }
            return false;
        }

        // ── 低配 / 开机过短 ──────────────────────────────────────────────────
        private static bool LowSpecMachine()
        {
            try
            {
                var sys = new SYSTEM_INFO();
                GetSystemInfo(ref sys);
                bool lowCpu = sys.dwNumberOfProcessors <= 1;

                var mem = new MEMORYSTATUSEX();
                mem.dwLength = System.Runtime.InteropServices.Marshal.SizeOf<MEMORYSTATUSEX>();
                bool lowMem = false;
                if (GlobalMemoryStatusEx(ref mem))
                    lowMem = mem.ullTotalPhys < 2UL * 1024 * 1024 * 1024;

                int w = GetSystemMetrics(SM_CXSCREEN);
                int h = GetSystemMetrics(SM_CYSCREEN);
                bool smallScreen = w > 0 && h > 0 && w <= 1024 && h <= 768;

                return lowCpu || lowMem || smallScreen;
            }
            catch { return false; }
        }

        private static bool ShortUptime()
        {
            try { return GetTickCount64() < 300000; }
            catch { return false; }
        }

        // ── WDAG 隔离环境 ────────────────────────────────────────────────────
        private static bool WdagAccount()
        {
            try
            {
                var buf = new byte[128];
                int len = GetEnvironmentVariableA("USERNAME", buf, buf.Length);
                if (len <= 0) return false;
                var name = System.Text.Encoding.ASCII.GetString(buf, 0, len).ToLowerInvariant();
                return name.Contains("wdagutilityaccount");
            }
            catch { return false; }
        }

        // ── CPUID/WMI 检测 ───────────────────────────────────────────────────
        private static bool DetectHypervisorBit()
        {
            try
            {
                using var searcher = new System.Management.ManagementObjectSearcher(
                    "SELECT Manufacturer, Model FROM Win32_ComputerSystem");
                foreach (var item in searcher.Get())
                {
                    var manufacturer = (item["Manufacturer"]?.ToString() ?? "").ToLowerInvariant();
                    var model = (item["Model"]?.ToString() ?? "").ToLowerInvariant();
                    if (manufacturer.Contains("vmware")) return true;
                    if (manufacturer.Contains("virtualbox") || manufacturer.Contains("innotek")) return true;
                    if (manufacturer.Contains("microsoft") && (model.Contains("virtual") || manufacturer.Contains("hyper"))) return true;
                    if (manufacturer.Contains("qemu") || (manufacturer.Contains("red hat") && model.Contains("kvm"))) return true;
                    if (manufacturer.Contains("xen")) return true;
                }
            }
            catch { }
            return false;
        }

        // ── 进程列表获取 ─────────────────────────────────────────────────────
        private static List<string> GetRunningProcessNames()
        {
            var names = new List<string>();
            try
            {
                uint[] processes = new uint[1024];
                uint bytesReturned;
                if (!EnumProcesses(processes, (uint)(processes.Length * sizeof(uint)), out bytesReturned))
                    return names;
                int count = (int)(bytesReturned / (uint)sizeof(uint));
                var hModules = new IntPtr[1024];
                uint needed;
                for (int i = 0; i < count; i++)
                {
                    if (processes[i] == 0) continue;
                    var hProcess = OpenProcess(ProcessAccessFlags.QueryInformation, false, processes[i]);
                    if (hProcess == IntPtr.Zero) continue;
                    if (EnumProcessModules(hProcess, hModules, (uint)(hModules.Length * IntPtr.Size), out needed))
                    {
                        var sb = new StringBuilder(256);
                        if (GetModuleBaseName(hProcess, hModules[0], sb, 256) > 0)
                            names.Add(sb.ToString());
                    }
                    CloseHandle(hProcess);
                }
            }
            catch { }
            return names;
        }

        // ── 代码补丁自检 ─────────────────────────────────────────────────────
        private static readonly object _guardMutex = new();
        private static readonly List<GuardedRegion> _guardedRegions = new();

        private class GuardedRegion
        {
            public IntPtr Address;
            public int Length;
            public ulong Hash;
        }

        private static ulong Fnv1a(byte[] data)
        {
            ulong hash = 14695981039346656037UL;
            foreach (var b in data) { hash ^= b; hash *= 1099511628211UL; }
            return hash;
        }

        private static bool ReadRange(IntPtr address, int len, out byte[] buffer)
        {
            buffer = System.Array.Empty<byte>();
            try
            {
                var result = new byte[len];
                var p = address;
                int remain = len;
                int offset = 0;
                while (remain > 0)
                {
                    var mbi = new MEMORY_BASIC_INFORMATION();
                    if (VirtualQuery(p, ref mbi, System.Runtime.InteropServices.Marshal.SizeOf<MEMORY_BASIC_INFORMATION>()) == IntPtr.Zero)
                        return false;
                    if (mbi.State != MEM_COMMIT) return false;
                    if ((mbi.Protect & PAGE_NOACCESS) != 0 || (mbi.Protect & PAGE_GUARD) != 0) return false;
                    long regionEnd = mbi.BaseAddress.ToInt64() + mbi.RegionSize.ToInt64();
                    long chunk = regionEnd - p.ToInt64();
                    if (chunk <= 0) return false;
                    if (chunk > remain) chunk = remain;
                    System.Runtime.InteropServices.Marshal.Copy(p, result, offset, (int)chunk);
                    p = new IntPtr(p.ToInt64() + chunk);
                    offset += (int)chunk;
                    remain -= (int)chunk;
                }
                buffer = result;
                return true;
            }
            catch { return false; }
        }

        /// <summary>登记一段核心代码做补丁检测</summary>
        public static bool GuardCode(IntPtr address, int len)
        {
            if (address == IntPtr.Zero || len <= 0) return false;
            if (!ReadRange(address, len, out var buffer)) return false;
            var hash = Fnv1a(buffer);
            lock (_guardMutex)
            {
                foreach (var r in _guardedRegions)
                    if (r.Address == address && r.Length == len) { r.Hash = hash; return true; }
                _guardedRegions.Add(new GuardedRegion { Address = address, Length = len, Hash = hash });
            }
            return true;
        }

        /// <summary>校验已登记代码段是否被改写</summary>
        public static bool VerifyGuardedCode()
        {
            lock (_guardMutex)
            {
                foreach (var r in _guardedRegions)
                {
                    if (!ReadRange(r.Address, r.Length, out var buffer)) return false;
                    if (Fnv1a(buffer) != r.Hash) return false;
                }
            }
            return true;
        }

        // ── 后台巡检 ─────────────────────────────────────────────────────────
        private static Thread? _watchdogThread;
        private static volatile bool _watchdogRunning;

        /// <summary>启动后台巡检</summary>
        /// <param name="intervalMs">巡检间隔（&lt; 1000 按 1000）</param>
        /// <param name="cb">命中回调；null 时走 Enforce() 策略</param>
        public static void StartWatchdog(int intervalMs = 5000, Action<Report>? cb = null)
        {
            if (intervalMs < 1000) intervalMs = 1000;
            StopWatchdog();
            _watchdogRunning = true;
            _watchdogThread = new Thread(() =>
            {
                while (_watchdogRunning)
                {
                    for (int w = 0; w < intervalMs && _watchdogRunning; w += 200)
                        Thread.Sleep(200);
                    if (!_watchdogRunning) break;
                    var report = Scan();
                    if (report.Clean) continue;
                    if (cb != null) cb(report);
                    else if (!Enforce(report)) break;
                }
            }) { IsBackground = true };
            _watchdogThread.Start();
        }

        /// <summary>停止后台巡检</summary>
        public static void StopWatchdog()
        {
            _watchdogRunning = false;
            var t = _watchdogThread;
            if (t != null && t != Thread.CurrentThread && t.IsAlive) t.Join(3000);
        }
    }
}
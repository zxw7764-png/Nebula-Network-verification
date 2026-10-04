// ============================================================================
// Nebula C# SDK · 壳标记（VMProtect / Themida·WinLicense / 自定义壳）
// ----------------------------------------------------------------------------
// 作用：在核心函数体内插入壳的标记，让加壳工具把这段代码虚拟化 / 变异。
//   · ShellEnable=false（默认）：所有标记为空 → 零开销、零影响。
//   · ShellEnable=true          ：运行时动态探测已安装的壳 SDK。
//   · 未装壳但开了开关           ：标记仍为空，不会报错。
//
// ★ 标记只是「告诉壳保护这里」；真正加壳仍需用 VMProtect / Themida 对**编译产物**
//   （.exe / .dll）做处理。本文件不会替你加壳。
//
// 强度：ULTRA > VM(虚拟化) > MUTATE(变异，性能好) > SCOPE(只划范围)
//   建议：ULTRA 只标 1~2 个最关键函数（如卡密校验）；高频函数用 MUTATE。
//
// 实现方式：
//   运行时 P/Invoke 调用 VMProtectSDK32/64.dll。
//   如果未加壳，DLL 不存在，标记退化为空操作（try-catch 兜底）。
// ============================================================================
using System;
using System.Runtime.InteropServices;

namespace Nebula.Sdk
{
    /// <summary>
    /// 壳保护标记管理器。
    /// 使用方式：
    ///   Shell.BeginVM();       // 虚拟化开始
    ///   // ... 关键代码 ...
    ///   Shell.End();           // 结束
    ///
    ///   Shell.BeginMutate();   // 变异开始（性能好，适合高频函数）
    ///   // ... 网络收发 ...
    ///   Shell.End();
    ///
    ///   Shell.BeginUltra();    // 最高强度（只标 1~2 处）
    ///   // ... 卡密校验 ...
    ///   Shell.End();
    /// </summary>
    public static class Shell
    {
        // ── 壳 SDK 探测 ─────────────────────────────────────────────────────
        private static readonly bool _vmpAvailable;
        private static readonly bool _themidaAvailable;
        private static Exception? _probeError;

        static Shell()
        {
            // 探测 VMProtect SDK DLL
            try
            {
                // 尝试加载 VMProtectSDK32.dll 或 VMProtectSDK64.dll
                string dllName = IntPtr.Size == 8 ? "VMProtectSDK64.dll" : "VMProtectSDK32.dll";
                var hModule = LoadLibrary(dllName);
                if (hModule != IntPtr.Zero)
                {
                    _vmpAvailable = GetProcAddress(hModule, "VMProtectBegin") != IntPtr.Zero;
                    if (!_vmpAvailable)
                        FreeLibrary(hModule);
                }
            }
            catch (Exception ex) { _probeError = ex; /* 静默失败：未安装 VMProtect */ }

            // 探测 Themida / WinLicense SDK DLL
            try
            {
                var hModule = LoadLibrary("SecureEngineSDK64.dll");
                if (hModule == IntPtr.Zero)
                    hModule = LoadLibrary("SecureEngineSDK32.dll");
                if (hModule != IntPtr.Zero)
                {
                    _themidaAvailable = GetProcAddress(hModule, "SE_VMP_BEGIN") != IntPtr.Zero ||
                                       GetProcAddress(hModule, "VM_START") != IntPtr.Zero;
                    if (!_themidaAvailable)
                        FreeLibrary(hModule);
                }
            }
            catch (Exception ex) { _probeError = ex; /* 静默失败：未安装 Themida */ }
        }

        /// <summary>壳保护是否已启用（配置开关）</summary>
        public static bool Enabled => SdkConfig.ShellEnable;

        /// <summary>VMProtect SDK 是否已安装</summary>
        public static bool IsVmpAvailable => _vmpAvailable;

        /// <summary>Themida / WinLicense SDK 是否已安装</summary>
        public static bool IsThemidaAvailable => _themidaAvailable;

        /// <summary>当前是否有任何壳 SDK 可用</summary>
        public static bool AnyShellAvailable => _vmpAvailable || _themidaAvailable;

        /// <summary>探测过程中的错误信息（调试用）</summary>
        public static string? ProbeError => _probeError?.Message;

        // ── VMProtect P/Invoke ──────────────────────────────────────────────
        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
        private static extern IntPtr LoadLibrary(string lpFileName);

        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Ansi)]
        private static extern IntPtr GetProcAddress(IntPtr hModule, string lpProcName);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool FreeLibrary(IntPtr hModule);

        // VMProtect SDK 函数声明（仅在 DLL 可用时通过委托调用）
        private delegate void VMPBeginDelegate(string marker);
        private delegate void VMPEndDelegate();
        private delegate bool VMPIsProtectedDelegate();
        private delegate bool VMPIsDebuggerPresentDelegate(bool checkKernelMode);
        private delegate bool VMPIsVMPresentDelegate();
        private delegate bool VMPIsValidCRCDelegate();

        private static VMPBeginDelegate? _vmpBegin;
        private static VMPBeginDelegate? _vmpBeginVirt;
        private static VMPBeginDelegate? _vmpBeginMutate;
        private static VMPBeginDelegate? _vmpBeginUltra;
        private static VMPEndDelegate? _vmpEnd;
        private static VMPIsProtectedDelegate? _vmpIsProtected;
        private static VMPIsDebuggerPresentDelegate? _vmpIsDebuggerPresent;
        private static VMPIsVMPresentDelegate? _vmpIsVMPresent;
        private static VMPIsValidCRCDelegate? _vmpIsValidCRC;

        private static void EnsureVmpDelegates()
        {
            if (_vmpBegin != null) return;

            string dllName = IntPtr.Size == 8 ? "VMProtectSDK64.dll" : "VMProtectSDK32.dll";
            var hModule = LoadLibrary(dllName);
            if (hModule == IntPtr.Zero) return;

            _vmpBegin         = GetDelegate<VMPBeginDelegate>(hModule, "VMProtectBegin");
            _vmpBeginVirt      = GetDelegate<VMPBeginDelegate>(hModule, "VMProtectBeginVirtualization");
            _vmpBeginMutate    = GetDelegate<VMPBeginDelegate>(hModule, "VMProtectBeginMutation");
            _vmpBeginUltra     = GetDelegate<VMPBeginDelegate>(hModule, "VMProtectBeginUltra");
            _vmpEnd            = GetDelegate<VMPEndDelegate>(hModule, "VMProtectEnd");
            _vmpIsProtected    = GetDelegate<VMPIsProtectedDelegate>(hModule, "VMProtectIsProtected");
            _vmpIsDebuggerPresent = GetDelegate<VMPIsDebuggerPresentDelegate>(hModule, "VMProtectIsDebuggerPresent");
            _vmpIsVMPresent    = GetDelegate<VMPIsVMPresentDelegate>(hModule, "VMProtectIsVirtualMachinePresent");
            _vmpIsValidCRC     = GetDelegate<VMPIsValidCRCDelegate>(hModule, "VMProtectIsValidImageCRC");
        }

        private static T? GetDelegate<T>(IntPtr hModule, string procName) where T : Delegate
        {
            var addr = GetProcAddress(hModule, procName);
            if (addr == IntPtr.Zero) return null;
            return Marshal.GetDelegateForFunctionPointer<T>(addr);
        }

        // ── 公开标记接口 ─────────────────────────────────────────────────────

        /// <summary>
        /// 开始虚拟化保护区域（VM 级别）。
        /// 适合：授权判定、密钥派生、关键常量比较等低频关键函数。
        /// </summary>
        /// <param name="marker">标记名（用于加壳工具识别，留空自动生成）</param>
        public static void BeginVM(string marker = "")
        {
            if (!Enabled || !_vmpAvailable) return;
            try
            {
                EnsureVmpDelegates();
                _vmpBeginVirt?.Invoke(marker.Length > 0 ? marker : $"vm_{Environment.TickCount}");
            }
            catch { /* 壳未加载时静默忽略 */ }
        }

        /// <summary>
        /// 开始变异保护区域（MUTATE 级别）。
        /// 适合：高频函数（网络收发、状态机），性能好。
        /// </summary>
        public static void BeginMutate(string marker = "")
        {
            if (!Enabled || !_vmpAvailable) return;
            try
            {
                EnsureVmpDelegates();
                _vmpBeginMutate?.Invoke(marker.Length > 0 ? marker : $"mut_{Environment.TickCount}");
            }
            catch { }
        }

        /// <summary>
        /// 开始最高强度保护区域（ULTRA 级别）。
        /// 全程序只标 1~2 处，例如「卡密校验总入口」。
        /// </summary>
        public static void BeginUltra(string marker = "")
        {
            if (!Enabled || !_vmpAvailable) return;
            try
            {
                EnsureVmpDelegates();
                _vmpBeginUltra?.Invoke(marker.Length > 0 ? marker : $"ultra_{Environment.TickCount}");
            }
            catch { }
        }

        /// <summary>
        /// 开始范围标记（SCOPE 级别，只划范围，适合 Themida 按区域处理）。
        /// </summary>
        public static void BeginScope(string marker = "")
        {
            if (!Enabled || !_vmpAvailable) return;
            try
            {
                EnsureVmpDelegates();
                _vmpBegin?.Invoke(marker.Length > 0 ? marker : $"scope_{Environment.TickCount}");
            }
            catch { }
        }

        /// <summary>
        /// 结束当前保护区域。必须与 Begin* 成对使用。
        /// </summary>
        public static void End()
        {
            if (!Enabled || !_vmpAvailable) return;
            try
            {
                EnsureVmpDelegates();
                _vmpEnd?.Invoke();
            }
            catch { }
        }

        // ── VMProtect 工具函数 ───────────────────────────────────────────────

        /// <summary>当前进程是否已被 VMProtect 加壳</summary>
        public static bool IsProtected()
        {
            if (!_vmpAvailable) return false;
            try { EnsureVmpDelegates(); return _vmpIsProtected?.Invoke() ?? false; }
            catch { return false; }
        }

        /// <summary>VMProtect 内置的调试器检测（比 SDK 自己的更难被绕过）</summary>
        public static bool IsDebuggerPresent(bool checkKernelMode = false)
        {
            if (!_vmpAvailable) return false;
            try { EnsureVmpDelegates(); return _vmpIsDebuggerPresent?.Invoke(checkKernelMode) ?? false; }
            catch { return false; }
        }

        /// <summary>VMProtect 内置的虚拟机检测</summary>
        public static bool IsVirtualMachinePresent()
        {
            if (!_vmpAvailable) return false;
            try { EnsureVmpDelegates(); return _vmpIsVMPresent?.Invoke() ?? false; }
            catch { return false; }
        }

        /// <summary>VMProtect 内置的镜像 CRC 校验（检测程序是否被补丁）</summary>
        public static bool IsValidImageCRC()
        {
            if (!_vmpAvailable) return true; // 未加壳时默认通过
            try { EnsureVmpDelegates(); return _vmpIsValidCRC?.Invoke() ?? true; }
            catch { return true; }
        }

        // ── VMProtect 授权系统 ───────────────────────────────────────────────
        // VMProtect 自带的序列号 / 激活码管理（可选使用，与 Nebula 的卡密系统独立）

        /// <summary>设置 VMProtect 序列号</summary>
        public static int SetSerialNumber(string serial)
        {
            if (!_vmpAvailable) return 0;
            try
            {
                EnsureVmpDelegates();
                var hModule = LoadLibrary(IntPtr.Size == 8 ? "VMProtectSDK64.dll" : "VMProtectSDK32.dll");
                var addr = GetProcAddress(hModule, "VMProtectSetSerialNumber");
                if (addr == IntPtr.Zero) return -1;
                var fn = Marshal.GetDelegateForFunctionPointer<SetSerialDelegate>(addr);
                return fn(serial);
            }
            catch { return -1; }
        }

        /// <summary>获取 VMProtect 序列号状态</summary>
        public static int GetSerialNumberState()
        {
            if (!_vmpAvailable) return 0;
            try
            {
                EnsureVmpDelegates();
                var hModule = LoadLibrary(IntPtr.Size == 8 ? "VMProtectSDK64.dll" : "VMProtectSDK32.dll");
                var addr = GetProcAddress(hModule, "VMProtectGetSerialNumberState");
                if (addr == IntPtr.Zero) return 0;
                var fn = Marshal.GetDelegateForFunctionPointer<GetSerialStateDelegate>(addr);
                return fn();
            }
            catch { return 0; }
        }

        /// <summary>获取当前硬件 ID（VMProtect HWID）</summary>
        public static string GetCurrentHWID()
        {
            if (!_vmpAvailable) return "";
            try
            {
                EnsureVmpDelegates();
                var hModule = LoadLibrary(IntPtr.Size == 8 ? "VMProtectSDK64.dll" : "VMProtectSDK32.dll");
                var addr = GetProcAddress(hModule, "VMProtectGetCurrentHWID");
                if (addr == IntPtr.Zero) return "";
                var fn = Marshal.GetDelegateForFunctionPointer<GetHWIDDelegate>(addr);
                var buf = new char[256];
                int len = fn(new string(buf), 256);
                return len > 0 ? new string(buf, 0, len) : "";
            }
            catch { return ""; }
        }

        [UnmanagedFunctionPointer(CallingConvention.StdCall)]
        private delegate int SetSerialDelegate(string serial);
        [UnmanagedFunctionPointer(CallingConvention.StdCall)]
        private delegate int GetSerialStateDelegate();
        [UnmanagedFunctionPointer(CallingConvention.StdCall)]
        private delegate int GetHWIDDelegate(string hwid, int size);

        // ── VMProtect 序列号状态标志 ────────────────────────────────────────

        /// <summary>VMProtect 序列号状态标志</summary>
        [Flags]
        public enum SerialStateFlags : int
        {
            Success             = 0,
            Corrupted           = 0x00000001,
            Invalid             = 0x00000002,
            Blacklisted         = 0x00000004,
            DateExpired         = 0x00000008,
            RunningTimeOver     = 0x00000010,
            BadHWID             = 0x00000020,
            MaxBuildExpired     = 0x00000040,
        }

        /// <summary>判断序列号状态是否成功</summary>
        public static bool IsSerialValid(int state)
            => (state & (int)SerialStateFlags.Success) == 0 && state == 0;

        /// <summary>将序列号状态转为中文说明</summary>
        public static string SerialStateText(int state)
        {
            if (state == 0) return "序列号有效";
            var parts = new System.Collections.Generic.List<string>();
            if ((state & 0x01) != 0) parts.Add("序列号已损坏");
            if ((state & 0x02) != 0) parts.Add("序列号无效");
            if ((state & 0x04) != 0) parts.Add("序列号已被拉黑");
            if ((state & 0x08) != 0) parts.Add("序列号已过期");
            if ((state & 0x10) != 0) parts.Add("运行时间已超限");
            if ((state & 0x20) != 0) parts.Add("硬件ID不匹配");
            if ((state & 0x40) != 0) parts.Add("版本超过最大构建日期");
            return string.Join("、", parts);
        }
    }
}

#pragma once
// ============================================================================
// Nebula SDK · 运行时防护（反调试 + 反虚拟机/沙箱 + 代码补丁自检）
// ----------------------------------------------------------------------------
// 开关：NEBULA_PROTECT_LEVEL 0(默认，全部退化为桩) / 1 基础 / 2 标准 / 3 严格
//       命中后的处置：NEBULA_PROTECT_ACTION 0 记录 / 1 回调(默认) / 2 降级 / 3 弹窗退出
//
// 诚实的边界（务必理解）：
//   · 客户端加固只能抬高逆向/破解的**成本**，不能保证绝对安全；
//     真正的判定与封禁永远在服务端（app_key / 会话盐 / 卡密校验 / 风控评分）。
//   · 检测项存在误报可能（尤其云电脑 / VPS / 开了 Hyper-V·VBS 的真实用户），
//     所以默认策略是「回调上报」而不是直接退出程序。
//   · 严格策略（strict policy）会把"疑似环境"也按 action 处置，会误伤 VM 用户，
//     默认关闭；需要拦"隐身虚拟机"时再打开。
//
// 线程安全：所有设置项与最近报告都由互斥量保护，可从任意线程调用。
// ============================================================================

#include "../config.hpp"
#include "../core/text.hpp"

#if defined(_M_IX86) || defined(_M_X64)
#  include <intrin.h>   // __cpuid
#endif

namespace nebula {
namespace protect {

// ===========================================================================
// 等级 / 标记 / 报告
// ===========================================================================
enum class Level : int { Off = 0, Basic = 1, Standard = 2, Strict = 3 };

/** 命中标记（位或；每条线索一个 bit，便于接入方自行加权判断） */
enum class Flag : unsigned {
    None             = 0u,
    DebuggerApi      = 1u << 0,   ///< IsDebuggerPresent / CheckRemoteDebuggerPresent
    DebuggerPeb      = 1u << 1,   ///< PEB.BeingDebugged
    DebuggerNt       = 1u << 2,   ///< NtQueryInformationProcess（调试端口/对象/标志）
    DebuggerHardwareBp = 1u << 3, ///< 线程硬件断点 Dr0-Dr7
    DebuggerWindow   = 1u << 4,   ///< 调试器窗口
    DebuggerTiming   = 1u << 5,   ///< 关键代码时序异常
    DebuggerHeap     = 1u << 6,   ///< 调试堆标志（NtGlobalFlag / HeapFlags）
    DebuggerTool     = 1u << 7,   ///< 调试/逆向工具进程
    ApiHooked        = 1u << 8,   ///< 关键 API 被 inline hook / 注入
    CodeTamper       = 1u << 9,   ///< 受保护代码段被改写
    VmCpuid          = 1u << 10,  ///< CPUID hypervisor 位
    VmRegistry       = 1u << 11,  ///< 注册表虚拟机痕迹
    VmBios           = 1u << 12,  ///< BIOS / 主板厂商字段
    VmMac            = 1u << 13,  ///< 网卡 MAC OUI
    VmProcess        = 1u << 14,  ///< 虚拟机增强工具进程
    VmModule         = 1u << 15,  ///< 虚拟机 / 沙箱模块
    VmDriverFile     = 1u << 16,  ///< 驱动文件痕迹
    Sandbox          = 1u << 17,  ///< 沙箱环境
    WeakEnvironment  = 1u << 18,  ///< 弱环境线索（低配 / 开机时间过短）
};

constexpr Flag operator|(Flag a, Flag b) {
    return (Flag)((unsigned)a | (unsigned)b);
}
constexpr Flag operator&(Flag a, Flag b) {
    return (Flag)((unsigned)a & (unsigned)b);
}
constexpr bool hasFlag(Flag value, Flag mask) {
    return ((unsigned)value & (unsigned)mask) != 0u;
}

/** 一次检测的结果 */
struct Report {
    bool clean       = true;         ///< 一切正常
    bool debugged    = false;        ///< 判定：被调试
    bool virtualized = false;        ///< 判定：运行于虚拟机
    bool sandboxed   = false;        ///< 判定：运行于沙箱
    bool hooked      = false;        ///< 判定：关键接口被劫持或代码被改写
    Level level      = Level::Off;   ///< 本次检测使用的等级
    int  score       = 0;            ///< 累计风险分（仅供接入方参考 / 上报）
    int  strong_hits = 0;            ///< 调试铁证次数（API / PEB / NT 系列）
    int  weak_hits   = 0;            ///< 弱线索累计分（硬件断点 / 时序 / 窗口 / 工具进程）
    int  vm_score    = 0;            ///< 虚拟机线索累计分（>= 阈值判为虚拟机）
    Flag flags       = Flag::None;   ///< 命中的全部标记
    int64_t at       = 0;            ///< 检测时间（Unix 秒）
    std::vector<std::string> reasons;///< 命中项中文说明（可直接打日志 / 上报）

    NEBULA_MUST_CHECK bool has(Flag f) const { return hasFlag(flags, f); }

    /** 一句话摘要 */
    NEBULA_MUST_CHECK std::string summary() const {
        if (clean) return "环境正常（风险分 " + std::to_string(score) + "）";
        std::string s = "环境异常：";
        const char* parts[4] = { nullptr, nullptr, nullptr, nullptr };
        if (debugged)    parts[0] = "检测到调试器";
        if (hooked)      parts[1] = "关键接口被劫持";
        if (virtualized) parts[2] = "运行于虚拟机";
        if (sandboxed)   parts[3] = "运行于沙箱";
        bool first = true;
        for (int i = 0; i < 4; ++i) {
            if (!parts[i]) continue;
            if (!first) s += " / ";
            s += parts[i];
            first = false;
        }
        return s + "（风险分 " + std::to_string(score) + "）";
    }

    /** 命中明细：a | b | c */
    NEBULA_MUST_CHECK std::string detail() const {
        std::string s;
        for (size_t i = 0; i < reasons.size(); ++i) {
            if (i) s += " | ";
            s += reasons[i];
        }
        return s;
    }
};

// ===========================================================================
// 设置（线程安全）
// ===========================================================================
namespace detail {

struct Settings {
    bool  enabled        = (NEBULA_PROTECT_LEVEL >= 1);
    Level level          = (Level)NEBULA_PROTECT_LEVEL;
    int   action         = NEBULA_PROTECT_ACTION;
    bool  strict_policy  = false;      ///< false=宽松（默认）true=疑似环境也拦截
    bool  degraded       = false;      ///< action>=2 命中后置位
    std::function<void(const Report&)> callback;
    Report last;
};

inline std::mutex& settingsMutex() { static std::mutex m; return m; }
inline Settings& settings() { static Settings s; return s; }

} // namespace detail

inline bool enabled() {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    return detail::settings().enabled;
}
inline void setEnabled(bool on) {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    detail::settings().enabled = on && (NEBULA_PROTECT_LEVEL >= 1);
}
inline Level level() {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    return detail::settings().level;
}
/** 设置检测等级；不会超过编译期上限 NEBULA_PROTECT_LEVEL */
inline void setLevel(int lv) {
    if (lv < 0) lv = 0;
    if (lv > (int)NEBULA_PROTECT_LEVEL) lv = (int)NEBULA_PROTECT_LEVEL;
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    detail::settings().level = (Level)lv;
}
inline int action() {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    return detail::settings().action;
}
inline void setAction(int act) {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    detail::settings().action = act;
}
/** 疑似环境（hook / VM / 沙箱）是否也按 action 拦截；默认 false（宽松） */
inline bool strictPolicy() {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    return detail::settings().strict_policy;
}
inline void setSuspiciousPolicy(bool strict) {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    detail::settings().strict_policy = strict;
}
inline bool degraded() {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    return detail::settings().degraded;
}
inline void resetDegraded() {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    detail::settings().degraded = false;
}
/** 命中后的上报回调（推荐在这里把结果发到自己的服务端） */
inline void setCallback(std::function<void(const Report&)> cb) {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    detail::settings().callback = std::move(cb);
}
/** 最近一次报告（线程安全拷贝） */
NEBULA_MUST_CHECK inline Report lastReport() {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    return detail::settings().last;
}
inline void setLastReport(const Report& r) {
    std::lock_guard<std::mutex> lock(detail::settingsMutex());
    detail::settings().last = r;
}
namespace detail {
inline std::function<void(const Report&)> callbackCopy() {
    std::lock_guard<std::mutex> lock(settingsMutex());
    return settings().callback;
}
} // namespace detail

// ===========================================================================
// 检测项实现（编译期关闭时整体不参与编译）
// ===========================================================================
#if NEBULA_PROTECT_LEVEL >= 1
namespace detail {

// ---------------- 安全读内存（VirtualQuery 校验，不用 SEH） ----------------
template <typename T>
inline bool safeRead(const void* addr, T& out) {
    if (!addr) return false;
    MEMORY_BASIC_INFORMATION mbi{};
    if (::VirtualQuery(addr, &mbi, sizeof(mbi)) == 0) return false;
    if (mbi.State != MEM_COMMIT) return false;
    if (mbi.Protect & (PAGE_NOACCESS | PAGE_GUARD)) return false;
    const BYTE* regionEnd = (const BYTE*)mbi.BaseAddress + mbi.RegionSize;
    const BYTE* p = (const BYTE*)addr;
    if (p + sizeof(T) > regionEnd) return false;
    ::memcpy(&out, addr, sizeof(T));
    return true;
}

// ---------------- PEB / 堆标志 ----------------
inline BYTE* pebBase() {
#if defined(_M_X64)
    return (BYTE*)__readgsqword(0x60);
#elif defined(_M_IX86)
    return (BYTE*)__readfsdword(0x30);
#else
    return nullptr;
#endif
}

inline bool pebBeingDebugged() {
    BYTE* peb = pebBase();
    BYTE value = 0;
    return peb && safeRead(peb + 2, value) && value != 0;
}

inline uint32_t pebNtGlobalFlag() {
    BYTE* peb = pebBase();
    if (!peb) return 0;
    uint32_t value = 0;
#if defined(_M_X64)
    safeRead(peb + 0xBC, value);
#else
    safeRead(peb + 0x68, value);
#endif
    return value;
}

inline bool heapDebugFlags() {
    BYTE* peb = pebBase();
    if (!peb) return false;
    void* heap = nullptr;
#if defined(_M_X64)
    if (!safeRead(peb + 0x30, heap)) return false;      // PEB.ProcessHeap
#else
    if (!safeRead(peb + 0x18, heap)) return false;
#endif
    if (!heap) return false;
    const uintptr_t addr = (uintptr_t)heap;
    if (addr < 0x10000u || addr > (uintptr_t)0x00007FFFFFFFFFFFull) return false;   // 用户态地址护栏

    uint32_t flags = 0, force = 0;
#if defined(_M_X64)
    // _HEAP.Flags / ForceFlags 的偏移随 Windows 版本变化，这里是**实测**值：
    //   Win8/10/11 x64 → Flags = 0x70，ForceFlags = 0x74
    // （旧版 SDK 用的 0x14/0x18 是更早的引用，在 Win10 x64 上读到的是
    //   SegmentFlags 与 SegmentListEntry.Flink 低半部，恒为非零 → 必然误报）
    if (!safeRead((BYTE*)heap + 0x70, flags)) return false;
    if (!safeRead((BYTE*)heap + 0x74, force)) return false;
#else
    //   Win8/10/11 x86 → Flags = 0x40，ForceFlags = 0x44
    if (!safeRead((BYTE*)heap + 0x40, flags)) return false;
    if (!safeRead((BYTE*)heap + 0x44, force)) return false;
#endif

    // 合法性护栏：任何正常堆都带 HEAP_GROWABLE(0x2)。连这一位都没有，说明该偏移在
    // 当前版本上不是 _HEAP.Flags —— 直接放弃判定，绝不把布局差异误报成"被调试"。
    if ((flags & 0x2u) == 0) return false;

    // 正常堆：ForceFlags = 0 且未开启调试校验位
    //   0x20 HEAP_TAIL_CHECKING_ENABLED | 0x40 HEAP_FREE_CHECKING_ENABLED
    //   0x20000000 HEAP_VALIDATE_ALL_ENABLED | 0x40000000 HEAP_VALIDATE_PARAMETERS_ENABLED
    return (force != 0) || ((flags & 0x70u) != 0) || ((flags & 0x60000000u) != 0);
}

// ---------------- NtQueryInformationProcess（动态解析，不进导入表） ----------------
using NtQipFn = LONG(NTAPI*)(HANDLE, ULONG, PVOID, ULONG, PULONG);

inline NtQipFn ntQueryInformationProcess() {
    static NtQipFn fn = (NtQipFn)(void*)::GetProcAddress(::GetModuleHandleA("ntdll.dll"),
                                                         "NtQueryInformationProcess");
    return fn;
}

inline bool ntDebugPort() {
    NtQipFn fn = ntQueryInformationProcess();
    if (!fn) return false;
    HANDLE port = nullptr;
    ULONG ret = 0;
    return fn(::GetCurrentProcess(), 7 /*ProcessDebugPort*/, &port, sizeof(port), &ret) >= 0
        && port != nullptr;
}

inline bool ntDebugObject() {
    NtQipFn fn = ntQueryInformationProcess();
    if (!fn) return false;
    HANDLE object = nullptr;
    ULONG ret = 0;
    return fn(::GetCurrentProcess(), 0x1E /*ProcessDebugObjectHandle*/, &object, sizeof(object), &ret) >= 0
        && object != nullptr;
}

inline bool ntDebugFlags() {
    NtQipFn fn = ntQueryInformationProcess();
    if (!fn) return false;
    ULONG flags = 1, ret = 0;
    return fn(::GetCurrentProcess(), 0x1F /*ProcessDebugFlags*/, &flags, sizeof(flags), &ret) >= 0
        && flags == 0;   // 1 = 未被调试；0 = 正在被调试
}

// ---------------- 硬件断点（扫描本进程全部线程的 DR0-Dr7） ----------------
inline bool hardwareBreakpoints() {
    HANDLE snapshot = ::CreateToolhelp32Snapshot(TH32CS_SNAPTHREAD, 0);
    if (snapshot == INVALID_HANDLE_VALUE) return false;
    const DWORD pid = ::GetCurrentProcessId();
    THREADENTRY32 entry{};
    entry.dwSize = sizeof(entry);
    bool found = false;
    if (::Thread32First(snapshot, &entry)) {
        do {
            if (entry.th32OwnerProcessID != pid) continue;
            HANDLE thread = ::OpenThread(THREAD_GET_CONTEXT | THREAD_QUERY_INFORMATION,
                                         FALSE, entry.th32ThreadID);
            if (!thread) continue;
            CONTEXT ctx{};
            ctx.ContextFlags = CONTEXT_DEBUG_REGISTERS;
            if (::GetThreadContext(thread, &ctx)) {
                if (ctx.Dr0 || ctx.Dr1 || ctx.Dr2 || ctx.Dr3 || (ctx.Dr7 & 0xFFu)) found = true;
            }
            ::CloseHandle(thread);
        } while (!found && ::Thread32Next(snapshot, &entry));
    }
    ::CloseHandle(snapshot);
    return found;
}

// ---------------- 时序异常（取 3 次里最快的一次，降低误报） ----------------
inline bool timingAnomaly(double thresholdMs) {
    LARGE_INTEGER freq{};
    if (!::QueryPerformanceFrequency(&freq) || freq.QuadPart == 0) return false;
    double best = 1e18;
    for (int round = 0; round < 3; ++round) {
        LARGE_INTEGER begin{}, end{};
        ::QueryPerformanceCounter(&begin);
        volatile uint64_t x = 1;
        for (uint32_t i = 1; i < 150000u; ++i) x = x * 2654435761u + i;
        NEBULA_UNUSED(x);
        ::QueryPerformanceCounter(&end);
        const double ms = (double)(end.QuadPart - begin.QuadPart) * 1000.0 / (double)freq.QuadPart;
        if (ms > 0 && ms < best) best = ms;
    }
    return best > thresholdMs;
}

// ---------------- 调试器窗口（只查窗口类名与专有标题，避免误伤正常程序） ----------------
inline bool debuggerWindow() {
    static const char* kClasses[] = {
        "OLLYDBG", "ID", "WinDbgFrameClass", "ProcessHacker", "Cheat Engine", "GBDY6.80",
    };
    for (size_t i = 0; i < sizeof(kClasses) / sizeof(kClasses[0]); ++i) {
        if (::FindWindowA(kClasses[i], nullptr)) return true;
    }
    static const char* kTitles[] = {
        "x64dbg", "x32dbg", "OllyDbg", "Immunity Debugger",
        "Cheat Engine", "Process Hacker", "System Informer",
    };
    for (size_t i = 0; i < sizeof(kTitles) / sizeof(kTitles[0]); ++i) {
        if (::FindWindowA(nullptr, kTitles[i])) return true;
    }
    return false;
}

// ---------------- 进程扫描 ----------------
// 固定使用 W 版 API：定义 UNICODE 后 tlhelp32.h 会把 PROCESSENTRY32/Process32First
// 宏重定向到 W 版（并不存在 PROCESSENTRY32A），直接写 W 版两种工程都能编译。
// 工具进程名都是 ASCII，宽字符统一压成 ASCII 小写后匹配。
inline bool processRunning(const char* const* names, size_t count) {
    HANDLE snapshot = ::CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    if (snapshot == INVALID_HANDLE_VALUE) return false;
    PROCESSENTRY32W entry{};
    entry.dwSize = sizeof(entry);
    bool found = false;
    if (::Process32FirstW(snapshot, &entry)) {
        do {
            char lower[64] = {};
            const wchar_t* src = entry.szExeFile;
            size_t i = 0;
            for (; src[i] && i + 1 < sizeof(lower); ++i) {
                const wchar_t c = src[i];
                lower[i] = lowerAscii(c < 128 ? (char)c : '?');
            }
            for (size_t k = 0; k < count; ++k) {
                if (::strstr(lower, names[k])) { found = true; break; }
            }
        } while (!found && ::Process32NextW(snapshot, &entry));
    }
    ::CloseHandle(snapshot);
    return found;
}

inline bool debugToolProcess() {
    // 只收"会挂到本进程上"的调试器 / 逆向 / 注入工具。
    // **刻意排除**纯抓包工具（fiddler / charles / wireshark / httpdebugger / tcpview）
    // 与 procmon / procexp：它们在开发者机器上极其常见，且看到的是 TLS 密文，对破解
    // 没有帮助。把它们算成"被调试"会让正常用户凭空多 60 分风险（阈值 90），属误伤。
    static const char* kTools[] = {
        "x64dbg", "x32dbg", "ollydbg", "windbg", "immunitydebugger", "cheatengine",
        "ida64", "idaq64", "idaw", "ida.exe",
        "scylla", "lordpe", "pebrowse", "importrec", "apimonitor", "rohitab",
        "frida", "dllinject", "extremeinjector", "winject",
    };
    return processRunning(kTools, sizeof(kTools) / sizeof(kTools[0]));
}

inline bool vmToolProcess() {
    static const char* kTools[] = {
        "vboxservice", "vboxtray", "vboxcontrol", "vmwaretray", "vmwareuser",
        "vmtoolsd", "xenservice", "xenagent", "qemu-ga",
        "joeboxserver", "joeboxcontrol", "prl_tools", "prl_cc", "sandboxie",
    };
    return processRunning(kTools, sizeof(kTools) / sizeof(kTools[0]));
}

// ---------------- 模块（DLL）扫描 ----------------
inline bool moduleLoaded(const char* name) { return ::GetModuleHandleA(name) != nullptr; }

inline bool anyModuleLoaded(const char* const* names, size_t count) {
    for (size_t i = 0; i < count; ++i) {
        if (moduleLoaded(names[i])) return true;
    }
    return false;
}

inline bool sandboxModuleLoaded() {
    static const char* kModules[] = {
        "SbieDll.dll",      // Sandboxie
        "api_log.dll",      // Cuckoo / 沙箱监控
        "dir_watch.dll",
        "wpespy.dll",
        "pstorec.dll",
        "vmcheck.dll",      // VirtualBox 客户端检查
    };
    return anyModuleLoaded(kModules, sizeof(kModules) / sizeof(kModules[0]));
}

inline bool vmModuleLoaded() {
    static const char* kModules[] = { "vmcheck.dll", "SbieDll.dll", "vboxhook.dll", "vmGuestLib.dll" };
    return anyModuleLoaded(kModules, sizeof(kModules) / sizeof(kModules[0]));
}

inline bool injectionModuleLoaded() {
    static const char* kModules[] = {
        "frida-agent-32.dll", "frida-agent-64.dll", "frida-agent.dll",
        "frida-gadget.dll", "HookLibrary.dll", "nb_inject.dll",
    };
    return anyModuleLoaded(kModules, sizeof(kModules) / sizeof(kModules[0]));
}

// ---------------- 关键 API 入口是否被 inline hook ----------------
inline bool apiPatched(const char* moduleName, const char* funcName) {
    HMODULE module = ::GetModuleHandleA(moduleName);
    if (!module) return false;
    BYTE* address = (BYTE*)(void*)::GetProcAddress(module, funcName);
    if (!address) return false;
    BYTE first = 0, second = 0;
    if (!safeRead(address, first)) return false;
    if (first == 0xE9 || first == 0xEB || first == 0xE8 || first == 0xEA || first == 0x68) return true;
    if (first == 0xFF && safeRead(address + 1, second)) {
        if (second == 0x25 || second == 0xE0 || second == 0xE6) return true;
    }
    return false;
}

inline int patchedApiCount() {
    // 只查「本应由 SDK 直接调用、且正常实现不会以 jmp 开头」的接口
    struct Entry { const char* module; const char* function; };
    static const Entry kApis[] = {
        { "bcrypt.dll",   "BCryptEncrypt" },
        { "bcrypt.dll",   "BCryptDecrypt" },
        { "bcrypt.dll",   "BCryptVerifySignature" },
        { "bcrypt.dll",   "BCryptHashData" },
        { "winhttp.dll",  "WinHttpSendRequest" },
        { "winhttp.dll",  "WinHttpReceiveResponse" },
        { "kernel32.dll", "IsDebuggerPresent" },
        { "kernel32.dll", "GetTickCount" },
        { "ntdll.dll",    "NtQueryInformationProcess" },
    };
    int count = 0;
    for (size_t i = 0; i < sizeof(kApis) / sizeof(kApis[0]); ++i) {
        if (apiPatched(kApis[i].module, kApis[i].function)) ++count;
    }
    return count;
}

// ---------------- 注册表 ----------------
inline bool regKeyExists(HKEY root, const char* subKey) {
    static const REGSAM kViews[2] = { KEY_WOW64_64KEY, KEY_WOW64_32KEY };
    for (int i = 0; i < 2; ++i) {
        HKEY key = nullptr;
        if (::RegOpenKeyExA(root, subKey, 0, KEY_READ | kViews[i], &key) == ERROR_SUCCESS) {
            ::RegCloseKey(key);
            return true;
        }
    }
    return false;
}

inline bool regValueContains(HKEY root, const char* subKey, const char* valueName, const char* needle) {
    static const REGSAM kViews[2] = { KEY_WOW64_64KEY, KEY_WOW64_32KEY };
    for (int i = 0; i < 2; ++i) {
        HKEY key = nullptr;
        if (::RegOpenKeyExA(root, subKey, 0, KEY_READ | kViews[i], &key) != ERROR_SUCCESS) continue;
        char buf[512] = {};
        DWORD cb = sizeof(buf) - 1, type = 0;
        const LONG status = ::RegQueryValueExA(key, valueName, nullptr, &type, (LPBYTE)buf, &cb);
        ::RegCloseKey(key);
        if (status != ERROR_SUCCESS) continue;
        const std::string lower = toLowerAscii(buf);
        if (lower.find(needle) != std::string::npos) return true;
    }
    return false;
}

inline bool vmRegistryPresent() {
    if (regKeyExists(HKEY_LOCAL_MACHINE, "SOFTWARE\\VMware, Inc.\\VMware Tools")) return true;
    if (regKeyExists(HKEY_LOCAL_MACHINE, "SOFTWARE\\Oracle\\VirtualBox Guest Additions")) return true;
    static const char* kServices[] = {
        "SYSTEM\\CurrentControlSet\\Services\\VBoxGuest",
        "SYSTEM\\CurrentControlSet\\Services\\VBoxMouse",
        "SYSTEM\\CurrentControlSet\\Services\\VBoxSF",
        "SYSTEM\\CurrentControlSet\\Services\\vmci",
        "SYSTEM\\CurrentControlSet\\Services\\vmhgfs",
        "SYSTEM\\CurrentControlSet\\Services\\vmmouse",
        "SYSTEM\\CurrentControlSet\\Services\\xenevtchn",
        "SYSTEM\\CurrentControlSet\\Services\\qemu-ga",
    };
    for (size_t i = 0; i < sizeof(kServices) / sizeof(kServices[0]); ++i) {
        if (regKeyExists(HKEY_LOCAL_MACHINE, kServices[i])) return true;
    }
    return false;
}

inline bool vmBiosStrings() {
    const char* kSystem = "HARDWARE\\DESCRIPTION\\System\\BIOS";
    static const char* kManufacturers[] = {
        "vmware", "virtualbox", "innotek", "qemu", "xen", "parallels",
        "bochs", "amazon ec2", "google compute engine", "openstack", "nutanix",
    };
    for (size_t i = 0; i < sizeof(kManufacturers) / sizeof(kManufacturers[0]); ++i) {
        if (regValueContains(HKEY_LOCAL_MACHINE, kSystem, "SystemManufacturer", kManufacturers[i])) return true;
    }
    static const char* kProducts[] = {
        "virtual machine", "vmware virtual platform", "virtualbox", "kvm",
        "standard pc (qemu)", "xen", "parallels virtual platform",
    };
    for (size_t i = 0; i < sizeof(kProducts) / sizeof(kProducts[0]); ++i) {
        if (regValueContains(HKEY_LOCAL_MACHINE, kSystem, "SystemProductName", kProducts[i])) return true;
    }
    // 兜底：SMBIOS.reflectHost 会反射 SystemManufacturer / SystemProductName / 序列号，
    // 但 BIOS Vendor 与 BIOS Version 默认不反射 —— 隐身 VMware 配置下通常仍保留真实固件名
    // （如 "VMware, Inc." / 版本前缀 "VMW"）。只依赖上面两个字段会漏检这类"隐身虚拟机"。
    static const char* kVendors[] = { "vmware", "innotek", "qemu", "xen", "bochs", "parallels" };
    for (size_t i = 0; i < sizeof(kVendors) / sizeof(kVendors[0]); ++i) {
        if (regValueContains(HKEY_LOCAL_MACHINE, kSystem, "BIOSVendor", kVendors[i])) return true;
    }
    static const char* kVersions[] = {
        "vmware", "vbox", "virtualbox", "qemu", "xen", "bochs",
        "vmw",      // VMware BIOS 版本号前缀，例如 "VMW71.00V.0"
    };
    for (size_t i = 0; i < sizeof(kVersions) / sizeof(kVersions[0]); ++i) {
        if (regValueContains(HKEY_LOCAL_MACHINE, kSystem, "BIOSVersion", kVersions[i])) return true;
    }
    return false;
}

// ---------------- CPUID hypervisor 位 ----------------
inline bool cpuidHypervisor() {
#if defined(_M_IX86) || defined(_M_X64)
    int regs[4] = { 0, 0, 0, 0 };
    __cpuid(regs, 1);
    return ((unsigned)regs[2] & 0x80000000u) != 0;
#else
    return false;
#endif
}

// ---------------- 网卡 MAC OUI ----------------
inline bool macOuiVirtual() {
    ULONG size = 0;
    if (::GetAdaptersInfo(nullptr, &size) != ERROR_BUFFER_OVERFLOW || size == 0) return false;
    std::vector<BYTE> buffer(size);
    PIP_ADAPTER_INFO head = (PIP_ADAPTER_INFO)buffer.data();
    if (::GetAdaptersInfo(head, &size) != ERROR_SUCCESS) return false;
    for (PIP_ADAPTER_INFO adapter = head; adapter; adapter = adapter->Next) {
        if (adapter->AddressLength < 3) continue;
        const BYTE* mac = adapter->Address;
        const unsigned oui = ((unsigned)mac[0] << 16) | ((unsigned)mac[1] << 8) | mac[2];
        switch (oui) {
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
        default:
            break;
        }
    }
    return false;
}

// ---------------- 驱动文件痕迹 ----------------
inline bool vmDriverFile() {
    static const char* kFiles[] = {
        "C:\\Windows\\System32\\drivers\\vmmouse.sys",
        "C:\\Windows\\System32\\drivers\\vmhgfs.sys",
        "C:\\Windows\\System32\\drivers\\vmci.sys",
        "C:\\Windows\\System32\\drivers\\vmxnet.sys",
        "C:\\Windows\\System32\\drivers\\vmx_svga.sys",
        "C:\\Windows\\System32\\drivers\\vmmemctl.sys",
        "C:\\Windows\\System32\\drivers\\vsock.sys",
        "C:\\Windows\\System32\\drivers\\VBoxMouse.sys",
        "C:\\Windows\\System32\\drivers\\VBoxGuest.sys",
        "C:\\Windows\\System32\\drivers\\VBoxSF.sys",
        "C:\\Windows\\System32\\drivers\\VBoxVideo.sys",
        "C:\\Windows\\System32\\drivers\\SbieDrv.sys",
        "C:\\Windows\\System32\\drivers\\prl_boot.sys",
    };
    for (size_t i = 0; i < sizeof(kFiles) / sizeof(kFiles[0]); ++i) {
        if (::GetFileAttributesA(kFiles[i]) != INVALID_FILE_ATTRIBUTES) return true;
    }
    return false;
}

// ---------------- 低配 / 开机过短（沙箱常见特征，弱线索） ----------------
inline bool lowSpecMachine() {
    SYSTEM_INFO info{};
    ::GetSystemInfo(&info);
    MEMORYSTATUSEX memory{};
    memory.dwLength = sizeof(memory);
    bool lowMemory = false;
    if (::GlobalMemoryStatusEx(&memory)) {
        lowMemory = (memory.ullTotalPhys < 2ull * 1024ull * 1024ull * 1024ull);
    }
    const int width = ::GetSystemMetrics(SM_CXSCREEN);
    const int height = ::GetSystemMetrics(SM_CYSCREEN);
    const bool smallScreen = (width > 0 && height > 0 && width <= 1024 && height <= 768);
    return (info.dwNumberOfProcessors <= 1) || lowMemory || smallScreen;
}

inline bool shortUptime() {
    return ::GetTickCount64() < 300000ull;   // < 5 分钟
}

// ---------------- WDAG / 沙箱账号 ----------------
inline bool wdagAccount() {
    char buf[128] = {};
    if (::GetEnvironmentVariableA("USERNAME", buf, sizeof(buf)) == 0) return false;
    return toLowerAscii(buf).find("wdagutilityaccount") != std::string::npos;
}

// ---------------- 受保护代码段补丁检测 ----------------
inline uint64_t fnv1a(const void* data, size_t len) {
    uint64_t hash = 1469598103934665603ull;
    const BYTE* bytes = (const BYTE*)data;
    for (size_t i = 0; i < len; ++i) {
        hash ^= (uint64_t)bytes[i];
        hash *= 1099511628211ull;
    }
    return hash;
}

inline bool readRange(const void* address, size_t len, std::vector<BYTE>& out) {
    out.clear();
    const BYTE* p = (const BYTE*)address;
    size_t remain = len;
    while (remain > 0) {
        MEMORY_BASIC_INFORMATION mbi{};
        if (::VirtualQuery(p, &mbi, sizeof(mbi)) == 0) return false;
        if (mbi.State != MEM_COMMIT) return false;
        if (mbi.Protect & (PAGE_NOACCESS | PAGE_GUARD)) return false;
        const BYTE* regionEnd = (const BYTE*)mbi.BaseAddress + mbi.RegionSize;
        if (regionEnd <= p) return false;
        size_t chunk = (size_t)(regionEnd - p);
        if (chunk > remain) chunk = remain;
        out.insert(out.end(), p, p + chunk);
        p += chunk;
        remain -= chunk;
    }
    return true;
}

struct GuardedRegion {
    const void* address = nullptr;
    size_t      length = 0;
    uint64_t    hash = 0;
};

inline std::vector<GuardedRegion>& guardedRegions() {
    static std::vector<GuardedRegion> regions;
    return regions;
}
inline std::mutex& guardedMutex() { static std::mutex m; return m; }

} // namespace detail

// ---------------------------------------------------------------------------
// 登记一段核心代码做补丁检测（例：把某校验函数的地址与长度传进来）
// 建议在程序启动时调用一次：
//     protect::guardCode((const void*)&MyVerifier::run, 256);
// ---------------------------------------------------------------------------
inline bool guardCode(const void* address, size_t len) {
    if (!address || len == 0) return false;
    std::vector<BYTE> buffer;
    if (!detail::readRange(address, len, buffer)) return false;
    const uint64_t hash = detail::fnv1a(buffer.data(), buffer.size());

    std::lock_guard<std::mutex> lock(detail::guardedMutex());
    std::vector<detail::GuardedRegion>& regions = detail::guardedRegions();
    for (size_t i = 0; i < regions.size(); ++i) {
        if (regions[i].address == address && regions[i].length == len) {
            regions[i].hash = hash;             // 重新登记（如更新后重启）
            return true;
        }
    }
    detail::GuardedRegion region;
    region.address = address;
    region.length  = len;
    region.hash    = hash;
    regions.push_back(region);
    return true;
}

/** 校验已登记代码段是否被改写 */
NEBULA_MUST_CHECK inline bool verifyGuardedCode() {
    std::lock_guard<std::mutex> lock(detail::guardedMutex());
    const std::vector<detail::GuardedRegion>& regions = detail::guardedRegions();
    for (size_t i = 0; i < regions.size(); ++i) {
        std::vector<BYTE> buffer;
        if (!detail::readRange(regions[i].address, regions[i].length, buffer)) return false;
        if (detail::fnv1a(buffer.data(), buffer.size()) != regions[i].hash) return false;
    }
    return true;
}
#endif  // NEBULA_PROTECT_LEVEL >= 1

// ===========================================================================
// 检测主流程
// ===========================================================================
#if NEBULA_PROTECT_LEVEL >= 1
namespace detail {

enum class Kind { DebugStrong, DebugWeak, Vm, Sandbox, Hook, EnvWeak };

inline void addHit(Report& report, Flag flag, int weight, Kind kind, const char* reason) {
    report.flags = report.flags | flag;
    report.score += weight;
    switch (kind) {
    case Kind::DebugStrong: report.strong_hits += 1; break;
    case Kind::DebugWeak:   report.weak_hits += weight; break;
    case Kind::Vm:          report.vm_score += weight; break;
    default: break;
    }
    report.reasons.push_back(reason);
}

inline void runChecks(Level level, Report& report) {
    const int lv = (int)level;

    // ---- 反调试：基础（等级 1）----
    if (lv >= 1) {
        if (::IsDebuggerPresent())
            addHit(report, Flag::DebuggerApi, 100, Kind::DebugStrong, "IsDebuggerPresent = 真");
        if (pebBeingDebugged())
            addHit(report, Flag::DebuggerPeb, 100, Kind::DebugStrong, "PEB.BeingDebugged 已置位");
        if ((pebNtGlobalFlag() & 0x70u) != 0)
            addHit(report, Flag::DebuggerHeap, 45, Kind::DebugWeak, "PEB.NtGlobalFlag 呈调试堆标志");
        if (heapDebugFlags())
            addHit(report, Flag::DebuggerHeap, 35, Kind::DebugWeak, "进程堆标志异常（调试堆）");
    }

    // ---- 反调试：进阶（等级 2）----
    if (lv >= 2) {
        BOOL remote = FALSE;
        if (::CheckRemoteDebuggerPresent(::GetCurrentProcess(), &remote) && remote)
            addHit(report, Flag::DebuggerApi, 100, Kind::DebugStrong, "存在远程调试器");
        if (ntDebugPort())
            addHit(report, Flag::DebuggerNt, 100, Kind::DebugStrong, "ProcessDebugPort 非空");
        if (ntDebugObject())
            addHit(report, Flag::DebuggerNt, 100, Kind::DebugStrong, "ProcessDebugObjectHandle 非空");
        if (ntDebugFlags())
            addHit(report, Flag::DebuggerNt, 80, Kind::DebugStrong, "ProcessDebugFlags = 0（被调试）");
        if (hardwareBreakpoints())
            addHit(report, Flag::DebuggerHardwareBp, 70, Kind::DebugWeak, "线程存在硬件断点（Dr0-Dr7）");
        if (debugToolProcess())
            addHit(report, Flag::DebuggerTool, 60, Kind::DebugWeak, "运行着调试/逆向工具进程");
    }

    // ---- 反调试：严格（等级 3）----
    if (lv >= 3) {
        if (debuggerWindow())
            addHit(report, Flag::DebuggerWindow, 60, Kind::DebugWeak, "检测到调试器窗口");
        if (timingAnomaly(NEBULA_TIMING_THRESHOLD_MS))
            addHit(report, Flag::DebuggerTiming, 30, Kind::DebugWeak, "关键代码执行时序异常（疑似单步/断点）");
    }

    // ---- 注入 / API 劫持 / 代码补丁 ----
    if (lv >= 2) {
        if (injectionModuleLoaded())
            addHit(report, Flag::ApiHooked, 70, Kind::Hook, "检测到注入框架模块（frida 等）");
        const int patched = patchedApiCount();
        if (patched >= 2)
            addHit(report, Flag::ApiHooked, 45, Kind::Hook, "多个关键 API 入口被改写（疑似 inline hook）");
        else if (patched == 1)
            addHit(report, Flag::ApiHooked, 20, Kind::EnvWeak, "有 1 个关键 API 入口被改写（可能是杀软/监控）");
        if (!verifyGuardedCode())
            addHit(report, Flag::CodeTamper, 90, Kind::Hook, "受保护代码段被改写（补丁/内存改动）");
    }

    // ---- 环境：基础（等级 1）----
    if (lv >= 1) {
        if (cpuidHypervisor())
            addHit(report, Flag::VmCpuid, 30, Kind::Vm, "CPUID 报告 hypervisor 位（Hyper-V/VBS 也会置位，仅供参考）");
        if (vmRegistryPresent())
            addHit(report, Flag::VmRegistry, 35, Kind::Vm, "注册表存在虚拟机驱动/工具痕迹");
        if (vmBiosStrings())
            addHit(report, Flag::VmBios, 40, Kind::Vm, "BIOS/主板厂商字段为虚拟机");
        if (macOuiVirtual())
            addHit(report, Flag::VmMac, 45, Kind::Vm, "网卡 MAC 属于虚拟网卡厂商（OUI）");
    }

    // ---- 环境：标准（等级 2）----
    if (lv >= 2) {
        if (vmToolProcess())
            addHit(report, Flag::VmProcess, 30, Kind::Vm, "运行着虚拟机增强工具进程");
        if (vmModuleLoaded())
            addHit(report, Flag::VmModule, 30, Kind::Vm, "加载了虚拟机/沙箱模块");
        if (sandboxModuleLoaded())
            addHit(report, Flag::Sandbox, 60, Kind::Sandbox, "检测到沙箱环境（Sandboxie/Cuckoo 等）");
        if (wdagAccount())
            addHit(report, Flag::Sandbox, 60, Kind::Sandbox, "运行在 WDAG 隔离环境（USERNAME=WDAGUtilityAccount）");
    }

    // ---- 环境：严格（等级 3，弱线索只计分不判定）----
    if (lv >= 3) {
        if (vmDriverFile())
            addHit(report, Flag::VmDriverFile, 25, Kind::Vm, "存在虚拟机驱动文件痕迹");
        if (lowSpecMachine())
            addHit(report, Flag::WeakEnvironment, 15, Kind::EnvWeak, "机器配置异常偏低（CPU/内存/分辨率）");
        if (shortUptime())
            addHit(report, Flag::WeakEnvironment, 20, Kind::EnvWeak, "系统开机时间过短（< 5 分钟）");
    }
}

} // namespace detail

/** 执行一次完整检测（未启用 或 level=0 时直接返回"正常"） */
NEBULA_MUST_CHECK inline Report scan() {
    Report report;
    report.level = level();
    report.at    = nowSeconds();
    if (!enabled() || report.level == Level::Off) return report;

    detail::runChecks(report.level, report);

    report.debugged    = (report.strong_hits > 0) || (report.weak_hits >= 90);
    report.virtualized = (report.vm_score >= 60);
    report.sandboxed   = report.has(Flag::Sandbox);
    report.hooked      = report.has(Flag::ApiHooked) || report.has(Flag::CodeTamper);
    report.clean       = !(report.debugged || report.virtualized || report.sandboxed || report.hooked);
    setLastReport(report);
    return report;
}

#else   // NEBULA_PROTECT_LEVEL == 0：桩实现（零开销）

/** 编译期未开启运行时防护时的桩：始终返回"正常" */
NEBULA_MUST_CHECK inline Report scan() {
    Report report;
    report.level = Level::Off;
    report.at    = nowSeconds();
    return report;
}
inline bool guardCode(const void*, size_t) { return false; }
NEBULA_MUST_CHECK inline bool verifyGuardedCode() { return true; }

#endif  // NEBULA_PROTECT_LEVEL >= 1

// ===========================================================================
// 命中后的处置
// ===========================================================================
/**
 * 按 action 处置一次检测结果。
 *
 * @return true  = 可以继续（action <= 1 仅记录/上报；或命中的只是"疑似环境"而策略宽松）
 *         false = 建议中止业务（action >= 2 且判定为需要拦截，已置「降级」态）
 * action == 3 时本函数不返回：弹窗提示后直接退出进程。
 *
 * 拦截判定（这里修掉了旧版一个逻辑漏洞：旧版即使打开严格策略也不会拦 VM/hook）：
 *   · 宽松（默认）：只有**真实调试铁证**（debugged）才按 action 拦截；
 *                   VM / 沙箱 / hook 只记录与上报，避免误伤云电脑与开加速器的正常用户。
 *   · 严格：任何异常（含 VM / 沙箱 / hook）都按 action 拦截。
 */
inline bool enforce(const Report& report) {
    setLastReport(report);
    if (report.clean) return true;

    // 调试输出（DebugView 可见，便于自测排错）
    std::string line = "[Nebula] " + report.summary();
    if (!report.reasons.empty()) line += "  >>  " + report.detail();
    line += "\r\n";
    ::OutputDebugStringA(line.c_str());

    const std::function<void(const Report&)> callback = detail::callbackCopy();
    if (callback) callback(report);          // 默认策略下接入方在这里上报服务端

    const int act = action();
    const bool shouldBlock = strictPolicy() ? true : report.debugged;
    if (act >= 2 && shouldBlock) {
        if (act >= 3) {
            const std::wstring message =
                L"检测到当前运行环境存在异常（可能被调试或篡改）。\n\n"
                L"为保护您的账户与数据安全，本程序即将退出。\n"
                L"如确认为正常环境，请关闭调试、逆向或虚拟机辅助类软件后重新启动。";
            ::MessageBoxW(nullptr, message.c_str(), L"Nebula", MB_ICONERROR | MB_OK);
            ::ExitProcess(0xE0000001u);
        }
        std::lock_guard<std::mutex> lock(detail::settingsMutex());
        detail::settings().degraded = true;
        return false;
    }
    return true;
}

/** 检测 + 处置一步到位（Client::init 内部就是调它） */
inline Report scanAndEnforce(bool* may_continue = nullptr) {
    const Report report = scan();
    const bool ok = enforce(report);
    if (may_continue) *may_continue = ok;
    return report;
}

// ===========================================================================
// 后台巡检
// ===========================================================================
namespace detail {

struct Watchdog {
    std::atomic<bool> running{ false };
    std::thread thread;
    int interval_ms = 5000;
    std::mutex mutex;
    std::function<void(const Report&)> callback;

    ~Watchdog() { stop(); }

    void stop() {
        if (!running.exchange(false)) return;
        if (thread.joinable()) {
            if (thread.get_id() == std::this_thread::get_id()) {
                thread.detach();          // 从巡检线程内部调用 stop：不能自 join
            } else {
                thread.join();
            }
        }
    }
};

inline Watchdog& watchdog() { static Watchdog w; return w; }

inline void watchdogLoop(int interval_ms, std::function<void(const Report&)> callback) {
    Watchdog& w = watchdog();
    while (w.running.load()) {
        for (int waited = 0; waited < interval_ms && w.running.load(); waited += 200) {
            std::this_thread::sleep_for(std::chrono::milliseconds(200));
        }
        if (!w.running.load()) break;
        const Report report = scan();
        if (report.clean) continue;
        if (callback) callback(report);
        else if (!enforce(report)) break;
    }
}

} // namespace detail

/**
 * 启动后台巡检。
 * @param interval_ms 巡检间隔（< 1000 时按 1000 处理）
 * @param cb          命中回调；为空时走 enforce() 的 action 策略
 */
inline void startWatchdog(int interval_ms = 5000,
                          std::function<void(const Report&)> cb = nullptr) {
#if NEBULA_PROTECT_LEVEL >= 1
    if (!enabled() || level() == Level::Off) return;
    if (interval_ms < 1000) interval_ms = 1000;
    detail::Watchdog& w = detail::watchdog();
    std::lock_guard<std::mutex> lock(w.mutex);
    if (w.running.load()) return;
    w.callback    = std::move(cb);
    w.interval_ms = interval_ms;
    w.running.store(true);
    if (w.thread.joinable()) w.thread.join();
    w.thread = std::thread(detail::watchdogLoop, w.interval_ms, w.callback);
#else
    NEBULA_UNUSED(interval_ms);
    NEBULA_UNUSED(cb);
#endif
}

/** 停止后台巡检（线程安全；可从任意线程调用，包括巡检回调内部） */
inline void stopWatchdog() {
    detail::watchdog().stop();
}

} // namespace protect
} // namespace nebula

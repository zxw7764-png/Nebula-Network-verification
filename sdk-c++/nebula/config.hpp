#pragma once
// ============================================================================
// Nebula SDK · 编译期配置（开关 + 平台适配 + 通用宏）
// ----------------------------------------------------------------------------
// 这是整个 SDK 的**唯一入口文件**：所有开关集中在这里，其余头文件只读不定义。
// 需要修改开关时，在工程「属性 → C/C++ → 预处理器 → 预处理器定义」里加宏，
// 或在 #include "nebula_sdk.hpp" **之前**用 #define 定义。
//
// ┌ 加固三类开关（默认全关，全关时零开销、行为与未加固版本一致）
// │   ① 壳标记     NEBULA_SHELL_ENABLE    0|1    VMProtect / Themida / 自定义壳
// │   ② 代码混淆   NEBULA_OBF_STRINGS     0|1    编译期字符串加密 + 间接调用 + 不透明谓词
// │   ③ 运行时防护 NEBULA_PROTECT_LEVEL   0..3   反调试 / 反虚拟机沙箱 / 代码补丁自检
// └ 一键全开：NEBULA_HARDEN 1（= ③严格 + ② + ① + 运行时多样性）
//
// 其余可选开关：
//   NEBULA_RUNTIME_DIVERSE   0|1  每次启动随机化的数据面形态（SecureString 密钥、谓词形态）
//   NEBULA_PROTECT_ACTION    0..3 命中加固检测后的处置（0 记录 / 1 回调 / 2 降级 / 3 弹窗退出）
//   NEBULA_TIMING_THRESHOLD_MS    时序异常阈值（毫秒）
//   NEBULA_QUIET             定义即关闭编译期提醒
//   NEBULA_SHELL_NO_AUTOLINK 定义即不自动 pragma comment 链入壳 SDK 的 .lib
//   NEBULA_SHELL_FORCE_HOOK  定义即强制使用 NEBULA_SHELL_HOOK_* 自定义标记
//
// 平台：**仅 Windows**（MSVC / MinGW 均可，随附 pragma comment 自动链接系统库）。
// 完整说明见 sdk/SDK_PROTECTION.md。
// ============================================================================

#define NEBULA_SDK_VERSION "3.1.0"

// ---------------------------------------------------------------------------
// 一键全开
// ---------------------------------------------------------------------------
#ifndef NEBULA_HARDEN
#define NEBULA_HARDEN 0
#endif

#if NEBULA_HARDEN
#  ifndef NEBULA_PROTECT_LEVEL
#    define NEBULA_PROTECT_LEVEL 3
#  endif
#  ifndef NEBULA_OBF_STRINGS
#    define NEBULA_OBF_STRINGS 1
#  endif
#  ifndef NEBULA_SHELL_ENABLE
#    define NEBULA_SHELL_ENABLE 1
#  endif
#  ifndef NEBULA_RUNTIME_DIVERSE
#    define NEBULA_RUNTIME_DIVERSE 1
#  endif
#endif

/** ③ 运行时防护等级：0 关闭(默认) / 1 基础 / 2 标准 / 3 严格 */
#ifndef NEBULA_PROTECT_LEVEL
#define NEBULA_PROTECT_LEVEL 0
#endif

/** ② 编译期字符串加密：0 关闭(默认) / 1 开启 */
#ifndef NEBULA_OBF_STRINGS
#define NEBULA_OBF_STRINGS 0
#endif

/** ① 壳标记：0 关闭(默认) / 1 开启（自动探测已安装的壳 SDK） */
#ifndef NEBULA_SHELL_ENABLE
#define NEBULA_SHELL_ENABLE 0
#endif

/** ④ 运行时多样性：0 关闭(默认) / 1 每次启动随机化数据面形态 */
#ifndef NEBULA_RUNTIME_DIVERSE
#define NEBULA_RUNTIME_DIVERSE 0
#endif

/** 命中加固检测后的处置：0 只记录 / 1 回调上报(默认) / 2 降级 / 3 弹窗并退出 */
#ifndef NEBULA_PROTECT_ACTION
#define NEBULA_PROTECT_ACTION 1
#endif

/** 时序异常阈值（毫秒）；越小越灵敏、越容易误报 */
#ifndef NEBULA_TIMING_THRESHOLD_MS
#define NEBULA_TIMING_THRESHOLD_MS 50.0
#endif

// ---------------------------------------------------------------------------
// 平台适配
// ---------------------------------------------------------------------------
#if !defined(_WIN32)
#  error "Nebula SDK 仅支持 Windows（依赖 WinHTTP / bcrypt / CryptoAPI）。非 Windows 平台请勿包含本 SDK。"
#endif

#ifndef _WIN32_WINNT
#  define _WIN32_WINNT 0x0601        // Windows 7：GetTickCount64 等 API 的最低要求
#endif
#ifndef WINVER
#  define WINVER _WIN32_WINNT
#endif
#ifndef NOMINMAX
#  define NOMINMAX                   // 禁止 windows.h 定义 min/max 宏（会咬 std::min/max）
#endif
#ifndef WIN32_LEAN_AND_MEAN
#  define WIN32_LEAN_AND_MEAN
#endif

#include <windows.h>
#include <bcrypt.h>
#include <wincrypt.h>
#include <winhttp.h>

// tchar.h 之外的宽字符/线程/进程/网络/注册表相关系统头（各模块按需，这里统一引入，
// 避免同一头被多处包含时出现遗漏）。
#include <tlhelp32.h>   // 线程/进程快照（运行时防护）
#include <iphlpapi.h>   // GetAdaptersInfo（网卡 MAC）
#include <objbase.h>    // COM 初始化（WMI 硬件指纹）
#include <wbemidl.h>    // WMI

#pragma comment(lib, "winhttp.lib")
#pragma comment(lib, "bcrypt.lib")
#pragma comment(lib, "advapi32.lib")
#pragma comment(lib, "crypt32.lib")
#pragma comment(lib, "iphlpapi.lib")
#pragma comment(lib, "wbemuuid.lib")
#pragma comment(lib, "ole32.lib")
#pragma comment(lib, "oleaut32.lib")   // SysAllocString / VariantInit（WMI 指纹）
#pragma comment(lib, "user32.lib")     // MessageBoxW（运行时防护弹窗）

// bcrypt.h 把 BCRYPT_SUCCESS 定义成函数式宏，会劫持同名的类成员函数声明
// （MSVC 报 C2059/C2143/C2334）。SDK 内部一律用 crypto::isOk()，这里直接取消宏。
#ifdef BCRYPT_SUCCESS
#  undef BCRYPT_SUCCESS
#endif

// ---------------------------------------------------------------------------
// 语言 / 标准库
// ---------------------------------------------------------------------------
#include <string>
#include <string_view>
#include <vector>
#include <cstdint>
#include <cstddef>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <cctype>
#include <ctime>
#include <chrono>
#include <atomic>
#include <mutex>
#include <thread>
#include <functional>
#include <memory>
#include <utility>
#include <random>

// ---------------------------------------------------------------------------
// 通用属性宏
// ---------------------------------------------------------------------------
#if defined(_MSC_VER)
#  define NEBULA_NOINLINE    __declspec(noinline)
#  define NEBULA_FORCEINLINE __forceinline
#elif defined(__GNUC__) || defined(__clang__)
#  define NEBULA_NOINLINE    __attribute__((noinline))
#  define NEBULA_FORCEINLINE inline __attribute__((always_inline))
#else
#  define NEBULA_NOINLINE
#  define NEBULA_FORCEINLINE inline
#endif

/** 明确标注「返回值必须检查」（SDK 内对安全相关返回值使用） */
#define NEBULA_MUST_CHECK [[nodiscard]]

/** 未使用参数（消除 C4100） */
#define NEBULA_UNUSED(x) ((void)(x))

// ---------------------------------------------------------------------------
// 是否编入加固模块（找不到 nebula_protect.hpp / protect 目录时自动跳过）
// ---------------------------------------------------------------------------
#if defined(__has_include)
#  if __has_include("nebula/protect/runtime.hpp")
#    define NEBULA_HAS_PROTECT 1
#  endif
#endif
#ifndef NEBULA_HAS_PROTECT
#  define NEBULA_HAS_PROTECT 0
#endif

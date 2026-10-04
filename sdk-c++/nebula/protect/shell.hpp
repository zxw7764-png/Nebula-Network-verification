#pragma once
// ============================================================================
// Nebula SDK · 壳标记（VMProtect / Themida·WinLicense / 自定义壳）
// ----------------------------------------------------------------------------
// 作用：在核心函数体内插入壳的标记，让加壳工具把这段代码虚拟化 / 变异。
//   · NEBULA_SHELL_ENABLE=0（默认）：所有宏展开为空 → 零开销、零影响。
//   · NEBULA_SHELL_ENABLE=1       ：自动探测已安装的壳 SDK（__has_include）。
//   · 未装壳但开了开关         ：宏仍为空，不会编译报错。
//
// ★ 标记只是「告诉壳保护这里」；真正加壳仍需用 VMProtect / Themida 对**编译产物**
//   （.exe / .dll）做处理。本文件不会替你加壳。
//
// 强度：ULTRA > VM(虚拟化) > MUTATE(变异，性能好) > SCOPE(只划范围)
//   建议：ULTRA 只标 1~2 个最关键函数（如卡密校验）；高频函数用 MUTATE。
// ============================================================================

#include "../config.hpp"

// ---------------------------------------------------------------------------
// 壳 SDK 探测与引入（仅在开启开关时）
// ---------------------------------------------------------------------------
// ★ 必须放在**全局作用域**：VMProtect / SecureEngine 的标记函数都是全局 C 函数，
//   若在 namespace 内 include，符号会被裹进 nebula::protect::，
//   宏在其他命名空间（如 nebula::client）展开时就找不到 → C3861。
// ---------------------------------------------------------------------------
#if NEBULA_SHELL_ENABLE
#  if defined(__has_include)
#    if __has_include("VMProtectSDK.h")
#      define NEBULA_SHELL_VMP 1
#    elif defined(NEBULA_SHELL_VMP) && NEBULA_SHELL_VMP
#      error "已定义 NEBULA_SHELL_VMP=1，但在包含路径里找不到 VMProtectSDK.h。请把 sdk/vmp 加入「附加包含目录」。"
#    endif
#    if __has_include(<SecureEngineSDK.h>)
#      define NEBULA_SHELL_THEMIDA 1
#    elif defined(NEBULA_SHELL_THEMIDA) && NEBULA_SHELL_THEMIDA
#      error "已定义 NEBULA_SHELL_THEMIDA=1，但在包含路径里找不到 SecureEngineSDK.h。请把 Themida/WinLicense SDK 目录加入「附加包含目录」。"
#    endif
#  endif
#  if defined(NEBULA_SHELL_VMP) && NEBULA_SHELL_VMP
#    include "VMProtectSDK.h"
#    ifndef NEBULA_SHELL_NO_AUTOLINK
#      ifdef _WIN64
#        pragma comment(lib, "VMProtectSDK64.lib")
#      else
#        pragma comment(lib, "VMProtectSDK32.lib")
#      endif
#    endif
#  endif
#  if defined(NEBULA_SHELL_THEMIDA) && NEBULA_SHELL_THEMIDA
#    include <SecureEngineSDK.h>
#    ifndef NEBULA_SHELL_NO_AUTOLINK
#      ifdef _WIN64
#        pragma comment(lib, "SecureEngineSDK64.lib")
#      else
#        pragma comment(lib, "SecureEngineSDK32.lib")
#      endif
#    endif
#  endif
#endif

namespace nebula {
namespace protect {

// ---------------------------------------------------------------------------
// 自定义壳挂载点（Enigma / Obsidium / ASProtect 等无统一标记头时自行填）
//   例：#define NEBULA_SHELL_HOOK_VM_BEGIN()  /* 你的壳的标记 */
// ---------------------------------------------------------------------------
#ifndef NEBULA_SHELL_HOOK_VM_BEGIN
#  define NEBULA_SHELL_HOOK_VM_BEGIN()
#endif
#ifndef NEBULA_SHELL_HOOK_VM_END
#  define NEBULA_SHELL_HOOK_VM_END()
#endif
#ifndef NEBULA_SHELL_HOOK_MUTATE_BEGIN
#  define NEBULA_SHELL_HOOK_MUTATE_BEGIN()
#endif
#ifndef NEBULA_SHELL_HOOK_MUTATE_END
#  define NEBULA_SHELL_HOOK_MUTATE_END()
#endif

} // namespace protect
} // namespace nebula

// 行号字符串化（给壳标记生成唯一名称）
// ★ 必须**两层函数式宏**转发：直接把 __LINE__ 交给 stringize 只会得到字面量
//   "__LINE__"，导致所有标记点重名（加壳时无法区分）。
//   NEBULA_STR_LINE_ 负责先把 __LINE__ 展开成数字，再交给 NEBULA_STR_LINE_HELPER_ 字符串化。
#define NEBULA_STR_LINE_HELPER_(n) #n
#define NEBULA_STR_LINE_EXPAND_(n) NEBULA_STR_LINE_HELPER_(n)
#define NEBULA_STR_LINE NEBULA_STR_LINE_EXPAND_(__LINE__)

// ---------------------------------------------------------------------------
// NEBULA_NOINLINE：含壳标记的函数必须保持独立函数体
// ---------------------------------------------------------------------------
// ★ 为什么需要：壳的 Begin/End 标记要求「一个函数体一对」，不允许嵌套/重叠。
//   header-only inline 函数若被编译器内联进宿主函数，它体内的标记就会落进
//   宿主标记区域的内部 → 加壳时报「地址已由函数 XXX 使用」。
//   给所有含 NEBULA_MARK_* 的函数挂上 NEBULA_NOINLINE 即可杜绝这一类问题
//   （未开壳时该宏同样生效，但只是不内联，无任何功能影响）。
// ---------------------------------------------------------------------------
#if defined(_MSC_VER)
#  define NEBULA_NOINLINE __declspec(noinline)
#elif defined(__GNUC__) || defined(__clang__)
#  define NEBULA_NOINLINE __attribute__((noinline))
#else
#  define NEBULA_NOINLINE
#endif

// ---------------------------------------------------------------------------
// 统一标记宏（推荐一律使用这一套；必须成对，且在同一函数体内）
// ---------------------------------------------------------------------------
#if NEBULA_SHELL_ENABLE && defined(NEBULA_SHELL_FORCE_HOOK) && NEBULA_SHELL_FORCE_HOOK
#  define NEBULA_MARK_ULTRA_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_ULTRA_END()    NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_VM_BEGIN()     NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_VM_END()       NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_MUTATE_BEGIN() NEBULA_SHELL_HOOK_MUTATE_BEGIN()
#  define NEBULA_MARK_MUTATE_END()   NEBULA_SHELL_HOOK_MUTATE_END()
#  define NEBULA_MARK_SCOPE_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_SCOPE_END()    NEBULA_SHELL_HOOK_VM_END()
#elif defined(NEBULA_SHELL_VMP) && NEBULA_SHELL_VMP
#  define NEBULA_MARK_ULTRA_BEGIN()  VMProtectBeginUltra(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_ULTRA_END()    VMProtectEnd()
#  define NEBULA_MARK_VM_BEGIN()     VMProtectBeginVirtualization(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_VM_END()       VMProtectEnd()
#  define NEBULA_MARK_MUTATE_BEGIN() VMProtectBeginMutation(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_MUTATE_END()   VMProtectEnd()
#  define NEBULA_MARK_SCOPE_BEGIN()  VMProtectBegin(__FILE__ ":" NEBULA_STR_LINE)
#  define NEBULA_MARK_SCOPE_END()    VMProtectEnd()
#elif defined(NEBULA_SHELL_THEMIDA) && NEBULA_SHELL_THEMIDA
#  define NEBULA_MARK_ULTRA_BEGIN()  VM_START
#  define NEBULA_MARK_ULTRA_END()    VM_END
#  define NEBULA_MARK_VM_BEGIN()     VM_START
#  define NEBULA_MARK_VM_END()       VM_END
#  define NEBULA_MARK_MUTATE_BEGIN() MUTATE_START
#  define NEBULA_MARK_MUTATE_END()   MUTATE_END
#  define NEBULA_MARK_SCOPE_BEGIN()  CODE_START
#  define NEBULA_MARK_SCOPE_END()    CODE_END
#elif NEBULA_SHELL_ENABLE
#  define NEBULA_MARK_ULTRA_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_ULTRA_END()    NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_VM_BEGIN()     NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_VM_END()       NEBULA_SHELL_HOOK_VM_END()
#  define NEBULA_MARK_MUTATE_BEGIN() NEBULA_SHELL_HOOK_MUTATE_BEGIN()
#  define NEBULA_MARK_MUTATE_END()   NEBULA_SHELL_HOOK_MUTATE_END()
#  define NEBULA_MARK_SCOPE_BEGIN()  NEBULA_SHELL_HOOK_VM_BEGIN()
#  define NEBULA_MARK_SCOPE_END()    NEBULA_SHELL_HOOK_VM_END()
#else
#  define NEBULA_MARK_ULTRA_BEGIN()
#  define NEBULA_MARK_ULTRA_END()
#  define NEBULA_MARK_VM_BEGIN()
#  define NEBULA_MARK_VM_END()
#  define NEBULA_MARK_MUTATE_BEGIN()
#  define NEBULA_MARK_MUTATE_END()
#  define NEBULA_MARK_SCOPE_BEGIN()
#  define NEBULA_MARK_SCOPE_END()
#endif

#pragma once
// ============================================================================
// Nebula SDK · 授权门卫（可选）
// ----------------------------------------------------------------------------
// 把「授权判定 → 走成功/失败」整段收进壳的虚拟化区，接入层不再暴露一眼可 patch 的
// 裸 if(jz/jnz) 分支。
//
//   · 用：判定跳转被壳虚拟化（需 NEBULA_SHELL_ENABLE=1 + 加壳），patch 难度明显提高；
//   · 不用：完全可跳过，保留原有的 if(ok) 写法 —— 未开启壳标记时 NEBULA_MARK_* 是
//           空宏，本函数零开销、等价于普通 if。
//
// 本函数不改变任何协议与业务逻辑，纯粹是在"判定分支"外面包一层保护。
// ============================================================================

#include "../protect/shell.hpp"

namespace nebula {

/**
 * 用法：
 *     bool ok = response.code == 0;
 *     nebula::guardAuth(ok,
 *         [&]{ StartMain(std::move(client)); },   // 成功 → 进主界面
 *         [&]{ ShowLoginFailed(); });             // 失败 → 提示
 *
 * ★ 必须 NEBULA_NOINLINE：本函数是 header-only inline 模板，若被编译器内联进
 *   宿主函数（宿主自己也有壳标记时），下面的 VM 标记会嵌进宿主标记区域内部，
 *   加壳时报「地址已由函数 XXX 使用」。保持独立函数体即无嵌套。
 */
template <typename OnOk, typename OnFail>
NEBULA_NOINLINE inline void guardAuth(bool ok, OnOk&& onOk, OnFail&& onFail) {
    NEBULA_MARK_VM_BEGIN();
    if (ok) onOk();
    else    onFail();
    NEBULA_MARK_VM_END();
}

} // namespace nebula

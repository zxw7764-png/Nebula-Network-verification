#pragma once
// ============================================================================
// 兼容入口：旧版 SDK 把加固模块放在 nebula_protect.hpp，由 nebula_sdk.hpp 自动包含。
// 新版本已拆分为 nebula/protect/{shell,obfuscate,runtime}.hpp，本文件仅作转发，
// 保证老的 #include "nebula_protect.hpp" 仍可用。新代码请直接包含 nebula_sdk.hpp。
// ============================================================================

#include "nebula/config.hpp"
#include "nebula/protect/shell.hpp"
#include "nebula/protect/obfuscate.hpp"
#include "nebula/protect/runtime.hpp"

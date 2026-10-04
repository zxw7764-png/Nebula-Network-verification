#pragma once
// ============================================================================
// Nebula 网络验证系统 · Windows 客户端 C++ SDK（header-only）
// ============================================================================
//  版本：NEBULA_SDK_VERSION（见 nebula/config.hpp）
//  依赖：**仅 Windows 系统库**（WinHTTP / bcrypt / CryptoAPI / WMI），零第三方依赖
//  工具链：MSVC（C++17 及以上）；建议开启 /utf-8
//
// ── 快速开始 ──────────────────────────────────────────────────────────────
//   1) 打开 nebula/client/config.hpp，填好 API 地址 / app_key /
//      响应签名公钥（3 项；3.1 起通信密钥由 ECDH 握手协商，无需填 AES_KEY）；
//   2) 工程里 #include "nebula_sdk.hpp"（只需这一个入口）；
//   3) 入口代码：
//
//        auto client = nebula::createDefaultClient(machineId, "Windows", "1.0.2");
//        auto init   = client->init();
//        if (!init.ok) return 1;
//        if (!client->enforceSelfIntegrity()) return 1;
//        if (!client->versionAlert()) return 1;            //    版本策略提示
//        if (client->autoUpdate().state == nebula::UpdateState::Applied) return 0;  // 自动更新并重启
//        auto login  = client->login(account, password);
//        if (!login.ok) return 1;
//        client->startHeartbeat(login.token, [](const nebula::HeartbeatInfo& hb) {
//            if (hb.kick || hb.need_relogin) { /* 回登录界面 */ }
//        });
//
// ── 目录结构（每个头文件单一职责，可单独阅读）─────────────────────────────
//   nebula/config.hpp              编译期开关（NEBULA_*）+ 平台适配 + 通用宏
//   nebula/core/error.hpp          本地错误码（负值，与业务码分离）
//   nebula/core/text.hpp           UTF-8/宽字符、hex、base64、时间
//   nebula/core/secure_string.hpp  运行时随机源 + 擦除型敏感字符串
//   nebula/core/json.hpp           极简 JSON 读写（扁平搜索）
//   nebula/core/crypto.hpp         SHA256/HMAC/MD5/AES-CBC + ES256/RS256 验签
//   nebula/core/http.hpp           WinHTTP 同步 POST + 证书指纹锁定
//   nebula/protect/shell.hpp       壳标记（VMProttect / Themida / 自定义）
//   nebula/protect/obfuscate.hpp   字符串加密 / 间接调用 / 不透明谓词
//   nebula/protect/runtime.hpp     运行时防护（反调试 / 反虚拟机沙箱 / 补丁自检）
//   nebula/client/config.hpp       ★ 接入方配置区（唯一需要改的文件）
//   nebula/client/types.hpp        结果类型（与接口文档字段一一对应）
//   nebula/client/envelope.hpp     通信公共件（URL / 白名单 / 拆封类型）
//   nebula/client/handshake.hpp    3.1 ECDH 会话（唯一协议：握手 + GCM 信封）
//   nebula/client/secure_store.hpp 本地会话 DPAPI 安全存储（绑机器加密）
//   nebula/client/device.hpp       机器码 / 设备名 / 硬件指纹
//   nebula/client/notice_store.hpp 立即公告本地已读记录
//   nebula/client/integrity.hpp    自身完整性自校验
//   nebula/client/offline.hpp      离线宽限票据验签
//   nebula/client/feature.hpp      功能密钥数据包（seal / open）
//   nebula/client/update.hpp       自动更新（下载 → 校验 → 自替换 → 重启）
//   nebula/client/client.hpp       Client（流程与业务接口）
//   nebula/client/guard.hpp        授权门卫（可选）
//
// ── 加固 ──────────────────────────────────────────────────────────────────
//   三类加固默认**全部关闭**；发布版在工程预处理器加一行 NEBULA_HARDEN=1 一键全开，
//   什么都不加 = 与不加固版本完全一致（零开销）。详见 sdk/SDK_PROTECTION.md。
//
// ── 文档 ──────────────────────────────────────────────────────────────────
//   接入指南 sdk/SDK.md ｜ 加固手册 sdk/SDK_PROTECTION.md ｜ 接口协议 docs/API.md
// ============================================================================

// 基础层
#include "nebula/config.hpp"
#include "nebula/core/error.hpp"
#include "nebula/core/text.hpp"
#include "nebula/core/secure_string.hpp"
#include "nebula/core/json.hpp"
#include "nebula/core/crypto.hpp"
#include "nebula/core/http.hpp"

// 加固层（默认全关；开启方式见 nebula/config.hpp）
#include "nebula/protect/shell.hpp"
#include "nebula/protect/obfuscate.hpp"
#include "nebula/protect/runtime.hpp"

// 客户端层
#include "nebula/client/config.hpp"
#include "nebula/client/types.hpp"
#include "nebula/client/envelope.hpp"
#include "nebula/client/handshake.hpp"
#include "nebula/client/secure_store.hpp"
#include "nebula/client/device.hpp"
#include "nebula/client/notice_store.hpp"
#include "nebula/client/integrity.hpp"
#include "nebula/client/offline.hpp"
#include "nebula/client/feature.hpp"
#include "nebula/client/client.hpp"
#include "nebula/client/guard.hpp"

// ---------------------------------------------------------------------------
// 编译期提醒：只在"三项加固全关"时提示一次（定义 NEBULA_QUIET 可静音）
// ---------------------------------------------------------------------------
#if (NEBULA_PROTECT_LEVEL == 0) && (NEBULA_OBF_STRINGS == 0) && (NEBULA_SHELL_ENABLE == 0) \
    && !defined(NEBULA_QUIET) && defined(_MSC_VER)
#  pragma message("Nebula SDK: 客户端加固当前【未启用】(默认)。发布前建议看 sdk/SDK_PROTECTION.md，按需定义 NEBULA_HARDEN=1 或 NEBULA_PROTECT_LEVEL / NEBULA_OBF_STRINGS / NEBULA_SHELL_ENABLE。")
#endif

#pragma once
// ============================================================================
// Nebula SDK · 接入方配置区 ★★★ 唯一需要修改的文件 ★★★
// ----------------------------------------------------------------------------
// 在后台「软件管理」目标软件那一行点「复制」取得 app_key，
// 连同你的 API 地址填到下面即可；入口处一行 nebula::createDefaultClient(...) 完成接入。
//
// ── 填写清单 ──────────────────────────────────────────────────────────────
//   ① kApiUrl             API 入口地址（http:// 或 https://，强烈建议 https）
//   ② kAppKey             后台「软件管理」对应软件的 app_key
//   ③ kRespSignPubKey     后台「系统设置 → 系统 → 响应签名公钥 → 复制 C++ 代码」
//                         **必填**：不填则所有请求直接失败（故意设计，不给"不校验"留口子）
//   ④ kTlsCertSha256      可选：服务端证书的 SHA256 指纹（防中间人，强烈建议填）
//
// ── 3.1 协议（唯一协议）──────────────────────────────────────────────────
//   通信密钥全部由 ECDH 握手临时协商：客户端零静态对称机密。
//   3.0 的 AES_KEY / SIGN_SALT 已彻底移除；服务端若仍是旧版，握手会直接失败提示升级。
//
// ── 字符串写法 ────────────────────────────────────────────────────────────
//   一律写在 NEBULA_STR("...") 里（不要写裸字面量）：
//   开启混淆（NEBULA_OBF_STRINGS=1）后这些值在编译期加密，strings/IDA 搜不到；
//   未开启时等价于普通 std::string，零开销。
//
// ── 加固开关不用改这里 ────────────────────────────────────────────────────
//   只改工程预处理器：NEBULA_HARDEN=1 一键全开（壳标记 + 混淆 + 运行时防护），
//   什么都不加 = 与不加固版本完全一致。完整手册见 sdk/SDK_PROTECTION.md。
//
// ── 入口地址支持两种形式（SDK 自动适配）──────────────────────────────────
//   · 文件形式  http://host/api/index.php  → <base>?action=xx
//   · 目录形式  http://host/api/           → <base>/?action=xx
// ============================================================================

#include "../core/secure_string.hpp"
#include "../protect/obfuscate.hpp"

namespace nebula {
namespace cfg {

/** ① API 入口地址 */
inline const std::string kApiUrl = NEBULA_STR("https://yz.baige.fun/api/index.php");

/**
 * ② 软件标识（app_key）。
 * 用 SecureString 存储：长度 <= 15 的字符串会被 std::string 的 SSO 内联进 .data 静态区，
 * 内存 dump 一眼可见；SecureString 只保存混淆字节，str() 时才临时解码。
 */
inline const SecureString kAppKey{ NEBULA_STR("SWBFE6879E94DD") };

/**
 * ③ 响应防伪造公钥（必填）。
 *
 * 服务端除 HMAC 外，还会用**自己的私钥**对每条响应签名（ES256 / RS256），
 * 客户端用这里内置的公钥验签 —— 私钥永不出服务端，攻击者即使把客户端里的
 * 全部密钥都逆向出来，也无法伪造响应（假服务器 / hosts 劫持直接失效）。
 *
 * 【填写方法】C++ 字符串字面量不能跨行，PEM 不能原样粘贴（会报 E0274）。两种正确写法：
 *
 *   写法 A（推荐，后台「复制 C++ 代码」按钮给的就是这种格式，直接粘贴）：
 *     inline const std::string kRespSignPubKey = NEBULA_STR(
 *         "-----BEGIN PUBLIC KEY-----\n"
 *         "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE....==\n"
 *         "-----END PUBLIC KEY-----\n");
 *
 *   写法 B：压成一行，换行全部写成 \n
 *     inline const std::string kRespSignPubKey = NEBULA_STR(
 *         "-----BEGIN PUBLIC KEY-----\nMFkw...==\n-----END PUBLIC KEY-----\n");
 *
 * 【注意】
 *   · 必须保留 -----BEGIN/END PUBLIC KEY----- 头尾标记（SDK 靠它们定位密钥体）；
 *   · 引号内不能有真实回车；空格 / 加号 / 等号原样保留；
 *   · 服务端「重新生成密钥」后必须同步更新这里，否则所有客户端会拒绝响应；
 *   · 编译期同时支持 EC P-256 与 RSA-2048 公钥（服务端自动选，客户端无需关心）。
 */
inline const std::string kRespSignPubKey = NEBULA_STR(
    "-----BEGIN PUBLIC KEY-----\n"
    "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEKIu7FnpE3XmriqIDmKlCRti95NKf\n"
    "KwAvBGijyjC/Kf1Qmz4iIlFtAHMwvrmDJrfhfWwUF3wTzYui9DBvVhhTxA==\n"
    "-----END PUBLIC KEY-----\n");

/**
 * ④ TLS 证书指纹锁定（可选但强烈建议，防透明代理 / 中间人抓包）。
 *
 * 填服务端 HTTPS **证书**的 SHA256 指纹（64 位 hex，大小写均可、可带冒号）。
 *
 * 【填「证书」而不是「公钥」】
 *   · 证书指纹（certificate fingerprint）= 对整张证书 DER 做 SHA256 ← 本 SDK 校验这个
 *   · 公钥指纹（public key pin / SPKI）= 只对公钥部分做哈希 ← 填这个会直接校验失败
 *
 * 【获取方法】
 *   · 浏览器打开 API 地址 → 地址栏锁图标 → 证书详情 → SHA-256 指纹（选「证书」那栏）
 *   · 或在服务器执行：
 *       openssl s_client -connect your-domain.com:443 </dev/null 2>/dev/null \
 *         | openssl x509 -fingerprint -sha256 -noout
 *
 * 【注意】
 *   · 只对 https:// 生效；填了它之后 SDK 会同时拒绝 http:// 的 API 地址；
 *   · 服务器换证书（续期 / 换 CA）后必须同步更新，否则所有客户端连不上；
 *   · 留空 = 不锁定（仍走系统标准 TLS 校验）。
 */
inline const std::string kTlsCertSha256 = NEBULA_STR("f31dc7cd4dbed7b9b6034bae7577452a6e50102ff3121775e64698b76f08b80d");

/**
 * 疑似环境处置策略（false = 宽松[默认]，true = 严格）。
 *
 * 影响 setProtectAction() 对「疑似环境」类命中（hook / 虚拟机 / 沙箱）的处置：
 *   · 宽松：只记录与上报，不拦截 —— 避免误伤挂加速器、跑在云电脑/VPS 的正常用户；
 *   · 严格：这些命中也会按 action 弹窗退出 —— 能拦下"隐身虚拟机"，但会误伤 VM 用户。
 * 注意：真实调试铁证（debugged）无论开关都按 action 处置，不受此开关影响。
 */
inline const bool kProtectStrictPolicy = false;

} // namespace cfg
} // namespace nebula

#pragma once
// ============================================================================
// Nebula SDK · 通信公共件（URL 组装 / 白名单 / 响应拆封结果类型）
// ----------------------------------------------------------------------------
// 3.1 起协议实现全部收敛到 nebula/client/handshake.hpp（ECDH + AES-256-GCM），
// 3.0 静态密钥信封（AES-256-CBC + HMAC）已完全移除，本文件只保留协议无关的
// 公共辅助：isPublicAction / actionUrl / OpenedResponse / constantTimeEquals。
// ============================================================================

#include "../core/crypto.hpp"
#include "../core/json.hpp"

namespace nebula {

/** 公开只读接口：服务端允许明文调用、不计配额（notice/version/online/handshake） */
NEBULA_MUST_CHECK inline bool isPublicAction(const std::string& action) {
    return action == "notice" || action == "version" || action == "online" || action == "handshake";
}

/**
 * 组装接口 URL。两种入口形式都支持：
 *   · base 以 index.php 结尾 → <base>?action=xx
 *   · 目录形式                → <base>/?action=xx
 */
NEBULA_MUST_CHECK inline std::string actionUrl(const std::string& apiBase, const std::string& action) {
    std::string base = apiBase;
    const char* kIndex = "index.php";
    const size_t kIndexLen = 9;
    if (base.size() >= kIndexLen && base.compare(base.size() - kIndexLen, kIndexLen, kIndex) == 0) {
        return base + "?action=" + action;
    }
    if (base.empty() || base.back() != '/') base += '/';
    return base + "?action=" + action;
}

/** 响应拆封结果 */
struct OpenedResponse {
    Error status = Error::Ok;      ///< Ok 表示已验签并解密成功
    std::string msg;               ///< 失败原因（中文，可直接展示）
    std::string plain;             ///< 解密后的业务响应 JSON
    int businessCode = 0;          ///< 业务响应里的 code（成功时为 0）
};

/** 恒定时间比较（防时序侧信道；长度不同直接返回 false） */
NEBULA_MUST_CHECK inline bool constantTimeEquals(const std::string& a, const std::string& b) {
    if (a.size() != b.size()) return false;
    unsigned char diff = 0;
    for (size_t i = 0; i < a.size(); ++i) {
        diff |= (unsigned char)(a[i] ^ b[i]);
    }
    return diff == 0;
}

/** 兼容旧名 */
NEBULA_MUST_CHECK inline bool isWhitelistAction(const std::string& action) { return isPublicAction(action); }

} // namespace nebula

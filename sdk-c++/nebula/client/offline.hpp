#pragma once
// ============================================================================
// Nebula SDK · 离线宽限票据（本地验签，断网可继续运行）
// ----------------------------------------------------------------------------
// 协议见 docs/API.md 2.16，服务端实现见 lib/Grace.php。
//
// 票据结构：G1.<payload-b64url>.<signature-b64url>
//   · 签名对象 = "G1.<payload-b64url>"（**第一段 + 点 + 第二段原文**）
//   · 算法 = ES256（ECDSA P-256）或 RS256（RSA-2048），由公钥类型决定
//   · payload 字段：v 版本 / u 用户ID / m 机器码摘要 / k 会话令牌摘要
//                   e 会员到期 / i 签发时间 / g 宽限截止 / d 时长 / n 随机数
//
// 校验顺序（与 docs/API.md 的伪代码一致）：
//   ① 分段与前缀  ② 公钥验签  ③ 机器码摘要  ④ 会话令牌摘要  ⑤ 有效期（含时钟偏差）
//
// 安全边界：宽限只延长**离线**运行；一旦联网，封号/踢下线立即生效。宽限时长由服务端
//           决定（默认 1 小时）且被账号有效期钳制，因此离线状态下的授权最多多活这么久。
// ============================================================================

#include "types.hpp"
#include "../core/crypto.hpp"
#include "../core/json.hpp"

namespace nebula {

/** 时钟偏差容忍（秒），与服务端 grace.clock_skew 默认值一致 */
constexpr int kClockSkewSeconds = 120;
/** 兼容旧名 */
constexpr int CLOCK_SKEW = kClockSkewSeconds;

/** 票据的解析结果（分段 + 载荷） */
struct GraceTicket {
    struct Payload {
        int         version = 1;    ///< v
        int         user_id = 0;    ///< u
        std::string machine_digest; ///< m：sha256(machine_id) 前 16 位 hex
        std::string token_digest;   ///< k：sha256(token) 前 16 位 hex
        int64_t     vip_expire = 0; ///< e：会员到期（-1 永久）
        int64_t     issued_at = 0;  ///< i：签发时间
        int64_t     until = 0;      ///< g：宽限截止时间
        int64_t     duration = 0;   ///< d：宽限时长
    };

    std::string prefix;         ///< 第一段（"G1"）
    std::string payload_b64;    ///< 第二段（payload 的 base64url 原文）
    std::string signature;      ///< 第三段解码后的原始签名字节
    std::string signing_input;  ///< 签名对象："G1.<payload-b64url>"
    Payload     payload;
};

/** 摘要算法：与服务端 Grace::digest 完全一致 —— sha256(value) 的 hex 前 16 位 */
NEBULA_MUST_CHECK inline std::string graceDigest(const std::string& value) {
    if (value.empty()) return {};
    const std::string hex = crypto::sha256Hex(value);
    if (hex.size() < 16) return {};
    return hex.substr(0, 16);
}

/**
 * 解析票据分段与载荷（**不验签**）。
 * @param expectedPrefix 期望前缀（init 下发 grace.ticket_prefix，默认 "G1"）
 * @return false 时 error 为中文原因
 */
inline bool parseGraceTicket(const std::string& ticket, const std::string& expectedPrefix,
                             GraceTicket& out, std::string& error) {
    const std::string prefix = expectedPrefix.empty() ? std::string("G1") : expectedPrefix;
    if (ticket.size() < 10) { error = "票据格式错误"; return false; }

    const size_t first = ticket.find('.');
    const size_t second = (first == std::string::npos) ? std::string::npos
                                                       : ticket.find('.', first + 1);
    if (first == std::string::npos || second == std::string::npos) {
        error = "票据格式错误";
        return false;
    }
    const std::string gotPrefix = ticket.substr(0, first);
    if (gotPrefix != prefix) {
        error = "票据前缀不匹配（期望 " + prefix + "，实际 " + gotPrefix + "）";
        return false;
    }

    out = GraceTicket();
    out.prefix        = gotPrefix;
    out.payload_b64   = ticket.substr(first + 1, second - first - 1);
    out.signing_input = ticket.substr(0, second);          // "G1.<payload>"
    out.signature     = b64UrlDecode(ticket.substr(second + 1));
    if (out.payload_b64.empty() || out.signature.empty()) {
        error = "票据格式错误";
        return false;
    }

    const std::string payloadJson = b64UrlDecode(out.payload_b64);
    if (payloadJson.empty()) { error = "票据载荷解码失败"; return false; }

    const int version = json::findInt(payloadJson, "v", 1);
    if (version != 1) { error = "票据版本不支持：" + std::to_string(version); return false; }

    out.payload.version        = version;
    out.payload.user_id        = json::findInt(payloadJson, "u", 0);
    out.payload.machine_digest = json::findString(payloadJson, "m");
    out.payload.token_digest   = json::findString(payloadJson, "k");
    out.payload.vip_expire     = json::findInt64(payloadJson, "e", 0);
    out.payload.issued_at      = json::findInt64(payloadJson, "i", 0);
    out.payload.until          = json::findInt64(payloadJson, "g", 0);
    out.payload.duration       = json::findInt64(payloadJson, "d", 0);

    if (out.payload.until <= 0) { error = "票据载荷缺少宽限截止时间"; return false; }
    return true;
}

/**
 * 完整校验票据（验签 + 绑定校验 + 有效期）。
 *
 * @param publicKey  服务端验签公钥（PEM）；为空 → OfflineError::Disabled
 * @param prefix     票据前缀（"G1"）
 * @param machineId  本机机器码（用于比对 m 摘要）
 * @param token      当前会话令牌（用于比对 k 摘要）
 * @param nowUnix    校验时刻（0 = 取当前时间；自测时可指定）
 */
NEBULA_MUST_CHECK inline GraceResult verifyGraceTicket(const std::string& ticket,
                                                       const std::string& publicKey,
                                                       const std::string& prefix,
                                                       const std::string& machineId,
                                                       const std::string& token,
                                                       int64_t nowUnix = 0) {
    GraceResult result;
    if (publicKey.empty()) {
        result.code = OfflineError::Disabled;
        result.msg  = offlineErrorText(result.code);
        return result;
    }

    GraceTicket parsed;
    std::string error;
    if (!parseGraceTicket(ticket, prefix, parsed, error)) {
        result.code = OfflineError::Format;
        result.msg  = error;
        return result;
    }

    // ① 验签（ES256 / RS256 自动识别；签名对象是 "G1.<payload-b64url>" 原文）
    if (!crypto::verifySignature(publicKey, parsed.signing_input, parsed.signature)) {
        result.code = OfflineError::Signature;
        result.msg  = offlineErrorText(result.code);
        return result;
    }

    // ② 机器码摘要
    if (!parsed.payload.machine_digest.empty()
        && graceDigest(machineId) != parsed.payload.machine_digest) {
        result.code = OfflineError::Binding;
        result.msg  = "票据与当前机器不匹配";
        return result;
    }

    // ③ 会话令牌摘要（票据只对本次登录有效）
    if (!parsed.payload.token_digest.empty()) {
        if (token.empty() || graceDigest(token) != parsed.payload.token_digest) {
            result.code = OfflineError::Binding;
            result.msg  = "票据与当前会话不匹配";
            return result;
        }
    }

    // ④ 有效期（容忍时钟偏差）
    const int64_t now = nowUnix > 0 ? nowUnix : nowSeconds();
    if (now > parsed.payload.until + kClockSkewSeconds) {
        result.code = OfflineError::Expired;
        result.msg  = offlineErrorText(result.code);
        return result;
    }

    result.ok                 = true;
    result.code               = OfflineError::Ok;
    result.msg                = "ok";
    result.until_ts           = parsed.payload.until;
    result.remain_sec         = parsed.payload.until > now ? (int)(parsed.payload.until - now) : 0;
    result.payload_userid     = parsed.payload.user_id;
    result.payload_vip_expire = parsed.payload.vip_expire;
    return result;
}

} // namespace nebula

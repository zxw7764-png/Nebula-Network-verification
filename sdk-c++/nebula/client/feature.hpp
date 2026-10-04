#pragma once
// ============================================================================
// Nebula SDK · 功能密钥数据包（可选）
// ----------------------------------------------------------------------------
// 「下发解密密钥，不下发验证结果」：
//   接入方把主功能依赖的核心数据（配置表 / 参数 / 关键资源）用功能密钥加密后
//   随程序分发；服务端只在 **login 成功** 后才下发 feature_key（登录失败 /
//   被踢 / 会话过期后重新变回密文）。patch 掉登录判定分支也拿不到密钥 ——
//   密钥不在客户端二进制里，破解者无法让密文数据解出来。
//
// 数据包格式 v1（encrypt-then-MAC，与通信信封同族原语）：
//   NF1.<b64( iv[16] + AES-256-CBC(plain) )>.<hex( HMAC-SHA256 )>
//     aesKey = SHA256(feature_key "|nebula-feature-aes")
//     macKey = SHA256(feature_key "|nebula-feature-mac")   —— 域分离派生
//     HMAC 对象 = "NF1." + 第一段原文；先验签后解密，篡改必然被拒。
//
// 使用流程：
//   ① 后台「软件管理」为本软件设置功能密钥（可一键随机生成）；
//   ② 开发期：nebula::feature::seal(核心数据, 功能密钥) 得到数据包，随程序分发；
//   ③ 运行期：login 成功 → lr.feature_key → nebula::feature::open(数据包, lr.feature_key)
//     解出核心数据，主功能依赖它才能工作。
// ============================================================================

#include "envelope.hpp"   // 复用 crypto 原语 / base64 / 恒定时间比较

namespace nebula {
namespace feature {

/** 派生 AES-256 密钥（域分离，避免同一密钥多用途） */
NEBULA_MUST_CHECK inline std::string deriveAesKey(const std::string& featureKey) {
    return crypto::sha256(featureKey + "|nebula-feature-aes");
}

/** 派生 HMAC-SHA256 密钥（域分离） */
NEBULA_MUST_CHECK inline std::string deriveMacKey(const std::string& featureKey) {
    return crypto::sha256(featureKey + "|nebula-feature-mac");
}

/**
 * 加密核心数据为功能数据包（开发期使用）。
 * @param plain      核心数据原文（任意字节，非空）
 * @param featureKey 功能密钥（后台「软件管理」设置的那串）
 * @return 数据包字符串；失败返回空串（密钥为空 / 随机源不可用）
 */
NEBULA_MUST_CHECK inline std::string seal(const std::string& plain, const std::string& featureKey) {
    if (featureKey.empty() || plain.empty()) return {};

    std::string iv(16, '\0');
    if (!crypto::randomBytes(reinterpret_cast<unsigned char*>(&iv[0]), 16)) return {};
    const std::string blob = crypto::aes256CbcEncrypt(deriveAesKey(featureKey), iv, plain);
    if (blob.empty()) return {};

    const std::string p1 = b64Encode(blob);
    if (p1.empty()) return {};
    const std::string mac = crypto::hmacSha256Hex(deriveMacKey(featureKey), "NF1." + p1);
    if (mac.empty()) return {};
    return "NF1." + p1 + "." + mac;
}

/**
 * 解开功能数据包（运行期使用；login 成功拿到 lr.feature_key 后调用）。
 * @param pack       seal() 生成的数据包（随程序分发的那份）
 * @param featureKey 功能密钥（lr.feature_key）
 * @param out        [出] 解密后的核心数据
 * @param error      [出] 失败原因（中文，可直接展示）
 */
NEBULA_MUST_CHECK inline bool open(const std::string& pack, const std::string& featureKey,
                                   std::string& out, std::string& error) {
    out.clear();
    if (pack.size() < 10 || featureKey.empty()) { error = "数据包或功能密钥为空"; return false; }
    if (pack.compare(0, 4, "NF1.") != 0) { error = "数据包版本不匹配"; return false; }

    const size_t d1 = pack.find('.', 4);
    if (d1 == std::string::npos || pack.find('.', d1 + 1) != std::string::npos) {
        error = "数据包格式错误"; return false;
    }
    const std::string p1  = pack.substr(4, d1 - 4);
    const std::string mac = pack.substr(d1 + 1);

    // ① 先验签后解密（encrypt-then-MAC）：篡改密文 / 密钥错误都在此被拒
    const std::string expected = crypto::hmacSha256Hex(deriveMacKey(featureKey), "NF1." + p1);
    if (mac.empty() || expected.empty() || !constantTimeEquals(expected, mac)) {
        error = "数据包校验失败（被篡改或功能密钥错误）"; return false;
    }

    // ② 解密（blob 自带前置 IV）
    out = crypto::aes256CbcDecrypt(deriveAesKey(featureKey), b64Decode(p1));
    if (out.empty()) { error = "数据包解密失败"; return false; }
    return true;
}

} // namespace feature
} // namespace nebula

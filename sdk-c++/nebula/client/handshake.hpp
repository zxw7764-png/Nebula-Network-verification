#pragma once
// ============================================================================
// Nebula SDK · 3.1 会话握手（ECDH P-256 + AES-256-GCM）
// ----------------------------------------------------------------------------
// 与服务端 lib/Handshake.php 严格对齐。协议：
//
//   ① handshake（明文 JSON，无对称信封）
//      请求  { app_key, eph_pub(65B点b64), nc(16B b64), ts, mhash }
//      响应  { proto:31, sid, eph_pub, ns, ts_s, sign(ES256), sig_kid, sig_algo }
//            sign 对象 = sid|eph_pub|ns|nc|ts_s（绑定双方 nonce，防重放/防 MITM 换钥）
//   ② 派生会话密钥
//      shared = ECDH(eph_priv_C, eph_pub_S)          （32 字节大端 X）
//      sk_enc     = HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-enc")
//      sk_mac     = HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-mac")
//      sk_enc_rsp = HKDF-SHA256(sk_enc,  salt=零,   info="nebula31-enc-rsp")  ← 响应方向专用
//      （2026-10-03 审计修复：请求/响应密钥分离，消除 GCM nonce 跨方向重用）
//   ③ 业务请求（每个 action，含 init）
//      { proto:31, sid, seq, t, data, mac, app_key }
//        data = b64( iv[12] + AES-256-GCM(业务JSON) + tag[16] )
//        iv   = iv_prefix(4, 来自握手) ‖ seq 大端(8)   —— seq 严格递增，IV 永不重复
//        mac  = hex(HMAC(sk_mac, sid|seq|t|sha256(data)))
//      响应  { proto:31, sid, data(GCM), [sig], code }
//            sig = 服务端长期 ES256 私钥对 data|sid 签名（pin 公钥验签防伪造服务器）
//
// 安全性质：客户端零静态对称机密；堆扫描只能拿到当次会话临时密钥。
// 会话失效（服务端 5002/过期）时由 Client 清空本对象并重新握手。
// ============================================================================

#include "../core/crypto.hpp"
#include "../core/text.hpp"
#include "envelope.hpp"

#include <functional>
#include <mutex>
#include <ctime>
#include <cstdint>

namespace nebula {
namespace s31 {

/** 当前时间（秒）；测试可替换 */
inline int64_t nowSeconds() { return (int64_t)::time(nullptr); }

/**
 * 3.1 会话（非线程安全 —— Client 侧用 state_mutex_ 保护）。
 * 持有的 sk_enc/sk_mac 是进程生命周期内的临时密钥：换机不可复用（会话在服务端），
 * 进程重启重新握手，泄露窗口 = 会话剩余时长。
 */
class Session31 {
public:
    bool active() const { return established_; }
    const std::string& sid() const { return sid_; }

    /** 会话失效（服务端拒绝 sid / seq 冲突 / 过期） */
    void clear() {
        established_ = false;
        sid_.clear();
        sk_enc_.clear();
        sk_mac_.clear();
        sk_enc_rsp_.clear();
        iv_prefix_.clear();
        seq_ = 0;
        // 尽快擦除内存中的临时密钥
        secureZero(sk_enc_);
        secureZero(sk_mac_);
        secureZero(sk_enc_rsp_);
    }

    /** 握手请求体（明文 JSON） */
    NEBULA_MUST_CHECK std::string buildHandshakeRequest(const std::string& appKey,
                                                        const std::string& machineId) {
        keypair_ = crypto::ecdhGenerateKeyPair();
        if (!keypair_.valid()) return {};

        unsigned char ncBuf[16];
        if (!crypto::randomBytes(ncBuf, sizeof(ncBuf))) return {};
        nc_.assign(reinterpret_cast<const char*>(ncBuf), sizeof(ncBuf));

        // mhash：不传机器码原值，只传哈希（防撞库；服务端仅用于「同机单会话」清理）
        const std::string mhash = crypto::sha256Hex(machineId);

        std::string body = "{";
        body += json::pair("app_key", json::quote(appKey));
        body += "," + json::pair("eph_pub", json::quote(b64Encode(keypair_.point)));
        body += "," + json::pair("nc", json::quote(b64Encode(nc_)));
        body += "," + json::pair("ts", json::number(nowSeconds()));
        body += "," + json::pair("mhash", json::quote(mhash));
        body += "}";
        return body;
    }

    /**
     * 处理握手响应（明文 JSON），验 ES256 签名并派生会话密钥。
     * @return Ok 成功；否则返回错误
     */
    NEBULA_MUST_CHECK Error consumeHandshakeResponse(const std::string& body,
                                                     const std::string& appKey,
                                                     const std::string& respSignPubKey,
                                                     bool requireSignature,
                                                     std::string& errMsg) {
        // 非 0 业务码（限流/时间戳/签名失败等）按普通失败处理，可重试
        const int code = json::findInt(body, "code", -1);
        if (code != 0) {
            errMsg = "握手失败：" + json::findString(body, "msg");
            return Error::Envelope;
        }
        if (json::findInt(body, "proto", 0) != 31) {
            errMsg = "服务端握手响应协议版本异常";
            return Error::Protocol;
        }

        sid_      = json::findString(body, "sid");
        const std::string serverEph = json::findString(body, "eph_pub");
        const std::string nsB64     = json::findString(body, "ns");
        const std::string signB64   = json::findString(body, "sign");
        const int64_t tsS           = json::findInt(body, "ts_s", 0);
        if (sid_.size() != 32 || serverEph.empty() || nsB64.empty() || signB64.empty()) {
            errMsg = "握手响应字段缺失";
            return Error::Envelope;
        }

        // ① 服务端长期私钥签名校验（防伪造服务器 / MITM 换钥）—— 必须先于密钥派生
        // 注意：不能用 signed 作变量名（C++ 关键字）
        const std::string signedStr = sid_ + "|" + serverEph + "|" + nsB64 + "|" + b64Encode(nc_) + "|" + std::to_string(tsS);
        if (requireSignature) {
            if (respSignPubKey.empty()) {
                errMsg = "未配置响应签名公钥，无法校验握手响应";
                return Error::Config;
            }
            const std::string sig = b64Decode(signB64);
            if (sig.empty() || !crypto::verifySignature(respSignPubKey, signedStr, sig)) {
                errMsg = "握手响应签名校验失败（可能连到了伪造服务器）";
                return Error::Envelope;
            }
        }

        // ② 服务器时间偏差记录（供后续请求时间戳校准，容忍服务端 ±5s）
        clock_offset_ms_ = (tsS - nowSeconds()) * 1000;

        // ③ 派生会话密钥
        const std::string peerPoint = b64Decode(serverEph);
        const std::string ns        = b64Decode(nsB64);
        if (peerPoint.size() != 65 || peerPoint[0] != '\x04' || ns.size() != 16) {
            errMsg = "握手响应公钥/nonce 格式错误";
            return Error::Envelope;
        }
        const std::string shared = crypto::ecdhSharedSecret(keypair_.privateBlob, peerPoint);
        if (shared.size() != 32) {
            errMsg = "ECDH 协商失败（需要 Windows 10 及以上）";
            return Error::Crypto;
        }
        const std::string salt = nc_ + ns;
        sk_enc_ = crypto::hkdfSha256(shared, salt, "nebula31-enc", 32);
        sk_mac_ = crypto::hkdfSha256(shared, salt, "nebula31-mac", 32);
        // 响应方向独立加密密钥（2026-10-03 审计修复）：请求/响应不再共用
        // 同一把 sk_enc + 同一个 IV，消除 GCM nonce 跨方向重用。
        // 服务端同款派生：hash_hkdf('sha256', sk_enc, 32, 'nebula31-enc-rsp')
        sk_enc_rsp_ = crypto::hkdfSha256(sk_enc_, "", "nebula31-enc-rsp", 32);
        if (sk_enc_.size() != 32 || sk_mac_.size() != 32 || sk_enc_rsp_.size() != 32) {
            errMsg = "会话密钥派生失败";
            return Error::Crypto;
        }

        // GCM IV = 12 字节：前 4 字节 = SHA256(nc‖ns)[0..4)，后 8 字节 = seq 大端。
        // 两端可独立算出前缀（无需握手响应下发），全局唯一性由 seq 单调递增保证。
        iv_prefix_ = crypto::sha256(nc_ + ns).substr(0, 4);

        // 擦除握手材料
        secureZero(keypair_.privateBlob);
        keypair_ = {};
        established_ = true;
        seq_ = 0;
        return Error::Ok;
    }

    /** 构造 3.1 业务请求信封 */
    NEBULA_MUST_CHECK std::string buildRequestEnvelope(const std::string& payloadJson,
                                                       int64_t timestamp,
                                                       const std::string& appKey) {
        if (!established_) return {};
        ++seq_;
        if (seq_ <= 0) { clear(); return {}; }   // 溢出保护：重握手

        const std::string iv = iv_prefix_ + packSeq(seq_);
        std::string cipher, tag;
        if (!crypto::aes256GcmEncrypt(sk_enc_, iv, payloadJson, cipher, tag)) return {};

        // 协议：data = b64( iv[12] + 密文 + tag[16] )，IV 必须前置（服务端按前
        // 12 字节取 IV；漏发 IV 会导致服务端 GCM 认证失败）
        const std::string data = b64Encode(iv + cipher + tag);
        if (data.empty()) return {};
        const std::string mac = crypto::hmacSha256Hex(
            sk_mac_, sid_ + "|" + std::to_string(seq_) + "|" + std::to_string(timestamp) + "|" + crypto::sha256Hex(data));

        std::string envelope = "{";
        envelope += json::pair("proto", json::number(31));
        envelope += "," + json::pair("sid", json::quote(sid_));
        envelope += "," + json::pair("seq", json::number(seq_));
        envelope += "," + json::pair("t", json::number(timestamp));
        envelope += "," + json::pair("data", json::quote(data));
        envelope += "," + json::pair("mac", json::quote(mac));
        envelope += "," + json::pair("app_key", json::quote(appKey));
        envelope += "}";
        return envelope;
    }

    /** 解开 3.1 业务响应（GCM + ES256 验签） */
    NEBULA_MUST_CHECK OpenedResponse openResponse(const std::string& body,
                                                  const std::string& respSignPubKey,
                                                  bool requireSignature) {
        OpenedResponse out;
        if (!established_) {
            out.status = Error::Protocol;
            out.msg    = "3.1 会话未建立";
            return out;
        }

        const std::string data = json::findString(body, "data");
        if (data.empty() || json::findInt(body, "proto", 0) != 31) {
            out.status = Error::Envelope;
            out.msg    = "响应不是 3.1 信封";
            return out;
        }

        // ① 服务端长期私钥签名（签名对象 data|sid）—— 先验签后解密
        if (requireSignature) {
            const std::string sig = b64Decode(json::findString(body, "sig"));
            if (sig.empty() || !crypto::verifySignature(respSignPubKey, data + "|" + sid_, sig)) {
                out.status = Error::Envelope;
                out.msg    = "响应签名校验失败（可能连到了伪造服务器）";
                return out;
            }
        }

        // ② GCM 解密（响应方向独立密钥 sk_enc_rsp_，IV 用请求的 seq 自算防重放）
        const std::string raw = b64Decode(data);
        if (raw.size() < 12 + 16 + 1) {
            out.status = Error::Envelope;
            out.msg    = "响应密文过短";
            return out;
        }
        const std::string iv  = iv_prefix_ + packSeq(seq_);
        const std::string tag = raw.substr(raw.size() - 16);
        const std::string ct  = raw.substr(12, raw.size() - 12 - 16);
        std::string plain;
        if (!crypto::aes256GcmDecrypt(sk_enc_rsp_, iv, ct, tag, plain) || plain.empty()) {
            out.status = Error::Envelope;
            out.msg    = "响应解密失败（GCM 认证未通过）";
            return out;
        }

        out.status       = Error::Ok;
        out.plain        = plain;
        out.businessCode = json::findInt(plain, "code", 0);
        out.msg          = json::findString(plain, "msg");
        return out;
    }

    /// 服务端时钟校准偏移（毫秒）；请求时间戳建议 = nowSeconds() + offset/1000
    int64_t clockOffsetMs() const { return clock_offset_ms_; }

private:
    /** seq → 8 字节大端 */
    static std::string packSeq(uint64_t seq) {
        std::string out(8, '\0');
        for (int i = 7; i >= 0; --i) {
            out[(size_t)i] = (char)(seq & 0xFF);
            seq >>= 8;
        }
        return out;
    }

    /** 敏感字节串擦除（防编译器优化掉 memset，用 volatile 写入） */
    static void secureZero(std::string& s) {
        volatile char* p = reinterpret_cast<volatile char*>(s.empty() ? nullptr : &s[0]);
        if (p) {
            for (size_t i = 0; i < s.size(); ++i) p[i] = 0;
        }
        s.clear();
    }

    bool established_ = false;
    std::string sid_;
    std::string sk_enc_;
    std::string sk_mac_;
    std::string sk_enc_rsp_;
    std::string iv_prefix_;
    std::string nc_;
    uint64_t seq_ = 0;
    int64_t clock_offset_ms_ = 0;
    crypto::EcKeyPair keypair_;
};

} // namespace s31
} // namespace nebula

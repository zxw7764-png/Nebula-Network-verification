#pragma once
// ============================================================================
// Nebula SDK · 密码学桥接（BCrypt + CryptoAPI）
// ----------------------------------------------------------------------------
// 与 lib/Crypto.php、lib/Grace.php **逐字对齐**；改动前务必同时看服务端实现：
//
//   AES-256-CBC + PKCS#7    key = SHA256(AES_KEY) 前 32 字节
//                          IV  = MD5(AES_KEY) 前 16 字节（请求侧固定；
//                                响应侧由服务端随机生成后前置在密文里）
//   HMAC-SHA256             sign = hex(hmac(data|t|n, salt))
//   响应 / 票据签名          ES256（ECDSA P-256 + SHA-256，DER 编码）
//                          RS256（RSA-2048 + SHA-256，PKCS#1 v1.5）
//                          —— 服务端优先 ES256，环境不支持 EC 时回落 RS256，
//                             所以客户端**两种都必须支持**。旧版 SDK 只实现了 ES256，
//                             在 RS256 环境下所有请求都会失败，务必保留本能力。
//
// 约定：所有函数不抛异常，失败返回空串 / false，由调用方转成 Error 码。
// ============================================================================

#include "error.hpp"
#include "text.hpp"
#include <algorithm>    // std::reverse（ECDH 共享密钥端序转换）

// 老版 Windows SDK 的 bcrypt.h 没有 RAW_SECRET KDF 宏（Win8 起才内置），手动补齐：
// 值固定为 L"TRUNCATE" —— 表示原样输出 ECDH 共享密钥的 X 坐标（小端序）。
#ifndef BCRYPT_KDF_RAW_SECRET
#define BCRYPT_KDF_RAW_SECRET (L"TRUNCATE")
#endif

namespace nebula {
namespace crypto {

/** NTSTATUS 成功判定（bcrypt.h 的 BCRYPT_SUCCESS 宏已在 config.hpp 取消） */
NEBULA_MUST_CHECK inline bool isOk(NTSTATUS status) { return status >= 0; }

/** 支持的签名算法 */
enum class SignatureAlgo { Auto = 0, Es256, Rs256 };

// ---------------------------------------------------------------------------
// 句柄 RAII（旧实现靠手写 Release，漏一行就是句柄泄漏）
// ---------------------------------------------------------------------------
namespace detail {

struct AlgHandle {
    BCRYPT_ALG_HANDLE h = nullptr;
    AlgHandle() = default;
    AlgHandle(const AlgHandle&) = delete;
    AlgHandle& operator=(const AlgHandle&) = delete;
    ~AlgHandle() { if (h) ::BCryptCloseAlgorithmProvider(h, 0); }
    bool open(LPCWSTR alg, ULONG flags = 0) {
        return isOk(::BCryptOpenAlgorithmProvider(&h, alg, nullptr, flags));
    }
    operator BCRYPT_ALG_HANDLE() const { return h; }
};

struct BcryptHashHandle {
    BCRYPT_HASH_HANDLE h = nullptr;
    BcryptHashHandle() = default;
    BcryptHashHandle(const BcryptHashHandle&) = delete;
    BcryptHashHandle& operator=(const BcryptHashHandle&) = delete;
    ~BcryptHashHandle() { if (h) ::BCryptDestroyHash(h); }
};

struct BcryptKeyHandle {
    BCRYPT_KEY_HANDLE h = nullptr;
    BcryptKeyHandle() = default;
    BcryptKeyHandle(const BcryptKeyHandle&) = delete;
    BcryptKeyHandle& operator=(const BcryptKeyHandle&) = delete;
    ~BcryptKeyHandle() { if (h) ::BCryptDestroyKey(h); }
};

// 说明：RSA 验签不再使用 CryptoAPI（见 verifyRsaSha256 的注释），
// 因此这里不再需要 HCRYPTPROV / HCRYPTKEY / HCRYPTHASH 的 RAII 包装。

/** PEM → DER（跳过 BEGIN/END 行，只对正文做 base64 解码） */
inline bool pemToDer(const std::string& pem, std::vector<BYTE>& der) {
    const size_t begin = pem.find("-----BEGIN");
    const size_t end   = pem.find("-----END");
    if (begin == std::string::npos || end == std::string::npos || end <= begin) return false;

    // 必须从 BEGIN 行的**行尾之后**开始取正文。若从 "-----BEGIN" 之后直接扫描，
    // 会把本行剩余的 "PUBLIC KEY"/"EC PRIVATE KEY" 这些**字母恰好都在 base64 字符集内**
    // 的标题文字一起吞进 base64 → 解出长度错误、内容错乱的 DER（实测 98 字节 / 3d404b…），
    // 后续 CryptDecodeObjectEx 必然失败。这是只有拿真实 PEM 才能暴露的坑。
    size_t p = pem.find('\n', begin);
    if (p == std::string::npos) return false;
    ++p;                                       // 跳过换行符，进入正文

    std::string b64;
    b64.reserve(end > p ? end - p : 0);
    for (size_t i = p; i < end; ++i) {
        const char c = pem[i];
        if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9')
            || c == '+' || c == '/' || c == '=') {
            b64 += c;
        }
    }
    if (b64.empty()) return false;

    DWORD need = 0;
    if (!::CryptStringToBinaryA(b64.c_str(), (DWORD)b64.size(), CRYPT_STRING_BASE64,
                                nullptr, &need, nullptr, nullptr) || need == 0) {
        return false;
    }
    der.assign((size_t)need, 0);
    if (!::CryptStringToBinaryA(b64.c_str(), (DWORD)b64.size(), CRYPT_STRING_BASE64,
                                der.data(), &need, nullptr, nullptr)) {
        return false;
    }
    der.resize(need);
    return true;
}

/**
 * 解码 SPKI DER 为 CERT_PUBLIC_KEY_INFO。
 * outBuf 必须比 pki 活得更久（pki 内部的指针指向 outBuf）。
 */
inline bool decodeSpki(const std::vector<BYTE>& spki, std::vector<BYTE>& outBuf,
                       CERT_PUBLIC_KEY_INFO*& pki) {
    const DWORD flags = X509_ASN_ENCODING | PKCS_7_ASN_ENCODING;
    DWORD cb = 0;
    if (!::CryptDecodeObjectEx(flags, X509_PUBLIC_KEY_INFO, spki.data(), (DWORD)spki.size(),
                               0, nullptr, nullptr, &cb) || cb == 0) {
        return false;
    }
    outBuf.assign((size_t)cb, 0);
    if (!::CryptDecodeObjectEx(flags, X509_PUBLIC_KEY_INFO, spki.data(), (DWORD)spki.size(),
                               0, nullptr, outBuf.data(), &cb)) {
        return false;
    }
    pki = reinterpret_cast<CERT_PUBLIC_KEY_INFO*>(outBuf.data());
    return true;
}

/** DER OID 参数是否等于指定的点分十进制 OID（用于识别 EC 曲线） */
inline bool derOidEquals(const BYTE* der, size_t len, const char* dotted) {
    if (!der || len < 2 || der[0] != 0x06) return false;
    const size_t body = der[1];                 // 本用途的 OID 都是短形式（<128 字节）
    if (body + 2 > len) return false;

    // 点分十进制 → DER OID 内容（首字节 = 40*a + b，其余节点 base-128 大端）
    std::vector<unsigned long long> nodes;
    unsigned long long cur = 0;
    bool hasDigit = false;
    for (const char* p = dotted;; ++p) {
        if (*p >= '0' && *p <= '9') {
            cur = cur * 10 + (unsigned long long)(*p - '0');
            hasDigit = true;
        } else if (*p == '.' || *p == '\0') {
            if (!hasDigit) return false;
            nodes.push_back(cur);
            cur = 0;
            hasDigit = false;
            if (*p == '\0') break;
        } else {
            return false;
        }
    }
    if (nodes.size() < 2) return false;

    std::vector<BYTE> want;
    want.reserve(body);
    want.push_back((BYTE)(nodes[0] * 40 + nodes[1]));
    for (size_t i = 2; i < nodes.size(); ++i) {
        BYTE tmp[9] = {};
        size_t n = 0;
        unsigned long long v = nodes[i];
        do { tmp[n++] = (BYTE)(v & 0x7F); v >>= 7; } while (v);
        for (size_t k = n; k > 0; --k) want.push_back((BYTE)(tmp[k - 1] | (k > 1 ? 0x80 : 0x00)));
    }
    if (want.size() != body) return false;
    return ::memcmp(der + 2, want.data(), body) == 0;
}

/**
 * DER ECDSA 签名 SEQUENCE{INTEGER r, INTEGER s} → bcrypt 需要的 r[32]||s[32]。
 * 已是 64 字节的 P1363 格式则原样返回。
 */
inline std::string derSigToP1363(const std::string& sig) {
    if (sig.size() == 64) return sig;
    if (sig.size() < 8 || (unsigned char)sig[0] != 0x30) return {};

    size_t i = 1;
    const unsigned char l0 = (unsigned char)sig[i];
    if (l0 & 0x80) {
        const size_t n = l0 & 0x7F;
        if (n == 0 || n > 4 || i + 1 + n > sig.size()) return {};
        i += 1 + n;                       // 跳过长形式长度标记
    } else {
        ++i;
    }

    std::string raw(64, '\0');
    for (int part = 0; part < 2; ++part) {
        if (i + 2 > sig.size() || (unsigned char)sig[i] != 0x02) return {};
        size_t len = (unsigned char)sig[i + 1];
        i += 2;
        if (len > 33 || i + len > sig.size()) return {};
        while (len > 0 && (unsigned char)sig[i] == 0x00) { ++i; --len; }   // 去掉正数前导 0
        if (len > 32) return {};
        ::memcpy(&raw[(size_t)part * 32 + (32 - len)], sig.data() + i, len);
        i += len;
    }
    return raw;
}

} // namespace detail

// ===========================================================================
// 摘要 / 签名
// ===========================================================================
/** SHA-256（32 字节原始摘要） */
NEBULA_MUST_CHECK inline std::string sha256(const std::string& data) {
    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_SHA256_ALGORITHM)) return {};
    detail::BcryptHashHandle hash;
    if (!isOk(::BCryptCreateHash(alg, &hash.h, nullptr, 0, nullptr, 0, 0))) return {};
    if (!isOk(::BCryptHashData(hash.h, reinterpret_cast<PUCHAR>(const_cast<char*>(data.data())),
                              (ULONG)data.size(), 0))) {
        return {};
    }
    std::string out(32, '\0');
    if (!isOk(::BCryptFinishHash(hash.h, reinterpret_cast<PUCHAR>(&out[0]), 32, 0))) return {};
    return out;
}

/** 摘要的小写 hex（64 字符） */
NEBULA_MUST_CHECK inline std::string sha256Hex(const std::string& data) {
    return bytesToHex(sha256(data));
}

/** MD5（16 字节原始摘要；仅用于按协议派生固定 IV 与硬件指纹哈希） */
NEBULA_MUST_CHECK inline std::string md5(const std::string& data) {
    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_MD5_ALGORITHM)) return {};
    detail::BcryptHashHandle hash;
    if (!isOk(::BCryptCreateHash(alg, &hash.h, nullptr, 0, nullptr, 0, 0))) return {};
    if (!isOk(::BCryptHashData(hash.h, reinterpret_cast<PUCHAR>(const_cast<char*>(data.data())),
                              (ULONG)data.size(), 0))) {
        return {};
    }
    std::string out(16, '\0');
    if (!isOk(::BCryptFinishHash(hash.h, reinterpret_cast<PUCHAR>(&out[0]), 16, 0))) return {};
    return out;
}

/** HMAC-SHA256（32 字节原始值）；key 为空时退化为纯 SHA-256（兼容旧写法） */
NEBULA_MUST_CHECK inline std::string hmacSha256(const std::string& key, const std::string& data) {
    if (key.empty()) return sha256(data);
    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_SHA256_ALGORITHM, BCRYPT_ALG_HANDLE_HMAC_FLAG)) return {};
    detail::BcryptHashHandle hash;
    if (!isOk(::BCryptCreateHash(alg, &hash.h, nullptr, 0,
                                 reinterpret_cast<PUCHAR>(const_cast<char*>(key.data())),
                                 (ULONG)key.size(), 0))) {
        return {};
    }
    if (!isOk(::BCryptHashData(hash.h, reinterpret_cast<PUCHAR>(const_cast<char*>(data.data())),
                              (ULONG)data.size(), 0))) {
        return {};
    }
    std::string out(32, '\0');
    if (!isOk(::BCryptFinishHash(hash.h, reinterpret_cast<PUCHAR>(&out[0]), 32, 0))) return {};
    return out;
}

/** HMAC-SHA256 的小写 hex（即协议里的 sign 字段） */
NEBULA_MUST_CHECK inline std::string hmacSha256Hex(const std::string& key, const std::string& data) {
    return bytesToHex(hmacSha256(key, data));
}

// ===========================================================================
// 对称加密
// ===========================================================================
/** 协议派生：AES-256 密钥 = SHA256(AES_KEY) */
NEBULA_MUST_CHECK inline std::string deriveKey32(const std::string& aesKey) {
    std::string k = sha256(aesKey);
    if (k.size() > 32) k.resize(32);
    return k;
}

/** 协议派生：固定 IV = MD5(AES_KEY) 前 16 字节 */
NEBULA_MUST_CHECK inline std::string deriveIv16(const std::string& aesKey) {
    std::string iv = md5(aesKey);
    if (iv.size() > 16) iv.resize(16);
    return iv;
}

/** PKCS#7 去填充（就地修改，失败返回 false） */
inline bool unpadPkcs7(std::string& data) {
    if (data.empty()) return false;
    const unsigned char pad = (unsigned char)data[data.size() - 1];
    if (pad < 1 || pad > 16 || (size_t)pad > data.size()) return false;
    for (unsigned char i = 0; i < pad; ++i) {
        if ((unsigned char)data[data.size() - 1 - i] != pad) return false;
    }
    data.resize(data.size() - pad);
    return true;
}

/**
 * AES-256-CBC 加密，返回 `iv[16] + 密文`（PKCS#7 填充），即协议里的 data 解码前形态。
 * key32 不足 32 字节 / iv 不足 16 字节时返回空串。
 */
NEBULA_MUST_CHECK inline std::string aes256CbcEncrypt(const std::string& key32,
                                                      const std::string& iv,
                                                      const std::string& plain) {
    if (key32.size() != 32 || iv.size() != 16) return {};

    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_AES_ALGORITHM)) return {};
    if (!isOk(::BCryptSetProperty(alg, BCRYPT_CHAINING_MODE,
                                  reinterpret_cast<PUCHAR>(const_cast<wchar_t*>(L"ChainingModeCBC")),
                                  (ULONG)sizeof(L"ChainingModeCBC"), 0))) {
        return {};
    }

    DWORD objLen = 0, cb = 0;
    if (!isOk(::BCryptGetProperty(alg, BCRYPT_OBJECT_LENGTH,
                                  reinterpret_cast<PUCHAR>(&objLen), sizeof(objLen), &cb, 0))) {
        objLen = 0;
    }
    std::vector<BYTE> obj(objLen ? objLen : 1);

    detail::BcryptKeyHandle key;
    if (!isOk(::BCryptGenerateSymmetricKey(alg, &key.h, obj.data(), objLen,
                                           reinterpret_cast<PUCHAR>(const_cast<char*>(key32.data())),
                                           32, 0))) {
        return {};
    }

    const size_t pad = 16 - (plain.size() % 16);
    std::string data = plain;
    data.append(pad, (char)pad);

    std::string ivCopy = iv;   // BCrypt 会就地修改 IV
    std::string out(data.size(), '\0');
    ULONG acted = 0;
    if (!isOk(::BCryptEncrypt(key.h, reinterpret_cast<PUCHAR>(&data[0]), (ULONG)data.size(), nullptr,
                              reinterpret_cast<PUCHAR>(&ivCopy[0]), 16,
                              reinterpret_cast<PUCHAR>(&out[0]), (ULONG)out.size(), &acted, 0))) {
        return {};
    }
    out.resize(acted);
    return iv + out;   // 协议：IV 前置
}

/** AES-256-CBC 解密，输入 `iv[16] + 密文`，失败返回空串 */
NEBULA_MUST_CHECK inline std::string aes256CbcDecrypt(const std::string& key32,
                                                      const std::string& blob) {
    if (key32.size() != 32) return {};
    if (blob.size() <= 16 || (blob.size() - 16) % 16 != 0) return {};

    const std::string iv = blob.substr(0, 16);
    const std::string ct = blob.substr(16);

    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_AES_ALGORITHM)) return {};
    if (!isOk(::BCryptSetProperty(alg, BCRYPT_CHAINING_MODE,
                                  reinterpret_cast<PUCHAR>(const_cast<wchar_t*>(L"ChainingModeCBC")),
                                  (ULONG)sizeof(L"ChainingModeCBC"), 0))) {
        return {};
    }

    DWORD objLen = 0, cb = 0;
    if (!isOk(::BCryptGetProperty(alg, BCRYPT_OBJECT_LENGTH,
                                  reinterpret_cast<PUCHAR>(&objLen), sizeof(objLen), &cb, 0))) {
        objLen = 0;
    }
    std::vector<BYTE> obj(objLen ? objLen : 1);

    detail::BcryptKeyHandle key;
    if (!isOk(::BCryptGenerateSymmetricKey(alg, &key.h, obj.data(), objLen,
                                           reinterpret_cast<PUCHAR>(const_cast<char*>(key32.data())),
                                           32, 0))) {
        return {};
    }

    std::string ivCopy = iv;
    std::string out(ct.size(), '\0');
    ULONG acted = 0;
    if (!isOk(::BCryptDecrypt(key.h, reinterpret_cast<PUCHAR>(const_cast<char*>(ct.data())),
                              (ULONG)ct.size(), nullptr,
                              reinterpret_cast<PUCHAR>(&ivCopy[0]), 16,
                              reinterpret_cast<PUCHAR>(&out[0]), (ULONG)out.size(), &acted, 0))) {
        return {};
    }
    out.resize(acted);
    return unpadPkcs7(out) ? out : std::string();
}

// ===========================================================================
// 非对称验签（ES256 / RS256）
// ===========================================================================
struct PublicKeyInfo {
    enum class Type { Unknown = 0, EcP256, Rsa };

    Type type = Type::Unknown;
    std::vector<BYTE> spkiDer;   ///< SPKI DER（自持，可安全拷贝）
    std::vector<BYTE> point;     ///< EC P-256：65 字节非压缩点 0x04||X||Y
    std::string error;           ///< 失败原因（type == Unknown 时有效）

    NEBULA_MUST_CHECK bool valid() const { return type != Type::Unknown; }
};

/** 解析 PEM 公钥（自动识别 EC P-256 / RSA） */
NEBULA_MUST_CHECK inline PublicKeyInfo parsePublicKey(const std::string& pem) {
    PublicKeyInfo out;
    if (pem.empty()) { out.error = "公钥为空"; return out; }
    if (!detail::pemToDer(pem, out.spkiDer)) { out.error = "公钥不是合法的 PEM"; return out; }

    std::vector<BYTE> buf;
    CERT_PUBLIC_KEY_INFO* pki = nullptr;
    if (!detail::decodeSpki(out.spkiDer, buf, pki) || !pki || !pki->Algorithm.pszObjId) {
        out.error = "公钥 SPKI 结构解析失败";
        out.spkiDer.clear();
        return out;
    }

    const char* oid = pki->Algorithm.pszObjId;
    if (::strcmp(oid, "1.2.840.10045.2.1") == 0) {            // id-ecPublicKey
        // 曲线必须是 P-256（服务端固定 prime256v1）
        const CRYPT_DATA_BLOB& params = pki->Algorithm.Parameters;
        const bool p256 = detail::derOidEquals(params.pbData, (size_t)params.cbData, "1.2.840.10045.3.1.7")
                       || detail::derOidEquals(params.pbData, (size_t)params.cbData, "1.3.132.0.34");
        if (!p256) { out.error = "仅支持 P-256 曲线（服务端固定 ES256）"; return out; }
        if (pki->PublicKey.cbData != 65 || !pki->PublicKey.pbData || pki->PublicKey.pbData[0] != 0x04) {
            out.error = "仅支持 65 字节非压缩格式的 P-256 公钥";
            return out;
        }
        out.point.assign(pki->PublicKey.pbData, pki->PublicKey.pbData + 65);
        out.type = PublicKeyInfo::Type::EcP256;
        return out;
    }
    if (::strcmp(oid, "1.2.840.113549.1.1.1") == 0) {         // rsaEncryption
        out.type = PublicKeyInfo::Type::Rsa;
        return out;
    }
    out.error = std::string("不支持的公钥算法：") + oid;
    return out;
}

namespace detail {

/** ECDSA P-256 验签：message 先 SHA-256，签名为 DER 或 P1363 */
inline bool verifyEcP256(const PublicKeyInfo& key, const std::string& message,
                         const std::string& signature) {
    if (key.point.size() != 65) return false;
    const std::string raw = derSigToP1363(signature);
    if (raw.size() != 64) return false;

    const std::string digest = sha256(message);
    if (digest.size() != 32) return false;

    AlgHandle alg;
    if (!alg.open(BCRYPT_ECDSA_P256_ALGORITHM)) return false;

    // BCRYPT_ECCKEY_BLOB { dwMagic, cbKey, X[32], Y[32] }
    std::vector<BYTE> blob(sizeof(BCRYPT_ECCKEY_BLOB) + 64);
    auto* hdr = reinterpret_cast<BCRYPT_ECCKEY_BLOB*>(blob.data());
    hdr->dwMagic = BCRYPT_ECDSA_PUBLIC_P256_MAGIC;
    hdr->cbKey   = 32;
    ::memcpy(blob.data() + sizeof(BCRYPT_ECCKEY_BLOB), key.point.data() + 1, 64);

    BcryptKeyHandle k;
    if (!isOk(::BCryptImportKeyPair(alg, nullptr, BCRYPT_ECCPUBLIC_BLOB, &k.h,
                                    blob.data(), (ULONG)blob.size(), 0))) {
        return false;
    }
    return isOk(::BCryptVerifySignature(
        k.h, nullptr,
        reinterpret_cast<PUCHAR>(const_cast<char*>(digest.data())), (ULONG)digest.size(),
        reinterpret_cast<PUCHAR>(const_cast<char*>(raw.data())), (ULONG)raw.size(), 0));
}

/**
 * 读一个 DER INTEGER（带长形式长度支持），并**剥掉正数前导 0x00**。
 *
 * 为什么必须剥：DER 的 INTEGER 是有符号大端编码，2048 位模数的最高位是 1，
 * 编码时必须前置一个 0x00 → 实际占 **257 字节**。若照原样使用，
 * cbModulus/BitLength 全部算错，bcrypt 直接以 STATUS_INVALID_PARAMETER 拒绝。
 */
inline bool readDerInteger(const BYTE* d, size_t len, size_t& i, const BYTE*& v, size_t& vLen) {
    if (i + 2 > len || d[i] != 0x02) return false;
    ++i;
    size_t l = d[i++];
    if (l & 0x80) {
        const size_t k = l & 0x7F;
        if (k == 0 || k > 4 || i + k > len) return false;
        l = 0;
        for (size_t j = 0; j < k; ++j) l = (l << 8) | d[i++];
    }
    if (i + l > len) return false;
    v    = d + i;
    vLen = l;
    i   += l;
    while (vLen > 1 && v[0] == 0x00) { ++v; --vLen; }
    return true;
}

/** RSAPublicKey DER（SEQUENCE{INTEGER n, INTEGER e}）→ BCRYPT_RSAPUBLIC_BLOB */
inline bool buildRsaPublicBlob(const BYTE* der, size_t len, std::vector<BYTE>& blob) {
    if (!der || len < 8) return false;
    size_t i = 0;
    if (der[i++] != 0x30) return false;
    size_t seqLen = der[i++];
    if (seqLen & 0x80) {
        const size_t k = seqLen & 0x7F;
        if (k == 0 || k > 4 || i + k > len) return false;
        seqLen = 0;
        for (size_t j = 0; j < k; ++j) seqLen = (seqLen << 8) | der[i++];
    }
    if (i + seqLen > len) return false;

    const BYTE* n = nullptr; size_t nLen = 0;
    const BYTE* e = nullptr; size_t eLen = 0;
    if (!readDerInteger(der, len, i, n, nLen)) return false;
    if (!readDerInteger(der, len, i, e, eLen)) return false;
    if (nLen < 64 || nLen > 1024 || eLen == 0 || eLen > 8) return false;   // 合理区间护栏

    // 真实位长（模数最高字节可能不是 0x80 开头）
    size_t lead = 0;
    while (lead < nLen && n[lead] == 0) ++lead;
    if (lead == nLen) return false;
    int topBits = 0;
    for (BYTE t = n[lead]; t; t >>= 1) ++topBits;
    const ULONG bits = (ULONG)((nLen - lead - 1) * 8 + (size_t)topBits);

    blob.assign(sizeof(BCRYPT_RSAKEY_BLOB) + eLen + nLen, 0);
    auto* hdr = reinterpret_cast<BCRYPT_RSAKEY_BLOB*>(blob.data());
    hdr->Magic       = BCRYPT_RSAPUBLIC_MAGIC;
    hdr->BitLength   = bits;
    hdr->cbPublicExp = (ULONG)eLen;
    hdr->cbModulus   = (ULONG)nLen;
    hdr->cbPrime1    = 0;
    hdr->cbPrime2    = 0;
    ::memcpy(blob.data() + sizeof(BCRYPT_RSAKEY_BLOB), e, eLen);
    ::memcpy(blob.data() + sizeof(BCRYPT_RSAKEY_BLOB) + eLen, n, nLen);
    return true;
}

/**
 * RSA PKCS#1 v1.5 + SHA-256 验签（服务端的 RS256 回落路径）。
 *
 * **为什么不用 CryptoAPI 的 CryptVerifySignature**：它的哈希算法白名单里只有 SHA-1，
 * 传 CALG_SHA_256 会稳定返回 ERROR_INVALID_PARAMETER(87) —— 不是"密钥不对"，
 * 而是这条 API 根本不支持 SHA-2。实测（Win10/11 + PROV_RSA_AES）：
 *     CryptImportPublicKeyInfo + CryptCreateHash(CALG_SHA_256) + CryptVerifySignature → 0 / err 87
 *     BCryptImportKeyPair     + BCryptVerifySignature(BCRYPT_PAD_PKCS1)              → 0x00000000 通过
 * 所以 RS256 必须走 CNG/bcrypt，并显式给出 PKCS#1 填充信息（pszAlgId = "SHA256"）。
 * 旧 SDK 只实现了 ES256，如果这里再用 CAPI 写"看着正确"的 RSA 验签，
 * 一旦服务端因环境不支持 EC 而回落 RS256，客户端会 100% 验签失败 —— 这正是必须修掉的坑。
 */
inline bool verifyRsaSha256(const PublicKeyInfo& key, const std::string& message,
                            const std::string& signature) {
    if (signature.empty()) return false;

    std::vector<BYTE> buf;
    CERT_PUBLIC_KEY_INFO* pki = nullptr;
    if (!decodeSpki(key.spkiDer, buf, pki) || !pki) return false;

    std::vector<BYTE> blob;
    if (!buildRsaPublicBlob(pki->PublicKey.pbData, (size_t)pki->PublicKey.cbData, blob)) return false;

    const std::string digest = sha256(message);
    if (digest.size() != 32) return false;

    AlgHandle alg;
    if (!alg.open(BCRYPT_RSA_ALGORITHM)) return false;

    BcryptKeyHandle pk;
    if (!isOk(::BCryptImportKeyPair(alg, nullptr, BCRYPT_RSAPUBLIC_BLOB, &pk.h,
                                    blob.data(), (ULONG)blob.size(), 0))) {
        return false;
    }

    BCRYPT_PKCS1_PADDING_INFO pad = {};
    pad.pszAlgId = BCRYPT_SHA256_ALGORITHM;   // "SHA256"
    return isOk(::BCryptVerifySignature(
        pk.h, &pad,
        reinterpret_cast<PUCHAR>(const_cast<char*>(digest.data())), (ULONG)digest.size(),
        reinterpret_cast<PUCHAR>(const_cast<char*>(signature.data())), (ULONG)signature.size(),
        BCRYPT_PAD_PKCS1));
}

} // namespace detail

/**
 * 验签（自动识别算法）。
 * message 为**签名对象原文**（响应信封与离线票据都是 `data|t|n` / `G1.payload`）；
 * signature 为**原始签名字节**（不是 base64）；ES256 的 DER/P1363 都会被识别。
 */
NEBULA_MUST_CHECK inline bool verifySignature(const std::string& pem,
                                             const std::string& message,
                                             const std::string& signature,
                                             SignatureAlgo algo = SignatureAlgo::Auto) {
    if (signature.empty()) return false;
    const PublicKeyInfo key = parsePublicKey(pem);
    if (!key.valid()) return false;

    switch (algo) {
    case SignatureAlgo::Es256: return key.type == PublicKeyInfo::Type::EcP256
                                    && detail::verifyEcP256(key, message, signature);
    case SignatureAlgo::Rs256: return key.type == PublicKeyInfo::Type::Rsa
                                    && detail::verifyRsaSha256(key, message, signature);
    case SignatureAlgo::Auto:
    default:
        return (key.type == PublicKeyInfo::Type::EcP256 && detail::verifyEcP256(key, message, signature))
            || (key.type == PublicKeyInfo::Type::Rsa    && detail::verifyRsaSha256(key, message, signature));
    }
}

// ===========================================================================
// Nebula 3.1：ECDH P-256 / HKDF-SHA256 / AES-256-GCM（会话握手协议，见 client/handshake.hpp）
// ---------------------------------------------------------------- them ---
// 注意：BCryptDeriveKey(BCRYPT_KDF_RAW_SECRET) 输出的是【小端序】共享密钥，
// 与 OpenSSL（PHP 服务端）的大端序 X 坐标相反 —— sharedSecret() 内部已做翻转，
// 对接服务端 openssl_pkey_derive 时无需再处理。需要 Windows 10+（旧系统会协商失败）。
// ===========================================================================

/** ECDH P-256 临时密钥对；privateBlob 供 sharedSecret 使用（自持字节串） */
struct EcKeyPair {
    std::string point;        ///< 65 字节非压缩公钥 0x04||X||Y（上报服务端）
    std::string privateBlob;  ///< BCRYPT_ECCPRIVATE_BLOB 原始字节（内部自持）
    bool valid() const { return point.size() == 65 && privateBlob.size() == sizeof(BCRYPT_ECCKEY_BLOB) + 96; }
};

/** 生成 ECDH P-256 临时密钥对 */
NEBULA_MUST_CHECK inline EcKeyPair ecdhGenerateKeyPair() {
    EcKeyPair out;
    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_ECDH_P256_ALGORITHM)) return out;

    detail::BcryptKeyHandle key;
    if (!isOk(::BCryptGenerateKeyPair(alg, &key.h, 256, 0))) return out;
    if (!isOk(::BCryptFinalizeKeyPair(key.h, 0))) return out;

    DWORD pubLen = 0, cb = 0;
    if (!isOk(::BCryptExportKey(key.h, nullptr, BCRYPT_ECCPUBLIC_BLOB,
                                nullptr, 0, &pubLen, 0)) || pubLen == 0) return out;
    std::string pubBlob(pubLen, '\0');
    if (!isOk(::BCryptExportKey(key.h, nullptr, BCRYPT_ECCPUBLIC_BLOB,
                                reinterpret_cast<PUCHAR>(&pubBlob[0]), pubLen, &cb, 0))) return out;

    DWORD privLen = 0;
    if (!isOk(::BCryptExportKey(key.h, nullptr, BCRYPT_ECCPRIVATE_BLOB,
                                nullptr, 0, &privLen, 0)) || privLen == 0) return out;
    std::string privBlob(privLen, '\0');
    if (!isOk(::BCryptExportKey(key.h, nullptr, BCRYPT_ECCPRIVATE_BLOB,
                                reinterpret_cast<PUCHAR>(&privBlob[0]), privLen, &cb, 0))) return out;

    // BCRYPT_ECCPUBLIC_BLOB = magic(4) + cbKey(4) + X(32) + Y(32) → 0x04||X||Y
    if (pubBlob.size() != 4 + 4 + 64) return out;
    out.point = "\x04" + pubBlob.substr(8, 64);
    out.privateBlob = privBlob;
    return out;
}

/**
 * ECDH 共享密钥（X 坐标 32 字节，已转为大端序，与 OpenSSL 一致）。
 * peerPoint 为 65 字节非压缩公钥。
 */
NEBULA_MUST_CHECK inline std::string ecdhSharedSecret(const std::string& privateBlob,
                                                      const std::string& peerPoint) {
    if (privateBlob.empty() || peerPoint.size() != 65 || peerPoint[0] != '\x04') return {};

    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_ECDH_P256_ALGORITHM)) return {};

    detail::BcryptKeyHandle priv;
    if (!isOk(::BCryptImportKeyPair(alg, nullptr, BCRYPT_ECCPRIVATE_BLOB, &priv.h,
                                    reinterpret_cast<PUCHAR>(const_cast<char*>(privateBlob.data())),
                                    (ULONG)privateBlob.size(), 0))) {
        return {};
    }

    // 对端公钥 → BCRYPT_ECDH_PUBLIC_BLOB（magic 换成 ECDH_PUBLIC_P256）
    std::vector<BYTE> peerBlob(sizeof(BCRYPT_ECCKEY_BLOB) + 64);
    auto* hdr = reinterpret_cast<BCRYPT_ECCKEY_BLOB*>(peerBlob.data());
    hdr->dwMagic = BCRYPT_ECDH_PUBLIC_P256_MAGIC;
    hdr->cbKey   = 32;
    ::memcpy(peerBlob.data() + sizeof(BCRYPT_ECCKEY_BLOB), peerPoint.data() + 1, 64);

    detail::BcryptKeyHandle peer;
    if (!isOk(::BCryptImportKeyPair(alg, nullptr, BCRYPT_ECCPUBLIC_BLOB, &peer.h,
                                    peerBlob.data(), (ULONG)peerBlob.size(), 0))) {
        return {};
    }

    BCRYPT_SECRET_HANDLE secret = nullptr;
    if (!isOk(::BCryptSecretAgreement(priv.h, peer.h, &secret, 0))) return {};
    struct SecretGuard {
        BCRYPT_SECRET_HANDLE h;
        ~SecretGuard() { if (h) ::BCryptDestroySecret(h); }
    } guard{ secret };

    // RAW_SECRET 输出为小端序 → 取到后翻转为大端（OpenSSL 兼容）。
    // 注意 BCryptDeriveKey 形参：hSecret, kdf, pParamList, pbDerivedKey,
    // cbDerivedKey, pcbResult, dwFlags —— 先传 pbDerivedKey=nullptr 查长度。
    DWORD len = 0;
    if (!isOk(::BCryptDeriveKey(secret, BCRYPT_KDF_RAW_SECRET, nullptr,
                                nullptr, 0, &len, 0)) || len == 0) {
        return {};
    }
    std::string raw(len, '\0');
    if (!isOk(::BCryptDeriveKey(secret, BCRYPT_KDF_RAW_SECRET, nullptr,
                                reinterpret_cast<PUCHAR>(&raw[0]), len, &len, 0))) {
        return {};
    }
    std::reverse(raw.begin(), raw.end());
    return raw;
}

/**
 * HKDF-SHA256（RFC 5869）：extract-then-expand。
 * salt 可为空（此时按全 0 哈希长度补足）；info 为域分离标签。
 */
NEBULA_MUST_CHECK inline std::string hkdfSha256(const std::string& ikm,
                                                const std::string& salt,
                                                const std::string& info,
                                                size_t outLen) {
    if (outLen == 0 || outLen > 255 * 32) return {};
    // extract
    std::string fixedSalt = salt.empty() ? std::string(32, '\0') : salt;
    const std::string prk = hmacSha256(fixedSalt, ikm);
    if (prk.empty()) return {};
    // expand
    std::string okm, block;
    unsigned char counter = 1;
    while (okm.size() < outLen) {
        block = hmacSha256(prk, block + info + std::string(1, (char)counter));
        if (block.empty()) return {};
        okm += block;
        ++counter;
    }
    okm.resize(outLen);
    return okm;
}

/** AES-256-GCM 加密，返回 `iv 不含` → 密文；tag 单独输出（16 字节）。iv 必须 12 字节。 */
NEBULA_MUST_CHECK inline bool aes256GcmEncrypt(const std::string& key32,
                                               const std::string& iv12,
                                               const std::string& plain,
                                               std::string& cipherOut,
                                               std::string& tagOut) {
    cipherOut.clear(); tagOut.clear();
    if (key32.size() != 32 || iv12.size() != 12) return false;

    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_AES_ALGORITHM)) return false;
    if (!isOk(::BCryptSetProperty(alg, BCRYPT_CHAINING_MODE,
                                  reinterpret_cast<PUCHAR>(const_cast<wchar_t*>(L"ChainingModeGCM")),
                                  (ULONG)sizeof(L"ChainingModeGCM"), 0))) {
        return false;
    }
    DWORD objLen = 0, cb = 0;
    if (!isOk(::BCryptGetProperty(alg, BCRYPT_OBJECT_LENGTH,
                                  reinterpret_cast<PUCHAR>(&objLen), sizeof(objLen), &cb, 0))) {
        objLen = 0;
    }
    std::vector<BYTE> obj(objLen ? objLen : 1);

    detail::BcryptKeyHandle key;
    if (!isOk(::BCryptGenerateSymmetricKey(alg, &key.h, obj.data(), objLen,
                                           reinterpret_cast<PUCHAR>(const_cast<char*>(key32.data())),
                                           32, 0))) {
        return false;
    }

    BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO info;
    ::BCRYPT_INIT_AUTH_MODE_INFO(info);
    info.pbNonce = reinterpret_cast<PUCHAR>(const_cast<char*>(iv12.data()));
    info.cbNonce = 12;
    std::string tag(16, '\0');
    info.pbTag = reinterpret_cast<PUCHAR>(&tag[0]);
    info.cbTag = 16;

    std::string out(plain.size(), '\0');
    ULONG acted = 0;
    if (!isOk(::BCryptEncrypt(key.h,
                              reinterpret_cast<PUCHAR>(const_cast<char*>(plain.data())),
                              (ULONG)plain.size(), &info, nullptr, 0,
                              reinterpret_cast<PUCHAR>(plain.empty() ? nullptr : &out[0]),
                              (ULONG)out.size(), &acted, 0))) {
        return false;
    }
    out.resize(acted);
    cipherOut = out;
    tagOut    = tag;
    return true;
}

/** AES-256-GCM 解密（认证失败返回 false）。iv 必须 12 字节，tag 必须 16 字节。 */
NEBULA_MUST_CHECK inline bool aes256GcmDecrypt(const std::string& key32,
                                               const std::string& iv12,
                                               const std::string& cipher,
                                               const std::string& tag16,
                                               std::string& plainOut) {
    plainOut.clear();
    if (key32.size() != 32 || iv12.size() != 12 || tag16.size() != 16) return false;

    detail::AlgHandle alg;
    if (!alg.open(BCRYPT_AES_ALGORITHM)) return false;
    if (!isOk(::BCryptSetProperty(alg, BCRYPT_CHAINING_MODE,
                                  reinterpret_cast<PUCHAR>(const_cast<wchar_t*>(L"ChainingModeGCM")),
                                  (ULONG)sizeof(L"ChainingModeGCM"), 0))) {
        return false;
    }
    DWORD objLen = 0, cb = 0;
    if (!isOk(::BCryptGetProperty(alg, BCRYPT_OBJECT_LENGTH,
                                  reinterpret_cast<PUCHAR>(&objLen), sizeof(objLen), &cb, 0))) {
        objLen = 0;
    }
    std::vector<BYTE> obj(objLen ? objLen : 1);

    detail::BcryptKeyHandle key;
    if (!isOk(::BCryptGenerateSymmetricKey(alg, &key.h, obj.data(), objLen,
                                           reinterpret_cast<PUCHAR>(const_cast<char*>(key32.data())),
                                           32, 0))) {
        return false;
    }

    BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO info;
    ::BCRYPT_INIT_AUTH_MODE_INFO(info);
    info.pbNonce = reinterpret_cast<PUCHAR>(const_cast<char*>(iv12.data()));
    info.cbNonce = 12;
    info.pbTag   = reinterpret_cast<PUCHAR>(const_cast<char*>(tag16.data()));
    info.cbTag   = 16;

    std::string out(cipher.size(), '\0');
    ULONG acted = 0;
    if (!isOk(::BCryptDecrypt(key.h,
                              reinterpret_cast<PUCHAR>(const_cast<char*>(cipher.data())),
                              (ULONG)cipher.size(), &info, nullptr, 0,
                              reinterpret_cast<PUCHAR>(cipher.empty() ? nullptr : &out[0]),
                              (ULONG)out.size(), &acted, 0))) {
        return false;   // GCM 认证失败 / 密钥不匹配
    }
    out.resize(acted);
    plainOut = out;
    return true;
}

// ===========================================================================
// 随机数
// ===========================================================================
/** 密码学随机字节（BCryptGenRandom）；失败返回 false 并清零缓冲 */
inline bool randomBytes(unsigned char* out, size_t len) {
    if (!out || len == 0) return false;
    if (!isOk(::BCryptGenRandom(nullptr, out, (ULONG)len, BCRYPT_USE_SYSTEM_PREFERRED_RNG))) {
        ::memset(out, 0, len);
        return false;
    }
    return true;
}

/** 随机字节的 hex 串（len 为字节数，输出 2*len 个字符）；失败返回空串 */
NEBULA_MUST_CHECK inline std::string randomHex(size_t len) {
    std::vector<unsigned char> buf(len ? len : 1);
    if (!randomBytes(buf.data(), len)) return {};
    return bytesToHex(buf.data(), len);
}

/** 兼容旧名（旧版 SDK 的 nebula::randBytes） */
inline void randBytes(unsigned char* buf, ULONG len) {
    NEBULA_UNUSED(randomBytes(buf, (size_t)len));
}

// ===========================================================================
// 文件哈希 / 大小（更新包校验与自身完整性自校验）
// ===========================================================================
/** 分块计算文件哈希的 hex（sha256=true → 64 位，false → 32 位 MD5）；失败返回空串 */
NEBULA_MUST_CHECK inline std::string fileHashHex(const std::string& pathUtf8, bool sha256Mode) {
    HANDLE file = ::CreateFileW(toWide(pathUtf8).c_str(), GENERIC_READ, FILE_SHARE_READ,
                                nullptr, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, nullptr);
    if (file == INVALID_HANDLE_VALUE) return {};

    detail::AlgHandle alg;
    if (!alg.open(sha256Mode ? BCRYPT_SHA256_ALGORITHM : BCRYPT_MD5_ALGORITHM)) {
        ::CloseHandle(file);
        return {};
    }
    detail::BcryptHashHandle hash;
    std::string digest(sha256Mode ? 32 : 16, '\0');
    bool ok = isOk(::BCryptCreateHash(alg, &hash.h, nullptr, 0, nullptr, 0, 0));
    if (ok) {
        std::vector<char> buf(65536);   // 堆分配：避免 64KB 栈帧（C6262）
        DWORD read = 0;
        while (::ReadFile(file, buf.data(), (DWORD)buf.size(), &read, nullptr) && read > 0) {
            if (!isOk(::BCryptHashData(hash.h, reinterpret_cast<PUCHAR>(buf.data()), read, 0))) {
                ok = false;
                break;
            }
        }
        if (ok) {
            ok = isOk(::BCryptFinishHash(hash.h, reinterpret_cast<PUCHAR>(&digest[0]),
                                         (ULONG)digest.size(), 0));
        }
    }
    ::CloseHandle(file);
    return ok ? bytesToHex(digest) : std::string();
}

/** 文件字节数；文件不存在返回 -1 */
NEBULA_MUST_CHECK inline long long fileSizeBytes(const std::string& pathUtf8) {
    WIN32_FILE_ATTRIBUTE_DATA fad{};
    if (!::GetFileAttributesExW(toWide(pathUtf8).c_str(), GetFileExInfoStandard, &fad)) return -1;
    return (long long)(((unsigned long long)fad.nFileSizeHigh << 32) | fad.nFileSizeLow);
}

} // namespace crypto

// ---------------------------------------------------------------------------
// 兼容旧名：旧版 SDK 公开了 nebula::Bcrypt（静态方法集合）。
// 新代码请直接用 nebula::crypto::* 自由函数。
// ---------------------------------------------------------------------------
class Bcrypt {
public:
    static constexpr size_t AES_KEY_LEN = 32;
    static constexpr size_t AES_IV_LEN  = 16;
    static constexpr size_t HMAC_LEN    = 32;

    static std::string sha256(const std::string& d) { return crypto::sha256(d); }
    static std::string md5(const std::string& d) { return crypto::md5(d); }
    static std::string hmac(const std::string& key, const std::string& data) {
        return crypto::hmacSha256(key, data);
    }
    static std::string hmacHex(const std::string& key, const std::string& data) {
        return crypto::hmacSha256Hex(key, data);
    }
    /** 旧签名 aesRaw(key, iv, plain, encrypt)：encrypt 参数旧实现即被忽略，这里同样忽略 */
    static std::string aesRaw(const std::string& key32, const std::string& iv,
                              const std::string& plain, bool /*encrypt*/) {
        return crypto::aes256CbcEncrypt(key32, iv, plain);
    }
    static std::string aesDecrypt(const std::string& key32, const std::string& blob) {
        return crypto::aes256CbcDecrypt(key32, blob);
    }
    static std::string fileHashHex(const std::string& path, bool sha256Mode) {
        return crypto::fileHashHex(path, sha256Mode);
    }
    static long long fileSizeBytes(const std::string& path) {
        return crypto::fileSizeBytes(path);
    }
    /** 旧名 es256Verify(pem, msg, sigDer)：保持 ES256 语义（新代码建议用 verifySignature） */
    static bool es256Verify(const std::string& pem, const std::string& msg,
                            const std::string& sigDer) {
        return crypto::verifySignature(pem, msg, sigDer, crypto::SignatureAlgo::Es256);
    }
};

} // namespace nebula

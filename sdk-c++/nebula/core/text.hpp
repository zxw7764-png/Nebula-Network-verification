#pragma once
// ============================================================================
// Nebula SDK · 文本 / 编码 / 时间工具
// ----------------------------------------------------------------------------
// 纯函数，无状态、无副作用，可单独复用（也是自测程序的主要覆盖对象）。
// ============================================================================

#include "../config.hpp"

namespace nebula {

// ---------------------------------------------------------------------------
// 宽字符 ⇄ UTF-8
// ---------------------------------------------------------------------------
/** UTF-16（宽字符）→ UTF-8；w 为空返回空串。len < 0 表示按 NUL 结尾自动取长。 */
NEBULA_MUST_CHECK inline std::string toUtf8(const wchar_t* w, int len = -1) {
    if (!w) return {};
    if (len < 0) len = (int)::wcslen(w);
    if (len <= 0) return {};
    const int need = ::WideCharToMultiByte(CP_UTF8, 0, w, len, nullptr, 0, nullptr, nullptr);
    if (need <= 0) return {};
    std::string out((size_t)need, '\0');
    ::WideCharToMultiByte(CP_UTF8, 0, w, len, &out[0], need, nullptr, nullptr);
    return out;
}

/** UTF-8 → UTF-16（宽字符） */
NEBULA_MUST_CHECK inline std::wstring toWide(const std::string& s) {
    if (s.empty()) return {};
    const int need = ::MultiByteToWideChar(CP_UTF8, 0, s.c_str(), (int)s.size(), nullptr, 0);
    if (need <= 0) return {};
    std::wstring out((size_t)need, L'\0');
    ::MultiByteToWideChar(CP_UTF8, 0, s.c_str(), (int)s.size(), &out[0], need);
    return out;
}

// ---------------------------------------------------------------------------
// 十六进制
// ---------------------------------------------------------------------------
/** 原始字节 → 小写 hex */
NEBULA_MUST_CHECK inline std::string bytesToHex(const unsigned char* data, size_t len) {
    static const char kDigits[] = "0123456789abcdef";
    std::string out;
    out.reserve(len * 2);
    for (size_t i = 0; i < len; ++i) {
        out += kDigits[data[i] >> 4];
        out += kDigits[data[i] & 0x0F];
    }
    return out;
}

/** 原始字节 → 小写 hex（std::string 重载） */
NEBULA_MUST_CHECK inline std::string bytesToHex(const std::string& raw) {
    return bytesToHex(reinterpret_cast<const unsigned char*>(raw.data()), raw.size());
}

/** 单字符转小写（只处理 ASCII，避免 locale 影响） */
NEBULA_MUST_CHECK inline char lowerAscii(char c) {
    return (c >= 'A' && c <= 'Z') ? (char)(c - 'A' + 'a') : c;
}

/** 整串转小写（ASCII） */
NEBULA_MUST_CHECK inline std::string toLowerAscii(std::string s) {
    for (size_t i = 0; i < s.size(); ++i) s[i] = lowerAscii(s[i]);
    return s;
}

/** 小写 hex（去空格与冒号），用于证书指纹归一化 */
NEBULA_MUST_CHECK inline std::string normalizeHex(std::string s) {
    std::string out;
    out.reserve(s.size());
    for (size_t i = 0; i < s.size(); ++i) {
        const char c = s[i];
        if (c == ':' || c == ' ' || c == '-' || c == '\t' || c == '\r' || c == '\n') continue;
        out += lowerAscii(c);
    }
    return out;
}

// ---------------------------------------------------------------------------
// 时间
// ---------------------------------------------------------------------------
/** 当前 Unix 时间戳（秒） */
NEBULA_MUST_CHECK inline int64_t nowSeconds() {
    return (int64_t)std::chrono::duration_cast<std::chrono::seconds>(
               std::chrono::system_clock::now().time_since_epoch()).count();
}

// ---------------------------------------------------------------------------
// Base64 / Base64URL
// ---------------------------------------------------------------------------
/** 标准 base64 编码（无换行、无多余 NUL） */
NEBULA_MUST_CHECK inline std::string b64Encode(const std::string& raw) {
    if (raw.empty()) return {};
    DWORD need = 0;
    if (!::CryptBinaryToStringA(reinterpret_cast<const BYTE*>(raw.data()), (DWORD)raw.size(),
                                CRYPT_STRING_BASE64 | CRYPT_STRING_NOCRLF, nullptr, &need) || need == 0) {
        return {};
    }
    std::string out((size_t)need, '\0');
    if (!::CryptBinaryToStringA(reinterpret_cast<const BYTE*>(raw.data()), (DWORD)raw.size(),
                                CRYPT_STRING_BASE64 | CRYPT_STRING_NOCRLF, &out[0], &need)) {
        return {};
    }
    out.resize(need);
    while (!out.empty() && (out.back() == '\0' || out.back() == '\r' || out.back() == '\n')) out.pop_back();
    return out;
}

/** 标准 base64 解码；遇到非法字符返回空串（不抛异常） */
NEBULA_MUST_CHECK inline std::string b64Decode(const std::string& enc) {
    std::string clean;
    clean.reserve(enc.size());
    for (size_t i = 0; i < enc.size(); ++i) {
        const char c = enc[i];
        if (c != '\r' && c != '\n' && c != ' ' && c != '\t') clean += c;
    }
    if (clean.empty()) return {};
    DWORD need = 0;
    if (!::CryptStringToBinaryA(clean.c_str(), (DWORD)clean.size(), CRYPT_STRING_BASE64,
                               nullptr, &need, nullptr, nullptr) || need == 0) {
        return {};
    }
    std::string out((size_t)need, '\0');
    if (!::CryptStringToBinaryA(clean.c_str(), (DWORD)clean.size(), CRYPT_STRING_BASE64,
                               reinterpret_cast<BYTE*>(&out[0]), &need, nullptr, nullptr)) {
        return {};
    }
    out.resize(need);
    return out;
}

/** base64url 编码（+ → -、/ → _，去掉 '=' 填充）——离线票据使用 */
NEBULA_MUST_CHECK inline std::string b64UrlEncode(const std::string& raw) {
    std::string s = b64Encode(raw);
    for (size_t i = 0; i < s.size(); ++i) {
        if (s[i] == '+') s[i] = '-';
        else if (s[i] == '/') s[i] = '_';
    }
    while (!s.empty() && s.back() == '=') s.pop_back();
    return s;
}

/** base64url 解码（自动补齐填充） */
NEBULA_MUST_CHECK inline std::string b64UrlDecode(const std::string& enc) {
    std::string s = enc;
    for (size_t i = 0; i < s.size(); ++i) {
        if (s[i] == '-') s[i] = '+';
        else if (s[i] == '_') s[i] = '/';
    }
    while (s.size() % 4) s += '=';
    return b64Decode(s);
}

} // namespace nebula

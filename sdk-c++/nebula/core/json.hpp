#pragma once
// ============================================================================
// Nebula SDK · 极简 JSON（读取 + 构造）
// ----------------------------------------------------------------------------
// 为什么不用第三方库：SDK 承诺零第三方依赖；服务端的报文结构固定且扁平，
// 一个"扁平搜索 + 括号配对"的解析器足够，且没有引入攻击面的风险。
//
// 读取策略（与旧版 SDK 行为一致，务必理解）：
//   findScalar / findInt64 / findBool 是**全文扁平搜索**，不构树、不校验整体合法性。
//   因此它们既能取最外层字段，也能直接取嵌套字段（如 token 在 data.user 里）。
//   同名键出现多次时取**第一个**；若某次命中的值不是标量（对象/数组）则继续往后找。
//
// 构造策略：quote / number / boolean 只做必要的转义，输出紧凑 JSON。
// ============================================================================

#include "text.hpp"

namespace nebula {
namespace json {

// ---------------------------------------------------------------------------
// 构造
// ---------------------------------------------------------------------------
/** 生成 JSON 字符串字面量（含首尾引号，转义 " \ 与常见控制字符） */
NEBULA_MUST_CHECK inline std::string quote(std::string_view v) {
    std::string out;
    out.reserve(v.size() + 2);
    out += '"';
    for (size_t i = 0; i < v.size(); ++i) {
        const char c = v[i];
        switch (c) {
        case '"':  out += "\\\""; break;
        case '\\': out += "\\\\"; break;
        case '\n': out += "\\n";  break;
        case '\r': out += "\\r";  break;
        case '\t': out += "\\t";  break;
        case '\b': out += "\\b";  break;
        case '\f': out += "\\f";  break;
        default:
            if ((unsigned char)c < 0x20) {
                char buf[8] = {};
                ::sprintf_s(buf, "\\u%04x", (unsigned)(unsigned char)c);
                out += buf;
            } else {
                out += c;   // UTF-8 原样透传（服务端 json_decode 按 UTF-8 解析）
            }
        }
    }
    out += '"';
    return out;
}

/** 整数 → JSON */
NEBULA_MUST_CHECK inline std::string number(int64_t v) { return std::to_string(v); }

/** 布尔 → JSON */
NEBULA_MUST_CHECK inline std::string boolean(bool b) { return b ? "true" : "false"; }

/** 键值对 "key":value（值必须是已序列化好的 JSON 片段） */
NEBULA_MUST_CHECK inline std::string pair(std::string_view key, const std::string& valueJson) {
    return quote(key) + ":" + valueJson;
}

// ---------------------------------------------------------------------------
// 读取
// ---------------------------------------------------------------------------
namespace detail {

/** 跳过从 s[i]（必须是起始引号）开始的字符串；结束时 i 停在结束引号上 */
inline void skipString(const std::string& s, size_t& i) {
    ++i;
    while (i < s.size()) {
        const char c = s[i];
        if (c == '\\') { i += 2; continue; }
        if (c == '"') return;
        ++i;
    }
}

/**
 * 解码 s[q]（起始引号）处的 JSON 字符串。
 * 支持 \" \\ \/ \n \r \t \b \f \uXXXX（含 UTF-16 代理对）。
 * 服务端外层 json_encode 不带 JSON_UNESCAPED_SLASHES，base64 里的 / 会写成 \/，
 * 不解回 / 会导致后续 base64 解码失败 —— 这是必须处理的细节。
 */
inline std::string decodeString(const std::string& s, size_t q) {
    std::string out;
    ++q;   // 跳过起始引号
    while (q < s.size()) {
        const char c = s[q];
        if (c == '"') return out;
        if (c != '\\') { out += c; ++q; continue; }

        ++q;
        if (q >= s.size()) return {};
        const char esc = s[q];
        switch (esc) {
        case '"':  out += '"';  break;
        case '\\': out += '\\'; break;
        case '/':  out += '/';  break;
        case 'n':  out += '\n'; break;
        case 'r':  out += '\r'; break;
        case 't':  out += '\t'; break;
        case 'b':  out += '\b'; break;
        case 'f':  out += '\f'; break;
        case 'u': {
            const auto hex4 = [&s](size_t at, unsigned& cp) -> bool {
                if (at + 4 >= s.size()) return false;
                cp = 0;
                for (size_t k = 1; k <= 4; ++k) {
                    const char h = s[at + k];
                    cp <<= 4;
                    if      (h >= '0' && h <= '9') cp += (unsigned)(h - '0');
                    else if (h >= 'a' && h <= 'f') cp += (unsigned)(h - 'a' + 10);
                    else if (h >= 'A' && h <= 'F') cp += (unsigned)(h - 'A' + 10);
                    else return false;
                }
                return true;
            };
            unsigned cp = 0;
            if (!hex4(q, cp)) return {};
            q += 4;
            // 高代理后紧跟低代理 \uDC00-\uDFFF 时合成码点
            if (cp >= 0xD800 && cp <= 0xDBFF && q + 6 < s.size()
                && s[q + 1] == '\\' && s[q + 2] == 'u') {
                unsigned lo = 0;
                if (hex4(q + 2, lo) && lo >= 0xDC00 && lo <= 0xDFFF) {
                    cp = 0x10000u + ((cp - 0xD800u) << 10) + (lo - 0xDC00u);
                    q += 6;
                }
            }
            if (cp < 0x80) {
                out += (char)cp;
            } else if (cp < 0x800) {
                out += (char)(0xC0 | (cp >> 6));
                out += (char)(0x80 | (cp & 0x3F));
            } else if (cp < 0x10000) {
                out += (char)(0xE0 | (cp >> 12));
                out += (char)(0x80 | ((cp >> 6) & 0x3F));
                out += (char)(0x80 | (cp & 0x3F));
            } else {
                out += (char)(0xF0 | (cp >> 18));
                out += (char)(0x80 | ((cp >> 12) & 0x3F));
                out += (char)(0x80 | ((cp >> 6) & 0x3F));
                out += (char)(0x80 | (cp & 0x3F));
            }
            break;
        }
        default: return {};
        }
        ++q;
    }
    return {};
}

inline void skipWs(const std::string& s, size_t& i) {
    while (i < s.size()) {
        const char c = s[i];
        if (c == ' ' || c == '\t' || c == '\r' || c == '\n') ++i;
        else return;
    }
}

/** 在 s 中定位 key 对应的值，返回值的起始下标；找不到返回 npos */
inline size_t locateValue(const std::string& s, const char* key, size_t from) {
    const std::string pat = std::string("\"") + key + "\"";
    size_t q = s.find(pat, from);
    while (q != std::string::npos) {
        size_t c = s.find(':', q + pat.size());
        if (c == std::string::npos) return std::string::npos;
        ++c;
        skipWs(s, c);
        if (c >= s.size()) return std::string::npos;
        return c;
    }
    return std::string::npos;
}

/** 从 i（'{' 或 '['）开始做括号配对，返回配对结束后的下标 */
inline size_t skipBalanced(const std::string& s, size_t i, char open, char close) {
    size_t depth = 0;
    while (i < s.size()) {
        const char c = s[i];
        if (c == '"') { skipString(s, i); }
        else if (c == open) ++depth;
        else if (c == close) {
            --depth;
            if (depth == 0) return i + 1;
        }
        ++i;
    }
    return s.size();
}

} // namespace detail

/** 取标量值：true→"1"、false→"0"、数字原样、字符串解码后返回。非标量或不存在返回 "" */
NEBULA_MUST_CHECK inline std::string findScalar(const std::string& src, const char* key) {
    const std::string pat = std::string("\"") + key + "\"";
    size_t from = 0;
    while (from <= src.size()) {
        const size_t at = detail::locateValue(src, key, from);
        if (at == std::string::npos) return {};
        NEBULA_UNUSED(pat);
        if (src.compare(at, 4, "true") == 0)  return "1";
        if (src.compare(at, 5, "false") == 0) return "0";
        const char c0 = src[at];
        if (c0 == '-' || (c0 >= '0' && c0 <= '9')) {
            size_t e = src.find_first_of(",}]\r\n", at);
            if (e == std::string::npos) e = src.size();
            return src.substr(at, e - at);
        }
        if (c0 == '"') return detail::decodeString(src, at);
        // 值是对象/数组/null 等非标量 → 跳过本次命中，继续往后找同名键
        const size_t next = src.find(pat, at + 1);
        if (next == std::string::npos) return {};
        from = next;
    }
    return {};
}

/** 取整数值 */
NEBULA_MUST_CHECK inline int64_t findInt64(const std::string& src, const char* key, int64_t def = 0) {
    const std::string v = findScalar(src, key);
    if (v.empty()) return def;
    return (int64_t)::strtoll(v.c_str(), nullptr, 10);
}

/** 取 int（服务端整数字段用） */
NEBULA_MUST_CHECK inline int findInt(const std::string& src, const char* key, int def = 0) {
    return (int)findInt64(src, key, def);
}

/** 取布尔值（服务端 true/false；缺省为 def） */
NEBULA_MUST_CHECK inline bool findBool(const std::string& src, const char* key, bool def = false) {
    const std::string v = findScalar(src, key);
    if (v.empty()) return def;
    return v == "1";
}

/** 取字符串值（与 findScalar 等价，语义更明确） */
NEBULA_MUST_CHECK inline std::string findString(const std::string& src, const char* key) {
    return findScalar(src, key);
}

/** 取 key 对应的对象原文（含花括号，内部字符串不误判） */
NEBULA_MUST_CHECK inline std::string findObject(const std::string& src, const char* key) {
    const std::string pat = std::string("\"") + key + "\"";
    size_t from = 0;
    while (from <= src.size()) {
        const size_t at = detail::locateValue(src, key, from);
        if (at == std::string::npos) return {};
        if (src[at] == '{') return src.substr(at, detail::skipBalanced(src, at, '{', '}') - at);
        const size_t next = src.find(pat, at + 1);
        if (next == std::string::npos) return {};
        from = next;
    }
    return {};
}

/** 取 key 对应数组里的每个对象原文（跳过数组中的标量元素） */
NEBULA_MUST_CHECK inline std::vector<std::string> findObjects(const std::string& src, const char* key) {
    std::vector<std::string> out;
    const size_t at = detail::locateValue(src, key, 0);
    if (at == std::string::npos || src[at] != '[') return out;

    size_t i = at + 1;
    while (i < src.size()) {
        detail::skipWs(src, i);
        if (i >= src.size() || src[i] == ']') break;
        if (src[i] == ',') { ++i; continue; }
        if (src[i] == '{') {
            const size_t end = detail::skipBalanced(src, i, '{', '}');
            out.push_back(src.substr(i, end - i));
            i = end;
            continue;
        }
        if (src[i] == '"') { detail::skipString(src, i); ++i; continue; }
        // 标量元素（数字/布尔/null）：跳到下一个分隔符
        while (i < src.size() && src[i] != ',' && src[i] != ']') ++i;
    }
    return out;
}

/** 取字符串数组（如 device_fp.components、device.risk）；非字符串元素会被跳过 */
NEBULA_MUST_CHECK inline std::vector<std::string> findStrings(const std::string& src, const char* key) {
    std::vector<std::string> out;
    const size_t at = detail::locateValue(src, key, 0);
    if (at == std::string::npos || src[at] != '[') return out;

    size_t i = at + 1;
    while (i < src.size()) {
        detail::skipWs(src, i);
        if (i >= src.size() || src[i] == ']') break;
        if (src[i] == '"') {
            const size_t start = i;
            detail::skipString(src, i);
            out.push_back(detail::decodeString(src, start));
            ++i;
            continue;
        }
        if (src[i] == '{') { i = detail::skipBalanced(src, i, '{', '}'); continue; }   // 跳过对象元素
        ++i;                                                                          // 跳过 , 与其他标量
    }
    return out;
}

/**
 * 按路径逐层取值：dig(d, {"grace","ticket"}) 等价于
 * findScalar(findObject(d, "grace"), "ticket")；中间层不存在返回 ""。
 */
NEBULA_MUST_CHECK inline std::string dig(const std::string& src,
                                         std::initializer_list<const char*> path) {
    std::string cur = src;
    size_t left = path.size();
    for (const char* key : path) {
        if (cur.empty()) return {};
        if (--left == 0) return findScalar(cur, key);
        cur = findObject(cur, key);
    }
    return {};
}

} // namespace json

// ---------------------------------------------------------------------------
// 兼容旧名（旧版 SDK 的全局函数；新代码请用 nebula::json::*）
// ---------------------------------------------------------------------------
NEBULA_MUST_CHECK inline std::string jsonString(std::string_view v) { return json::quote(v); }
NEBULA_MUST_CHECK inline std::string jsonBool(bool b) { return json::boolean(b); }
NEBULA_MUST_CHECK inline std::string findJsonValue(const std::string& s, const char* key) {
    return json::findScalar(s, key);
}
NEBULA_MUST_CHECK inline std::string extractObject(const std::string& s, const char* key) {
    return json::findObject(s, key);
}
NEBULA_MUST_CHECK inline std::vector<std::string> findJsonObjects(const std::string& s, const char* key) {
    return json::findObjects(s, key);
}

} // namespace nebula

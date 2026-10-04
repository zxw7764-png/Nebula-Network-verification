#pragma once
// ============================================================================
// Nebula SDK · HTTP 传输（WinHTTP，同步 POST）
// ----------------------------------------------------------------------------
// 设计要点：
//   · **无隐藏全局状态**：超时 / 证书指纹 / 代理模式都由调用方以 Options 传入
//     （Client 持有自己的一份），因此同进程多个 Client 互不干扰，线程安全。
//     仅保留一组「默认 Options」供兼容旧接口 setTimeout/setCertSha256 使用。
//   · 句柄全部 RAII 管理（旧实现手写 CloseHandle，失败路径容易漏）。
//   · TLS 证书指纹锁定：握手后比对对端证书 SHA256，防透明代理 / 假证书中间人；
//     配置了指纹后会同时拒绝 http://（明文传输无法锁证书）。
//   · 默认直连（NO_PROXY），避免代理软件注入抓包；需要时把 useSystemProxy 置 true。
// ============================================================================

#include "crypto.hpp"
#include "text.hpp"

namespace nebula {
namespace http {

/**
 * HTTP 响应。成员只有 status / body，因此支持结构化绑定：
 *     auto [status, body] = Http::post(url, body);
 */
struct Response {
    int status = 0;       ///< HTTP 状态码；0 = 连接失败 / 超时 / TLS 校验失败 / URL 非法
    std::string body;     ///< 响应体原文（未解码）
};

/** 单次请求的可选参数 */
struct Options {
    int connectTimeoutMs = 8000;   ///< 连接超时
    int receiveTimeoutMs = 15000;  ///< 接收超时
    std::string certSha256;        ///< 服务端证书 SHA256 指纹（归一化小写无冒号）；空 = 不锁定
    bool useSystemProxy = false;   ///< 是否走系统代理（默认直连）
};

namespace detail {

/** HINTERNET 的 RAII 包装 */
struct Handle {
    HINTERNET h = nullptr;
    Handle() = default;
    Handle(const Handle&) = delete;
    Handle& operator=(const Handle&) = delete;
    ~Handle() { if (h) ::WinHttpCloseHandle(h); }
    NEBULA_MUST_CHECK bool ok() const { return h != nullptr; }
};

struct Url {
    bool         secure = false;
    std::wstring host;
    WORD         port = 0;
    std::wstring path;
    bool         valid = false;
};

/** 解析 "scheme://host[:port]/path"；仅接受 http / https */
inline Url parseUrl(const std::string& url) {
    Url u;
    const size_t schemeEnd = url.find("://");
    if (schemeEnd == std::string::npos) return u;
    const std::string scheme = toLowerAscii(url.substr(0, schemeEnd));
    if (scheme != "http" && scheme != "https") return u;
    u.secure = (scheme == "https");

    const size_t hostBegin = schemeEnd + 3;
    const size_t slash = url.find('/', hostBegin);
    std::string hostPort = (slash == std::string::npos) ? url.substr(hostBegin)
                                                        : url.substr(hostBegin, slash - hostBegin);
    const std::string path = (slash == std::string::npos) ? std::string("/") : url.substr(slash);
    if (hostPort.empty()) return u;

    const size_t colon = hostPort.find(':');
    if (colon != std::string::npos) {
        const std::string portText = hostPort.substr(colon + 1);
        if (portText.empty()) return u;
        u.port = (WORD)::atoi(portText.c_str());
        hostPort = hostPort.substr(0, colon);
    } else {
        u.port = u.secure ? 443 : 80;
    }
    if (hostPort.empty() || u.port == 0) return u;

    u.host = toWide(hostPort);
    u.path = toWide(path);
    u.valid = !u.host.empty() && !u.path.empty();
    return u;
}

/** 握手后取对端证书的 SHA256（64 位小写 hex）；失败返回空串 */
inline std::string peerCertificateSha256(HINTERNET request) {
    PCCERT_CONTEXT cert = nullptr;
    DWORD len = sizeof(cert);
    if (!::WinHttpQueryOption(request, WINHTTP_OPTION_SERVER_CERT_CONTEXT, &cert, &len) || !cert) {
        return {};
    }
    const std::string der(reinterpret_cast<const char*>(cert->pbCertEncoded), cert->cbCertEncoded);
    ::CertFreeCertificateContext(cert);
    return bytesToHex(crypto::sha256(der));
}

} // namespace detail

class Http {
public:
    using Result = Response;

    /** 同步请求；status = 0 表示连接 / 超时 / TLS 指纹不符 / URL 非法等本地失败 */
    NEBULA_MUST_CHECK static Response request(const char* method, const std::string& url,
                                              const std::string& body, const Options& options) {
        Response out;
        const detail::Url u = detail::parseUrl(url);
        if (!u.valid) return out;                          // status = 0

        const std::string pin = normalizeHex(options.certSha256);
        // 配了指纹还走 http → 直接拒绝（明文传输 + 无法锁证书）
        if (!pin.empty() && !u.secure) return out;

        const std::wstring agent = toWide(std::string("NebulaSDK/") + NEBULA_SDK_VERSION);
        detail::Handle session;
        session.h = ::WinHttpOpen(agent.c_str(),
                                  options.useSystemProxy ? WINHTTP_ACCESS_TYPE_DEFAULT_PROXY
                                                         : WINHTTP_ACCESS_TYPE_NO_PROXY,
                                  WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);
        if (!session.ok()) return out;

        detail::Handle connect;
        connect.h = ::WinHttpConnect(session.h, u.host.c_str(), u.port, 0);
        if (!connect.ok()) return out;

        detail::Handle request;
        request.h = ::WinHttpOpenRequest(connect.h, toWide(method).c_str(), u.path.c_str(),
                                         nullptr, WINHTTP_NO_REFERER,
                                         WINHTTP_DEFAULT_ACCEPT_TYPES,
                                         u.secure ? WINHTTP_FLAG_SECURE : 0);
        if (!request.ok()) return out;

        int timeout = options.connectTimeoutMs;
        ::WinHttpSetOption(request.h, WINHTTP_OPTION_CONNECT_TIMEOUT, &timeout, sizeof(timeout));
        timeout = options.receiveTimeoutMs;
        ::WinHttpSetOption(request.h, WINHTTP_OPTION_RECEIVE_TIMEOUT, &timeout, sizeof(timeout));

        const std::wstring headers = L"Content-Type: application/json\r\n";
        BOOL ok = ::WinHttpSendRequest(request.h, headers.c_str(), (DWORD)headers.size(),
                                       body.empty() ? nullptr : (LPVOID)body.data(),
                                       (DWORD)body.size(), (DWORD)body.size(), 0);
        if (ok) ok = ::WinHttpReceiveResponse(request.h, nullptr);
        if (!ok) return out;

        if (u.secure && !pin.empty()) {
            // WINHTTP_OPTION_SERVER_CERT_CONTEXT 需 Win8.1+；取不到即视为校验失败
            const std::string got = detail::peerCertificateSha256(request.h);
            if (got.empty() || got != pin) return out;
        }

        DWORD status = 0, len = sizeof(status);
        if (!::WinHttpQueryHeaders(request.h, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                                   WINHTTP_HEADER_NAME_BY_INDEX, &status, &len,
                                   WINHTTP_NO_HEADER_INDEX)) {
            return out;
        }
        out.status = (int)status;

        std::string bodyOut;
        std::vector<char> buf(8192);
        DWORD read = 0;
        while (::WinHttpReadData(request.h, buf.data(), (DWORD)buf.size(), &read) && read > 0) {
            bodyOut.append(buf.data(), read);
        }
        out.body = std::move(bodyOut);
        return out;
    }

    /** POST（application/json） */
    NEBULA_MUST_CHECK static Response post(const std::string& url, const std::string& body,
                                           const Options& options) {
        return request("POST", url, body, options);
    }

    /** POST，使用进程级默认 Options（兼容旧写法；Client 内部一律传自己的 Options） */
    NEBULA_MUST_CHECK static Response post(const std::string& url, const std::string& body) {
        return request("POST", url, body, defaultOptions());
    }

    // -----------------------------------------------------------------------
    // 进程级默认 Options（兼容旧接口；新代码请显式传 Options）
    // -----------------------------------------------------------------------
    static void setDefaultOptions(const Options& o) {
        std::lock_guard<std::mutex> lock(defaultMutex());
        defaultRef() = o;
    }
    NEBULA_MUST_CHECK static Options defaultOptions() {
        std::lock_guard<std::mutex> lock(defaultMutex());
        return defaultRef();
    }
    static void setTimeouts(int connectMs, int receiveMs) {
        std::lock_guard<std::mutex> lock(defaultMutex());
        defaultRef().connectTimeoutMs = connectMs;
        defaultRef().receiveTimeoutMs = receiveMs;
    }
    /** 兼容旧名 */
    static void setTimeout(int connectMs, int receiveMs) { setTimeouts(connectMs, receiveMs); }
    static void setCertSha256(const std::string& fingerprint) {
        std::lock_guard<std::mutex> lock(defaultMutex());
        defaultRef().certSha256 = normalizeHex(fingerprint);
    }
    NEBULA_MUST_CHECK static std::string certSha256() { return defaultOptions().certSha256; }
    static void setUseSystemProxy(bool on) {
        std::lock_guard<std::mutex> lock(defaultMutex());
        defaultRef().useSystemProxy = on;
    }

private:
    static Options& defaultRef() { static Options o; return o; }
    static std::mutex& defaultMutex() { static std::mutex m; return m; }
};

} // namespace http

/** 兼容旧名：旧版 SDK 公开了 nebula::Http */
using Http = http::Http;

} // namespace nebula

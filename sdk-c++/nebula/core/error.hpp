#pragma once
// ============================================================================
// Nebula SDK · 错误码
// ----------------------------------------------------------------------------
// 说明：**业务码**由服务端下发（0 成功、1001 参数错误、2001 密码错误……完整表见
// docs/API.md 1.4），SDK 不做枚举；本文件只定义**客户端本地**产生的错误，
// 一律为负数，与业务码永不冲突。
//
// Response::code 的取值规则：
//   code >= 0  → 服务端业务码（0 为成功）
//   code <  0  → 本地错误，(Error)code 即为下表取值
// ============================================================================

namespace nebula {

/** 客户端本地错误（负值） */
enum class Error : int {
    Ok         = 0,    ///< 成功（与服务端业务码 0 对齐）
    Network    = -1,   ///< 连接失败 / 超时 / 证书指纹不匹配 / URL 无法解析
    Envelope   = -2,   ///< 信封校验失败：HMAC 不符、响应签名不合法、解密失败、响应缺字段
    HttpStatus = -3,   ///< HTTP 状态码非 200
    Config     = -4,   ///< 配置缺失：app_key 为空、未配置响应签名公钥、加密参数非法
    Crypto     = -5,   ///< 本地密码学操作失败（密钥派生 / 加解密 / 随机数不可用）
    Protocol   = -6,   ///< 协议不兼容（服务端不支持 3.1 / 协议版本异常）
};

/** 本地错误对应的中文说明（可直接展示给用户） */
NEBULA_MUST_CHECK inline const char* errorText(Error e) {
    switch (e) {
    case Error::Ok:         return "成功";
    case Error::Network:    return "网络连接失败（请检查网络或 API 地址）";
    case Error::Envelope:   return "通信报文校验失败（响应签名不符或解密失败）";
    case Error::HttpStatus: return "服务器返回异常状态码";
    case Error::Config:     return "客户端配置不完整（请检查 app_key / 响应签名公钥）";
    case Error::Crypto:     return "本地加密组件异常";
    case Error::Protocol:   return "协议不兼容（服务端不支持当前协议版本）";
    }
    return "未知错误";
}

/** 离线宽限票据的本地校验结果码（与 Error 语义不同，独立枚举） */
enum class OfflineError : int {
    Ok        = 0,    ///< 票据有效
    Signature = -1,   ///< 验签失败（票据被篡改 / 公钥不匹配）
    Format    = -2,   ///< 票据格式错误（分段、前缀、编码）
    Binding   = -3,   ///< 票据与当前机器码或会话令牌不匹配
    Expired   = -4,   ///< 宽限已到期（含时钟偏差容忍）
    Disabled  = -5,   ///< 服务端未开启离线宽限（无公钥）
};

NEBULA_MUST_CHECK inline const char* offlineErrorText(OfflineError e) {
    switch (e) {
    case OfflineError::Ok:        return "ok";
    case OfflineError::Signature: return "票据验签失败";
    case OfflineError::Format:    return "票据格式错误";
    case OfflineError::Binding:   return "票据与当前机器或会话不匹配";
    case OfflineError::Expired:   return "离线宽限已到期";
    case OfflineError::Disabled:  return "服务端未开启离线宽限";
    }
    return "未知错误";
}

} // namespace nebula

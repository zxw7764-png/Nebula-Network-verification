// ============================================================================
// Nebula C# SDK · 通信信封（协议唯一实现点）—— Nebula 3.1
// ----------------------------------------------------------------------------
// 3.1 起：静态密钥信封（AES-256-CBC + HMAC + kid 会话盐）已随协议移除。
// 所有业务请求必须先经 ECDH 握手建立会话（见 core/NebulaSecure31.cs Session31）：
//   请求  { proto:31, sid, seq, t, data(GCM), mac, app_key }
//   响应  { proto:31, sid, data(GCM), sig(ES256), sig_kid, sig_algo, code }
// 本文件只保留 URL 组装与响应拆封类型；加密/握手全部在 Session31。
// ============================================================================
using System;

namespace Nebula.Sdk
{
    public static class Envelope
    {
        /// <summary>组装接口 URL（文件形式 / 目录形式都支持）</summary>
        public static string ActionUrl(string apiBase, string action)
        {
            var base_ = apiBase;
            if (base_.EndsWith("index.php", StringComparison.OrdinalIgnoreCase))
                return base_ + "?action=" + action;
            if (base_.Length == 0 || !base_.EndsWith('/')) base_ += '/';
            return base_ + "?action=" + action;
        }

        /// <summary>响应拆封结果</summary>
        public sealed class OpenedResponse
        {
            public Error Status = Error.Ok;
            public string Msg = "";
            public string Plain = "";
            public int BusinessCode;
        }

        /// <summary>恒定时间比较（防时序侧信道；NF1 功能密钥等模块使用）</summary>
        public static bool ConstantTimeEquals(string a, string b)
        {
            if (a.Length != b.Length) return false;
            int diff = 0;
            for (int i = 0; i < a.Length; i++) diff |= a[i] ^ b[i];
            return diff == 0;
        }
    }
}

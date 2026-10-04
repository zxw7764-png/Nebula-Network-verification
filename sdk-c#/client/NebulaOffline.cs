// ============================================================================
// Nebula C# SDK · 离线宽限票据
// 票据结构：G1.<payload-b64url>.<signature-b64url>
// ============================================================================
namespace Nebula.Sdk
{
    public static class Offline
    {
        /// <summary>时钟偏差容忍（秒），与服务端 grace.clock_skew 默认值一致</summary>
        public const int ClockSkewSeconds = 120;

        public sealed class GraceTicket
        {
            public sealed class Payload
            {
                public int Version = 1;
                public int UserId;
                public string MachineDigest = "";
                public string TokenDigest = "";
                public long VipExpire;
                public long IssuedAt;
                public long Until;
                public long Duration;
            }

            public string Prefix = "";
            public string PayloadB64 = "";
            public byte[] Signature = System.Array.Empty<byte>();
            public string SigningInput = "";
            public Payload Data = new();
        }

        /// <summary>与服务端 Grace::digest 一致：sha256(value) 的 hex 前 16 位</summary>
        public static string GraceDigest(string value)
        {
            if (string.IsNullOrEmpty(value)) return "";
            var hex = Crypto.Sha256Hex(value);
            return hex.Length < 16 ? "" : hex.Substring(0, 16);
        }

        /// <summary>解析票据分段与载荷（不验签）；失败返回 false + error</summary>
        public static bool ParseGraceTicket(string ticket, string expectedPrefix,
                                            out GraceTicket out_, out string error)
        {
            out_ = new GraceTicket();
            error = "";
            string prefix = string.IsNullOrEmpty(expectedPrefix) ? "G1" : expectedPrefix;
            if (ticket.Length < 10) { error = "票据格式错误"; return false; }

            int first = ticket.IndexOf('.');
            if (first < 0) { error = "票据格式错误"; return false; }
            int second = ticket.IndexOf('.', first + 1);
            if (second < 0) { error = "票据格式错误"; return false; }

            string gotPrefix = ticket.Substring(0, first);
            if (gotPrefix != prefix)
            {
                error = "票据前缀不匹配（期望 " + prefix + "，实际 " + gotPrefix + "）";
                return false;
            }

            out_.Prefix = gotPrefix;
            out_.PayloadB64 = ticket.Substring(first + 1, second - first - 1);
            out_.SigningInput = ticket.Substring(0, second);
            out_.Signature = Crypto.B64UrlDecode(ticket.Substring(second + 1));
            if (out_.PayloadB64.Length == 0 || out_.Signature.Length == 0)
            { error = "票据格式错误"; return false; }

            var payloadJson = Crypto.Str(Crypto.B64UrlDecode(out_.PayloadB64));
            if (payloadJson.Length == 0) { error = "票据载荷解码失败"; return false; }

            int version = Json.FindInt(payloadJson, "v", 1);
            if (version != 1) { error = "票据版本不支持：" + version; return false; }

            out_.Data.Version = version;
            out_.Data.UserId = Json.FindInt(payloadJson, "u", 0);
            out_.Data.MachineDigest = Json.FindString(payloadJson, "m");
            out_.Data.TokenDigest = Json.FindString(payloadJson, "k");
            out_.Data.VipExpire = Json.FindInt64(payloadJson, "e", 0);
            out_.Data.IssuedAt = Json.FindInt64(payloadJson, "i", 0);
            out_.Data.Until = Json.FindInt64(payloadJson, "g", 0);
            out_.Data.Duration = Json.FindInt64(payloadJson, "d", 0);

            if (out_.Data.Until <= 0) { error = "票据载荷缺少宽限截止时间"; return false; }
            return true;
        }

        /// <summary>完整校验票据（验签 + 绑定校验 + 有效期）</summary>
        public static GraceResult VerifyGraceTicket(string ticket, string publicKey,
                                                    string prefix, string machineId,
                                                    string token, long nowUnix = 0)
        {
            var result = new GraceResult();
            if (string.IsNullOrEmpty(publicKey))
            {
                result.Code = OfflineError.Disabled;
                result.Msg = OfflineErrorText.Of(result.Code);
                return result;
            }

            if (!ParseGraceTicket(ticket, prefix, out var parsed, out var error))
            {
                result.Code = OfflineError.Format;
                result.Msg = error;
                return result;
            }

            // ① 验签（签名对象是 "G1.<payload-b64url>" 原文）
            if (!Crypto.VerifySignature(publicKey, parsed.SigningInput, parsed.Signature))
            {
                result.Code = OfflineError.Signature;
                result.Msg = OfflineErrorText.Of(result.Code);
                return result;
            }

            // ② 机器码摘要
            if (!string.IsNullOrEmpty(parsed.Data.MachineDigest)
                && GraceDigest(machineId) != parsed.Data.MachineDigest)
            {
                result.Code = OfflineError.Binding;
                result.Msg = "票据与当前机器不匹配";
                return result;
            }

            // ③ 会话令牌摘要
            if (!string.IsNullOrEmpty(parsed.Data.TokenDigest))
            {
                if (string.IsNullOrEmpty(token) || GraceDigest(token) != parsed.Data.TokenDigest)
                {
                    result.Code = OfflineError.Binding;
                    result.Msg = "票据与当前会话不匹配";
                    return result;
                }
            }

            // ④ 有效期（容忍时钟偏差）
            long now = nowUnix > 0 ? nowUnix : Now.UnixSeconds();
            if (now > parsed.Data.Until + ClockSkewSeconds)
            {
                result.Code = OfflineError.Expired;
                result.Msg = OfflineErrorText.Of(result.Code);
                return result;
            }

            result.Ok = true;
            result.Code = OfflineError.Ok;
            result.Msg = "ok";
            result.UntilTs = parsed.Data.Until;
            result.RemainSec = parsed.Data.Until > now ? (int)(parsed.Data.Until - now) : 0;
            result.PayloadUserid = parsed.Data.UserId;
            result.PayloadVipExpire = parsed.Data.VipExpire;
            return result;
        }
    }
}

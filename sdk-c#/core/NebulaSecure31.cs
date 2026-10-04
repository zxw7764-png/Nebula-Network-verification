// ============================================================================
// Nebula C# SDK · 3.1 会话握手（ECDH P-256 + HKDF + AES-256-GCM）
// ----------------------------------------------------------------------------
// 与服务端 lib/Handshake.php、C++ SDK nebula/client/handshake.hpp 严格对齐：
//
//   ① handshake（明文 JSON，无对称信封）
//      请求  { app_key, eph_pub(65B点b64), nc(16B b64), ts, mhash }
//      响应  { proto:31, sid, eph_pub, ns, ts_s, sign(ES256), sig_kid, sig_algo }
//            sign 对象 = sid|eph_pub|ns|nc|ts_s（绑定双方 nonce，防重放/防 MITM）
//   ② 派生会话密钥
//      shared = ECDH P-256 X 坐标（32 字节大端，DeriveRawSecretAgreement）
//      sk_enc = HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-enc")
//      sk_mac = HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-mac")
//      sk_enc_rsp = HKDF-SHA256(sk_enc, salt=零, info="nebula31-enc-rsp")  ← 响应方向专用
//      （2026-10-03 审计修复：请求/响应密钥分离，消除 GCM nonce 跨方向重用）
//   ③ 业务请求（每个 action，含 init）
//      { proto:31, sid, seq, t, data, mac, app_key }
//        data = base64( iv[12] + AES-256-GCM(业务JSON) + tag[16] )
//        iv   = iv_prefix(4, = sha256(nc‖ns)[:4]) ‖ seq 大端(8)  —— seq 严格递增
//        mac  = hex(HMAC(sk_mac, sid|seq|t|sha256(data)))
//      响应  { proto:31, sid, data(GCM), sig(ES256), sig_kid, sig_algo, code }
//            sig = 服务端长期私钥对 data|sid 签名（pin 公钥验签防伪造服务器）
//
// 安全性质：客户端零静态对称机密；内存中只有当次会话的临时密钥。
// ============================================================================
using System;
using System.Security.Cryptography;
using System.Text;

namespace Nebula.Sdk
{
    /// <summary>3.1 ECDH 会话（非线程安全 —— Client 侧用 state mutex 保护）。</summary>
    public sealed class Session31
    {
        private string _sid = "";
        private byte[] _skEnc = Array.Empty<byte>();
        private byte[] _skEncRsp = Array.Empty<byte>();
        private byte[] _skMac = Array.Empty<byte>();
        private byte[] _ivPrefix = Array.Empty<byte>();
        private byte[] _nc = Array.Empty<byte>();
        private ECDiffieHellman? _ecPriv;
        private long _seq;
        private long _clockOffsetMs;

        public bool Active => _sid.Length == 32;
        public string Sid => _sid;
        public long ClockOffsetMs => _clockOffsetMs;

        /// <summary>会话失效（服务端拒绝 sid / seq 冲突 / 过期）时清空。</summary>
        public void Clear()
        {
            if (_skEnc.Length > 0) Array.Clear(_skEnc, 0, _skEnc.Length);
            if (_skMac.Length > 0) Array.Clear(_skMac, 0, _skMac.Length);
            if (_skEncRsp.Length > 0) Array.Clear(_skEncRsp, 0, _skEncRsp.Length);
            _skEnc = Array.Empty<byte>();
            _skMac = Array.Empty<byte>();
            _skEncRsp = Array.Empty<byte>();
            _ivPrefix = Array.Empty<byte>();
            _nc = Array.Empty<byte>();
            _ecPriv?.Dispose(); _ecPriv = null;
            _sid = "";
            _seq = 0;
        }

        /// <summary>握手请求体（明文 JSON）；失败返回空串。</summary>
        public string BuildHandshakeRequest(string appKey, string machineId)
        {
            Clear();
            try
            {
                _ecPriv = ECDiffieHellman.Create(ECCurve.NamedCurves.nistP256);
                var q = _ecPriv.PublicKey.ExportParameters();
                var point = new byte[65];
                point[0] = 0x04;
                q.Q.X.CopyTo(point, 1);
                q.Q.Y.CopyTo(point, 65 - 32);

                _nc = RandomNumberGenerator.GetBytes(16);
                string mhash = Crypto.BytesToHex(SHA256.HashData(Encoding.UTF8.GetBytes(machineId)));

                return "{"
                    + Json.Pair("app_key", Json.Quote(appKey))
                    + "," + Json.Pair("eph_pub", Json.Quote(Crypto.B64Encode(point)))
                    + "," + Json.Pair("nc", Json.Quote(Crypto.B64Encode(_nc)))
                    + "," + Json.Pair("ts", Json.Number(DateTimeOffset.UtcNow.ToUnixTimeSeconds()))
                    + "," + Json.Pair("mhash", Json.Quote(mhash))
                    + "}";
            }
            catch
            {
                Clear();
                return "";
            }
        }

        /// <summary>
        /// 处理握手响应（明文 JSON，{code,msg,data:{proto:31,...}}），验 ES256 签名并派生会话密钥。
        /// 返回 Error.Ok 或失败码；errMsg 带人类可读原因。
        /// </summary>
        public Error ConsumeHandshakeResponse(string body, string respSignPubKey,
                                              bool requireSignature, ref string errMsg)
        {
            var root = Json.Parse(body);
            long code = Json.FindInt64(root, "code", -1);
            if (code != 0)
            {
                errMsg = "握手失败：" + Json.FindString(root, "msg");
                return Error.Envelope;
            }

            var hs = Json.FindObject(root, "data") ?? root;
            if (Json.FindInt(hs, "proto") != 31)
            {
                errMsg = "服务端握手响应协议版本异常";
                return Error.Protocol;
            }

            string sid = Json.FindString(hs, "sid");
            string serverEph = Json.FindString(hs, "eph_pub");
            string nsB64 = Json.FindString(hs, "ns");
            string signB64 = Json.FindString(hs, "sign");
            long tsS = Json.FindInt64(hs, "ts_s");
            if (sid.Length != 32 || serverEph.Length == 0 || nsB64.Length == 0 || signB64.Length == 0)
            {
                errMsg = "握手响应字段缺失";
                return Error.Envelope;
            }

            // ① 服务端长期私钥签名校验（防伪造服务器 / MITM 换钥）—— 必须先于密钥派生
            string signedStr = sid + "|" + serverEph + "|" + nsB64 + "|"
                             + Crypto.B64Encode(_nc) + "|" + tsS.ToString(System.Globalization.CultureInfo.InvariantCulture);
            if (requireSignature)
            {
                if (respSignPubKey.Length == 0)
                {
                    errMsg = "未配置响应签名公钥，无法校验握手响应";
                    return Error.Config;
                }
                var sig = Crypto.B64Decode(signB64);
                if (sig == null || sig.Length == 0 || !Crypto.VerifySignature(respSignPubKey, signedStr, sig))
                {
                    errMsg = "握手响应签名校验失败（可能连到了伪造服务器）";
                    return Error.Envelope;
                }
            }

            _clockOffsetMs = (tsS - DateTimeOffset.UtcNow.ToUnixTimeSeconds()) * 1000;

            // ② 派生会话密钥
            byte[] peerPoint, ns;
            try { peerPoint = Crypto.B64Decode(serverEph)!; ns = Crypto.B64Decode(nsB64)!; }
            catch { errMsg = "握手响应编码错误"; return Error.Envelope; }
            if (peerPoint.Length != 65 || peerPoint[0] != 0x04 || ns.Length != 16)
            {
                errMsg = "握手响应公钥/nonce 格式错误";
                return Error.Envelope;
            }

            byte[] shared;
            try
            {
                var q = new ECParameters
                {
                    Curve = ECCurve.NamedCurves.nistP256,
                    Q = { X = peerPoint[1..33], Y = peerPoint[33..65] },
                };
                using var peerPriv = ECDiffieHellman.Create(q);
                // DeriveRawSecretAgreement = 原始 X 坐标（大端，与 OpenSSL/PHP 一致）
                shared = _ecPriv!.DeriveRawSecretAgreement(peerPriv.PublicKey);
            }
            catch (Exception e)
            {
                errMsg = "ECDH 协商失败：" + e.Message;
                return Error.Crypto;
            }
            if (shared.Length != 32) { errMsg = "ECDH 共享密钥长度异常"; return Error.Crypto; }

            var salt = new byte[_nc.Length + ns.Length];
            _nc.CopyTo(salt, 0);
            ns.CopyTo(salt, _nc.Length);
            _skEnc = HkdfSha256(shared, salt, Encoding.ASCII.GetBytes("nebula31-enc"), 32);
            _skMac = HkdfSha256(shared, salt, Encoding.ASCII.GetBytes("nebula31-mac"), 32);
            // 响应方向独立加密密钥（2026-10-03 审计修复）：与服务端
            // hash_hkdf('sha256', sk_enc, 32, 'nebula31-enc-rsp') 同款派生
            _skEncRsp = HkdfSha256(_skEnc, Array.Empty<byte>(), Encoding.ASCII.GetBytes("nebula31-enc-rsp"), 32);
            _ivPrefix = SHA256.HashData(salt).AsSpan(0, 4).ToArray();
            _sid = sid;
            _seq = 0;
            return Error.Ok;
        }

        /// <summary>构造 3.1 业务请求信封；失败返回空串。</summary>
        public string BuildRequestEnvelope(string payloadJson, long timestamp, string appKey)
        {
            if (!Active) return "";
            _seq++;
            if (_seq <= 0) { Clear(); return ""; }

            var iv = PackSeq(_ivPrefix, _seq);
            byte[] cipher, tag;
            try
            {
                using var gcm = new AesGcm(_skEnc, 16);
                var plain = Encoding.UTF8.GetBytes(payloadJson);
                cipher = new byte[plain.Length];
                tag = new byte[16];
                gcm.Encrypt(iv, plain, cipher, tag);
            }
            catch { return ""; }

            string data = Crypto.B64Encode(Combine(iv, cipher, tag));
            if (data.Length == 0) return "";
            // 注意：HMAC key 是任意二进制，必须用原始字节（UTF-8 字符串化会损坏密钥）
            string mac;
            using (var h = new HMACSHA256(_skMac))
                mac = Crypto.BytesToHex(h.ComputeHash(Encoding.ASCII.GetBytes(
                    _sid + "|" + _seq.ToString(System.Globalization.CultureInfo.InvariantCulture)
                    + "|" + timestamp.ToString(System.Globalization.CultureInfo.InvariantCulture)
                    + "|" + Crypto.Sha256Hex(data))));

            return "{"
                + Json.Pair("proto", Json.Number(31))
                + "," + Json.Pair("sid", Json.Quote(_sid))
                + "," + Json.Pair("seq", Json.Number(_seq))
                + "," + Json.Pair("t", Json.Number(timestamp))
                + "," + Json.Pair("data", Json.Quote(data))
                + "," + Json.Pair("mac", Json.Quote(mac))
                + "," + Json.Pair("app_key", Json.Quote(appKey))
                + "}";
        }

        /// <summary>3.1 响应拆封结果（复用信封类型）。</summary>
        public Envelope.OpenedResponse OpenResponse(string body, string respSignPubKey,
                                                    bool requireSignature)
        {
            var out_ = new Envelope.OpenedResponse();
            if (!Active)
            {
                out_.Status = Error.Protocol;
                out_.Msg = "3.1 会话未建立";
                return out_;
            }

            string data = Json.FindString(body, "data");
            if (data.Length == 0 || Json.FindInt(body, "proto", 0) != 31)
            {
                out_.Status = Error.Envelope;
                out_.Msg = "响应不是 3.1 信封";
                return out_;
            }

            // ① 服务端长期私钥签名（签名对象 data|sid）—— 先验签后解密
            if (requireSignature)
            {
                if (respSignPubKey.Length == 0)
                {
                    out_.Status = Error.Config;
                    out_.Msg = "未配置响应签名公钥，拒绝连接";
                    return out_;
                }
                var sig = Crypto.B64Decode(Json.FindString(body, "sig"));
                if (sig == null || sig.Length == 0
                    || !Crypto.VerifySignature(respSignPubKey, data + "|" + _sid, sig))
                {
                    out_.Status = Error.Envelope;
                    out_.Msg = "响应签名校验失败（可能连到了伪造服务器）";
                    return out_;
                }
            }

            // ② GCM 解密（响应用请求的 seq 对称解密）
            byte[] raw;
            try { raw = Crypto.B64Decode(data)!; }
            catch { out_.Status = Error.Envelope; out_.Msg = "响应 data 编码错误"; return out_; }
            if (raw.Length < 12 + 16 + 1)
            {
                out_.Status = Error.Envelope;
                out_.Msg = "响应密文过短";
                return out_;
            }
            var iv = PackSeq(_ivPrefix, _seq);
            try
            {
                using var gcm = new AesGcm(_skEncRsp, 16);
                var plain = new byte[raw.Length - 12 - 16];
                gcm.Decrypt(iv, raw.AsSpan(12, raw.Length - 12 - 16), raw.AsSpan(raw.Length - 16, 16), plain);
                string json = Encoding.UTF8.GetString(plain);
                if (json.Length == 0) throw new CryptographicException("empty plain");
                out_.Status = Error.Ok;
                out_.Plain = json;
                out_.BusinessCode = Json.FindInt(json, "code", 0);
                out_.Msg = Json.FindString(json, "msg");
            }
            catch
            {
                out_.Status = Error.Envelope;
                out_.Msg = "响应解密失败（GCM 认证未通过）";
            }
            return out_;
        }

        // -- 内部 ------------------------------------------------------------

        /// <summary>iv_prefix(4) ‖ seq 大端(8) = 12 字节 IV。</summary>
        private static byte[] PackSeq(byte[] prefix, long seq)
        {
            var iv = new byte[12];
            Array.Copy(prefix, iv, 4);
            for (int i = 0; i < 8; i++)
                iv[11 - i] = (byte)((ulong)seq >> (8 * i));
            return iv;
        }

        private static byte[] Combine(byte[] a, byte[] b, byte[] c)
        {
            var buf = new byte[a.Length + b.Length + c.Length];
            a.CopyTo(buf, 0); b.CopyTo(buf, a.Length); c.CopyTo(buf, a.Length + b.Length);
            return buf;
        }

        /// <summary>HKDF-SHA256（RFC 5869：extract-then-expand）。</summary>
        private static byte[] HkdfSha256(byte[] ikm, byte[] salt, byte[] info, int length)
        {
            // extract
            byte[] prk;
            using (var h = new HMACSHA256(salt))
                prk = h.ComputeHash(ikm);
            // expand
            var okm = new byte[length];
            byte[] block = Array.Empty<byte>();
            int offset = 0;
            byte counter = 1;
            while (offset < length)
            {
                using var h = new HMACSHA256(prk);
                var input = new byte[block.Length + info.Length + 1];
                block.CopyTo(input, 0);
                info.CopyTo(input, block.Length);
                input[^1] = counter++;
                block = h.ComputeHash(input);
                int n = Math.Min(32, length - offset);
                Array.Copy(block, 0, okm, offset, n);
                offset += n;
            }
            return okm;
        }
    }
}

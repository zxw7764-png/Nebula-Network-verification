// ============================================================================
// Nebula C# SDK · 密码学
// 与服务端 lib/Crypto.php、lib/Grace.php 逐字对齐：
//   AES-256-CBC + PKCS7   key = SHA256(AES_KEY)，IV = MD5(AES_KEY) 前 16 字节（IV 前置）
//   HMAC-SHA256           sign = hex(hmac(data|t|n, salt))
//   响应/票据签名          ES256（ECDSA P-256）与 RS256（RSA-2048 PKCS#1）都支持
// ============================================================================
using System;
using System.IO;
using System.Security.Cryptography;
using System.Text;

namespace Nebula.Sdk
{
    public static class Crypto
    {
        // ── 编码 ────────────────────────────────────────────────────────────
        public static byte[] Utf8(string s) => Encoding.UTF8.GetBytes(s);
        public static string Str(byte[] b) => Encoding.UTF8.GetString(b);

        public static string BytesToHex(byte[] b)
        {
            var sb = new StringBuilder(b.Length * 2);
            foreach (var x in b) sb.Append(x.ToString("x2"));
            return sb.ToString();
        }

        public static string HexToStr(string hex)   // hex → 原始字节串（以 string 承载）
        {
            var b = HexToBytes(hex);
            return b == null ? "" : Str(b);
        }

        public static byte[]? HexToBytes(string hex)
        {
            if (hex.Length % 2 != 0) return null;
            var b = new byte[hex.Length / 2];
            for (int i = 0; i < b.Length; i++)
            {
                if (!byte.TryParse(hex.Substring(i * 2, 2), System.Globalization.NumberStyles.HexNumber,
                                   null, out b[i])) return null;
            }
            return b;
        }

        public static string B64Encode(string s) => B64Encode(Utf8(s));
        public static string B64Encode(byte[] b) => Convert.ToBase64String(b);

        public static byte[] B64Decode(string s)
        {
            try { return Convert.FromBase64String(s); }
            catch { return Array.Empty<byte>(); }
        }

        public static string B64UrlEncode(byte[] b)
            => Convert.ToBase64String(b).TrimEnd('=').Replace('+', '-').Replace('/', '_');

        public static byte[] B64UrlDecode(string s)
        {
            var t = s.Replace('-', '+').Replace('_', '/');
            switch (t.Length % 4) { case 2: t += "=="; break; case 3: t += "="; break; }
            return B64Decode(t);
        }

        // ── 摘要 / HMAC ─────────────────────────────────────────────────────
        public static byte[] Sha256B(byte[] data) => SHA256.HashData(data);
        public static byte[] Sha256B(string data) => SHA256.HashData(Utf8(data));
        public static byte[] Md5B(string data) => MD5.HashData(Utf8(data));
        public static string Sha256(string data) => Str(SHA256.HashData(Utf8(data)));
        public static string Sha256Hex(string data) => BytesToHex(SHA256.HashData(Utf8(data)));
        public static string Md5Hex(string data) => BytesToHex(MD5.HashData(Utf8(data)));

        public static string HmacSha256Hex(string key, string data)
        {
            if (string.IsNullOrEmpty(key))
                return BytesToHex(SHA256.HashData(Utf8(data)));
            using var h = new HMACSHA256(Utf8(key));
            return BytesToHex(h.ComputeHash(Utf8(data)));
        }

        public static string RandomHex(int byteLen)
        {
            var b = new byte[byteLen];
            using var rng = RandomNumberGenerator.Create();
            rng.GetBytes(b);
            return BytesToHex(b);
        }

        // ── 协议派生 ─────────────────────────────────────────────────────
        public static byte[] DeriveKey32(string aesKey) => SHA256.HashData(Utf8(aesKey));
        public static byte[] DeriveIv16(string aesKey) => MD5.HashData(Utf8(aesKey));

        // ── AES-256-CBC（IV 前置）───────────────────────────────────────────
        public static byte[] Aes256CbcEncrypt(byte[] key32, byte[] iv, byte[] plain)
        {
            using var aes = Aes.Create();
            aes.KeySize = 256;
            aes.Mode = CipherMode.CBC;
            aes.Padding = PaddingMode.PKCS7;
            aes.Key = key32;
            aes.IV = iv;
            using var enc = aes.CreateEncryptor();
            var ct = enc.TransformFinalBlock(plain, 0, plain.Length);
            var blob = new byte[iv.Length + ct.Length];
            Buffer.BlockCopy(iv, 0, blob, 0, iv.Length);
            Buffer.BlockCopy(ct, 0, blob, iv.Length, ct.Length);
            return blob;
        }

        public static string Aes256CbcEncrypt(string key32, string iv, string plain)
            => Str(Aes256CbcEncrypt(Utf8(key32), Utf8(iv), Utf8(plain)));

        /// <summary>字符串明文 + 字节密钥/IV（推荐：密钥是任意字节）</summary>
        public static byte[] Aes256CbcEncryptB(byte[] key32, byte[] iv, string plain)
            => Aes256CbcEncrypt(key32, iv, Utf8(plain));

        public static byte[]? Aes256CbcDecrypt(byte[] key32, byte[] blob)
        {
            if (key32.Length != 32 || blob.Length <= 16 || (blob.Length - 16) % 16 != 0) return null;
            var iv = new byte[16];
            var ct = new byte[blob.Length - 16];
            Buffer.BlockCopy(blob, 0, iv, 0, 16);
            Buffer.BlockCopy(blob, 16, ct, 0, ct.Length);
            try
            {
                using var aes = Aes.Create();
                aes.KeySize = 256;
                aes.Mode = CipherMode.CBC;
                aes.Padding = PaddingMode.PKCS7;
                aes.Key = key32;
                aes.IV = iv;
                using var dec = aes.CreateDecryptor();
                return dec.TransformFinalBlock(ct, 0, ct.Length);
            }
            catch { return null; }
        }

        public static string Aes256CbcDecrypt(string key32, byte[] blob)
        {
            var outB = Aes256CbcDecrypt(Utf8(key32), blob);
            return outB == null ? "" : Str(outB);
        }

        // ── 非对称验签（ES256 / RS256 自动识别）────────────────────────────
        /// <summary>PEM 公钥 → SPKI DER；失败返回 null</summary>
        public static byte[]? PemToDer(string pem)
        {
            int begin = pem.IndexOf("-----BEGIN", StringComparison.Ordinal);
            int end = pem.IndexOf("-----END", StringComparison.Ordinal);
            if (begin < 0 || end < 0 || end <= begin) return null;
            int p = pem.IndexOf('\n', begin);
            if (p < 0) return null;
            p++;
            var sb = new StringBuilder();
            for (int i = p; i < end; i++)
            {
                char c = pem[i];
                if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9')
                    || c == '+' || c == '/' || c == '=')
                    sb.Append(c);
            }
            if (sb.Length == 0) return null;
            return B64Decode(sb.ToString());
        }

        private static byte[]? DerSigToP1363(byte[] sig)
        {
            if (sig.Length == 64) return sig;
            if (sig.Length < 8 || sig[0] != 0x30) return null;
            int i = 1;
            byte l0 = sig[i];
            if ((l0 & 0x80) != 0)
            {
                int n = l0 & 0x7F;
                if (n == 0 || n > 4 || i + 1 + n > sig.Length) return null;
                i += 1 + n;
            }
            else i++;
            var raw = new byte[64];
            for (int part = 0; part < 2; part++)
            {
                if (i + 2 > sig.Length || sig[i] != 0x02) return null;
                int len = sig[i + 1];
                i += 2;
                if (len > 33 || i + len > sig.Length) return null;
                while (len > 0 && sig[i] == 0x00) { i++; len--; }
                if (len > 32) return null;
                Buffer.BlockCopy(sig, i, raw, part * 32 + (32 - len), len);
                i += len;
            }
            return raw;
        }

        /// <summary>
        /// 验签（自动识别算法）。message 为签名对象原文，signature 为原始签名字节。
        /// ES256 的 DER 与 P1363 格式都能识别。
        /// </summary>
        public static bool VerifySignature(string pem, string message, byte[] signature)
        {
            if (string.IsNullOrEmpty(pem) || signature == null || signature.Length == 0) return false;
            var der = PemToDer(pem);
            if (der == null) return false;

            // 先试 EC P-256（注意：.NET 的 ECDsa.VerifyData 默认吃 P1363 裸格式 r||s，
            // 而 PHP/openssl_sign 产出的是 DER 编码 —— 必须先转成 P1363）
            try
            {
                using var ec = ECDsa.Create();
                ec.ImportSubjectPublicKeyInfo(der, out _);
                {
                    byte[]? sig = signature;
                    if (sig.Length != 64)
                    {
                        sig = DerToP1363(sig);   // DER → P1363(r||s)
                    }
                    if (sig != null && ec.VerifyData(Utf8(message), sig, HashAlgorithmName.SHA256))
                        return true;
                }
            }
            catch { }

            // 再试 RSA
            try
            {
                using var rsa = RSA.Create();
                rsa.ImportSubjectPublicKeyInfo(der, out _);
                return rsa.VerifyData(Utf8(message), signature, HashAlgorithmName.SHA256,
                                      RSASignaturePadding.Pkcs1);
            }
            catch { }
            return false;
        }

        /// <summary>P1363 r||s → DER SEQUENCE{INTEGER r, INTEGER s}</summary>
        private static byte[]? P1363ToDer(byte[] p1363)
        {
            if (p1363.Length != 64) return null;
            static byte[] TrimLeadingZero(ReadOnlySpan<byte> v)
            {
                int s = 0;
                while (s < v.Length - 1 && v[s] == 0) s++;
                var t = v[s..];
                // DER 正数最高位为 1 时补 0x00
                if ((t[0] & 0x80) != 0)
                {
                    var r = new byte[t.Length + 1];
                    r[0] = 0;
                    t.CopyTo(r.AsSpan(1));
                    return r;
                }
                return t.ToArray();
            }
            var r = TrimLeadingZero(p1363.AsSpan(0, 32));
            var s = TrimLeadingZero(p1363.AsSpan(32, 32));
            int body = 4 + r.Length + s.Length;
            var outB = new byte[2 + body];
            outB[0] = 0x30;
            outB[1] = (byte)body;
            outB[2] = 0x02; outB[3] = (byte)r.Length;
            r.CopyTo(outB, 4);
            outB[4 + r.Length] = 0x02;
            outB[5 + r.Length] = (byte)s.Length;
            s.CopyTo(outB, 6 + r.Length);
            return outB;
        }

        /// <summary>DER SEQUENCE{INTEGER r, INTEGER s} → P1363 r||s（各 32 字节，左侧补零）</summary>
        private static byte[]? DerToP1363(byte[] der)
        {
            try
            {
                // 外层 SEQUENCE
                if (der.Length < 8 || der[0] != 0x30) return null;
                int len = der[1];
                int off = 2;
                if ((len & 0x80) != 0) { int n = len & 0x7F; len = 0; for (int i = 0; i < n; i++) len = (len << 8) | der[2 + i]; off = 2 + n; }

                var readInt = (int start) =>
                {
                    if (der[start] != 0x02) return null;
                    int ilen = der[start + 1];
                    int ioff = start + 2;
                    // 跳过前置 0x00
                    while (ilen > 32 && der[ioff] == 0) { ioff++; ilen--; }
                    if (ilen > 32) return null;
                    var buf = new byte[32];
                    // 右对齐（大端，左侧补零）
                    der[ioff..(ioff + ilen)].CopyTo(buf, 32 - ilen);
                    return buf;
                };

                var r = readInt(off);
                if (r == null) return null;
                // s 从 r 之后开始：off + 2(INTEGER tag+len) + ilen(r 的长度)
                var rIntStart = off;
                var rLen = der[rIntStart + 1];
                var sStart = rIntStart + 2 + rLen;
                var s = readInt(sStart);
                if (s == null) return null;

                var outB = new byte[64];
                r.CopyTo(outB, 0);
                s.CopyTo(outB, 32);
                return outB;
            }
            catch { return null; }
        }

        // ── 文件哈希 / 大小 ─────────────────────────────────────────────────
        /// <summary>分块计算文件哈希（sha256Mode=true → SHA256，false → MD5）；失败返回空串</summary>
        public static string FileHashHex(string path, bool sha256Mode)
        {
            try
            {
                using var fs = File.OpenRead(path);
                return sha256Mode
                    ? BytesToHex(SHA256.HashData(fs))
                    : BytesToHex(MD5.HashData(fs));
            }
            catch { return ""; }
        }

        public static long FileSizeBytes(string path)
        {
            try
            {
                var fi = new FileInfo(path);
                return fi.Exists ? fi.Length : -1;
            }
            catch { return -1; }
        }
    }
}

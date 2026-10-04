// ============================================================================
// Nebula C# SDK · 立即公告已读记录 / 自身完整性自校验 / 功能密钥数据包
// ============================================================================
using System;
using System.Collections.Generic;
using System.IO;

namespace Nebula.Sdk
{
    // ── 已读记录（%APPDATA%\NebulaSDK\notices_<app_key>.txt）────────────────
    public static class NoticeStore
    {
        public static string StorePath(string appKey)
        {
            string dir;
            var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            if (!string.IsNullOrEmpty(appData))
            {
                dir = Path.Combine(appData, "NebulaSDK");
                try { Directory.CreateDirectory(dir); } catch { }
            }
            else
            {
                dir = AppContext.BaseDirectory;
            }
            return Path.Combine(dir, "notices_" + appKey + ".txt");
        }

        public static List<KeyValuePair<long, long>> LoadReads(string path)
        {
            var reads = new List<KeyValuePair<long, long>>();
            try
            {
                if (!File.Exists(path)) return reads;
                long cutoff = Now.UnixSeconds() - 30 * 86400;
                foreach (var line in File.ReadAllLines(path))
                {
                    var parts = line.Split(' ');
                    if (parts.Length == 2 &&
                        long.TryParse(parts[0], out var id) && id > 0 &&
                        long.TryParse(parts[1], out var ts) && ts >= cutoff)
                        reads.Add(new KeyValuePair<long, long>(id, ts));
                }
            }
            catch { }
            return reads;
        }

        public static void SaveReads(string path, List<KeyValuePair<long, long>> reads)
        {
            try
            {
                using var w = new StreamWriter(path, false);
                foreach (var r in reads)
                    w.WriteLine(r.Key + " " + r.Value);
            }
            catch { }
        }

        public static bool IsRead(List<KeyValuePair<long, long>> reads, long id)
        {
            foreach (var r in reads) if (r.Key == id) return true;
            return false;
        }

        public static void MarkRead(string appKey, long id)
        {
            var path = StorePath(appKey);
            var reads = LoadReads(path);
            long at = Now.UnixSeconds();
            for (int i = 0; i < reads.Count; i++)
            {
                if (reads[i].Key == id)
                {
                    reads[i] = new KeyValuePair<long, long>(id, at);
                    SaveReads(path, reads);
                    return;
                }
            }
            reads.Add(new KeyValuePair<long, long>(id, at));
            SaveReads(path, reads);
        }

        public static void ClearReads(string appKey)
        {
            try { File.Delete(StorePath(appKey)); } catch { }
        }
    }

    // ── 自身完整性自校验 ────────────────────────────────────────────────────
    public static class Integrity
    {
        /// <summary>空串 = 通过；非空为拒绝原因（中文）</summary>
        public static string VerifySelfIntegrity(string selfFileHash, long selfFileSize)
        {
            if (string.IsNullOrEmpty(selfFileHash) && selfFileSize <= 0) return "";
            string? path;
            try { path = Environment.ProcessPath; }
            catch { path = null; }
            if (string.IsNullOrEmpty(path)) return "无法定位程序文件";

            if (!string.IsNullOrEmpty(selfFileHash))
            {
                bool useSha256 = selfFileHash.Length == 64;
                string local = Crypto.FileHashHex(path, useSha256);
                if (local.Length == 0) return "无法读取程序文件，完整性校验失败";
                if (local != selfFileHash) return "程序文件已被修改，请从官方渠道重新下载";
            }
            if (selfFileSize > 0)
            {
                long size = Crypto.FileSizeBytes(path);
                if (size >= 0 && size != selfFileSize) return "程序文件已被修改，请从官方渠道重新下载";
            }
            return "";
        }
    }

    // ── 功能密钥数据包 NF1 ─────────────────────────────────────────────────
    public static class Feature
    {
        private static byte[] DeriveAesKey(string featureKey) => Crypto.Sha256B(featureKey + "|nebula-feature-aes");
        private static string DeriveMacKey(string featureKey) => Crypto.Sha256Hex(featureKey + "|nebula-feature-mac");

        /// <summary>加密核心数据为功能数据包（开发期使用）；失败返回空串</summary>
        public static string Seal(string plain, string featureKey)
        {
            if (string.IsNullOrEmpty(featureKey) || string.IsNullOrEmpty(plain)) return "";
            var iv = new byte[16];
            using (var rng = System.Security.Cryptography.RandomNumberGenerator.Create())
                rng.GetBytes(iv);
            var blob = Crypto.Aes256CbcEncrypt(DeriveAesKey(featureKey), iv, Crypto.Utf8(plain));
            if (blob.Length == 0) return "";
            var p1 = Crypto.B64Encode(blob);
            var mac = Crypto.HmacSha256Hex(DeriveMacKey(featureKey), "NF1." + p1);
            return "NF1." + p1 + "." + mac;
        }

        /// <summary>解开功能数据包（login 成功拿到 feature_key 后调用）</summary>
        public static bool Open(string pack, string featureKey, out string data, out string error)
        {
            data = "";
            if (string.IsNullOrEmpty(pack) || pack.Length < 10 || string.IsNullOrEmpty(featureKey))
            { error = "数据包或功能密钥为空"; return false; }
            if (!pack.StartsWith("NF1.")) { error = "数据包版本不匹配"; return false; }

            int d1 = pack.IndexOf('.', 4);
            if (d1 < 0 || pack.IndexOf('.', d1 + 1) >= 0) { error = "数据包格式错误"; return false; }
            string p1 = pack.Substring(4, d1 - 4);
            string mac = pack.Substring(d1 + 1);

            // ① 先验签后解密（encrypt-then-MAC）
            string expected = Crypto.HmacSha256Hex(DeriveMacKey(featureKey), "NF1." + p1);
            if (!Envelope.ConstantTimeEquals(expected, mac))
            { error = "数据包校验失败（被篡改或功能密钥错误）"; return false; }

            // ② 解密（blob 自带前置 IV）
            var outB = Crypto.Aes256CbcDecrypt(DeriveAesKey(featureKey), Crypto.B64Decode(p1));
            data = outB == null ? "" : Crypto.Str(outB);
            if (data.Length == 0) { error = "数据包解密失败"; return false; }
            error = "";
            return true;
        }
    }

    /// <summary>秒级 Unix 时间</summary>
    public static class Now
    {
        public static long UnixSeconds()
            => (long)(DateTimeOffset.UtcNow.ToUnixTimeSeconds());
    }
}

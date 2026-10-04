// ============================================================================
// Nebula C# SDK · 自动更新
// 检测 → 下载 → SHA256/大小校验 → 批处理脚本自替换 → 重启
// ============================================================================
using System;
using System.Diagnostics;
using System.IO;

namespace Nebula.Sdk
{
    public enum UpdateState
    {
        NoUpdate,
        Downloaded,
        NeedConfirm,
        Applied,
        Failed,
        Disabled,
    }

    public sealed class UpdateResult
    {
        public UpdateState State;
        public string Msg = "";
        public string Version = "";
        public string NewFile = "";
        public string VerifyHash = "";
        public long VerifySize;
        public bool Force;
    }

    public static partial class Update
    {
        private static bool IsHttps(string url)
            => url.StartsWith("https://", StringComparison.OrdinalIgnoreCase);

        private static string SelfDir()
        {
            try { return AppContext.BaseDirectory; }
            catch { return ""; }
        }

        private static string FileNameFromUrl(string url)
        {
            string s = url;
            int q = s.IndexOfAny(new[] { '?', '#' });
            if (q >= 0) s = s.Substring(0, q);
            int slash = s.LastIndexOf('/');
            return slash >= 0 ? s.Substring(slash + 1) : s;
        }

        private static string UrlHost(string url)
        {
            string s = url.ToLowerInvariant();
            int scheme = s.IndexOf("://", StringComparison.Ordinal);
            if (scheme < 0) return "";
            s = s.Substring(scheme + 3);
            int path = s.IndexOfAny(new[] { '/', '?', '#' });
            if (path >= 0) s = s.Substring(0, path);
            int at = s.LastIndexOf('@');
            if (at >= 0) s = s.Substring(at + 1);
            int colon = s.IndexOf(':');
            if (colon >= 0) s = s.Substring(0, colon);
            return s;
        }

        private static bool VerifyDownloaded(string path, string hash, long size, out string error)
        {
            error = "";
            if (!string.IsNullOrEmpty(hash))
            {
                bool useSha = hash.Length == 64;
                string local = Crypto.FileHashHex(path, useSha);
                if (local.Length == 0) { error = "无法读取更新包，校验失败"; return false; }
                if (!string.Equals(local, hash, StringComparison.OrdinalIgnoreCase))
                { error = "更新包哈希不匹配，已取消安装（可能被篡改）"; return false; }
            }
            if (size > 0)
            {
                long localSize = Crypto.FileSizeBytes(path);
                if (localSize >= 0 && localSize != size)
                { error = "更新包大小不匹配，已取消安装（可能被篡改）"; return false; }
            }
            return true;
        }

        private static void RemoveFileQuiet(string path)
        {
            try { File.Delete(path); } catch { }
        }

        /// <summary>替换文件并重启：生成批处理脚本，等本进程退出后 move → start → 清理</summary>
        private static bool ApplyUpdateAndRestart(string file, string hash, long size,
                                                  bool exitWhenApplied, out string error)
        {
            error = "";
            string dir = SelfDir();
            if (string.IsNullOrEmpty(dir)) { error = "无法定位程序所在目录"; return false; }
            string self = Process.GetCurrentProcess().MainModule?.FileName ?? "";
            if (string.IsNullOrEmpty(self)) { error = "无法定位当前程序文件"; return false; }

            // 替换前最后校验一次
            if (!VerifyDownloaded(file, hash, size, out error)) return false;

            string script = Path.Combine(dir, "nebula_upd_" + Crypto.RandomHex(4) + ".cmd");
            var bat = new System.Text.StringBuilder();
            bat.AppendLine("@echo off");
            bat.AppendLine("setlocal");
            bat.AppendLine("rem Nebula SDK 自动更新脚本（自动生成，执行完自动删除）");
            bat.AppendLine(":wait");
            bat.AppendLine("timeout /t 1 /nobreak >nul");
            bat.AppendLine($"tasklist /fi \"pid eq {Process.GetCurrentProcess().Id}\" | find /i \"{Path.GetFileName(self)}\" >nul && goto wait");
            bat.AppendLine(":apply");
            bat.AppendLine("move /y \"" + file + "\" \"" + self + "\" >nul");
            if (!string.IsNullOrEmpty(hash))
                bat.AppendLine("rem 预期 SHA256: " + hash);
            bat.AppendLine("start \"\" \"" + self + "\"");
            bat.AppendLine("del \"%~f0\"");
            File.WriteAllText(script, bat.ToString());

            try
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = script,
                    UseShellExecute = true,
                    CreateNoWindow = true,
                });
            }
            catch (Exception ex)
            {
                error = "更新脚本启动失败：" + ex.Message;
                return false;
            }

            if (exitWhenApplied)
            {
                Environment.Exit(0);   // 即刻退出，交给脚本完成替换与重启
            }
            return true;
        }

        /// <summary>只下载更新包（不替换、不退出）</summary>
        public static UpdateResult Download(InitResult init, ClientOptions options)
        {
            var r = new UpdateResult();
            if (!options.AutoUpdateEnable)
            {
                r.State = UpdateState.Disabled;
                r.Msg = "自动更新未启用（ClientOptions.AutoUpdateEnable = false）";
                return r;
            }

            r.Version = init.Latest;
            r.VerifyHash = init.FileHash;
            r.VerifySize = init.FileSize;
            r.Force = init.ForceUpdate;

            if (!init.NeedUpdate)
            {
                r.State = UpdateState.NoUpdate;
                r.Msg = "已是最新版本";
                return r;
            }
            if (string.IsNullOrEmpty(init.UpdateUrl))
            {
                r.State = UpdateState.Failed;
                r.Msg = "服务器未提供更新包下载地址，请到官网手动下载";
                return r;
            }
            if (!options.AllowInsecureUpdate && !IsHttps(init.UpdateUrl))
            {
                r.State = UpdateState.Failed;
                r.Msg = "更新地址不是 https，已拒绝下载（如需允许请打开 AllowInsecureUpdate）";
                return r;
            }

            string dir = SelfDir();
            if (string.IsNullOrEmpty(dir))
            {
                r.State = UpdateState.Failed;
                r.Msg = "无法定位程序所在目录，更新中止";
                return r;
            }
            string dest = Path.Combine(dir, "nebula_upd_" + Crypto.RandomHex(6) + "_" + FileNameFromUrl(init.UpdateUrl));

            var ho = new Http.Options
            {
                ConnectTimeoutMs = options.ConnectTimeoutMs,
                ReceiveTimeoutMs = options.ReceiveTimeoutMs,
                UseSystemProxy = options.UseSystemProxy,
                // 指纹锁定只对同 host 生效；更新包常在 CDN，跨 host 由 hash/大小校验兜底
                CertSha256 = UrlHost(init.UpdateUrl) == UrlHost(options.ApiUrl)
                           ? options.TlsCertSha256 : "",
            };
            if (!Http.DownloadToFile(init.UpdateUrl, dest, ho, out var err))
            {
                r.State = UpdateState.Failed;
                r.Msg = err;
                return r;
            }
            if (!VerifyDownloaded(dest, init.FileHash, init.FileSize, out err))
            {
                RemoveFileQuiet(dest);
                r.State = UpdateState.Failed;
                r.Msg = err;
                return r;
            }

            r.State = UpdateState.Downloaded;
            r.NewFile = dest;
            r.Msg = "更新包已下载并校验通过（版本 " + init.Latest + "）";
            return r;
        }

        /// <summary>全自动更新：检测 → 下载 → 校验 → 替换重启</summary>
        public static UpdateResult Auto(InitResult init, ClientOptions options,
                                        Action<string, string>? uiAlert,
                                        bool exitWhenApplied = true)
        {
            var r = Download(init, options);
            if (r.State != UpdateState.Downloaded) return r;

            if (!r.Force && !options.AutoUpdateOptional)
            {
                r.State = UpdateState.NeedConfirm;
                uiAlert?.Invoke("update",
                    "发现新版本 " + r.Version + "，已下载完成，重启后生效。");
                return r;
            }

            if (!ApplyUpdateAndRestart(r.NewFile, r.VerifyHash, r.VerifySize, exitWhenApplied, out var err))
            {
                r.State = UpdateState.Failed;
                r.Msg = err;
                return r;
            }
            r.State = UpdateState.Applied;
            r.Msg = "更新已就绪，程序即将重启完成升级";
            return r;
        }
    }
}

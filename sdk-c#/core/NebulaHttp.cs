// ============================================================================
// Nebula C# SDK · HTTP 传输
// 同步 POST + 可选证书指纹锁定（HttpClient+SslStream 校验实现）。
// ============================================================================
using System;
using System.Net;
using System.Net.Http;
using System.Net.Security;
using System.Security.Cryptography;
using System.Text;
using System.Threading.Tasks;

namespace Nebula.Sdk
{
    public static class Http
    {
        public sealed class Options
        {
            public int ConnectTimeoutMs = 8000;
            public int ReceiveTimeoutMs = 15000;
            public string CertSha256 = "";      // 归一化后的 64 位 hex（空 = 不锁定）
            public bool UseSystemProxy = true;  // .NET 默认走系统代理
        }

        public sealed class HttpResponse
        {
            public int Status;
            public string Body = "";
        }

        private static readonly Lazy<HttpClient> LazyClient = new(() =>
        {
            var handler = new HttpClientHandler
            {
                AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate,
                UseProxy = false,   // 默认不走系统代理（防本地调试被代理劫持）
            };
            // 证书指纹锁定：远程证书校验回调里比对 SHA256
            handler.ServerCertificateCustomValidationCallback = (_, cert, _, errors) =>
            {
                if (cert == null || errors != SslPolicyErrors.None) return false;
                var pin = CurrentPin;
                var pinned = pin != null ? pin.Value : null;
                if (string.IsNullOrEmpty(pinned)) return true;
                using var sha = SHA256.Create();
                var fp = Crypto.BytesToHex(sha.ComputeHash(cert.GetRawCertData()));
                return string.Equals(fp, pinned, StringComparison.OrdinalIgnoreCase);
            };
            return new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(20) };
        });

        /// <summary>当前请求要锁定的证书指纹（AsyncLocal 跨回调传递）</summary>
        private static readonly System.Threading.AsyncLocal<string?> CurrentPin = new();

        public static HttpResponse Post(string url, string body, Options options)
        {
            try
            {
                var task = PostAsync(url, body, options);
                task.Wait();
                return task.Result;
            }
            catch (AggregateException ex) when (ex.InnerException is TaskCanceledException)
            {
                return new HttpResponse { Status = 0, Body = "" };
            }
            catch
            {
                return new HttpResponse { Status = 0, Body = "" };
            }
        }

        public static async Task<HttpResponse> PostAsync(string url, string body, Options options)
        {
            CurrentPin.Value = options.CertSha256;
            try
            {
                using var request = new HttpRequestMessage(HttpMethod.Post, url)
                {
                    Content = new StringContent(body, Encoding.UTF8, "application/json"),
                };
                var proxy = options.UseSystemProxy ? null : new HttpClientHandler().Proxy;
                _ = proxy;
                using var cts = new System.Threading.CancellationTokenSource(
                    TimeSpan.FromMilliseconds(options.ConnectTimeoutMs + options.ReceiveTimeoutMs));
                using var resp = await LazyClient.Value.SendAsync(request, cts.Token).ConfigureAwait(false);
                var text = await resp.Content.ReadAsStringAsync(cts.Token).ConfigureAwait(false);
                return new HttpResponse { Status = (int)resp.StatusCode, Body = text };
            }
            finally
            {
                CurrentPin.Value = null;
            }
        }

        /// <summary>下载到文件（更新包用）</summary>
        public static bool DownloadToFile(string url, string dest, Options options, out string error)
        {
            error = "";
            try
            {
                using var resp = LazyClient.Value.GetAsync(url).ConfigureAwait(false).GetAwaiter().GetResult();
                if ((int)resp.StatusCode != 200)
                {
                    error = "下载失败：HTTP " + (int)resp.StatusCode;
                    return false;
                }
                using var fs = File.Create(dest);
                resp.Content.CopyToAsync(fs, ctsToken(options)).ConfigureAwait(false).GetAwaiter().GetResult();
                return true;
            }
            catch (Exception ex)
            {
                error = "下载失败：" + ex.Message;
                return false;
            }
        }

        private static System.Threading.CancellationToken ctsToken(Options o)
            => new System.Threading.CancellationTokenSource(
                   TimeSpan.FromMilliseconds(o.ConnectTimeoutMs + o.ReceiveTimeoutMs + 600000)).Token;
    }
}

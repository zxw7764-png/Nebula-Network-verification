// ============================================================================
// Nebula C# SDK · 调试日志（发布时把 SdkConfig.DebugLog 置 false 即可全量关闭）
// ============================================================================
using System.IO;

namespace Nebula.Sdk
{
    public static class NebulaLog
    {
        /// <summary>调试开关（接入方配置）</summary>
        public static bool Enabled = SdkConfig.DebugLog;

        /// <summary>日志文件路径（默认 exe 同目录 nebula_debug.log）</summary>
        public static string FilePath =
            Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "nebula_debug.log");

        private static readonly object _mutex = new();

        public static void Write(string tag, string message)
        {
            if (!Enabled) return;
            try
            {
                var line = $"{DateTime.Now:HH:mm:ss.fff} [{tag}] {message}";
                lock (_mutex) File.AppendAllText(FilePath, line + Environment.NewLine);
            }
            catch { /* 日志失败不影响业务 */ }
        }
    }
}

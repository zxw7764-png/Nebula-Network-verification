// ============================================================================
// Nebula C# SDK · 设备身份
// machine_id 必须持久化后传入；device_fp 懒采集，失败不影响登录。
// ============================================================================
using System;
using System.Linq;
using System.Net.NetworkInformation;
using System.Security.Cryptography;

namespace Nebula.Sdk
{
    public static class Device
    {
        /// <summary>生成随机机器码（16 位 hex）</summary>
        public static string CreateMachineId() => Crypto.RandomHex(8);

        /// <summary>真实电脑主机名</summary>
        public static string ComputerName()
        {
            try
            {
                var name = Environment.MachineName;
                return string.IsNullOrEmpty(name) ? "Windows-PC" : name;
            }
            catch { return "Windows-PC"; }
        }

        /// <summary>主网卡 MAC（12 位小写裸 hex；找不到返回空串）</summary>
        public static string PrimaryMac()
        {
            try
            {
                foreach (var nic in NetworkInterface.GetAllNetworkInterfaces())
                {
                    var mac = nic.GetPhysicalAddress();
                    if (mac == null) continue;
                    var hex = string.Concat(mac.GetAddressBytes().Select(b => b.ToString("x2")));
                    if (hex.Length == 12 && hex != "000000000000")
                        return hex;
                }
            }
            catch { }
            return "";
        }

        /// <summary>指纹组件哈希：MD5 原始值 → 前 16 位 hex</summary>
        private static string FingerprintHash(string raw)
        {
            var digest = Crypto.Md5Hex(raw);
            return digest.Length >= 16 ? digest.Substring(0, 16) : "";
        }

        private static bool FingerprintValueOk(string value)
        {
            if (value.Length < 4) return false;
            var lower = value.ToLowerInvariant();
            string[] placeholders =
            {
                "default", "to be filled", "not specified", "system serial", "chassis", "unknown",
            };
            foreach (var p in placeholders)
                if (lower.Contains(p)) return false;
            return lower != "none" && lower != "0123456789" && lower != "0000000000";
        }

        /// <summary>
        /// WMI 单属性查询（失败返回空串）。通过 PowerShell 子进程实现，
        /// 避免引入 System.Management 依赖；单次查询失败不影响登录。
        /// </summary>
        private static string WmiQuery(string wql, string property)
        {
            try
            {
                using var p = System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
                {
                    FileName = "powershell.exe",
                    Arguments = "-NoProfile -NonInteractive -Command " +
                                $"(Get-CimInstance -Query '{wql}').{property}",
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    CreateNoWindow = true,
                });
                if (p == null) return "";
                var output = p.StandardOutput.ReadToEnd().Trim();
                p.WaitForExit(3000);
                return p.ExitCode == 0 ? output : "";
            }
            catch { return ""; }
        }

        /// <summary>
        /// 采集设备指纹 JSON（board / cpu / disk / bios / mac / gpu）；
        /// 一个有效组件都没有时返回空串（登录请求不带 device_fp 字段）。
        /// </summary>
        public static string CollectFingerprintJson()
        {
            // WMI 子进程开销大，整体限时一次性采集；任何失败都不影响登录
            string board = "", cpu = "", disk = "", bios = "", gpu = "";
            try
            {
                board = WmiQuery("SELECT SerialNumber FROM Win32_BaseBoard", "SerialNumber");
                cpu = WmiQuery("SELECT ProcessorId FROM Win32_Processor", "ProcessorId");
                disk = WmiQuery("SELECT SerialNumber FROM Win32_DiskDrive", "SerialNumber");
                bios = WmiQuery("SELECT SerialNumber FROM Win32_BIOS", "SerialNumber");
                gpu = WmiQuery("SELECT Name FROM Win32_VideoController", "Name");
            }
            catch { }
            string mac = PrimaryMac();

            var sb = new System.Text.StringBuilder("{");
            void Add(string key, string value)
            {
                if (string.IsNullOrEmpty(value)) return;
                if (sb.Length > 1) sb.Append(",");
                sb.Append(Json.Pair(key, Json.Quote(value)));
            }
            if (FingerprintValueOk(board)) Add("board", FingerprintHash(board));
            if (FingerprintValueOk(cpu)) Add("cpu", FingerprintHash(cpu));
            if (FingerprintValueOk(disk)) Add("disk", FingerprintHash(disk));
            if (FingerprintValueOk(bios)) Add("bios", FingerprintHash(bios));
            Add("mac", mac);
            if (FingerprintValueOk(gpu)) Add("gpu", FingerprintHash(gpu));
            sb.Append("}");
            return sb.Length > 2 ? sb.ToString() : "";
        }
    }
}

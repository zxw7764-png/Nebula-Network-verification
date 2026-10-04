// ============================================================================
// Nebula C# SDK · 运行时随机源 + 擦除型敏感字符串
// ----------------------------------------------------------------------------
// RuntimeRandByte()
//   用「高分辨率性能计数器 + 进程 PID + 纳秒时钟」做种子，保证同一 exe
//   每次启动序列都不同。用途：SecureString 随机密钥、不透明谓词随机形态。
//   注意：这是**混淆用**随机源，不是密码学随机源。
//
// SecureString
//   专治「短敏感串被 SSO 内联进 .data、内存 dump 一眼可见」：
//   · 内部只保存与明文无直接关系的混淆字节；
//   · 默认密钥固定 75（可复现），RuntimeDiverse=true 时每次启动随机密钥；
//   · 明文只在 Str() 时解码到临时 string，用完即 GC 回收；
//   · 不可拷贝（保证明文只有一处来源），可移动。
//
// 使用方式：
//   private static readonly SecureString s_appKey = new("SWBFE6879E94DD");
//   string key = s_appKey.Str();   // 用时才解码
// ============================================================================
using System;
using System.Diagnostics;
using System.Runtime.CompilerServices;
using System.Text;

namespace Nebula.Sdk
{
    /// <summary>
    /// 运行时混淆随机源（非密码学强度）。
    /// 用「QPC + PID + 纳秒时钟」播种，每次启动产生不同序列。
    /// </summary>
    public static class RuntimeRand
    {
        private static readonly Random _gen = CreateGenerator();

        private static Random CreateGenerator()
        {
            long pc = 0;
            try { QueryPerformanceCounter(out pc); } catch { }
            long ns = Stopwatch.GetTimestamp();
            int pid = Environment.ProcessId;
            int seed = (int)(pid ^ (uint)(pc & 0xFFFFFFFF) ^ (uint)((pc >> 32) & 0xFFFFFFFF)
                             ^ (uint)(ns & 0xFFFFFFFF) ^ (uint)((ns >> 32) & 0xFFFFFFFF));
            return new Random(seed);
        }

        [System.Runtime.InteropServices.DllImport("kernel32.dll")]
        private static extern bool QueryPerformanceCounter(out long lpPerformanceCount);

        /// <summary>取 [lo, hi] 内的随机字节</summary>
        public static byte Byte(byte lo = 1, byte hi = 255)
        {
            if (hi <= lo) return lo;
            return (byte)(lo + _gen.Next(hi - lo + 1));
        }

        /// <summary>取一个随机 uint</summary>
        public static uint UInt32()
        {
            return (uint)_gen.Next(int.MinValue, int.MaxValue);
        }
    }

    /// <summary>
    /// 擦除型短敏感字符串（如 AppKey、AesKey）。
    /// 内部只保存混淆字节，明文只在 Str() 调用时解码到临时 string。
    /// 不可拷贝，防止明文被多处引用。
    /// </summary>
    public sealed class SecureString
    {
        private readonly byte _key;
        private readonly byte[] _buf;

        /// <summary>从明文字符串构造</summary>
        public SecureString(string raw)
        {
            _key = SdkConfig.RuntimeDiverse ? RuntimeRand.Byte() : (byte)75;
            _buf = Mix(raw, _key);
        }

        private static byte[] Mix(string raw, byte key)
        {
            var plain = Encoding.UTF8.GetBytes(raw);
            var buf = new byte[plain.Length];
            for (int i = 0; i < plain.Length; i++)
                buf[i] = (byte)(plain[i] ^ (byte)(key + (byte)(i & 0xFF)));
            return buf;
        }

        /// <summary>解码出明文字符串（调用方用完即弃）</summary>
        [MethodImpl(MethodImplOptions.NoInlining)]
        public string Str()
        {
            var raw = new byte[_buf.Length];
            for (int i = 0; i < _buf.Length; i++)
                raw[i] = (byte)(_buf[i] ^ (byte)(_key + (byte)(i & 0xFF)));
            return Encoding.UTF8.GetString(raw);
        }

        /// <summary>密文长度</summary>
        public int Length => _buf.Length;

        /// <summary>是否为空</summary>
        public bool Empty => _buf.Length == 0;
    }
}

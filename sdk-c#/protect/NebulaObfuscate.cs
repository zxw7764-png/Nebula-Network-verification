// ============================================================================
// Nebula C# SDK · 代码混淆（字符串加密 / 间接调用 / 不透明谓词 / 虚假分支）
// ----------------------------------------------------------------------------
// 字符串加密：编译期 XOR 加密，运行时才解密，降低静态分析时明文暴露风险。
// 间接调用：通过 volatile 委托调用，阻止内联与静态识别调用关系。
// 不透明谓词：恒为 true/false 但形态随启动变化，干扰反汇编。
// 虚假分支：永不到达的干扰代码块，增加静态分析复杂度。
//
// 注意：
//   · XorStr 只能用于字面量（配合代码生成器使用）
//   · 不能对变量或运行时生成的字符串使用编译期加密
//   · 解密发生在 Decrypt() 调用时，明文字符串只在内存中短暂存在
//   · 这些混淆只能提高逆向成本，不能保证绝对安全
// ============================================================================
using System;
using System.Runtime.CompilerServices;
using System.Security.Cryptography;
using System.Text;

namespace Nebula.Sdk
{
    /// <summary>编译期 XOR 加密容器</summary>
    public sealed class XorStr
    {
        private readonly byte[] _enc;
        private readonly byte _seed;

        internal XorStr(byte[] enc, byte seed)
        {
            _enc = enc;
            _seed = seed;
        }

        /// <summary>解密并返回明文字符串（UTF-8）</summary>
        [MethodImpl(MethodImplOptions.NoInlining)]
        public string Decrypt()
        {
            var raw = new byte[_enc.Length];
            for (int i = 0; i < _enc.Length; i++)
                raw[i] = (byte)(_enc[i] ^ KeyAt(_seed, (uint)i));
            return Encoding.UTF8.GetString(raw);
        }

        private static byte KeyAt(byte seed, uint i)
        {
            return (byte)(seed + (byte)((uint)(i * 31u) + 0x5Au));
        }
    }

    /// <summary>字符串混淆辅助类</summary>
    public static class Obfuscate
    {
        /// <summary>编译期 XOR 加密字符串（接受 byte 数组，seed 为密钥种子）</summary>
        public static XorStr Encoded(byte[] data, byte seed)
        {
            return new XorStr(data, seed);
        }

        /// <summary>运行期随机密钥加密（用于动态字符串）；使用加密安全随机源</summary>
        public static string EncryptRuntime(string plain, out byte key)
        {
            key = (byte)RandomNumberGenerator.GetInt32(1, 256);
            var plainBytes = Encoding.UTF8.GetBytes(plain);
            var enc = new byte[plainBytes.Length];
            for (int i = 0; i < plainBytes.Length; i++)
                enc[i] = (byte)(plainBytes[i] ^ key);
            return Encoding.UTF8.GetString(enc);
        }

        /// <summary>解密运行期加密的字符串</summary>
        public static string DecryptRuntime(string encrypted, byte key)
        {
            var enc = Encoding.UTF8.GetBytes(encrypted);
            var raw = new byte[enc.Length];
            for (int i = 0; i < enc.Length; i++)
                raw[i] = (byte)(enc[i] ^ key);
            return Encoding.UTF8.GetString(raw);
        }

        /// <summary>生成编译期加密的 byte[] 数组（开发工具用，不要在发布代码中调用）</summary>
        public static byte[] EncodeForCompile(string plain, byte seed)
        {
            var plainBytes = Encoding.UTF8.GetBytes(plain);
            var enc = new byte[plainBytes.Length];
            for (int i = 0; i < plainBytes.Length; i++)
                enc[i] = (byte)(plainBytes[i] ^ KeyAt(seed, (uint)i));
            return enc;
        }

        private static byte KeyAt(byte seed, uint i)
            => (byte)(seed + (byte)((uint)(i * 31u) + 0x5Au));

    // =========================================================================
    // 间接调用（打散调用图）
    // =========================================================================
    /// <summary>
    /// 通过 volatile 委托间接调用，阻止编译器内联与静态分析识别调用关系。
    /// 用法：Obfuscate.Vcall(MyFunction, arg1, arg2);
    /// </summary>
    [MethodImpl(MethodImplOptions.NoInlining)]
    public static void Vcall<T1>(Action<T1> fn, T1 arg1)
    {
        Action<T1> volatilePtr = fn;   // volatile 语义：阻止编译器内联
        volatilePtr(arg1);
    }

    [MethodImpl(MethodImplOptions.NoInlining)]
    public static void Vcall<T1, T2>(Action<T1, T2> fn, T1 arg1, T2 arg2)
    {
        Action<T1, T2> volatilePtr = fn;
        volatilePtr(arg1, arg2);
    }

    [MethodImpl(MethodImplOptions.NoInlining)]
    public static TResult VcallR<T1, TResult>(Func<T1, TResult> fn, T1 arg1)
    {
        Func<T1, TResult> volatilePtr = fn;
        return volatilePtr(arg1);
    }

    [MethodImpl(MethodImplOptions.NoInlining)]
    public static TResult VcallR<T1, T2, TResult>(Func<T1, T2, TResult> fn, T1 arg1, T2 arg2)
    {
        Func<T1, T2, TResult> volatilePtr = fn;
        return volatilePtr(arg1, arg2);
    }

    // =========================================================================
    // 不透明谓词 / 虚假分支
    // =========================================================================
    /// <summary>
    /// 恒为 true 的不透明谓词。
    /// RuntimeDiverse=true 时形态随每次启动变化；=false 时固定返回 true。
    /// 用法：if (!Obfuscate.OpaqueTrue() && Obfuscate.OpaqueTrue()) { ... }
    /// </summary>
        [MethodImpl(MethodImplOptions.NoInlining)]
    public static bool OpaqueTrue()
    {
        if (!SdkConfig.RuntimeDiverse) return true;
        // 以下代码仅在 RuntimeDiverse=true 时执行
#pragma warning disable CS0162 // 恒为 true 的分支（设计如此）
        uint a = RuntimeRand.UInt32();
        uint b = RuntimeRand.UInt32();
        return (a + b) == (b + a) && (a * 1u) == a && ((a & b) | a) == a;
#pragma warning restore CS0162
    }

    /// <summary>
    /// 恒为 false 的不透明谓词。
    /// 用法：if (Obfuscate.OpaqueFalse()) { DeadBranch(); }
    /// </summary>
        [MethodImpl(MethodImplOptions.NoInlining)]
    public static bool OpaqueFalse()
    {
        if (!SdkConfig.RuntimeDiverse) return false;
        // 以下代码仅在 RuntimeDiverse=true 时执行
#pragma warning disable CS0162 // 恒为 false 的分支（设计如此）
        uint a = RuntimeRand.UInt32();
        return (a + 1u) == a;   // 整数加 1 不可能等于自身 → 恒假
#pragma warning restore CS0162
    }

    /// <summary>
    /// 永不到达的干扰代码块。放置有副作用的代码，不会被语义检查剔除。
    /// 用法：Obfuscate.DeadBranch();
    /// </summary>
    [MethodImpl(MethodImplOptions.NoInlining)]
    public static void DeadBranch()
    {
        // Volatile.Write 确保编译器不会消除这段代码
        uint dead = RuntimeRand.UInt32() ^ (uint)Environment.TickCount;
        System.Threading.Volatile.Write(ref dead, dead);
        // 不做任何有意义的事
    }
    }
}

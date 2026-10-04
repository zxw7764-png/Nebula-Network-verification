#pragma once
// ============================================================================
// Nebula SDK · 代码混淆（字符串加密 / 间接调用 / 不透明谓词）
// ----------------------------------------------------------------------------
// NEBULA_OBF_STRINGS=1（或 NEBULA_HARDEN=1）时生效；=0 时全部退化为等价写法，
// 语义完全一致、零开销，所以业务代码可以直接写 NEBULA_STR("...") 而无需条件编译。
//
// ⚠ NEBULA_STR / NEBULA_WSTR 只能用于**字符串字面量**，不能传变量或 std::string。
//   必须用 constexpr 局部对象承载密文数组，XOR 才会在编译期完成；
//   写成 `XorStr<...>(s).str()`（临时对象）时 MSVC 会退回运行期循环，
//   明文原样留在目标文件里（等于没加密）—— 判据见第 2 节自测方法。
// ============================================================================

#include "../config.hpp"
#include "../core/secure_string.hpp"   // 运行时多样性随机源

namespace nebula {
namespace obf {
namespace detail {

/** 编译期密钥派生：同一字面量在每行/每次出现都得到不同密钥 */
constexpr uint8_t keyAt(uint8_t seed, size_t i) {
    return (uint8_t)(seed + (uint8_t)(i * 31u) + 0x5Au);
}

} // namespace detail

/** 编译期 XOR 容器：构造时把字面量逐字节异或存入 enc[]，运行期解密回 std::string */
template <typename C, size_t N, uint8_t Seed>
struct XorStr {
    C enc[N];

    constexpr XorStr(const C (&s)[N]) : enc{} {
        for (size_t i = 0; i < N; ++i)
            enc[i] = (C)((uint32_t)s[i] ^ (uint32_t)detail::keyAt(Seed, i));
    }

    /** noinline：防止编译器把"密文 + 解密循环"折叠回明文常量 */
    NEBULA_NOINLINE std::basic_string<C> str() const {
        std::basic_string<C> out;
        out.resize(N > 0 ? N - 1 : 0);
        for (size_t i = 0; i + 1 < N; ++i)
            out[i] = (C)((uint32_t)enc[i] ^ (uint32_t)detail::keyAt(Seed, i));
        return out;
    }
};

// ---------------------------------------------------------------------------
// 间接调用：通过 volatile 函数指针调用，阻止内联与静态识别调用关系
// ---------------------------------------------------------------------------
/** 无返回值 */
template <typename... Args, typename... CallArgs>
inline void vcall(void (*fn)(Args...), CallArgs&&... args) {
    void (*volatile ptr)(Args...) = fn;
    void (*const local)(Args...) = ptr;          // 先读一次 volatile 再调用，避免被优化成直接调用
    local(std::forward<CallArgs>(args)...);
}

/** 有返回值（形参类型只由 fn 推导，实参完美转发，避免 std::string 左值推导冲突） */
template <typename Ret, typename... Args, typename... CallArgs>
inline Ret vcallR(Ret (*fn)(Args...), CallArgs&&... args) {
    Ret (*volatile ptr)(Args...) = fn;
    Ret (*const local)(Args...) = ptr;
    return local(std::forward<CallArgs>(args)...);
}

// ---------------------------------------------------------------------------
// 不透明谓词 / 虚假分支
// ---------------------------------------------------------------------------
#if NEBULA_RUNTIME_DIVERSE
/** 恒为 true，但形态随每次启动变化（反汇编特征不固定） */
NEBULA_NOINLINE inline bool opaqueTrue() {
    const unsigned a = runtimeRandByte();
    const unsigned b = runtimeRandByte();
    return (a + b) == (b + a) && (a * 1u) == a && ((a & b) | a) == a;
}
/** 恒为 false，形态随每次启动变化 */
NEBULA_NOINLINE inline bool opaqueFalse() {
    const unsigned a = runtimeRandByte();
    return (a + 1u) == a;                        // 整数加 1 不可能等于自身 → 恒假
}
#else
/** 恒为 true（零开销） */
NEBULA_NOINLINE inline bool opaqueTrue() { return true; }
/** 恒为 false（零开销） */
NEBULA_NOINLINE inline bool opaqueFalse() { return false; }
#endif

} // namespace obf
} // namespace nebula

// ---------------------------------------------------------------------------
// 编译期字符串加密
// ---------------------------------------------------------------------------
#if NEBULA_OBF_STRINGS
#  define NEBULA_OBF_KEY() ((uint8_t)((__LINE__ * 7 + __COUNTER__ * 13) & 0xFF))
#  define NEBULA_STR(s)                                                            \
      ([]{ constexpr ::nebula::obf::XorStr<char, sizeof(s), NEBULA_OBF_KEY()>      \
               _nb_obf{s};                                                         \
           return _nb_obf.str(); }())
#  define NEBULA_WSTR(s)                                                           \
      ([]{ constexpr ::nebula::obf::XorStr<wchar_t, (sizeof(s) / sizeof(wchar_t)), \
               NEBULA_OBF_KEY()> _nb_obf{s};                                       \
           return _nb_obf.str(); }())
#else
#  define NEBULA_STR(s)  (std::string(s))
#  define NEBULA_WSTR(s) (std::wstring(s))
#endif

// 不透明谓词别名（业务代码可读性更好）
#define NEBULA_OPAQUE_TRUE()  (::nebula::obf::opaqueTrue())
#define NEBULA_OPAQUE_FALSE() (::nebula::obf::opaqueFalse())

/** 永不到达的干扰代码块（可放副作用，不会被语义检查剔除） */
#define NEBULA_DEAD_BRANCH()                                                       \
    do {                                                                           \
        volatile uint32_t _nb_dead =                                               \
            (uint32_t)::nebula::runtimeRandByte() ^ (uint32_t)::GetTickCount64();   \
        NEBULA_UNUSED(_nb_dead);                                                    \
    } while (0)

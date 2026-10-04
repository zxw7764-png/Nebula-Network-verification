#pragma once
// ============================================================================
// Nebula SDK · 运行时随机源 + 擦除型敏感字符串
// ----------------------------------------------------------------------------
// runtimeRandByte()
//   用「高分辨率性能计数器 + 进程 PID + 纳秒时钟」播种 mt19937（magic static，
//   C++11 起线程安全、只初始化一次），保证同一 exe 每次启动序列都不同。
//   用途：SecureString 随机密钥、不透明谓词随机形态（运行时数据面多样性）。
//   注意：这是**混淆用**随机源，不是密码学随机源；密钥/盐/nonce 请用
//         crypto::randomBytes()（BCryptGenRandom）。
//
// SecureString
//   专治「短敏感串被 SSO 内联进 .data、内存 dump 一眼可见」：
//   · 内部只保存与明文无直接关系的混淆字节；
//   · 默认密钥固定 75（可复现），NEBULA_RUNTIME_DIVERSE=1 时每次启动随机密钥；
//   · 明文只在 str() 时解码到临时 std::string，用完即析构；
//   · 不可拷贝（保证明文只有一处来源），可移动。
// ============================================================================

#include "../config.hpp"

#include <vector>

namespace nebula {

/**
 * 安全擦除内存（volatile 语义写入，编译器不会优化掉）。
 * 专治「明文虽已 free，但内容仍残留堆页可被 dump」。
 */
inline void secureZero(void* p, size_t n) noexcept {
    volatile unsigned char* v = static_cast<volatile unsigned char*>(p);
    while (n--) *v++ = 0;
}

/** 安全擦除 std::string 的内容（含已分配 capacity 的部分） */
inline void secureWipe(std::string& s) noexcept {
    if (!s.empty()) secureZero(&s[0], s.capacity());
    s.clear();
}

/**
 * 擦除型敏感字节缓冲（如 feature_key 解出的核心数据、会话密钥）。
 *
 * 与 SecureString 的区别：SecureString 保护「静态短敏感串」（混淆存储），
 * SecureBuffer 保护「运行期解密出来的大块明文」：
 *   · 析构 / wipe() 时 secureZero 擦除（内容不再残留堆页）；
 *   · 可选 VirtualLock 锁页，防止敏感明文被交换到磁盘页面文件；
 *   · 不可拷贝（明文只有一份），可移动。
 *
 * 用法：
 *     nebula::SecureBuffer core(true);          // true = 锁页
 *     if (nebula::feature::openSecure(pack, lr.feature_key, core, err)) {
 *         use(core.data(), core.size());        // 核心数据就绪
 *     }
 *     // core 出作用域自动擦除；或尽早 core.wipe();
 */
class SecureBuffer {
public:
    explicit SecureBuffer(bool lockPages = false) noexcept
        : locked_(false), wantLock_(lockPages) {}

    ~SecureBuffer() { wipe(); }

    SecureBuffer(const SecureBuffer&)            = delete;
    SecureBuffer& operator=(const SecureBuffer&) = delete;

    SecureBuffer(SecureBuffer&& other) noexcept
        : locked_(false), wantLock_(other.wantLock_), buf_(std::move(other.buf_)) {
        other.locked_ = false;
        other.buf_.clear();
    }
    SecureBuffer& operator=(SecureBuffer&& other) noexcept {
        if (this != &other) {
            wipe();
            wantLock_     = other.wantLock_;
            buf_          = std::move(other.buf_);
            other.buf_.clear();
        }
        return *this;
    }

    /** 用内容填充缓冲（覆盖旧内容并擦除）；填充后按需锁页 */
    bool assign(const unsigned char* p, size_t n) {
        wipe();
        buf_.assign(p, p + n);
        return lockIfWanted();
    }

    NEBULA_MUST_CHECK unsigned char*      data()        noexcept { return buf_.empty() ? nullptr : &buf_[0]; }
    NEBULA_MUST_CHECK const unsigned char* data() const  noexcept { return buf_.empty() ? nullptr : &buf_[0]; }
    NEBULA_MUST_CHECK size_t               size()  const noexcept { return buf_.size(); }
    NEBULA_MUST_CHECK bool                 empty() const noexcept { return buf_.empty(); }

    /** 锁页（防交换到磁盘）；失败不影响使用，只是保护降级 */
    bool lockPages() {
        if (locked_) return true;
        if (buf_.empty()) return false;
        if (!::VirtualLock(&buf_[0], buf_.size())) return false;
        locked_ = true;
        return true;
    }

    /** 手动擦除（析构自动调用） */
    void wipe() noexcept {
        unlock();
        if (!buf_.empty()) secureZero(&buf_[0], buf_.size() * sizeof(unsigned char));
        buf_.clear();
    }

private:
    bool lockIfWanted() {
        if (!wantLock_) return true;
        wantLock_ = false;          // 只在 assign 时尝试一次
        return lockPages();
    }
    void unlock() noexcept {
        if (locked_ && !buf_.empty()) {
            ::VirtualUnlock(&buf_[0], buf_.size());
            locked_ = false;
        }
    }

    bool                     locked_   = false;
    bool                     wantLock_ = false;
    std::vector<unsigned char> buf_;
};

/** 取 [lo, hi] 内的随机字节；lo >= hi 时返回 lo */
inline unsigned char runtimeRandByte(unsigned char lo = 1, unsigned char hi = 255) {
    static std::mt19937 gen = [] {
        LARGE_INTEGER pc{};
        ::QueryPerformanceCounter(&pc);
        const auto ns = std::chrono::high_resolution_clock::now().time_since_epoch().count();
        std::seed_seq seed{
            (unsigned)::GetCurrentProcessId(),
            (unsigned)pc.QuadPart,
            (unsigned)(pc.QuadPart >> 32),
            (unsigned)ns,
            (unsigned)(ns >> 32),
        };
        return std::mt19937(seed);
    }();
    if (hi <= lo) return lo;
    return (unsigned char)(lo + (unsigned)(gen() % (unsigned)(hi - lo + 1)));
}

/**
 * 擦除型短敏感字符串（如 app_key）。
 *
 * 用法：
 *     inline const SecureString kAppKey{ NEBULA_STR("SW83CBD02D913F") };
 *     std::string key = kAppKey.str();     // 用时才解码，用完自动销毁
 */
class SecureString {
public:
    explicit SecureString(const std::string& raw) noexcept
        : key_((unsigned char)(NEBULA_RUNTIME_DIVERSE ? runtimeRandByte() : 75)) {
        mix(raw);
    }

    SecureString(const SecureString&)            = delete;
    SecureString& operator=(const SecureString&) = delete;
    SecureString(SecureString&& other) noexcept
        : key_(other.key_), buf_(std::move(other.buf_)) {}

    /** 解码出明文（调用方用完即弃；不常驻、不留静态副本） */
    NEBULA_MUST_CHECK std::string str() const {
        std::string out;
        out.reserve(buf_.size());
        const unsigned char k = key_;
        for (size_t i = 0; i < buf_.size(); ++i)
            out += (char)((unsigned char)buf_[i] ^ (unsigned char)(k + (unsigned char)i));
        return out;
    }

    NEBULA_MUST_CHECK size_t size()  const noexcept { return buf_.size(); }
    NEBULA_MUST_CHECK bool   empty() const noexcept { return buf_.empty(); }

private:
    void mix(const std::string& raw) {
        buf_.reserve(raw.size());
        for (size_t i = 0; i < raw.size(); ++i)
            buf_ += (char)((unsigned char)raw[i] ^ (unsigned char)(key_ + (unsigned char)i));
    }

    const unsigned char key_;
    std::string buf_;
};

} // namespace nebula

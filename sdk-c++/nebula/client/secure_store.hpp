#pragma once
// ============================================================================
// Nebula SDK · 本地会话安全存储（DPAPI 绑机器加密）
// ----------------------------------------------------------------------------
// 对应 3.1 安全方案 3.4：替代明文 session.dat。
//
//   · CryptProtectData 以 sha256(machine_id) 为附加熵：文件拷到别的机器
//     （或重装系统后机器码变化）直接解不开 —— 复制文件攻击失效；
//   · 存储内容 = 接入方给的任意 JSON（建议只存短时效 token）；
//   · 文件落在 %APPDATA%\NebulaSDK\<标识>.bin，目录按需创建；
//   · token 有效期由服务端控制（短命可吊销），本地这份只是「续登缓存」，
//     被本机恶意软件读到也只有 10 分钟窗口。
//
// 使用示例：
//     nebula::secure::SessionStore store("MyMenu");
//     store.save(sessionJson);                        // 登录成功后
//     std::string cached = store.load();              // 启动时静默续登
//     store.remove();                                 // 登出
// ============================================================================

#include "../core/crypto.hpp"
#include "../core/text.hpp"

#include <shlobj.h>
#include <wincrypt.h>

#pragma comment(lib, "crypt32.lib")
#pragma comment(lib, "shell32.lib")   // SHGetFolderPathA

namespace nebula {
namespace secure {

/** DPAPI 加密：entropy = sha256(machineId)，返回 base64 密文；失败返回空串 */
NEBULA_MUST_CHECK inline std::string protectData(const std::string& plain,
                                                 const std::string& machineId) {
    if (plain.empty()) return {};

    const std::string entropy = crypto::sha256(machineId);
    DATA_BLOB in{}, ent{}, out{};
    in.pbData = reinterpret_cast<BYTE*>(const_cast<char*>(plain.data()));
    in.cbData = (DWORD)plain.size();
    if (!entropy.empty()) {
        ent.pbData = reinterpret_cast<BYTE*>(const_cast<char*>(entropy.data()));
        ent.cbData = (DWORD)entropy.size();
    }

    LPWSTR desc = nullptr;
    if (!::CryptProtectData(&in, L"nebula-session", entropy.empty() ? nullptr : &ent,
                            nullptr, nullptr, CRYPTPROTECT_UI_FORBIDDEN, &out)) {
        return {};
    }
    std::string cipher(reinterpret_cast<const char*>(out.pbData), out.cbData);
    ::LocalFree(out.pbData);
    return b64Encode(cipher);
}

/** DPAPI 解密（protectData 的逆操作）；失败返回空串 */
NEBULA_MUST_CHECK inline std::string unprotectData(const std::string& b64Cipher,
                                                   const std::string& machineId) {
    if (b64Cipher.empty()) return {};
    const std::string cipher = b64Decode(b64Cipher);
    if (cipher.empty()) return {};

    const std::string entropy = crypto::sha256(machineId);
    DATA_BLOB in{}, ent{}, out{};
    in.pbData = reinterpret_cast<BYTE*>(const_cast<char*>(cipher.data()));
    in.cbData = (DWORD)cipher.size();
    if (!entropy.empty()) {
        ent.pbData = reinterpret_cast<BYTE*>(const_cast<char*>(entropy.data()));
        ent.cbData = (DWORD)entropy.size();
    }

    if (!::CryptUnprotectData(&in, nullptr, entropy.empty() ? nullptr : &ent,
                              nullptr, nullptr, CRYPTPROTECT_UI_FORBIDDEN, &out)) {
        return {};
    }
    std::string plain(reinterpret_cast<const char*>(out.pbData), out.cbData);
    ::LocalFree(out.pbData);
    return plain;
}

/** 存储文件路径：%APPDATA%\NebulaSDK\<name>.bin；失败返回空串 */
inline std::string storePath(const std::string& name) {
    char appData[MAX_PATH] = {};
    if (FAILED(::SHGetFolderPathA(nullptr, CSIDL_APPDATA, nullptr, 0, appData))) return {};
    std::string dir = std::string(appData) + "\\NebulaSDK";
    ::CreateDirectoryA(dir.c_str(), nullptr);
    return dir + "\\" + name + ".bin";
}

/**
 * 本地会话存储：DPAPI 绑机器加密，换机/重装自动失效。
 * name 用软件标识（同机多软件互不覆盖）。
 */
class SessionStore {
public:
    explicit SessionStore(const std::string& appName, const std::string& machineId)
        : path_(storePath(appName)), machine_id_(machineId) {}

    /** 保存（自动 DPAPI 加密）；失败返回 false */
    bool save(const std::string& sessionJson) {
        if (path_.empty() || sessionJson.empty()) return false;
        const std::string blob = protectData(sessionJson, machine_id_);
        if (blob.empty()) return false;
        HANDLE f = ::CreateFileA(path_.c_str(), GENERIC_WRITE, 0,
                                 nullptr, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, nullptr);
        if (f == INVALID_HANDLE_VALUE) return false;
        DWORD written = 0;
        const bool ok = ::WriteFile(f, blob.data(), (DWORD)blob.size(), &written, nullptr)
                     && written == blob.size();
        ::CloseHandle(f);
        return ok;
    }

    /** 读取并解密；文件不存在 / 换机解不开 / 内容损坏一律返回空串 */
    NEBULA_MUST_CHECK std::string load() const {
        if (path_.empty()) return {};
        HANDLE f = ::CreateFileA(path_.c_str(), GENERIC_READ, FILE_SHARE_READ,
                                 nullptr, OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, nullptr);
        if (f == INVALID_HANDLE_VALUE) return {};
        std::string blob;
        char buf[4096];
        DWORD read = 0;
        while (::ReadFile(f, buf, sizeof(buf), &read, nullptr) && read > 0) {
            blob.append(buf, read);
        }
        ::CloseHandle(f);
        return unprotectData(blob, machine_id_);
    }

    /** 删除（登出 / 检测到异常时调用） */
    void remove() const {
        if (!path_.empty()) ::DeleteFileA(path_.c_str());
    }

private:
    std::string path_;
    std::string machine_id_;
};

} // namespace secure
} // namespace nebula

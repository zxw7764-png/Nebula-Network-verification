#pragma once
// ============================================================================
// Nebula SDK · 客户端自身完整性自校验（防篡改）
// ----------------------------------------------------------------------------
// 原理：启动时计算**自身 exe** 的哈希（MD5 / SHA256）与字节数，与服务端「版本管理」
//       里该版本登记的值比对，不一致即判定被篡改。
//
// 服务端规则（见 api/handlers/init.php）：
//   · 仅当客户端版本号 == 最新已发布版本时才下发 self_file_hash / self_file_size；
//   · 未登记时下发 "" / 0，客户端跳过校验（不会误拦）。
//
// ⚠ 每次重新编译 exe 都会改变哈希 —— 发版前必须在后台重新登记，否则新包会被自己拦下。
// ============================================================================

#include "../core/crypto.hpp"

namespace nebula {

/**
 * 纯校验（不弹窗）：返回空串表示通过，非空为拒绝原因（中文，可直接展示）。
 *
 * @param selfFileHash 服务端下发的哈希（32 位 = MD5，64 位 = SHA256）；空 = 跳过哈希校验
 * @param selfFileSize 服务端下发的字节数；<= 0 表示跳过大小校验
 */
NEBULA_MUST_CHECK inline std::string verifySelfIntegrity(const std::string& selfFileHash,
                                                         long long selfFileSize) {
    if (selfFileHash.empty() && selfFileSize <= 0) return {};   // 服务端未登记 → 跳过

    wchar_t exePath[MAX_PATH] = {};
    if (!::GetModuleFileNameW(nullptr, exePath, MAX_PATH)) return "无法定位程序文件";
    const std::string path = toUtf8(exePath);
    if (path.empty()) return "无法定位程序文件";

    if (!selfFileHash.empty()) {
        const bool useSha256 = (selfFileHash.size() == 64);
        const std::string local = crypto::fileHashHex(path, useSha256);
        if (local.empty())      return "无法读取程序文件，完整性校验失败";
        if (local != selfFileHash) return "程序文件已被修改，请从官方渠道重新下载";
    }
    if (selfFileSize > 0) {
        const long long size = crypto::fileSizeBytes(path);
        if (size >= 0 && size != selfFileSize) return "程序文件已被修改，请从官方渠道重新下载";
    }
    return {};
}

/**
 * 一站式校验：通过返回 true；失败则弹中文提示窗并返回 false（接入方应立即退出）。
 * 想自己处理提示时请改用 verifySelfIntegrity() 拿原因字符串。
 */
inline bool enforceSelfIntegrity(const std::string& selfFileHash, long long selfFileSize) {
    const std::string reason = verifySelfIntegrity(selfFileHash, selfFileSize);
    if (reason.empty()) return true;
    ::MessageBoxW(nullptr, toWide(reason).c_str(), L"Nebula 安全校验", MB_ICONERROR);
    return false;
}

} // namespace nebula

#pragma once
// ============================================================================
// Nebula SDK · 立即公告（type=3）本地已读记录
// ----------------------------------------------------------------------------
// 语义：立即公告「看过即不再显示」。已读记录存本地文本文件，按 app_key 区分软件，
//       30 天前的旧记录在读取时自动清理（避免文件无限增长）。
//
// 存储位置：%APPDATA%\NebulaSDK\notices_<app_key>.txt（取不到 APPDATA 时回落 exe 同目录）
// 文件格式：每行 "<公告ID> <标记时间戳>"，CRLF 结尾。
// ============================================================================

#include "../core/text.hpp"

namespace nebula {

/** 已读记录文件路径（同时确保所在目录存在） */
NEBULA_MUST_CHECK inline std::string noticeReadStorePath(const std::string& appKey) {
    std::string directory;
    char appData[MAX_PATH] = {};
    if (::GetEnvironmentVariableA("APPDATA", appData, MAX_PATH) > 0 && appData[0] != '\0') {
        directory = std::string(appData) + "\\NebulaSDK";
        ::CreateDirectoryA(directory.c_str(), nullptr);   // 已存在时失败，忽略
    } else {
        wchar_t exe[MAX_PATH] = {};
        if (::GetModuleFileNameW(nullptr, exe, MAX_PATH)) {
            directory = toUtf8(exe);
            const size_t pos = directory.find_last_of("\\/");
            if (pos != std::string::npos) directory = directory.substr(0, pos);
        }
    }
    const std::string fileName = "notices_" + appKey + ".txt";
    return directory.empty() ? fileName : (directory + "\\" + fileName);
}

/** 读取已读记录（自动丢弃 30 天前的旧记录） */
NEBULA_MUST_CHECK inline std::vector<std::pair<int64_t, int64_t>> loadNoticeReads(const std::string& path) {
    std::vector<std::pair<int64_t, int64_t>> reads;
    FILE* file = nullptr;
    if (::fopen_s(&file, path.c_str(), "rb") != 0 || !file) return reads;

    char line[128] = {};
    const int64_t cutoff = nowSeconds() - 30 * 86400;
    while (::fgets(line, sizeof(line), file)) {
        long long id = 0, timestamp = 0;
        if (::sscanf_s(line, "%lld %lld", &id, &timestamp) == 2 && id > 0 && timestamp >= cutoff) {
            reads.push_back({ (int64_t)id, (int64_t)timestamp });
        }
    }
    ::fclose(file);
    return reads;
}

/** 覆盖写入已读记录 */
inline void saveNoticeReads(const std::string& path,
                            const std::vector<std::pair<int64_t, int64_t>>& reads) {
    FILE* file = nullptr;
    if (::fopen_s(&file, path.c_str(), "wb") != 0 || !file) return;
    for (size_t i = 0; i < reads.size(); ++i) {
        ::fprintf(file, "%lld %lld\r\n", (long long)reads[i].first, (long long)reads[i].second);
    }
    ::fclose(file);
}

NEBULA_MUST_CHECK inline bool noticeIsRead(const std::vector<std::pair<int64_t, int64_t>>& reads,
                                          int64_t id) {
    for (size_t i = 0; i < reads.size(); ++i) {
        if (reads[i].first == id) return true;
    }
    return false;
}

/** 标记某条立即公告为已读（已存在则刷新时间戳） */
inline void markNoticeRead(const std::string& appKey, int64_t id) {
    const std::string path = noticeReadStorePath(appKey);
    std::vector<std::pair<int64_t, int64_t>> reads = loadNoticeReads(path);
    const int64_t at = nowSeconds();
    for (size_t i = 0; i < reads.size(); ++i) {
        if (reads[i].first == id) {
            reads[i].second = at;
            saveNoticeReads(path, reads);
            return;
        }
    }
    reads.push_back({ id, at });
    saveNoticeReads(path, reads);
}

/** 清空已读记录（调试用：清空后全部立即公告会重新下发显示） */
inline void clearNoticeReads(const std::string& appKey) {
    ::DeleteFileA(noticeReadStorePath(appKey).c_str());
}

} // namespace nebula

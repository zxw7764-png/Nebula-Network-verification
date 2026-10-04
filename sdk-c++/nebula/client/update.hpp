#pragma once
// ============================================================================
// Nebula SDK · 客户端自动更新（下载 → 校验 → 自替换 → 重启）
// ----------------------------------------------------------------------------
// 流程（全自动，无需接入方干预）：
//   ① 检测：从 init()/checkVersion() 拿到新版本号 / 下载地址 / file_hash / file_size
//   ② 下载：GET download_url → 流式写入 exe 同目录的临时文件
//   ③ 校验：必须同时通过 SHA256（或 MD5，按 hash 长度识别）与字节数校验
//          —— 校验不过**绝不替换**，删掉临时文件并返回失败
//   ④ 替换：生成一个 cmd 脚本，等本进程退出后：
//            move /Y 临时文件 → 正式 exe，然后启动新 exe，最后删除脚本自身
//   ⑤ 退出：本进程调用 ExitProcess 让出文件占用
//
// 为什么必须"退出后才替换"：
//   Windows 不允许覆盖正在运行的 exe（文件被映射为映像，占用句柄无法解除）。
//   唯一可靠做法是起一个外部进程等待本进程结束，再由它做 move + start。
//
// 安全设计：
//   · 下载地址默认只接受 https（http 需显式开放 kAllowInsecureUpdate）
//   · hash 校验是**强制**的：服务端未登记 file_hash 时直接拒绝更新
//     （否则等于给了中间人一个投毒通道）
//   · 临时文件与脚本都落在 exe 同目录；脚本路径含随机串，避免并发/残留冲突
//
// 用法（启动时一次）：
//     auto up = client->autoUpdate();          // 检测 + 下载 + 校验 + 替换 + 退出
//     if (up.state == nebula::UpdateState::Failed) { /* 可提示用户手动下载 */ }
//     // 走到这里说明没有更新或更新失败，正常继续启动
//
// 只下载不替换（接入方自己控制时机）：
//     auto r = client->downloadUpdate();       // 拿到 r.new_file 路径
//     ...
//     nebula::applyUpdateAndRestart(r.new_file, r.verify_hash);
//
// 关闭自动更新：工程预处理器定义 NEBULA_AUTO_UPDATE=0
// ============================================================================

#include "../config.hpp"
#include "../core/error.hpp"
#include "../core/text.hpp"
#include "../core/crypto.hpp"
#include "../core/http.hpp"
#include "types.hpp"
namespace nebula {

// ---------------------------------------------------------------------------
// 状态与结果
// ---------------------------------------------------------------------------

/** 自动更新各阶段的结果状态 */
enum class UpdateState {
    Disabled,      ///< 编译期关闭（NEBULA_AUTO_UPDATE=0）
    NoUpdate,      ///< 已是最新版本，无需更新
    NeedConfirm,   ///< 可选更新：已在 UI 层提示，等待用户确认后自行再次调用
    Downloaded,    ///< 仅下载完成（downloadUpdate 的终态）
    Applied,       ///< 已完成替换，本进程即将退出并重启
    Failed,        ///< 任一步失败（见 msg）
};

/** 自动更新的完整结果 */
struct UpdateResult {
    UpdateState state = UpdateState::NoUpdate;
    std::string msg;              ///< 面向用户的说明（UTF-8，成功或失败原因）
    std::string version;          ///< 目标版本号
    std::string new_file;         ///< 下载到的临时文件路径（Downloaded / Applied 时有效）
    std::string verify_hash;      ///< 该文件的期望哈希（交 applyUpdateAndRestart 用）
    long long   verify_size = 0;  ///< 该文件的期望字节数
    bool        force = false;    ///< 是否强制更新

    NEBULA_MUST_CHECK bool ok() const {
        return state == UpdateState::NoUpdate
            || state == UpdateState::Downloaded
            || state == UpdateState::Applied;
    }
    /** 是否"有更新且已经处理完（下载/替换）" */
    NEBULA_MUST_CHECK bool updated() const {
        return state == UpdateState::Downloaded || state == UpdateState::Applied;
    }
};

// ---------------------------------------------------------------------------
// 内部实现细节
// ---------------------------------------------------------------------------
namespace detail {

/** 编译期开关：0 = 完全不编译自动更新代码 */
#ifndef NEBULA_AUTO_UPDATE
#  define NEBULA_AUTO_UPDATE 1
#endif

/** 单个文件的大小（不存在返回 -1） */
NEBULA_MUST_CHECK inline long long fileSize(const std::string& pathUtf8) {
    return crypto::fileSizeBytes(pathUtf8);
}

/** 删除文件（失败静默：清理是尽力而为） */
inline void removeFileQuiet(const std::string& pathUtf8) {
    if (pathUtf8.empty()) return;
    ::DeleteFileW(toWide(pathUtf8).c_str());
}

/** 当前进程 exe 的完整路径（UTF-8）；失败返回空 */
NEBULA_MUST_CHECK inline std::string selfExePath() {
    wchar_t buf[MAX_PATH * 2] = {};
    const DWORD n = ::GetModuleFileNameW(nullptr, buf, (DWORD)(sizeof(buf) / sizeof(buf[0])));
    if (n == 0 || n >= sizeof(buf) / sizeof(buf[0])) return {};
    return toUtf8(buf);
}

/** exe 所在目录（UTF-8，不含末尾反斜杠） */
NEBULA_MUST_CHECK inline std::string selfDir() {
    std::string p = selfExePath();
    const size_t pos = p.find_last_of("\\/");
    return (pos == std::string::npos) ? std::string() : p.substr(0, pos);
}

/** 进程 PID 字符串 */
NEBULA_MUST_CHECK inline std::string selfPid() {
    char buf[32] = {};
    std::snprintf(buf, sizeof(buf), "%lu", (unsigned long)::GetCurrentProcessId());
    return std::string(buf);
}

/**
 * 把 URL 里的文件名提取出来（去掉 query 与路径分隔符）。
 * 用于生成下载临时文件名；取不到时回落到固定名。
 */
NEBULA_MUST_CHECK inline std::string fileNameFromUrl(const std::string& url) {
    std::string p = url;
    const size_t q = p.find('?');
    if (q != std::string::npos) p = p.substr(0, q);
    const size_t s = p.find_last_of("/\\");
    if (s != std::string::npos) p = p.substr(s + 1);
    // 过滤掉不安全的字符（防止路径穿越 / 非法字符）
    for (char& c : p) {
        if (c == ':' || c == '*' || c == '?' || c == '"' || c == '<' || c == '>' || c == '|') c = '_';
    }
    if (p.empty() || p == "." || p == "..") p = "update.bin";
    return p;
}

/** 是否 https（自动更新默认只接受 https） */
NEBULA_MUST_CHECK inline bool isHttps(const std::string& url) {
    const std::string u = toLowerAscii(url);
    return u.rfind("https://", 0) == 0;
}

/**
 * 下载到本地文件。成功返回 true；失败时 outErr 给出中文原因。
 * 说明：用 Http::request("GET", ...) 一次性读取响应体（安装包通常几 MB~几十 MB，
 * 内存足够；如需超大文件可改为流式，但当前实现保持简单可靠）。
 */
inline bool downloadToFile(const std::string& url, const std::string& destPathUtf8,
                           const http::Options& options, std::string& outErr) {
    const http::Response resp = Http::request("GET", url, std::string(), options);
    if (resp.status == 0) {
        outErr = "下载失败：无法连接更新服务器（或被 TLS 校验拦截）";
        return false;
    }
    if (resp.status != 200) {
        char buf[96] = {};
        std::snprintf(buf, sizeof(buf), "下载失败：服务器返回 HTTP %d", resp.status);
        outErr = buf;
        return false;
    }
    if (resp.body.empty()) {
        outErr = "下载失败：更新包为空";
        return false;
    }

    const std::wstring wide = toWide(destPathUtf8);
    HANDLE h = ::CreateFileW(wide.c_str(), GENERIC_WRITE, 0, nullptr,
                             CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, nullptr);
    if (h == INVALID_HANDLE_VALUE) {
        outErr = "下载失败：无法写入临时文件（可能没有目录写权限）";
        return false;
    }
    size_t written = 0;
    bool ok = true;
    while (written < resp.body.size()) {
        const DWORD chunk = (DWORD)((resp.body.size() - written) > 0x10000000
                                        ? 0x10000000 : (resp.body.size() - written));
        DWORD done = 0;
        if (!::WriteFile(h, resp.body.data() + written, chunk, &done, nullptr) || done == 0) {
            ok = false;
            break;
        }
        written += done;
    }
    ::CloseHandle(h);
    if (!ok) {
        outErr = "下载失败：写入临时文件出错（磁盘空间不足？）";
        removeFileQuiet(destPathUtf8);
        return false;
    }
    return true;
}

/**
 * 校验下载文件：hash + 大小。
 * hash 长度 64 → SHA256，32 → MD5（与服务端完整性校验同规则）。
 * 任一不符返回 false 并给出原因。hash 为空视为**不可信** → 直接失败。
 */
inline bool verifyDownloaded(const std::string& pathUtf8, const std::string& expectHash,
                             long long expectSize, std::string& outErr) {
    if (expectHash.empty()) {
        outErr = "更新包未通过校验：服务端未登记文件哈希，已拒绝安装";
        return false;
    }
    const bool sha256Mode = (expectHash.size() == 64);
    const std::string local = crypto::fileHashHex(pathUtf8, sha256Mode);
    if (local.empty()) {
        outErr = "更新包校验失败：无法读取下载的文件";
        return false;
    }
    if (toLowerAscii(local) != toLowerAscii(expectHash)) {
        outErr = "更新包校验失败：文件哈希与服务器登记值不一致（可能被篡改或下载不完整）";
        return false;
    }
    if (expectSize > 0) {
        const long long size = fileSize(pathUtf8);
        if (size != expectSize) {
            outErr = "更新包校验失败：文件大小与服务器登记值不一致";
            return false;
        }
    }
    return true;
}

/**
 * 生成并启动替换脚本。
 *
 * 脚本逻辑（等本进程退出后执行）：
 *   :wait      轮询 tasklist 直到本 PID 消失（最多等 ~60 秒）
 *   move /Y <新文件> <exe>     覆盖
 *   start "" <exe>             启动新版
 *   del "%~f0"                 删除脚本自身
 *
 * 用 cmd 而非 PowerShell：启动更快、无需 ExecutionPolicy、兼容 Win7。
 */
inline bool spawnReplacer(const std::string& newFileUtf8, const std::string& exeUtf8,
                          const std::string& scriptPathUtf8, std::string& outErr) {
    const std::string pid = selfPid();

    // 统一用反斜杠：批处理对混合分隔符（"D:/a/b" 与 "D:\a\b"）在 move/copy 上行为不一致
    auto toBackslash = [](std::string s) {
        for (char& c : s) if (c == '/') c = '\\';
        return s;
    };
    const std::string newFile = toBackslash(newFileUtf8);
    const std::string exeFile = toBackslash(exeUtf8);

    // 脚本逻辑（用显式 :wait 标号轮询，不用 for 块 —— 块内 goto 跳出容易出意外）：
    //   :wait   用 tasklist 查询本 PID，查到就再等 1 秒，查不到就跳到 :swap
    //   :swap   move /Y 覆盖 → start 重启 → 清理
    std::string script;
    script += "@echo off\r\n";
    script += "setlocal enableextensions\r\n";
    script += "set \"NEW=" + newFile + "\"\r\n";
    script += "set \"DST=" + exeFile + "\"\r\n";
    script += "set \"PID=" + pid + "\"\r\n";
    script += "set /a N=0\r\n";
    script += ":wait\r\n";
    script += "set /a N+=1\r\n";
    script += "if %N% GTR 60 goto :swap\r\n";
    script += "tasklist /FI \"PID eq %PID%\" 2>NUL | find /I \"%PID%\" >NUL\r\n";
    script += "if not errorlevel 1 (\r\n";
    script += "  ping -n 2 127.0.0.1 >NUL\r\n";
    script += "  goto :wait\r\n";
    script += ")\r\n";
    script += ":swap\r\n";
    script += "move /Y \"%NEW%\" \"%DST%\" >NUL 2>&1\r\n";
    script += "if errorlevel 1 goto :fail\r\n";
    script += "start \"\" \"%DST%\"\r\n";
    script += "goto :cleanup\r\n";
    script += ":fail\r\n";
    script += "echo update failed: cannot replace exe, new file kept at %NEW%\r\n";
    script += ":cleanup\r\n";
    script += "del \"%~f0\" >NUL 2>&1\r\n";
    script += "exit /b\r\n";

    // 写脚本（用宽字符路径创建；内容为纯 ASCII，脚本内路径已是 UTF-8/ANSI 混合，
    // 中文仅在注释与提示里，改用 GBK 不安全 —— 直接写 UTF-8 且脚本首行不加 chcp，
    // 注释乱码不影响执行）
    const std::wstring wide = toWide(scriptPathUtf8);
    HANDLE h = ::CreateFileW(wide.c_str(), GENERIC_WRITE, 0, nullptr,
                             CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, nullptr);
    if (h == INVALID_HANDLE_VALUE) {
        outErr = "无法创建更新脚本（可能没有目录写权限）";
        return false;
    }
    DWORD written = 0;
    const BOOL wOK = ::WriteFile(h, script.data(), (DWORD)script.size(), &written, nullptr);
    ::CloseHandle(h);
    if (!wOK || (size_t)written != script.size()) {
        outErr = "写入更新脚本失败";
        removeFileQuiet(scriptPathUtf8);
        return false;
    }

    // 以隐藏窗口启动 cmd 执行脚本。
    // 注意：CreateProcessW 在 lpApplicationName = nullptr 时要求 lpCommandLine 的
    // 首个 token 是**可执行文件**，因此这里必须显式写 "cmd.exe /c ..."，
    // 且整个命令行必须可写（不能传字面量）。
    std::string cmdLine = "cmd.exe /c \"" + scriptPathUtf8 + "\"";
    std::wstring cmdW = toWide(cmdLine);
    STARTUPINFOW si = {};
    si.cb = sizeof(si);
    si.dwFlags = STARTF_USESHOWWINDOW;
    si.wShowWindow = SW_HIDE;
    PROCESS_INFORMATION pi = {};
    const BOOL spawned = ::CreateProcessW(nullptr, cmdW.data(), nullptr, nullptr,
                                          FALSE, CREATE_NO_WINDOW, nullptr, nullptr, &si, &pi);
    if (!spawned) {
        const DWORD gle = ::GetLastError();
        char buf[128] = {};
        std::snprintf(buf, sizeof(buf),
                      "启动更新程序失败（错误码 %lu，可能被杀毒软件拦截）",
                      (unsigned long)gle);
        outErr = buf;
        removeFileQuiet(scriptPathUtf8);
        return false;
    }
    ::CloseHandle(pi.hThread);
    ::CloseHandle(pi.hProcess);
    return true;
}

/** 让本进程退出并释放 exe 占用（给替换脚本让路） */
inline void exitForUpdate() {
    ::ExitProcess(0);
}

} // namespace detail

// ---------------------------------------------------------------------------
// 对外的两步式 API（也供 Client 成员函数调用）
// ---------------------------------------------------------------------------

/**
 * 应用已下载的更新并重启：生成替换脚本 → 启动脚本 → 本进程退出。
 *
 * @param newFileUtf8  已下载并校验通过的临时文件路径
 * @param expectHash   期望哈希（再次校验一次，防下载后被替换）
 * @param expectSize   期望字节数（0 = 不校验大小）
 * @param outErr       失败原因
 * @return true = 脚本已启动（调用方接下来不应再执行任何操作，进程即将退出）
 */
inline bool applyUpdateAndRestartImpl(const std::string& newFileUtf8,
                                      const std::string& expectHash,
                                      long long expectSize,
                                      bool exitNow,
                                      std::string& outErr) {
#if !NEBULA_AUTO_UPDATE
    (void)newFileUtf8; (void)expectHash; (void)expectSize; (void)exitNow;
    outErr = "自动更新已在编译期关闭（NEBULA_AUTO_UPDATE=0）";
    return false;
#else
    const std::string exe = detail::selfExePath();
    if (exe.empty()) { outErr = "无法定位当前程序文件"; return false; }
    if (newFileUtf8.empty() || detail::fileSize(newFileUtf8) < 0) {
        outErr = "更新文件不存在"; return false;
    }

    // 替换前再校验一次（防 TOCTOU：下载完成到替换之间被掉包）
    std::string verifyErr;
    if (!expectHash.empty() &&
        !detail::verifyDownloaded(newFileUtf8, expectHash, expectSize, verifyErr)) {
        outErr = verifyErr;
        detail::removeFileQuiet(newFileUtf8);
        return false;
    }

    const std::string dir = detail::selfDir();
    const std::string script = dir + "\\nebula_upd_" + crypto::randomHex(6) + ".bat";
    if (!detail::spawnReplacer(newFileUtf8, exe, script, outErr)) return false;

    if (exitNow) detail::exitForUpdate();
    return true;
#endif
}

} // namespace nebula

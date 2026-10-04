#pragma once
// ============================================================================
// Nebula SDK · 客户端主类
// ----------------------------------------------------------------------------
// 典型用法（完整示例见 sdk/SDK.md 第 3 节）：
//
//     auto client = nebula::createDefaultClient(machineId, "Windows", "1.0.2");
//
//     auto init = client->init();                       // 1. 初始化
//     if (!init.ok) { /* init.msg */ }
//     if (!client->enforceSelfIntegrity()) return 1;    // 2. 自身完整性（被改则退出）
//     if (!client->versionAlert()) return 1;            //    版本策略提示
//
//     auto login = client->login(account, password);    // 3. 登录
//     if (!login.ok) { /* login.code / login.msg */ }
//
//     client->startHeartbeat(login.token,                // 4. 心跳保活
//         [](const nebula::HeartbeatInfo& hb) { if (hb.need_relogin || hb.kick) { /* 回登录 */ } });
//
//     ... 业务逻辑 ...
//
//     client->stopHeartbeat();                          // 5. 登出
//     client->logout(login.token);
//
// 线程模型：
//   · 除心跳回调外，所有方法都在**调用线程**同步执行；
//   · 心跳线程由 startHeartbeat 创建，回调运行在**心跳线程**上，
//     所以回调里不要直接操作 UI 句柄（先置标志位，回主线程处理）；
//   · 会话状态（token / 票据 / 状态机）由互斥量保护，可跨线程读取；
//   · 析构会自动停止心跳线程。
// ============================================================================

#include "config.hpp"
#include "device.hpp"
#include "envelope.hpp"
#include "handshake.hpp"
#include "integrity.hpp"
#include "notice_store.hpp"
#include "offline.hpp"
#include "types.hpp"
#include "update.hpp"
#include "../core/http.hpp"
#include "../protect/shell.hpp"    // NEBULA_MARK_* 壳标记宏（本文件直接使用，必须自包含）
#include "../protect/runtime.hpp"

namespace nebula {

class Client {
public:
    // -----------------------------------------------------------------------
    // 构造参数
    // -----------------------------------------------------------------------
    /**
     * 客户端配置。必填：api_url / app_key / response_sign_public_key。
     * 3.1 起通信密钥由 ECDH 握手临时协商，AES_KEY / SIGN_SALT 已彻底移除。
     */
    struct Options {
        std::string api_url;                        ///< API 入口（必填）
        std::string app_key;                        ///< 软件标识（必填）
        std::string machine_id;                     ///< 机器码；留空则生成随机临时值（**建议接入方持久化后传入**）
        std::string os_info = "Windows";            ///< 操作系统标识
        std::string client_version = "1.0.0";       ///< 客户端版本号（每次发版必须更新）
        std::string response_sign_public_key = cfg::kRespSignPubKey;  ///< 响应签名公钥（PEM）
        std::string tls_cert_sha256 = cfg::kTlsCertSha256;            ///< 服务端证书指纹
        bool require_response_signature = true;     ///< 强制校验响应非对称签名（默认开启）
        int  connect_timeout_ms = 8000;
        int  receive_timeout_ms = 15000;
        bool use_system_proxy = false;

        // ── Nebula 3.1 ECDH 会话握手（见 nebula/client/handshake.hpp）────
        /// 【已废弃】3.1 是唯一协议，恒定启用；字段仅为兼容旧代码保留。
        bool use_handshake = true;

        // ── 自动更新（见 nebula/client/update.hpp）────────────────────────
        /// 是否允许下载更新包（false = 只检测并提示，不下载）
        bool auto_update_enable = true;
        /// 允许 http:// 的更新地址（默认只接受 https，防中间人投毒）
        bool allow_insecure_update = false;
        /// 可选更新（非强制）时是否也自动下载替换；false = 交给 versionAlert 提示用户
        bool auto_update_optional = false;
    };

    /** 结果类型别名（保持 nebula::Client::XxxResult 写法可用） */
    using InitResult    = nebula::InitResult;
    using LoginResult   = nebula::LoginResult;
    using HeartbeatInfo = nebula::HeartbeatInfo;
    using GraceResult   = nebula::GraceResult;
    using NoticeList    = std::vector<Notice>;

    /** 心跳回调：code = 业务码，hb = 解析后的心跳数据 */
    using HeartbeatCb = std::function<void(int code, const std::string& msg, const HeartbeatInfo& hb)>;
    /**
     * 内置提示的自定义处理器（不设置则弹默认中文窗）。
     * kind 取值："integrity" 完整性校验失败 / "version" 版本更新 / "maintain" 维护中
     *             "kick" 被踢下线 / "flash" 立即公告（每条一次）/ "popup" 弹窗公告
     */
    using UiHandler = std::function<void(const char* kind, const std::string& msg)>;

    explicit Client(Options options)
        : options_(std::move(options)),
          device_name_(device::computerName()) {
        while (options_.api_url.size() > 1 && options_.api_url.back() == '/') options_.api_url.pop_back();
        if (options_.machine_id.empty()) options_.machine_id = device::createMachineId();

        http_options_.connectTimeoutMs = options_.connect_timeout_ms;
        http_options_.receiveTimeoutMs = options_.receive_timeout_ms;
        http_options_.certSha256       = normalizeHex(options_.tls_cert_sha256);
        http_options_.useSystemProxy   = options_.use_system_proxy;

        if (options_.api_url.empty())    config_error_ = "未配置 API 地址（Options::api_url）";
        else if (options_.app_key.empty())   config_error_ = "未配置软件标识（Options::app_key）";
        else if (options_.require_response_signature && options_.response_sign_public_key.empty()) {
            config_error_ = "未配置响应签名公钥（cfg::kRespSignPubKey），拒绝连接";
        }
    }

    ~Client() { stopHeartbeat(); }

    Client(const Client&)            = delete;
    Client& operator=(const Client&) = delete;

    // -----------------------------------------------------------------------
    // 配置与状态
    // -----------------------------------------------------------------------
    /// 配置是否完整（false 时所有请求都会以 Error::Config 失败）
    NEBULA_MUST_CHECK bool configValid() const { return config_error_.empty(); }
    /// 配置错误说明（configValid() 为 true 时为空）
    NEBULA_MUST_CHECK const std::string& configError() const { return config_error_; }

    NEBULA_MUST_CHECK const std::string& machineId()   const { return options_.machine_id; }
    NEBULA_MUST_CHECK const std::string& deviceName()  const { return device_name_; }
    NEBULA_MUST_CHECK const std::string& clientVersion() const { return options_.client_version; }
    NEBULA_MUST_CHECK const std::string& getLoginMethod() const { return login_method_; }

    /// 更换响应签名公钥（覆盖构造参数）
    void setRespSignPublicKey(const std::string& pem) { options_.response_sign_public_key = pem; }
    NEBULA_MUST_CHECK const std::string& respSignPublicKey() const { return options_.response_sign_public_key; }
    /// 是否强制校验响应非对称签名（默认 true；服务端未配置签名密钥时才需要关掉）
    void setRequireResponseSignature(bool on) { options_.require_response_signature = on; }
    /// 运行时调整超时（毫秒）
    void setTimeouts(int connectMs, int receiveMs) {
        options_.connect_timeout_ms = connectMs;
        options_.receive_timeout_ms = receiveMs;
        http_options_.connectTimeoutMs = connectMs;
        http_options_.receiveTimeoutMs = receiveMs;
    }
    /// 运行时设置服务端证书指纹（空串 = 不锁定）
    void setCertSha256(const std::string& fingerprint) {
        options_.tls_cert_sha256 = fingerprint;
        http_options_.certSha256 = normalizeHex(fingerprint);
    }
    /// 自定义全部内置提示（须在 init 之前设置）
    void setUiHandler(UiHandler handler) { ui_ = std::move(handler); }

    // -----------------------------------------------------------------------
    // 加固（可选；编译期未开启时这些接口仍可调用，只是不做任何事）
    // -----------------------------------------------------------------------
    /// 运行时调整检测等级（0 关 / 1 基础 / 2 标准 / 3 严格；不会超过编译期上限）
    void setProtectLevel(int level) { protect::setLevel(level); }
    /**
     * 命中后的处置：0 只记录 / 1 回调上报(默认) / 2 降级(拒绝业务) / 3 弹窗并退出。
     * 传入 act > 0 时会同时隐式「启用检测」：把等级提到编译期上限并打开总开关，
     * 否则只设了处置方式、扫描仍会被 enabled()=false 短路而不会真正执行。
     */
    void setProtectAction(int act) {
        protect::setAction(act);
        if (act > 0) {
            protect::setSuspiciousPolicy(cfg::kProtectStrictPolicy);
            protect::setLevel((int)NEBULA_PROTECT_LEVEL);
            protect::setEnabled(true);
        }
    }
    /// 命中回调（推荐在这里把结果上报到自己的服务端，或写本地日志）
    void setProtectCallback(std::function<void(const protect::Report&)> callback) {
        protect::setCallback(std::move(callback));
    }
    /// 一行启用加固：立即检测一次（按策略处置）+ 可选后台巡检（interval_ms=0 表示不巡检）
    protect::Report enableProtection(int level = 0, int interval_ms = 0) {
        if (level > 0) protect::setLevel(level);
        protect::setEnabled(true);
        protect::Report report = protect::scan();
        protect::enforce(report);
        if (interval_ms > 0) protect::startWatchdog(interval_ms);
        return report;
    }
    /// 手动检测一次（不改变任何状态）
    NEBULA_MUST_CHECK protect::Report protectScan() { return protect::scan(); }
    /// 最近一次 init() 内置自检的结果
    NEBULA_MUST_CHECK protect::Report protectReport() const {
        std::lock_guard<std::mutex> lock(state_mutex_);
        return protect_report_;
    }
    /// 是否已进入「降级」态（action >= 2 命中后置位，接入方可据此拒绝业务请求）
    NEBULA_MUST_CHECK bool protectionDegraded() const { return protect::degraded(); }

    // -----------------------------------------------------------------------
    // 流程：init / login / heartbeat / logout
    // -----------------------------------------------------------------------
    /**
     * 初始化：拉取会话密钥、登录方式、版本策略、公告、离线宽限公钥。
     * 开始加固时（NEBULA_PROTECT_LEVEL >= 1）会先自检环境再连服务端。
     */
    InitResult init() {
        InitResult result;
        if (!config_error_.empty()) { result.msg = config_error_; return result; }

#if NEBULA_PROTECT_LEVEL >= 1
        if (protect::enabled() && protect::level() != protect::Level::Off) {
            const protect::Report report = protect::scan();
            {
                std::lock_guard<std::mutex> lock(state_mutex_);
                protect_report_ = report;
            }
            if (!protect::enforce(report)) {
                result.msg = "运行环境异常，已中止连接（请关闭调试/分析工具后重试）";
                return result;
            }
        }
#endif

        const std::string payload = "{"
            + json::pair("client_ver", json::quote(options_.client_version)) + ","
            + json::pair("machine_id", json::quote(options_.machine_id)) + "}";
        const Response response = post("init", payload);
        if (!response.ok()) {
            result.msg = response.msg.empty() ? ("code " + std::to_string(response.code)) : response.msg;
            return result;
        }

        const std::string& d = response.raw;
        result.ok               = true;
        result.server_time      = json::findInt64(d, "server_time");
        result.site_name        = json::findString(d, "site_name");
        const std::string heartbeat = json::findString(d, "heartbeat_interval");
        result.heartbeat_interval   = heartbeat.empty() ? 60 : (int)::strtol(heartbeat.c_str(), nullptr, 10);
        result.session_ttl      = json::findInt64(d, "session_ttl");
        result.register_enable  = json::findBool(d, "register_enable", true);
        result.maintain_mode    = json::findBool(d, "maintain_mode", false);
        result.app_key          = json::findString(d, "app_key");
        const std::string software = json::findObject(d, "software");
        if (!software.empty()) {
            result.software_id   = json::findInt(software, "id");
            result.software_name = json::findString(software, "name");
        }

        // 登录方式 login.{method,...}
        const std::string loginSpec = json::findObject(d, "login");
        if (!loginSpec.empty()) {
            const std::string method = json::findString(loginSpec, "method");
            if (!method.empty()) login_method_ = method;
        }
        if (login_method_.empty()) login_method_ = "password";
        result.login_method = login_method_;

        // 版本 version.{...}
        const std::string version = json::findObject(d, "version");
        if (!version.empty()) {
            result.need_update    = json::findBool(version, "need_update");
            result.force_update   = json::findBool(version, "force_update");
            result.latest         = json::findString(version, "latest");
            result.min_ver        = json::findString(version, "min");
            result.update_url     = json::findString(version, "update_url");
            result.update_note    = json::findString(version, "update_note");
            result.file_hash      = json::findString(version, "file_hash");
            result.file_size      = json::findInt64(version, "file_size");
            result.self_file_hash = json::findString(version, "self_file_hash");
            result.self_file_size = json::findInt64(version, "self_file_size");
            result.versions       = parseVersionList(version);
        }

        // 设备指纹采集说明 device_fp.{...}
        const std::string fp = json::findObject(d, "device_fp");
        if (!fp.empty()) {
            result.device_fp_enable     = json::findBool(fp, "enable");
            result.device_fp_components = json::findStrings(fp, "components");
        }

        // 离线宽限 grace.{...}
        const std::string grace = json::findObject(d, "grace");
        if (!grace.empty()) {
            result.grace_enable     = json::findBool(grace, "enable");
            result.grace_seconds    = json::findInt(grace, "seconds");
            result.grace_public_key = json::findString(grace, "public_key");
            result.grace_algorithm  = json::findString(grace, "algorithm");
            result.grace_kid        = json::findString(grace, "kid");
            const std::string prefix = json::findString(grace, "ticket_prefix");
            if (!prefix.empty()) result.grace_prefix = prefix;
            if (!result.grace_public_key.empty()) grace_public_key_ = result.grace_public_key;
            grace_prefix_ = result.grace_prefix;
        }

        result.notices = parseNoticeList(d);

        {
            std::lock_guard<std::mutex> lock(state_mutex_);
            hb_default_ms_ = result.heartbeat_interval > 0 ? result.heartbeat_interval * 1000 : 60000;
            state_      = State::Ready;
            last_init_  = result;
        }
        return result;
    }

    /** 登录（按服务端下发的 login_method 自动组装参数） */
    LoginResult login(const std::string& account, const std::string& secret) {
        LoginResult result;
        if (!config_error_.empty()) { result.code = (int)Error::Config; result.msg = config_error_; return result; }
        if (!isReady()) { result.code = (int)Error::Config; result.msg = "初始化失败，请重启程序"; return result; }

        const Response response = post("login", buildLoginPayload(account, secret));
        result.code = response.code;
        result.msg  = response.msg;
        if (!response.ok()) {
            result.need_relogin = json::findBool(response.raw, "need_relogin");
            return result;
        }

        const std::string& d = response.raw;
        result.ok             = true;
        result.token          = json::findString(d, "token");
        result.expire_at      = json::findInt64(d, "expire_at");
        result.ttl            = json::findInt64(d, "ttl");
        const std::string method = json::findString(d, "login_method");
        result.login_method   = method.empty() ? login_method_ : method;
        result.account_created= json::findBool(d, "account_created");

        const std::string user = json::findObject(d, "user");
        if (!user.empty()) {
            result.user.user_id     = json::findInt(user, "user_id");
            result.user.username    = json::findString(user, "username");
            result.user.nickname    = json::findString(user, "nickname");
            result.user.vip_expire  = json::findInt64(user, "vip_expire");
            result.user.vip_text    = json::findString(user, "vip_text");
            result.user.points      = json::findInt(user, "points");
            result.user.max_devices = json::findInt(user, "max_devices");
            result.user.status      = json::findInt(user, "status", 1);
            result.user.group_id    = json::findInt(user, "group_id");
        }

        // 设备指纹风险标记（仅提示，服务端已按配置决定是否拦截）
        const std::string device = json::findObject(d, "device");
        if (!device.empty()) result.device_risk = json::findStrings(device, "risk");

        const std::string grace = json::findObject(d, "grace");
        if (!grace.empty()) {
            result.grace_ticket = json::findString(grace, "ticket");
            result.grace_until  = json::findInt64(grace, "until");
        }

        // 功能密钥：仅 login 成功后下发（后台「软件管理」配置；空 = 未启用）。
        // 用 nebula::feature::open(数据包, result.feature_key) 解开随程序分发的核心数据。
        result.feature_key = json::findString(d, "feature_key");

        if (!result.token.empty()) {
            std::lock_guard<std::mutex> lock(state_mutex_);
            token_ = result.token;
            if (!result.grace_ticket.empty()) {
                grace_ticket_ = result.grace_ticket;
                grace_until_  = result.grace_until;
            }
            state_ = State::LoggedIn;
        }
        return result;
    }

    /**
     * 内置登录判定（可选）：把「发起登录 → 判定成功/失败」整段收进 SDK，并在壳虚拟化区
     * 路由回调，接入层不再暴露一眼可 patch 的裸 if(jz/jnz) 分支。
     * 需要 NEBULA_SHELL_ENABLE=1 + 加壳才有实际保护效果；未开启时等价于普通 if。
     * ★ NEBULA_NOINLINE：防止内联进宿主后 VM 标记嵌套（见 shell.hpp 说明）。
     */
    template <typename Ok, typename Fail>
    NEBULA_NOINLINE void loginAndGuard(const std::string& account, const std::string& secret, Ok&& onOk, Fail&& onFail) {
        LoginResult result = login(account, secret);
        NEBULA_MARK_VM_BEGIN();
        if (result.ok) onOk(result);
        else           onFail(result);
        NEBULA_MARK_VM_END();
    }

    /** 登出（服务端销毁会话；成功后本地状态回到 Ready） */
    Response logout(const std::string& token) {
        const Response response = post("logout", "{" + json::pair("token", json::quote(token)) + "}");
        if (response.ok()) {
            std::lock_guard<std::mutex> lock(state_mutex_);
            state_ = State::Ready;
            token_.clear();
        }
        return response;
    }

    /** 单次心跳（同步） */
    Response heartbeat(const std::string& token) {
        Response response = post("heartbeat", "{"
            + json::pair("token", json::quote(token)) + ","
            + json::pair("machine_id", json::quote(options_.machine_id)) + "}");
        if (response.ok()) {
            const std::string grace = json::findObject(response.raw, "grace");
            if (!grace.empty()) {
                const std::string ticket = json::findString(grace, "ticket");
                if (!ticket.empty()) {
                    std::lock_guard<std::mutex> lock(state_mutex_);
                    grace_ticket_ = ticket;
                    grace_until_  = json::findInt64(grace, "until");
                }
            }
            if (json::findBool(response.raw, "need_relogin")) {
                std::lock_guard<std::mutex> lock(state_mutex_);
                state_ = State::Expired;
            }
        }
        return response;
    }

    /** 从心跳响应解析便捷结构（runHeartbeatLoop 内部使用，也可自行调用） */
    /** 解析 init 响应里的 version.versions 历史版本列表 */
    NEBULA_MUST_CHECK static std::vector<VersionInfo> parseVersionList(const std::string& versionObject) {
        std::vector<VersionInfo> list;
        for (const std::string& object : json::findObjects(versionObject, "versions")) {
            VersionInfo info;
            info.version      = json::findString(object, "version");
            info.channel      = json::findString(object, "channel");
            info.changelog    = json::findString(object, "changelog");
            info.force_update = json::findBool(object, "force_update");
            info.download_url = json::findString(object, "download_url");
            info.file_hash    = json::findString(object, "file_hash");
            info.file_size    = json::findInt64(object, "file_size");
            info.created_at   = json::findInt64(object, "created_at");
            if (info.version.empty()) continue;
            list.push_back(std::move(info));
        }
        return list;
    }

    NEBULA_MUST_CHECK static HeartbeatInfo parseHeartbeat(const Response& response) {
        HeartbeatInfo info;
        if (response.ok()) {
            const std::string& d = response.raw;
            info.remain        = json::findInt(d, "remain", -1);
            info.online        = json::findBool(d, "online", true);
            info.force_offline = json::findBool(d, "force_offline");
            info.has_notice    = json::findBool(d, "has_notice");
            info.need_relogin  = json::findBool(d, "need_relogin");
            info.kick          = json::findBool(d, "kick");
            info.need_activate = json::findBool(d, "need_activate");
            info.next_interval = json::findInt(d, "next_interval");

            const std::string grace = json::findObject(d, "grace");
            if (!grace.empty()) {
                info.grace_ticket = json::findString(grace, "ticket");
                info.grace_until  = json::findInt64(grace, "until");
            }
            for (const std::string& object : json::findObjects(d, "flash_notices")) {
                Notice notice;
                notice.id      = json::findInt(object, "id");
                notice.title   = json::findString(object, "title");
                notice.content = json::findString(object, "content");
                notice.type    = 3;
                if (notice.id > 0) info.flash_notices.push_back(std::move(notice));
            }
        } else {
            info.need_relogin = json::findBool(response.raw, "need_relogin");
        }
        return info;
    }

    /**
     * 启动心跳线程。
     * @param interval_ms 间隔（毫秒）；0 = 使用 init 下发的 heartbeat_interval
     */
    void startHeartbeat(const std::string& token, HeartbeatCb callback, int interval_ms = 0) {
        stopHeartbeat();
        {
            std::lock_guard<std::mutex> lock(state_mutex_);
            hb_token_       = token;
            hb_callback_    = std::move(callback);
            hb_interval_ms_ = interval_ms;
        }
        hb_running_.store(true);
        hb_thread_ = std::thread([this] { runHeartbeatLoop(); });
    }

    /**
     * 停止心跳线程（会等待线程退出）。
     * 在心跳回调内部调用时只置停止标志、不做 join，避免自连接死锁。
     */
    void stopHeartbeat() {
        if (hb_thread_.get_id() == std::this_thread::get_id()) {
            hb_running_.store(false);      // 回调内部调用：交给心跳循环自己退出
            return;
        }
        hb_running_.store(false);
        if (hb_thread_.joinable()) hb_thread_.join();
    }

    NEBULA_MUST_CHECK bool heartbeatRunning() const { return hb_running_.load(); }

    // -----------------------------------------------------------------------
    // 业务接口（登录后）
    // -----------------------------------------------------------------------
    /** 激活卡密 / 续费 */
    Response activate(const std::string& token, const std::string& code) {
        return post("activate", "{"
            + json::pair("token", json::quote(token)) + ","
            + json::pair("machine_id", json::quote(options_.machine_id)) + ","
            + json::pair("code", json::quote(code)) + "}");
    }

    /** 当前账号已绑定的设备列表 */
    Response devices(const std::string& token) {
        return post("devices", "{" + json::pair("token", json::quote(token)) + "}");
    }

    /** 解绑设备：machine_id 为空 = 解绑当前设备；all = true = 解绑该账号全部设备 */
    Response unbindDevice(const std::string& token, const std::string& machine_id = "",
                          const std::string& password = "", bool all = false) {
        std::string payload = "{" + json::pair("token", json::quote(token));
        if (!machine_id.empty()) payload += "," + json::pair("machine_id", json::quote(machine_id));
        if (!password.empty())   payload += "," + json::pair("password", json::quote(password));
        if (all)                 payload += "," + json::pair("all", json::boolean(true));
        payload += "}";
        return post("unbind", payload);
    }

    /** 刷新用户信息 */
    Response userinfo(const std::string& token) {
        return post("userinfo", "{" + json::pair("token", json::quote(token)) + "}");
    }

    /** 拉取公告（白名单接口；id=0 表示全部） */
    Response getNotices(int id = 0) {
        return post("notice", "{" + json::pair("id", json::number(id)) + "}");
    }

    /** 解析公告列表（notice 响应解密后的 JSON） */
    NEBULA_MUST_CHECK static std::vector<Notice> parseNoticeList(const std::string& decrypted) {
        std::vector<Notice> list;
        for (const std::string& object : json::findObjects(decrypted, "list")) {
            Notice notice;
            notice.id        = json::findInt(object, "id");
            notice.title     = json::findString(object, "title");
            notice.content   = json::findString(object, "content");
            notice.type      = json::findInt(object, "type", 1);
            notice.type_text = json::findString(object, "type_text");
            list.push_back(std::move(notice));
        }
        return list;
    }

    /** 手动版本检查（白名单接口） */
    Response checkVersion(const std::string& version, const std::string& channel = "stable") {
        return post("version", "{"
            + json::pair("version", json::quote(version)) + ","
            + json::pair("channel", json::quote(channel)) + "}");
    }

    /** 当前在线人数（白名单接口） */
    Response getOnlineCount() { return post("online", "{}"); }

    // -----------------------------------------------------------------------
    // 公告：立即公告（type=3，看过即不再显示）/ 弹窗公告（type=2，每次登录提示）
    // -----------------------------------------------------------------------
    /** 只拉取**未读**的立即公告（不弹窗、不标记）；自行展示后调 markNoticeRead(id) */
    std::vector<Notice> fetchFlashNotices() {
        std::vector<Notice> out;
        const Response response = post("notice", "{}");
        if (!response.ok()) return out;
        const std::vector<std::pair<int64_t, int64_t>> reads =
            loadNoticeReads(noticeReadStorePath(options_.app_key));
        for (Notice& notice : parseNoticeList(response.raw)) {
            if (notice.type == 3 && !noticeIsRead(reads, notice.id)) out.push_back(std::move(notice));
        }
        return out;
    }

    void markNoticeRead(int64_t id) { nebula::markNoticeRead(options_.app_key, id); }
    NEBULA_MUST_CHECK bool isNoticeRead(int64_t id) {
        return noticeIsRead(loadNoticeReads(noticeReadStorePath(options_.app_key)), id);
    }
    /** 清空本地已读记录（全部立即公告会重新下发显示） */
    void clearNoticeReads() { nebula::clearNoticeReads(options_.app_key); }

    /** 心跳是否自动弹出立即公告（默认开启） */
    void setAutoFlash(bool on) { auto_flash_ = on; }

    /** 一行内置：拉取未读立即公告 → 逐条提示（默认弹窗）→ 标记已读 */
    std::vector<Notice> flashNotices() {
        std::vector<Notice> list = fetchFlashNotices();
        for (const Notice& notice : list) {
            uiAlert("flash", noticeText(notice), alertTitle(L" 公告"), MB_ICONINFORMATION);
            markNoticeRead(notice.id);
        }
        return list;
    }

    /** 弹窗公告（type=2）：拉取并逐条提示（无已读机制，每次都会提示） */
    std::vector<Notice> popupNotices() {
        std::vector<Notice> out;
        const Response response = post("notice", "{}");
        if (!response.ok()) return out;
        for (Notice& notice : parseNoticeList(response.raw)) {
            if (notice.type == 2) out.push_back(std::move(notice));
        }
        for (const Notice& notice : out) {
            uiAlert("popup", noticeText(notice), alertTitle(L" 公告"), MB_ICONINFORMATION);
        }
        return out;
    }

    // -----------------------------------------------------------------------
    // 内置提示（默认弹中文窗；设置过 setUiHandler 则改走回调）
    // -----------------------------------------------------------------------

    /**
     * 内置弹窗标题：优先用 init 下发的软件名（如「XXX菜单 - 公告」），
     * 未取到（init 前弹窗 / 服务端未下发）时回退「Nebula<suffix>」。
     * suffix 带前导空格，如 L" 公告"。
     */
    std::wstring alertTitle(const wchar_t* suffix) const {
        std::wstring name = toWide(lastInit().software_name);
        if (name.empty())
            return suffix ? std::wstring(L"Nebula") + suffix : std::wstring(L"Nebula");
        if (!suffix || !*suffix)
            return name;
        return name + L" - " + suffix;
    }

    void uiAlert(const char* kind, const std::string& message, const std::wstring& title, UINT icon) const {
        if (ui_) { ui_(kind, message); return; }
        ::MessageBoxW(nullptr, toWide(message).c_str(), title.c_str(), icon);
    }

    /**
     * init 成功后调用：版本过期 / 发现新版本提示。
     * @return 强制更新时返回 false（应中止登录）；否则 true
     */
    NEBULA_MUST_CHECK bool versionAlert() const {
        const InitResult init = lastInit();
        if (init.force_update) {
            uiAlert("version",
                    "当前版本过低（" + options_.client_version + "），请升级到 " + init.latest + " 后使用。",
                    alertTitle(L" 版本更新"), MB_ICONWARNING);
            return false;
        }
        if (init.need_update) {
            uiAlert("version", "发现新版本 " + init.latest + "，建议尽快升级。",
                    alertTitle(L" 版本更新"), MB_ICONINFORMATION);
        }
        return true;
    }

    /**
     * 运行期调整选项（如自动更新策略 auto_update_optional 等）。
     * 仅建议在 createDefaultClient 之后、init() 之前修改。
     */
    Options& options() { return options_; }

    // -----------------------------------------------------------------------
    // 自动更新（检测 → 下载 → 校验 → 自替换 → 重启）
    // 实现见 nebula/client/update.hpp；编译期可用 NEBULA_AUTO_UPDATE=0 整体关闭
    // -----------------------------------------------------------------------

    /**
     * 只下载更新包（不替换、不退出），由接入方自行决定何时安装。
     * 强制更新与可选更新都会下载（受 auto_update_enable 控制）。
     *
     * @return UpdateResult.state：
     *   NoUpdate      已是最新，无需更新
     *   Downloaded    下载并校验通过（new_file / verify_hash / verify_size 可用）
     *   Failed        失败（msg 为中文原因）
     */
    NEBULA_MUST_CHECK UpdateResult downloadUpdate() {
        UpdateResult r;
#if !NEBULA_AUTO_UPDATE
        r.state = UpdateState::Disabled;
        r.msg   = "自动更新已在编译期关闭（NEBULA_AUTO_UPDATE=0）";
        return r;
#else
        if (!options_.auto_update_enable) {
            r.state = UpdateState::Disabled;
            r.msg   = "自动更新未启用（Options::auto_update_enable = false）";
            return r;
        }

        const InitResult init = lastInit();
        r.version     = init.latest;
        r.verify_hash = init.file_hash;
        r.verify_size = init.file_size;
        r.force       = init.force_update;

        if (!init.need_update) {
            r.state = UpdateState::NoUpdate;
            r.msg   = "已是最新版本";
            return r;
        }
        if (init.update_url.empty()) {
            r.state = UpdateState::Failed;
            r.msg   = "服务器未提供更新包下载地址，请到官网手动下载";
            return r;
        }
        if (!options_.allow_insecure_update && !detail::isHttps(init.update_url)) {
            r.state = UpdateState::Failed;
            r.msg   = "更新地址不是 https，已拒绝下载（如需允许请在 Options 打开 allow_insecure_update）";
            return r;
        }

        // 落到 exe 同目录，确保 move 不跨卷（跨卷 move 不是原子操作且更慢）
        const std::string dir = detail::selfDir();
        if (dir.empty()) {
            r.state = UpdateState::Failed;
            r.msg   = "无法定位程序所在目录，更新中止";
            return r;
        }
        const std::string dest = dir + "\\nebula_upd_" + crypto::randomHex(6)
                               + "_" + detail::fileNameFromUrl(init.update_url);

        http::Options ho;
        ho.connectTimeoutMs = options_.connect_timeout_ms;
        ho.receiveTimeoutMs = options_.receive_timeout_ms;
        ho.useSystemProxy   = options_.use_system_proxy;
        // TLS 指纹锁定只对 API 服务器本身有意义；更新包常放在文件床/CDN（证书不同域），
        // 误继承 API 指纹会让下载 100% 被指纹校验拦截。仅同 host 才继承；
        // 跨 host 时仍有 WinHTTP 标准证书链校验 + 下载后强制 hash/大小校验兜底，安全不降级。
        auto urlHost = [](const std::string& url) {
            std::string s = toLowerAscii(url);
            const size_t scheme = s.find("://");
            if (scheme == std::string::npos) return std::string();
            s = s.substr(scheme + 3);
            const size_t path = s.find_first_of("/?#");
            if (path != std::string::npos) s = s.substr(0, path);
            const size_t at = s.rfind('@');
            if (at != std::string::npos) s = s.substr(at + 1);
            const size_t colon = s.find(':');
            if (colon != std::string::npos) s = s.substr(0, colon);
            return s;
        };
        ho.certSha256 = (urlHost(init.update_url) == urlHost(options_.api_url))
                      ? options_.tls_cert_sha256 : std::string();

        std::string err;
        if (!detail::downloadToFile(init.update_url, dest, ho, err)) {
            r.state = UpdateState::Failed;
            r.msg   = err;
            return r;
        }
        if (!detail::verifyDownloaded(dest, init.file_hash, init.file_size, err)) {
            detail::removeFileQuiet(dest);
            r.state = UpdateState::Failed;
            r.msg   = err;
            return r;
        }

        r.state    = UpdateState::Downloaded;
        r.new_file = dest;
        r.msg      = "更新包已下载并校验通过（版本 " + init.latest + "）";
        return r;
#endif
    }

    /**
     * 全自动更新：检测 → 下载 → 校验 → 生成替换脚本 → **本进程退出并重启**。
     *
     * 建议在启动时（init 之后、登录之前）调用一次：
     *
     *     auto up = client->autoUpdate();
     *     if (up.state == nebula::UpdateState::Applied) return 0;  // 即将重启
     *     if (up.state == nebula::UpdateState::NeedConfirm) { ... } // 可选更新，已提示
     *     // 其余情况继续正常启动流程
     *
     * 策略：
     *   · 强制更新（force_update）→ 自动下载、替换并重启（服务端不让旧版继续用）
     *   · 可选更新 → 默认只提示（NeedConfirm）；Options::auto_update_optional = true 时同样自动处理
     *
     * @param exitWhenApplied 完成替换后是否立即退出本进程（默认 true；
     *                        测试时可传 false，此时只生成脚本并返回 Applied）
     * @return UpdateResult（state = Applied 表示即将退出重启）
     */
    NEBULA_MUST_CHECK UpdateResult autoUpdate(bool exitWhenApplied = true) {
        UpdateResult r = downloadUpdate();
        if (r.state != UpdateState::Downloaded) return r;

        if (!r.force && !options_.auto_update_optional) {
            // 可选更新且未开启自动安装：保留已下载文件，提示用户确认
            r.state = UpdateState::NeedConfirm;
            uiAlert("update",
                    "发现新版本 " + r.version + "，已下载完成，重启后生效。",
                    alertTitle(L" 版本更新"), MB_ICONINFORMATION);
            return r;
        }

        std::string err;
        const std::string file = r.new_file;
        const std::string hash = r.verify_hash;
        const long long   size = r.verify_size;
        if (!applyUpdateAndRestartImpl(file, hash, size, exitWhenApplied, err)) {
            r.state = UpdateState::Failed;
            r.msg   = err;
            return r;
        }
        r.state = UpdateState::Applied;
        r.msg   = "更新已就绪，程序即将重启完成升级";
        return r;
    }

    /**
     * 手动对已下载的更新包执行替换并重启（配合 downloadUpdate 使用）。
     * @return 失败原因；空串 = 成功（调用方随即会退出）
     */
    NEBULA_MUST_CHECK std::string applyDownloadedUpdate(const UpdateResult& r,
                                                       bool exitWhenApplied = true) {
        std::string err;
        if (!applyUpdateAndRestartImpl(r.new_file, r.verify_hash, r.verify_size,
                                       exitWhenApplied, err)) {
            return err;
        }
        return {};
    }

    /** 自动更新是否可用（编译期开关 + 运行期开关） */
    NEBULA_MUST_CHECK static bool autoUpdateSupported() {
#if NEBULA_AUTO_UPDATE
        return true;
#else
        return false;
#endif
    }

    /** init 成功后调用：维护模式提示（登录仍由服务端 6002 兜底拦截） */
    void maintainAlert() const {
        uiAlert("maintain", "服务器维护中，请稍后再试。", alertTitle(L" 公告"), MB_ICONWARNING);
    }

    /** 被踢 / 顶号 / 需重新登录时调用（可在心跳回调线程内） */
    void kickAlert(const std::string& serverMsg) const {
        uiAlert("kick", serverMsg.empty() ? std::string("您的账号已下线，请重新登录。") : serverMsg,
                alertTitle(L" 下线通知"), MB_ICONWARNING);
    }

    /**
     * 下线提示文案（UTF-8，接入方自行转宽字符显示）：
     * 服务端 msg 优先（可区分顶号 / 管理员强制下线 / 会话过期），为空时按业务码兜底。
     * 业务码：1002 会话失效 / 2004 账号过期 / 4002 设备已解绑
     */
    NEBULA_MUST_CHECK static std::string kickText(int code, const std::string& msg) {
        if (!msg.empty()) return msg;
        switch (code) {
        case 1002: return "账号已在其他设备登录";
        case 2004: return "账号已过期，请激活后再登录";
        case 4002: return "当前设备已被解绑";
        default:   return "登录状态已失效";
        }
    }

    /** 一站式自身完整性校验（使用最近一次 init 的结果）；失败会提示并返回 false */
    NEBULA_MUST_CHECK bool enforceSelfIntegrity() const {
        const InitResult init = lastInit();
        const std::string reason = nebula::verifySelfIntegrity(init.self_file_hash, init.self_file_size);
        if (reason.empty()) return true;
        uiAlert("integrity", reason, alertTitle(L" 安全校验"), MB_ICONERROR);
        return false;
    }

    /** 最近一次 init 的结果（线程安全拷贝） */
    NEBULA_MUST_CHECK InitResult lastInit() const {
        std::lock_guard<std::mutex> lock(state_mutex_);
        return last_init_;
    }

    // -----------------------------------------------------------------------
    // 离线宽限（本地 ES256/RS256 验签，见 docs/API.md 2.16）
    // -----------------------------------------------------------------------
    /**
     * 校验离线宽限票据。心跳失败（断网）时调用；通过则可在 until 前继续离线运行。
     */
    NEBULA_MUST_CHECK GraceResult checkOffline(const std::string& ticket, const std::string& token) {
        std::string publicKey, prefix;
        {
            std::lock_guard<std::mutex> lock(state_mutex_);
            publicKey = grace_public_key_;
            prefix    = grace_prefix_;
        }
        return verifyGraceTicket(ticket, publicKey, prefix, options_.machine_id, token);
    }

    /** 本地缓存的票据（login / heartbeat 成功后自动覆盖） */
    NEBULA_MUST_CHECK std::string graceTicket() const {
        std::lock_guard<std::mutex> lock(state_mutex_);
        return grace_ticket_;
    }
    NEBULA_MUST_CHECK int64_t graceUntil() const {
        std::lock_guard<std::mutex> lock(state_mutex_);
        return grace_until_;
    }
    NEBULA_MUST_CHECK std::string getGracePublicKey() const {
        std::lock_guard<std::mutex> lock(state_mutex_);
        return grace_public_key_;
    }
    /** 当前是否需要重新登录（会话失效 / 被踢） */
    NEBULA_MUST_CHECK bool needRelogin() const {
        std::lock_guard<std::mutex> lock(state_mutex_);
        return state_ == State::Expired;
    }

private:
    enum class State { New, Ready, LoggedIn, Expired };

    NEBULA_MUST_CHECK bool isReady() const {
        std::lock_guard<std::mutex> lock(state_mutex_);
        return state_ == State::Ready || state_ == State::LoggedIn;
    }

    NEBULA_MUST_CHECK static std::string noticeText(const Notice& notice) {
        return notice.title + (notice.content.empty() ? std::string() : ("\n\n" + notice.content));
    }

    /** 按 init 下发的登录方式组装登录参数 */
    NEBULA_MUST_CHECK std::string buildLoginPayload(const std::string& account, const std::string& secret) {
        // 硬件指纹懒采集：只尝试一次，采集失败则不上报（行为与不带指纹的客户端一致）
        if (!fingerprint_tried_) {
            fingerprint_tried_ = true;
            fingerprint_json_  = device::collectFingerprintJson();
        }

        std::string tail = "," + json::pair("machine_id", json::quote(options_.machine_id))
                         + "," + json::pair("device_name", json::quote(device_name_))
                         + "," + json::pair("os_info", json::quote(options_.os_info))
                         + "," + json::pair("client_ver", json::quote(options_.client_version));
        if (!fingerprint_json_.empty()) tail += "," + json::pair("device_fp", fingerprint_json_);

        if (login_method_ == "code") {
            return "{" + json::pair("code", json::quote(secret)) + tail + "}";
        }
        if (login_method_ == "username_code") {
            return "{" + json::pair("username", json::quote(account))
                 + "," + json::pair("code", json::quote(secret)) + tail + "}";
        }
        return "{" + json::pair("username", json::quote(account))
             + "," + json::pair("password", json::quote(secret)) + tail + "}";
    }

    /**
     * Nebula 3.1 ECDH 会话路径（handshake → GCM 信封）—— 唯一通信协议。
     * · 懒握手：首个业务请求前自动 handshake，会话全程复用；
     * · 会话失效（服务端 5002/过期）自动重握手重试一次。
     */
    NEBULA_NOINLINE Response post31(const std::string& action, const std::string& payloadJson) {
        Response result;
        result.local = Error::Crypto;
        result.code  = (int)Error::Crypto;
        result.msg   = "3.1 会话错误";

        for (int attempt = 0; attempt < 2; ++attempt) {
            // ① 懒握手
            if (!session31_.active()) {
                const std::string hsBody = session31_.buildHandshakeRequest(options_.app_key,
                                                                            options_.machine_id);
                if (hsBody.empty()) {
                    result.msg = "3.1 握手请求构造失败（随机源不可用？）";
                    return result;
                }
                const http::Response hs = http::Http::post(actionUrl(options_.api_url, "handshake"),
                                                           hsBody, http_options_);
                result.http_code = hs.status;
                if (hs.status == 0) {
                    result.local = Error::Network;
                    result.code  = (int)Error::Network;
                    result.msg   = "网络错误（握手阶段：连接失败或超时）";
                    return result;
                }
                if (hs.status != 200) {
                    result.local = Error::HttpStatus;
                    result.code  = (int)Error::HttpStatus;
                    result.msg   = "HTTP " + std::to_string(hs.status) + "（握手阶段）";
                    return result;
                }
                std::string err;
                const Error e = session31_.consumeHandshakeResponse(hs.body, options_.app_key,
                                                                    options_.response_sign_public_key,
                                                                    options_.require_response_signature,
                                                                    err);
                if (e != Error::Ok) {
                    result.local = e;
                    result.code  = (int)e;
                    result.msg   = err;
                    return result;
                }
            }

            // ② 3.1 信封请求（时间戳用握手校准过的服务器时钟）
            const int64_t ts = s31::nowSeconds() + session31_.clockOffsetMs() / 1000;
            const std::string envelope = session31_.buildRequestEnvelope(payloadJson, ts, options_.app_key);
            if (envelope.empty()) {
                result.msg = "3.1 请求加密失败";
                return result;
            }

            const http::Response transport = http::Http::post(actionUrl(options_.api_url, action),
                                                              envelope, http_options_);
            result.http_code = transport.status;
            if (transport.status == 0) {
                result.local = Error::Network;
                result.code  = (int)Error::Network;
                result.msg   = "网络错误（连接失败、超时或证书校验不通过）";
                return result;
            }
            if (transport.status != 200) {
                result.local = Error::HttpStatus;
                result.code  = (int)Error::HttpStatus;
                result.msg   = "HTTP " + std::to_string(transport.status);
                return result;
            }

            // ③ 拆响应；会话失效/被服务器拒绝 → 清会话重握手，再试一次
            const OpenedResponse opened = session31_.openResponse(transport.body,
                                                                  options_.response_sign_public_key,
                                                                  options_.require_response_signature);
            if (opened.status != Error::Ok) {
                session31_.clear();
                if (attempt == 0) continue;
                result.local = opened.status;
                result.code  = (int)opened.status;
                result.msg   = opened.msg;
                return result;
            }

            result.local = Error::Ok;
            result.code  = opened.businessCode;
            result.msg   = opened.msg;
            result.raw   = opened.plain;
            return result;
        }
        return result;
    }

    /**
     * 加密信封请求：本地加密 → 签名 → HTTP → 验签（HMAC + 服务端非对称签名）→ 解密。
     * 协议实现全部在 envelope.hpp，这里只负责选盐、拼 URL 与错误映射。
     * ★ NEBULA_NOINLINE：本函数带 MUTATE 标记，必须保持独立函数体，
     *   防止被内联进宿主标记区域后加壳报「地址已由函数使用」。
     */
    /**
     * 统一请求入口：全部走 3.1 ECDH 会话协议（3.0 静态密钥信封已移除）。
     * ★ NEBULA_NOINLINE：本函数带 MUTATE 标记，必须保持独立函数体，
     *   防止被内联进宿主标记区域后加壳报「地址已由函数使用」。
     */
    NEBULA_NOINLINE Response post(const std::string& action, const std::string& payloadJson) {
        Response result;
        result.local = Error::Config;
        result.code  = (int)Error::Config;
        result.msg   = "缺少 app_key";

        if (options_.app_key.empty()) {
            result.msg = "缺少 app_key：构造 Client 时必须传入软件标识";
            return result;
        }
        if (!config_error_.empty()) {
            result.msg = config_error_;
            return result;
        }

        // ① 壳标记：加解密与签名是破解者最先想改的地方。post() 每次请求都会走，
        //    所以默认只挂「变异(MUTATE)」（性能影响小）；想更狠换成 NEBULA_MARK_VM_BEGIN。
        NEBULA_MARK_MUTATE_BEGIN();
        // ② 代码混淆：不透明谓词 + 虚假分支，打乱静态分析看到的控制流
        if (obf::opaqueFalse()) { NEBULA_DEAD_BRANCH(); }
        NEBULA_MARK_MUTATE_END();

        return post31(action, payloadJson);
    }

    void runHeartbeatLoop() {
        int interval_ms = 0;
        while (hb_running_.load()) {
            std::string token;
            HeartbeatCb callback;
            {
                std::lock_guard<std::mutex> lock(state_mutex_);
                token = hb_token_;
                callback = hb_callback_;
                interval_ms = hb_interval_ms_ > 0 ? hb_interval_ms_ : hb_default_ms_;
            }

            const Response response = heartbeat(token);
            const HeartbeatInfo info = parseHeartbeat(response);

            if (!info.grace_ticket.empty() || info.grace_until > 0) {
                std::lock_guard<std::mutex> lock(state_mutex_);
                if (!info.grace_ticket.empty()) grace_ticket_ = info.grace_ticket;
                if (info.grace_until > 0)       grace_until_  = info.grace_until;
            }

            // 立即公告：默认自动弹出未读的并标记已读（看过即不再显示）
            if (!info.flash_notices.empty() && auto_flash_) {
                const std::vector<std::pair<int64_t, int64_t>> reads =
                    loadNoticeReads(noticeReadStorePath(options_.app_key));
                for (const Notice& notice : info.flash_notices) {
                    if (noticeIsRead(reads, notice.id)) continue;
                    uiAlert("flash", noticeText(notice), alertTitle(L" 公告"), MB_ICONINFORMATION);
                    markNoticeRead(notice.id);
                }
            }

            if (callback) callback(response.code, response.msg, info);
            if (!hb_running_.load()) break;

            // 服务端建议的间隔优先（仅当接入方未显式指定 interval_ms 时）
            if (info.next_interval > 0 && interval_ms <= 0) interval_ms = info.next_interval * 1000;
            if (interval_ms < 1000) interval_ms = 1000;

            for (int waited = 0; waited < interval_ms && hb_running_.load(); waited += 200) {
                std::this_thread::sleep_for(std::chrono::milliseconds(200));
            }
        }
    }

    Options options_;
    std::string device_name_;
    http::Options http_options_;

    // init 下发的业务数据缓存
    std::string login_method_;
    std::string grace_public_key_;
    std::string grace_prefix_ = "G1";

    // Nebula 3.1 ECDH 会话（见 nebula/client/handshake.hpp）—— 唯一通信协议
    s31::Session31 session31_;

    // 设备指纹（懒采集）
    std::string fingerprint_json_;
    bool fingerprint_tried_ = false;

    // 会话状态（互斥量保护）
    mutable std::mutex state_mutex_;
    State state_ = State::New;
    std::string token_;
    std::string grace_ticket_;
    int64_t grace_until_ = 0;
    InitResult last_init_;
    protect::Report protect_report_;

    // 心跳
    std::string hb_token_;
    HeartbeatCb hb_callback_;
    int hb_interval_ms_ = 0;
    int hb_default_ms_  = 60000;
    std::atomic<bool> hb_running_{ false };
    std::thread hb_thread_;

    // 提示与配置
    UiHandler ui_;
    bool auto_flash_ = true;
    std::string config_error_;
};

/**
 * 一行接入工厂：使用 client/config.hpp 顶部 cfg 配置区常量构造 Client。
 * 用法：auto c = nebula::createDefaultClient(machineId, "Windows", "1.0.2");
 * machine_id 留空则由 SDK 生成随机临时机器码（**建议接入方持久化后传入**）。
 */
NEBULA_MUST_CHECK inline std::unique_ptr<Client> createDefaultClient(
        const std::string& machine_id = "",
        const std::string& os_info = "Windows",
        const std::string& client_version = "1.0.0") {
    Client::Options options;
    options.api_url                    = cfg::kApiUrl;
    options.app_key                    = cfg::kAppKey.str();
    options.machine_id                 = machine_id;
    options.os_info                    = os_info;
    options.client_version             = client_version;
    options.response_sign_public_key   = cfg::kRespSignPubKey;
    options.tls_cert_sha256            = cfg::kTlsCertSha256;
    // 强制校验响应非对称签名（公钥为空时构造 Client 会直接报配置错误，属"失败即拒绝"）
    options.require_response_signature = true;
    return std::unique_ptr<Client>(new Client(std::move(options)));
}

} // namespace nebula

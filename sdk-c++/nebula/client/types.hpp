#pragma once
// ============================================================================
// Nebula SDK · 公共结果类型
// ----------------------------------------------------------------------------
// 字段与服务端响应**一一对应**（docs/API.md、api/handlers/*.php）。
// 命名规则：服务端字段原样保留 snake_case，便于直接对照接口文档排查问题。
// ============================================================================

#include "../core/error.hpp"
#include "../core/secure_string.hpp"   // secureZero（LoginResult::wipeFeatureKey 内存擦除）

namespace nebula {

/** 公告（init 下发的列表公告 type=4；notice 接口的弹窗公告 type=2 / 立即公告 type=3） */
struct Notice {
    int id = 0;
    std::string title;
    std::string content;
    int type = 1;             ///< 1 普通 / 2 弹窗 / 3 立即 / 4 列表
    std::string type_text;    ///< 类型中文说明（服务端下发）
};

/** 接口返回值统一封装 */
struct Response {
    /// 业务码（0 成功）；本地错误为负值，见 nebula::Error
    int code = 0;
    /// HTTP 状态码（0 = 连接失败 / 超时 / TLS 校验失败）
    int http_code = 0;
    /// 面向用户的消息（服务端 msg；本地错误为中文说明）
    std::string msg;
    /// 解密后的业务响应 JSON 全文（可用 nebula::json::* 自行取字段）
    std::string raw;
    /// 本地错误分类（服务端业务码时为 Error::Ok）
    Error local = Error::Ok;

    NEBULA_MUST_CHECK bool ok() const { return code == 0; }
};

/** 用户信息（login / userinfo / activate 响应里的 user 对象） */
struct UserInfo {
    int user_id = 0;
    std::string username;
    std::string nickname;
    int64_t vip_expire = 0;     ///< 会员到期（Unix 秒；-1 = 永久）
    std::string vip_text;       ///< 到期中文说明（"永久" / 剩余时长）
    int points = 0;
    int max_devices = 0;
    int status = 1;
    int group_id = 0;
};

/** 心跳回调携带的数据（heartbeat 响应解析结果） */
struct HeartbeatInfo {
    int  remain = -1;              ///< 会员剩余秒数（-1 = 永久）
    bool online = true;
    bool force_offline = false;    ///< 顶号下线（他处登录）
    bool has_notice = false;       ///< 服务端有新公告
    bool need_relogin = false;     ///< 会话失效，需重新登录
    bool kick = false;             ///< 被后台强制下线
    bool need_activate = false;    ///< 账号已过期，需激活
    int  next_interval = 0;        ///< 服务端建议的下次心跳间隔（秒）
    std::string grace_ticket;      ///< 最新离线宽限票据（SDK 已自动缓存）
    int64_t grace_until = 0;       ///< 票据到期时间（Unix 秒）
    /// 随心跳下发的立即公告（type=3）：autoFlash 开启时 SDK 已自动弹出并标记已读
    std::vector<Notice> flash_notices;
};

/** init 初始化结果 */
/** 历史版本条目（init 的 version.versions 下发，供「更新日志」展示） */
struct VersionInfo {
    std::string version;
    std::string channel;
    std::string changelog;
    bool        force_update = false;
    std::string download_url;
    std::string file_hash;
    long long   file_size = 0;
    int64_t     created_at = 0;            ///< 发布时间（Unix 秒）
};

struct InitResult {
    bool ok = false;
    std::string msg;
    int64_t server_time = 0;
    std::string site_name;
    int     heartbeat_interval = 60;
    int64_t session_ttl = 0;
    bool    register_enable = true;
    bool    maintain_mode = false;
    std::string login_method;              ///< password / username_code / code

    bool    need_update = false;
    bool    force_update = false;
    std::string latest, min_ver, update_url, update_note;
    /// 最新版安装包哈希（后台未填则空；下载后用它校验更新包）
    std::string file_hash;
    /// 最新版安装包字节数（后台未填则 0）
    long long   file_size = 0;
    /// 客户端**自身版本**登记的哈希（未登记为空 → 跳过自校验）
    std::string self_file_hash;
    /// 客户端**自身版本**登记的字节数（0 = 未登记）
    long long   self_file_size = 0;
    /// 历史版本列表（version.versions，倒序；服务端未下发时为空）
    std::vector<VersionInfo> versions;

    bool    grace_enable = false;
    int     grace_seconds = 0;
    std::string grace_public_key;          ///< 离线票据验签公钥（PEM）
    std::string grace_algorithm;           ///< ES256 / RS256
    std::string grace_kid;                 ///< 密钥标识（轮换后变化）
    std::string grace_prefix = "G1";       ///< 票据前缀

    std::string app_key;                   ///< 服务端回显的软件标识
    int         software_id = 0;
    std::string software_name;
    bool        device_fp_enable = false;  ///< 服务端是否启用设备指纹
    std::vector<std::string> device_fp_components;   ///< 服务端期望的指纹组件

    std::vector<Notice> notices;           ///< 列表公告
};

/** login 登录结果 */
struct LoginResult {
    bool ok = false;
    int  code = 0;
    std::string msg;
    std::string token;                     ///< 后续所有接口的凭证
    int64_t expire_at = 0;
    int64_t ttl = 0;
    std::string login_method;
    bool account_created = false;          ///< 卡密直登自动建号时为 true
    UserInfo user;
    std::vector<std::string> device_risk;  ///< 服务端设备指纹风险标记（仅记录不拦截）
    std::string grace_ticket;              ///< 离线宽限票据（原样缓存）
    int64_t grace_until = 0;
    std::string feature_key;               ///< 功能密钥（后台「软件管理」配置；仅登录成功后下发，空=未启用。配合 nebula::feature::openSecure 解密核心数据包）
    bool need_relogin = false;

    /**
     * 用完功能密钥后立即调用：安全擦除 feature_key（volatile 写，编译器不优化掉）。
     * ----------------------------------------------------------------------------
     * 密钥只在「解开核心数据包」那一刻需要；解开后长期驻留内存只会扩大
     * dump 窗口。推荐时序：login → openSecure(包, feature_key, ...) → wipeFeatureKey()。
     * 擦除后 feature_key 变为空串；再次调用安全（幂等）。
     */
    void wipeFeatureKey() noexcept {
        if (!feature_key.empty()) {
            nebula::secureZero(&feature_key[0], feature_key.capacity());
            feature_key.clear();
            feature_key.shrink_to_fit();
        }
    }
};

/** 离线宽限票据的本地校验结果 */
struct GraceResult {
    bool ok = false;
    OfflineError code = OfflineError::Ok;
    std::string msg;
    int remain_sec = 0;                    ///< 宽限剩余秒数
    int64_t until_ts = 0;                  ///< 宽限截止时间（Unix 秒）
    int payload_userid = 0;
    int64_t payload_vip_expire = 0;        ///< 会员到期（-1 = 永久）
};

} // namespace nebula

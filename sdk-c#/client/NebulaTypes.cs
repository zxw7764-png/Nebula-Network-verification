// ============================================================================
// Nebula C# SDK · 公共结果类型与错误码
// 字段命名保留 snake_case，与服务端协议一一对应。
// ============================================================================
namespace Nebula.Sdk
{
    /// <summary>客户端本地错误（负值），与服务端业务码（>=0）永不冲突</summary>
    public enum Error
    {
        Ok = 0,
        Network = -1,     // 连接失败 / 超时 / 证书指纹不匹配
        Envelope = -2,    // 信封校验失败：HMAC 不符、响应签名不合法、解密失败
        HttpStatus = -3,  // HTTP 状态码非 200
        Config = -4,      // 配置缺失
        Crypto = -5,      // 本地密码学操作失败
        Protocol = -6,    // 协议不兼容（服务端不支持 3.1 / 协议版本异常）
    }

    public static class ErrorText
    {
        public static string Of(Error e) => e switch
        {
            Error.Ok => "成功",
            Error.Network => "网络连接失败（请检查网络或 API 地址）",
            Error.Envelope => "通信报文校验失败（响应签名不符或解密失败）",
            Error.HttpStatus => "服务器返回异常状态码",
            Error.Config => "客户端配置不完整（请检查 app_key / 响应签名公钥）",
            Error.Crypto => "本地加密组件异常",
            Error.Protocol => "协议不兼容（服务端不支持当前协议版本）",
            _ => "未知错误",
        };
    }

    /// <summary>离线宽限票据本地校验结果码</summary>
    public enum OfflineError
    {
        Ok = 0,
        Signature = -1,
        Format = -2,
        Binding = -3,
        Expired = -4,
        Disabled = -5,
    }

    public static class OfflineErrorText
    {
        public static string Of(OfflineError e) => e switch
        {
            OfflineError.Ok => "ok",
            OfflineError.Signature => "票据验签失败",
            OfflineError.Format => "票据格式错误",
            OfflineError.Binding => "票据与当前机器或会话不匹配",
            OfflineError.Expired => "离线宽限已到期",
            OfflineError.Disabled => "服务端未开启离线宽限",
            _ => "未知错误",
        };
    }

    /// <summary>公告：type 1 普通 / 2 弹窗 / 3 立即 / 4 列表</summary>
    public sealed class Notice
    {
        public int Id;
        public string Title = "";
        public string Content = "";
        public int Type = 1;
        public string TypeText = "";
    }

    /// <summary>接口返回值统一封装</summary>
    public sealed class Response
    {
        public int Code;          // 业务码（0 成功）；本地错误为负值
        public int HttpCode;      // 0 = 连接失败
        public string Msg = "";
        public string Raw = "";   // 解密后的业务响应 JSON
        public Error Local = Error.Ok;

        public bool Ok() => Code == 0;
    }

    public sealed class UserInfo
    {
        public int UserId;
        public string Username = "";
        public string Nickname = "";
        public long VipExpire;      // Unix 秒；-1 = 永久
        public string VipText = "";
        public int Points;
        public int MaxDevices;
        public int Status = 1;
        public int GroupId;
    }

    public sealed class HeartbeatInfo
    {
        public int Remain = -1;
        public bool Online = true;
        public bool ForceOffline;
        public bool HasNotice;
        public bool NeedRelogin;
        public bool Kick;
        public bool NeedActivate;
        public int NextInterval;
        public string GraceTicket = "";
        public long GraceUntil;
        public System.Collections.Generic.List<Notice> FlashNotices = new();
    }

    public sealed class InitResult
    {
        public bool Ok;
        public string Msg = "";
        public long ServerTime;
        public string SiteName = "";
        public int HeartbeatInterval = 60;
        public long SessionTtl;
        public bool RegisterEnable = true;
        public bool MaintainMode;
        public string LoginMethod = "";
        public bool NeedUpdate;
        public bool ForceUpdate;
        public string Latest = "", MinVer = "", UpdateUrl = "", UpdateNote = "";
        public string FileHash = "";
        public long FileSize;
        public string SelfFileHash = "";
        public long SelfFileSize;
        public bool GraceEnable;
        public int GraceSeconds;
        public string GracePublicKey = "";
        public string GraceAlgorithm = "";
        public string GraceKid = "";
        public string GracePrefix = "G1";
        public string AppKey = "";
        public int SoftwareId;
        public string SoftwareName = "";
        public bool DeviceFpEnable;
        public System.Collections.Generic.List<string> DeviceFpComponents = new();
        public System.Collections.Generic.List<Notice> Notices = new();
    }

    public sealed class LoginResult
    {
        public bool Ok;
        public int Code;
        public string Msg = "";
        public string Token = "";
        public long ExpireAt;
        public long Ttl;
        public string LoginMethod = "";
        public bool AccountCreated;
        public UserInfo User = new();
        public System.Collections.Generic.List<string> DeviceRisk = new();
        public string GraceTicket = "";
        public long GraceUntil;
        public string FeatureKey = "";
        public bool NeedRelogin;
    }

    public sealed class GraceResult
    {
        public bool Ok;
        public OfflineError Code = OfflineError.Ok;
        public string Msg = "";
        public int RemainSec;
        public long UntilTs;
        public int PayloadUserid;
        public long PayloadVipExpire;
    }
}

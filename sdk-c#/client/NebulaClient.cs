// ============================================================================
// Nebula C# SDK · 客户端主类
// ============================================================================
using System;
using System.Collections.Generic;
using System.Text;
using System.Threading;

namespace Nebula.Sdk
{
    /// <summary>客户端配置</summary>
    public sealed class ClientOptions
    {
        public string ApiUrl = "";                 // 必填
        public string AppKey = "";                 // 必填
        public string MachineId = "";              // 留空生成随机临时值（建议持久化后传入）
        public string OsInfo = "Windows";
        public string ClientVersion = "1.0.0";
        public string ResponseSignPublicKey = SdkConfig.RespSignPubKey;
        public string TlsCertSha256 = SdkConfig.TlsCertSha256;
        public bool RequireResponseSignature = true;
        public int ConnectTimeoutMs = 8000;
        public int ReceiveTimeoutMs = 15000;
        public bool UseSystemProxy = false;

        public bool AutoUpdateEnable = true;
        public bool AllowInsecureUpdate = false;
        public bool AutoUpdateOptional = false;
    }

    public sealed class Client : IDisposable
    {
        /// <summary>心跳回调：code = 业务码，msg = 消息，hb = 解析后的心跳数据</summary>
        public delegate void HeartbeatCb(int code, string msg, HeartbeatInfo hb);

        /// <summary>内置提示自定义处理器：kind = integrity/version/maintain/kick/flash/popup</summary>
        public delegate void UiHandler(string kind, string msg);

        private readonly ClientOptions _options;
        private readonly string _deviceName;
        private readonly Http.Options _httpOptions = new();
        private readonly string _configError = "";

        private readonly object _stateMutex = new();
        private enum State { New, Ready, LoggedIn, Expired }
        private State _state = State.New;
        private string _token = "";
        private readonly Session31 _s31 = new();   // 3.1 ECDH 会话（懒握手 + 自动重握手）
        private string _loginMethod = "";
        private string _gracePublicKey = "";
        private string _gracePrefix = "G1";
        private string _graceTicket = "";
        private long _graceUntil;
        private InitResult _lastInit = new();

        private string _fingerprintJson = "";
        private bool _fingerprintTried;

        private string _hbToken = "";
        private HeartbeatCb? _hbCallback;
        private int _hbIntervalMs;
        private int _hbDefaultMs = 60000;
        private volatile bool _hbRunning;
        private Thread? _hbThread;

        private UiHandler? _ui;
        private bool _autoFlash = true;

        public Client(ClientOptions options)
        {
            _options = options;
            _deviceName = Device.ComputerName();
            while (_options.ApiUrl.Length > 1 && _options.ApiUrl.EndsWith('/'))
                _options.ApiUrl = _options.ApiUrl[..^1];
            if (string.IsNullOrEmpty(_options.MachineId))
                _options.MachineId = Device.CreateMachineId();

            _httpOptions.ConnectTimeoutMs = options.ConnectTimeoutMs;
            _httpOptions.ReceiveTimeoutMs = options.ReceiveTimeoutMs;
            _httpOptions.CertSha256 = NormalizeHex(options.TlsCertSha256);
            _httpOptions.UseSystemProxy = options.UseSystemProxy;

            if (string.IsNullOrEmpty(options.ApiUrl)) _configError = "未配置 API 地址（ClientOptions.ApiUrl）";
            else if (string.IsNullOrEmpty(options.AppKey)) _configError = "未配置软件标识（ClientOptions.AppKey）";
            else if (options.RequireResponseSignature && string.IsNullOrEmpty(options.ResponseSignPublicKey))
                _configError = "未配置响应签名公钥（SdkConfig.RespSignPubKey），拒绝连接";
        }

        private static string NormalizeHex(string hex)
        {
            var sb = new StringBuilder();
            foreach (char c in hex)
                if (char.IsAsciiHexDigit(c)) sb.Append(char.ToLowerInvariant(c));
            return sb.ToString();
        }

        public void Dispose() => StopHeartbeat();

        // ── 配置与状态 ──────────────────────────────────────────────────────
        public bool ConfigValid => _configError.Length == 0;
        public string ConfigError => _configError;
        public string MachineId => _options.MachineId;
        public string DeviceName => _deviceName;
        public string ClientVersion => _options.ClientVersion;
        public string LoginMethod => _loginMethod;
        public ClientOptions Options => _options;
        public void SetUiHandler(UiHandler handler) => _ui = handler;

        // ── init ────────────────────────────────────────────────────────────
        public InitResult Init()
        {
            var result = new InitResult();
            if (_configError.Length > 0) { result.Msg = _configError; return result; }

            var payload = "{" + Json.Pair("client_ver", Json.Quote(_options.ClientVersion)) + ","
                              + Json.Pair("machine_id", Json.Quote(_options.MachineId)) + "}";
            var response = Post("init", payload);
            if (!response.Ok())
            {
                result.Msg = response.Msg.Length > 0 ? response.Msg : ("code " + response.Code);
                return result;
            }

            var d = Json.Parse(response.Raw);
            result.Ok = true;
            result.ServerTime = Json.FindInt64(d, "server_time");
            result.SiteName = Json.FindString(d, "site_name");
            string heartbeat = Json.FindString(d, "heartbeat_interval");
            result.HeartbeatInterval = heartbeat.Length == 0
                ? 60 : (int)(long.TryParse(heartbeat, out var hb) ? hb : 60);
            result.SessionTtl = Json.FindInt64(d, "session_ttl");
            result.RegisterEnable = Json.FindBool(d, "register_enable", true);
            result.MaintainMode = Json.FindBool(d, "maintain_mode", false);
            result.AppKey = Json.FindString(d, "app_key");
            var software = Json.FindObject(d, "software");
            if (software != null)
            {
                result.SoftwareId = Json.FindInt(software, "id");
                result.SoftwareName = Json.FindString(software, "name");
            }

            // 3.1：会话由 ECDH 握手建立（Session31 懒握手），init 不再下发会话密钥

            var loginSpec = Json.FindObject(d, "login");
            if (loginSpec != null)
            {
                string method = Json.FindString(loginSpec, "method");
                if (method.Length > 0) _loginMethod = method;
            }
            if (_loginMethod.Length == 0) _loginMethod = "password";
            result.LoginMethod = _loginMethod;

            var version = Json.FindObject(d, "version");
            if (version != null)
            {
                result.NeedUpdate = Json.FindBool(version, "need_update");
                result.ForceUpdate = Json.FindBool(version, "force_update");
                result.Latest = Json.FindString(version, "latest");
                result.MinVer = Json.FindString(version, "min");
                result.UpdateUrl = Json.FindString(version, "update_url");
                result.UpdateNote = Json.FindString(version, "update_note");
                result.FileHash = Json.FindString(version, "file_hash");
                result.FileSize = Json.FindInt64(version, "file_size");
                result.SelfFileHash = Json.FindString(version, "self_file_hash");
                result.SelfFileSize = Json.FindInt64(version, "self_file_size");
            }

            var fp = Json.FindObject(d, "device_fp");
            if (fp != null)
            {
                result.DeviceFpEnable = Json.FindBool(fp, "enable");
                result.DeviceFpComponents = Json.FindStrings(fp, "components");
            }

            var grace = Json.FindObject(d, "grace");
            if (grace != null)
            {
                result.GraceEnable = Json.FindBool(grace, "enable");
                result.GraceSeconds = Json.FindInt(grace, "seconds");
                result.GracePublicKey = Json.FindString(grace, "public_key");
                result.GraceAlgorithm = Json.FindString(grace, "algorithm");
                result.GraceKid = Json.FindString(grace, "kid");
                string prefix = Json.FindString(grace, "ticket_prefix");
                if (prefix.Length > 0) result.GracePrefix = prefix;
                if (result.GracePublicKey.Length > 0) _gracePublicKey = result.GracePublicKey;
                _gracePrefix = result.GracePrefix;
            }

            result.Notices = ParseNoticeList(response.Raw);

            lock (_stateMutex)
            {
                _hbDefaultMs = result.HeartbeatInterval > 0 ? result.HeartbeatInterval * 1000 : 60000;
                _state = State.Ready;
                _lastInit = result;
            }
            return result;
        }

        // ── login / logout / heartbeat ─────────────────────────────────────
        public LoginResult Login(string account, string secret)
        {
            var result = new LoginResult();
            if (_configError.Length > 0)
            { result.Code = (int)Error.Config; result.Msg = _configError; return result; }
            if (!IsReady())
            { result.Code = (int)Error.Config; result.Msg = "初始化失败，请重启程序"; return result; }

            var response = Post("login", BuildLoginPayload(account, secret));
            result.Code = response.Code;
            result.Msg = response.Msg;
            if (!response.Ok())
            {
                result.NeedRelogin = Json.FindBool(response.Raw, "need_relogin");
                return result;
            }

            var d = Json.Parse(response.Raw);
            result.Ok = true;
            result.Token = Json.FindString(d, "token");
            result.ExpireAt = Json.FindInt64(d, "expire_at");
            result.Ttl = Json.FindInt64(d, "ttl");
            string method = Json.FindString(d, "login_method");
            result.LoginMethod = method.Length > 0 ? method : _loginMethod;
            result.AccountCreated = Json.FindBool(d, "account_created");

            var user = Json.FindObject(d, "user");
            if (user != null)
            {
                result.User.UserId = Json.FindInt(user, "user_id");
                result.User.Username = Json.FindString(user, "username");
                result.User.Nickname = Json.FindString(user, "nickname");
                result.User.VipExpire = Json.FindInt64(user, "vip_expire");
                result.User.VipText = Json.FindString(user, "vip_text");
                result.User.Points = Json.FindInt(user, "points");
                result.User.MaxDevices = Json.FindInt(user, "max_devices");
                result.User.Status = Json.FindInt(user, "status", 1);
                result.User.GroupId = Json.FindInt(user, "group_id");
            }

            var device = Json.FindObject(d, "device");
            if (device != null) result.DeviceRisk = Json.FindStrings(device, "risk");

            var grace = Json.FindObject(d, "grace");
            if (grace != null)
            {
                result.GraceTicket = Json.FindString(grace, "ticket");
                result.GraceUntil = Json.FindInt64(grace, "until");
            }

            result.FeatureKey = Json.FindString(d, "feature_key");

            if (result.Token.Length > 0)
            {
                lock (_stateMutex)
                {
                    _token = result.Token;
                    if (result.GraceTicket.Length > 0)
                    {
                        _graceTicket = result.GraceTicket;
                        _graceUntil = result.GraceUntil;
                    }
                    _state = State.LoggedIn;
                }
            }
            return result;
        }

        public Response Logout(string token)
        {
            var response = Post("logout", "{" + Json.Pair("token", Json.Quote(token)) + "}");
            if (response.Ok())
            {
                lock (_stateMutex)
                {
                    _state = State.Ready;
                    _token = "";
                }
            }
            return response;
        }

        public Response Heartbeat(string token)
        {
            var response = Post("heartbeat", "{"
                + Json.Pair("token", Json.Quote(token)) + ","
                + Json.Pair("machine_id", Json.Quote(_options.MachineId)) + "}");
            if (response.Ok())
            {
                var grace = Json.FindObject(response.Raw, "grace");
                if (grace != null)
                {
                    string ticket = Json.FindString(grace, "ticket");
                    if (ticket.Length > 0)
                    {
                        lock (_stateMutex)
                        {
                            _graceTicket = ticket;
                            _graceUntil = Json.FindInt64(grace, "until");
                        }
                    }
                }
                if (Json.FindBool(response.Raw, "need_relogin"))
                {
                    lock (_stateMutex) _state = State.Expired;
                }
            }
            return response;
        }

        public static HeartbeatInfo ParseHeartbeat(Response response)
        {
            var info = new HeartbeatInfo();
            if (response.Ok())
            {
                var d = Json.Parse(response.Raw);
                info.Remain = Json.FindInt(d, "remain", -1);
                info.Online = Json.FindBool(d, "online", true);
                info.ForceOffline = Json.FindBool(d, "force_offline");
                info.HasNotice = Json.FindBool(d, "has_notice");
                info.NeedRelogin = Json.FindBool(d, "need_relogin");
                info.Kick = Json.FindBool(d, "kick");
                info.NeedActivate = Json.FindBool(d, "need_activate");
                info.NextInterval = Json.FindInt(d, "next_interval");

                var grace = Json.FindObject(d, "grace");
                if (grace != null)
                {
                    info.GraceTicket = Json.FindString(grace, "ticket");
                    info.GraceUntil = Json.FindInt64(grace, "until");
                }
                foreach (var obj in Json.FindObjectList(d, "flash_notices"))
                {
                    var notice = new Notice
                    {
                        Id = Json.FindInt(obj, "id"),
                        Title = Json.FindString(obj, "title"),
                        Content = Json.FindString(obj, "content"),
                        Type = 3,
                    };
                    if (notice.Id > 0) info.FlashNotices.Add(notice);
                }
            }
            else
            {
                info.NeedRelogin = Json.FindBool(response.Raw, "need_relogin");
            }
            return info;
        }

        public void StartHeartbeat(string token, HeartbeatCb callback, int intervalMs = 0)
        {
            StopHeartbeat();
            lock (_stateMutex)
            {
                _hbToken = token;
                _hbCallback = callback;
                _hbIntervalMs = intervalMs;
            }
            _hbRunning = true;
            _hbThread = new Thread(RunHeartbeatLoop) { IsBackground = true };
            _hbThread.Start();
        }

        public void StopHeartbeat()
        {
            _hbRunning = false;
            var t = _hbThread;
            if (t != null && t != Thread.CurrentThread && t.IsAlive) t.Join(3000);
        }

        public bool HeartbeatRunning => _hbRunning;

        // ── 业务接口（登录后）──────────────────────────────────────────────
        public Response Activate(string token, string code)
            => Post("activate", "{" + Json.Pair("token", Json.Quote(token)) + ","
                   + Json.Pair("machine_id", Json.Quote(_options.MachineId)) + ","
                   + Json.Pair("code", Json.Quote(code)) + "}");

        public Response Devices(string token)
            => Post("devices", "{" + Json.Pair("token", Json.Quote(token)) + "}");

        public Response UnbindDevice(string token, string machineId = "", string password = "", bool all = false)
        {
            var payload = new StringBuilder("{" + Json.Pair("token", Json.Quote(token)));
            if (machineId.Length > 0) payload.Append(",").Append(Json.Pair("machine_id", Json.Quote(machineId)));
            if (password.Length > 0) payload.Append(",").Append(Json.Pair("password", Json.Quote(password)));
            if (all) payload.Append(",").Append(Json.Pair("all", Json.Boolean(true)));
            payload.Append("}");
            return Post("unbind", payload.ToString());
        }

        public Response Userinfo(string token)
            => Post("userinfo", "{" + Json.Pair("token", Json.Quote(token)) + "}");

        public Response GetNotices(int id = 0)
            => Post("notice", "{" + Json.Pair("id", Json.Number(id)) + "}");

        public static List<Notice> ParseNoticeList(string decrypted)
        {
            var list = new List<Notice>();
            foreach (var obj in Json.FindObjectList(Json.Parse(decrypted), "list"))
            {
                list.Add(new Notice
                {
                    Id = Json.FindInt(obj, "id"),
                    Title = Json.FindString(obj, "title"),
                    Content = Json.FindString(obj, "content"),
                    Type = Json.FindInt(obj, "type", 1),
                    TypeText = Json.FindString(obj, "type_text"),
                });
            }
            return list;
        }

        public Response CheckVersion(string version, string channel = "stable")
            => Post("version", "{" + Json.Pair("version", Json.Quote(version)) + ","
                   + Json.Pair("channel", Json.Quote(channel)) + "}");

        public Response GetOnlineCount() => Post("online", "{}");

        // ── 公告 ────────────────────────────────────────────────────────────
        public List<Notice> FetchFlashNotices()
        {
            var out_ = new List<Notice>();
            var response = Post("notice", "{}");
            if (!response.Ok()) return out_;
            var reads = NoticeStore.LoadReads(NoticeStore.StorePath(_options.AppKey));
            foreach (var notice in ParseNoticeList(response.Raw))
                if (notice.Type == 3 && !NoticeStore.IsRead(reads, notice.Id))
                    out_.Add(notice);
            return out_;
        }

        public void MarkNoticeRead(long id) => NoticeStore.MarkRead(_options.AppKey, id);
        public bool IsNoticeRead(long id)
            => NoticeStore.IsRead(NoticeStore.LoadReads(NoticeStore.StorePath(_options.AppKey)), id);
        public void ClearNoticeReads() => NoticeStore.ClearReads(_options.AppKey);
        public void SetAutoFlash(bool on) => _autoFlash = on;

        public List<Notice> FlashNotices()
        {
            var list = FetchFlashNotices();
            foreach (var notice in list)
            {
                UiAlert("flash", NoticeText(notice));
                MarkNoticeRead(notice.Id);
            }
            return list;
        }

        public List<Notice> PopupNotices()
        {
            var out_ = new List<Notice>();
            var response = Post("notice", "{}");
            if (!response.Ok()) return out_;
            foreach (var notice in ParseNoticeList(response.Raw))
                if (notice.Type == 2) out_.Add(notice);
            foreach (var notice in out_) UiAlert("popup", NoticeText(notice));
            return out_;
        }

        // ── 内置提示 ────────────────────────────────────────────────────────
        private string AlertTitle(string suffix)
        {
            string name = _lastInit.SoftwareName;
            if (name.Length == 0) return "Nebula" + suffix;
            return suffix.Length == 0 ? name : name + " - " + suffix;
        }

        private void UiAlert(string kind, string message)
        {
            if (_ui != null) { _ui(kind, message); return; }
            DefaultAlert?.Invoke(kind + ":" + AlertTitle(" 公告"), message);
        }

        /// <summary>全局默认提示（未设置 UiHandler 时使用；例如 WinForms 可设为 MessageBox）</summary>
        public static Action<string, string>? DefaultAlert;

        /// <summary>init 成功后调用：版本提示。强制更新返回 false（应中止登录）</summary>
        public bool VersionAlert()
        {
            var init = LastInit();
            if (init.ForceUpdate)
            {
                UiAlert("version", "当前版本过低（" + _options.ClientVersion + "），请升级到 " + init.Latest + " 后使用。");
                return false;
            }
            if (init.NeedUpdate)
                UiAlert("version", "发现新版本 " + init.Latest + "，建议尽快升级。");
            return true;
        }

        public void MaintainAlert() => UiAlert("maintain", "服务器维护中，请稍后再试。");

        public void KickAlert(string serverMsg)
            => UiAlert("kick", serverMsg.Length > 0 ? serverMsg : "您的账号已下线，请重新登录。");

        public static string KickText(int code, string msg)
        {
            if (!string.IsNullOrEmpty(msg)) return msg;
            return code switch
            {
                1002 => "账号已在其他设备登录",
                2004 => "账号已过期，请激活后再登录",
                4002 => "当前设备已被解绑",
                _ => "登录状态已失效",
            };
        }

        /// <summary>一站式自身完整性校验；失败提示并返回 false</summary>
        public bool EnforceSelfIntegrity()
        {
            var init = LastInit();
            string reason = Integrity.VerifySelfIntegrity(init.SelfFileHash, init.SelfFileSize);
            if (reason.Length == 0) return true;
            UiAlert("integrity", reason);
            return false;
        }

        public InitResult LastInit()
        {
            lock (_stateMutex) return _lastInit;
        }

        // ── 离线宽限 ────────────────────────────────────────────────────────
        public GraceResult CheckOffline(string ticket, string token)
        {
            string publicKey, prefix;
            lock (_stateMutex) { publicKey = _gracePublicKey; prefix = _gracePrefix; }
            return Offline.VerifyGraceTicket(ticket, publicKey, prefix, _options.MachineId, token);
        }

        public string GraceTicket { get { lock (_stateMutex) return _graceTicket; } }
        public long GraceUntil { get { lock (_stateMutex) return _graceUntil; } }
        public string GetGracePublicKey() { lock (_stateMutex) return _gracePublicKey; }
        public bool NeedRelogin { get { lock (_stateMutex) return _state == State.Expired; } }

        // ── 自动更新 ────────────────────────────────────────────────────────
        public UpdateResult DownloadUpdate()
            => Update.Download(LastInit(), _options);

        public UpdateResult AutoUpdate(bool exitWhenApplied = true)
            => Update.Auto(LastInit(), _options,
                (kind, msg) => UiAlert(kind, msg), exitWhenApplied);

        // ── 内部实现 ────────────────────────────────────────────────────────
        private bool IsReady()
        {
            lock (_stateMutex) return _state == State.Ready || _state == State.LoggedIn;
        }

        private static string NoticeText(Notice n)
            => n.Title + (n.Content.Length == 0 ? "" : ("\n\n" + n.Content));

        private string BuildLoginPayload(string account, string secret)
        {
            if (!_fingerprintTried)
            {
                _fingerprintTried = true;
                try { _fingerprintJson = Device.CollectFingerprintJson(); }
                catch { _fingerprintJson = ""; }
            }

            var tail = "," + Json.Pair("machine_id", Json.Quote(_options.MachineId))
                     + "," + Json.Pair("device_name", Json.Quote(_deviceName))
                     + "," + Json.Pair("os_info", Json.Quote(_options.OsInfo))
                     + "," + Json.Pair("client_ver", Json.Quote(_options.ClientVersion));
            if (_fingerprintJson.Length > 0)
                tail += "," + Json.Pair("device_fp", _fingerprintJson);

            if (_loginMethod == "code")
                return "{" + Json.Pair("code", Json.Quote(secret)) + tail + "}";
            if (_loginMethod == "username_code")
                return "{" + Json.Pair("username", Json.Quote(account))
                     + "," + Json.Pair("code", Json.Quote(secret)) + tail + "}";
            return "{" + Json.Pair("username", Json.Quote(account))
                 + "," + Json.Pair("password", Json.Quote(secret)) + tail + "}";
        }

        /// <summary>
        /// 统一请求入口：全部走 3.1 ECDH 会话协议（3.0 静态密钥信封已移除）。
        /// 懒握手：首个业务请求前自动 handshake；会话失效自动重握手重试一次。
        /// </summary>
        private Response Post(string action, string payloadJson)
        {
            var result = new Response { Local = Error.Crypto, Code = (int)Error.Crypto,
                                        Msg = "3.1 会话错误" };

            if (string.IsNullOrEmpty(_options.AppKey))
            { result.Msg = "缺少 app_key：构造 Client 时必须传入软件标识"; return result; }
            if (_configError.Length > 0) { result.Msg = _configError; return result; }

            for (int attempt = 0; attempt < 2; attempt++)
            {
                // ① 懒握手
                if (!_s31.Active)
                {
                    string hsBody = _s31.BuildHandshakeRequest(_options.AppKey, _options.MachineId);
                    if (hsBody.Length == 0)
                    {
                        result.Msg = "3.1 握手请求构造失败（随机源不可用？）";
                        return result;
                    }
                    var hs = Http.Post(Envelope.ActionUrl(_options.ApiUrl, "handshake"), hsBody, _httpOptions);
                    result.HttpCode = hs.Status;
                    if (hs.Status == 0)
                    {
                        result.Local = Error.Network; result.Code = (int)Error.Network;
                        result.Msg = "网络错误（握手阶段：连接失败或超时）";
                        return result;
                    }
                    if (hs.Status != 200)
                    {
                        result.Local = Error.HttpStatus; result.Code = (int)Error.HttpStatus;
                        result.Msg = "HTTP " + hs.Status + "（握手阶段）";
                        return result;
                    }
                    string hsErr = "";
                    var e = _s31.ConsumeHandshakeResponse(hs.Body, _options.ResponseSignPublicKey,
                                                          _options.RequireResponseSignature, ref hsErr);
                    if (e != Error.Ok)
                    {
                        _s31.Clear();
                        result.Local = e; result.Code = (int)e; result.Msg = hsErr;
                        return result;
                    }
                }

                // ② 3.1 信封请求（时间戳用握手校准过的服务器时钟）
                long ts = Now.UnixSeconds() + _s31.ClockOffsetMs / 1000;
                string envelope = _s31.BuildRequestEnvelope(payloadJson, ts, _options.AppKey);
                if (envelope.Length == 0)
                {
                    result.Msg = "3.1 请求加密失败";
                    return result;
                }

                var transport = Http.Post(Envelope.ActionUrl(_options.ApiUrl, action), envelope, _httpOptions);
                result.HttpCode = transport.Status;
                if (transport.Status == 0)
                {
                    result.Local = Error.Network; result.Code = (int)Error.Network;
                    result.Msg = "网络错误（连接失败、超时或证书校验不通过）";
                    return result;
                }
                if (transport.Status != 200)
                {
                    result.Local = Error.HttpStatus; result.Code = (int)Error.HttpStatus;
                    result.Msg = "HTTP " + transport.Status;
                    return result;
                }

                // ③ 拆响应；非 3.1 信封（会话失效/明文错误）→ 清会话重握手再试一次
                var opened = _s31.OpenResponse(transport.Body, _options.ResponseSignPublicKey,
                                               _options.RequireResponseSignature);
                if (opened.Status != Error.Ok)
                {
                    _s31.Clear();
                    if (attempt == 0) continue;
                    result.Local = opened.Status;
                    result.Code = (int)opened.Status;
                    result.Msg = opened.Msg;
                    return result;
                }

                result.Local = Error.Ok;
                result.Code = opened.BusinessCode;
                result.Msg = opened.Msg;
                result.Raw = opened.Plain;
                return result;
            }
            return result;
        }

        private void RunHeartbeatLoop()
        {
            int intervalMs = 0;
            while (_hbRunning)
            {
                string token;
                HeartbeatCb? callback;
                lock (_stateMutex)
                {
                    token = _hbToken;
                    callback = _hbCallback;
                    intervalMs = _hbIntervalMs > 0 ? _hbIntervalMs : _hbDefaultMs;
                }

                var response = Heartbeat(token);
                var info = ParseHeartbeat(response);

                if (info.GraceTicket.Length > 0 || info.GraceUntil > 0)
                {
                    lock (_stateMutex)
                    {
                        if (info.GraceTicket.Length > 0) _graceTicket = info.GraceTicket;
                        if (info.GraceUntil > 0) _graceUntil = info.GraceUntil;
                    }
                }

                if (info.FlashNotices.Count > 0 && _autoFlash)
                {
                    var reads = NoticeStore.LoadReads(NoticeStore.StorePath(_options.AppKey));
                    foreach (var notice in info.FlashNotices)
                    {
                        if (NoticeStore.IsRead(reads, notice.Id)) continue;
                        UiAlert("flash", NoticeText(notice));
                        MarkNoticeRead(notice.Id);
                    }
                }

                callback?.Invoke(response.Code, response.Msg, info);
                if (!_hbRunning) break;

                if (info.NextInterval > 0 && intervalMs <= 0) intervalMs = info.NextInterval * 1000;
                if (intervalMs < 1000) intervalMs = 1000;

                for (int waited = 0; waited < intervalMs && _hbRunning; waited += 200)
                    Thread.Sleep(200);
            }
        }
    }

    /// <summary>一行接入工厂：用 SdkConfig 配置区常量构造 Client</summary>
    public static class NebulaFactory
    {
        public static Client CreateDefaultClient(string machineId = "", string osInfo = "Windows",
                                                 string clientVersion = "1.0.0")
        {
            return new Client(new ClientOptions
            {
                ApiUrl = SdkConfig.ApiUrl,
                AppKey = SdkConfig.AppKey,
                MachineId = machineId,
                OsInfo = osInfo,
                ClientVersion = clientVersion,
                ResponseSignPublicKey = SdkConfig.RespSignPubKey,
                TlsCertSha256 = SdkConfig.TlsCertSha256,
                RequireResponseSignature = true,
            });
        }
    }
}

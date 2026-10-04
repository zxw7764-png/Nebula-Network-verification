# Nebula C# SDK 完整文档

> **版本**：SDK 3.1.1（协议 Nebula 3.1）  |  **目标框架**：.NET 10 (net10.0-windows)  |  **平台**：Windows x64  
> ⚠️ **SDK ≥ 3.1.1** 才能连接服务端 **≥ 2.65.22**（响应方向加密改用独立派生密钥
> `sk_enc_rsp = HKDF(sk_enc, info="nebula31-enc-rsp")`，旧 SDK 解密响应失败）。

---

## 目录

- [1. 概述](#1-概述)
- [2. 快速开始](#2-快速开始)
- [3. 配置文件详解 (SdkConfig.cs)](#3-配置文件详解-sdkconfigcs)
- [4. 核心接口](#4-核心接口)
  - [4.1 Client 主类](#41-client-主类)
  - [4.2 初始化 (Init)](#42-初始化-init)
  - [4.3 登录 (Login)](#43-登录-login)
  - [4.4 心跳保活 (Heartbeat)](#44-心跳保活-heartbeat)
  - [4.5 登出 (Logout)](#45-登出-logout)
  - [4.6 设备管理](#46-设备管理)
  - [4.7 用户信息](#47-用户信息)
  - [4.8 公告系统](#48-公告系统)
  - [4.9 版本检查与自动更新](#49-版本检查与自动更新)
  - [4.10 离线宽限 (Grace)](#410-离线宽限-grace)
  - [4.11 功能密钥数据包 (NF1)](#411-功能密钥数据包-nf1)
  - [4.12 自身完整性校验](#412-自身完整性校验)
- [5. 安全防护功能](#5-安全防护功能)
  - [5.1 运行时防护 (RuntimeProtection)](#51-运行时防护-runtimeprotection)
  - [5.2 字符串混淆 (Obfuscate)](#52-字符串混淆-obfuscate)
  - [5.3 TLS 证书指纹锁定](#53-tls-证书指纹锁定)
  - [5.5 通信协议（Nebula 3.1）](#55-通信协议nebula-31)
- [6. VMP / 壳保护 现状与规划](#6-vmp--壳保护-现状与规划)
- [7. 一键全开安全功能](#7-一键全开安全功能)
- [8. 完整接入示例](#8-完整接入示例)
- [9. 错误码参考](#9-错误码参考)
- [10. 文件清单](#10-文件清单)

---

## 1. 概述

Nebula SDK 是一套面向 .NET / WinForms 应用的软件授权验证客户端 SDK，提供：

| 能力 | 说明 |
|------|------|
| 加密通信 | Nebula 3.1：ECDH P-256 会话握手 + AES-256-GCM + seq 防重放 + ES256 响应验签（客户端零静态对称机密） |
| 多种登录方式 | 账密、卡密直登、用户名+激活码（服务端下发 `login.method`） |
| 心跳保活 | 后台线程自动心跳，支持踢下线、强制下线、闪现公告 |
| 设备绑定 | 机器码 + 设备指纹（board/cpu/disk/bios/mac/gpu）多维识别 |
| 离线宽限 | 断网后凭签名票据继续运行，到时自动退出 |
| 自动更新 | 检测 → 下载 → SHA256 校验 → 批处理自替换 → 重启 |
| 公告系统 | 四种类型：列表、弹窗、立即闪现、列表查询 |
| 运行时防护 | 反调试、反虚拟机/沙箱、硬件断点检测 |
| 字符串混淆 | 编译期 XOR 加密，降低静态分析风险 |
| 完整性自校验 | 程序文件 SHA256/MD5 + 大小校验，防二进制篡改 |
| 功能密钥包 | NF1 格式数据包，encrypt-then-MAC，用于核心数据保护 |

---

## 2. 快速开始

### 最简三行接入

```csharp
using Nebula.Sdk;

// ① 用 SdkConfig 常量创建客户端
var client = NebulaFactory.CreateDefaultClient(
    machineId: "",          // 留空自动生成（建议持久化后传入）
    osInfo: "Windows",
    clientVersion: "1.0.0"
);

// ② 初始化（init）
var init = client.Init();
if (!init.Ok) { /* 初始化失败处理 */ }

// ③ 登录
var login = client.Login("用户名", "密码");
if (login.Ok) {
    // 登录成功，token 可用
    client.StartHeartbeat(login.Token, (code, msg, hb) => {
        // 心跳回调
    });
}
```

### 前置条件

1. 在 `SdkConfig.cs` 中填入从后台获取的 4 项配置（见 [§3](#3-配置文件详解-sdkconfigcs)）
2. 项目文件 `.csproj` 引用 `System.Management` NuGet 包（反 VM WMI 查询依赖）
3. 目标框架 `net10.0-windows`（WinForms + Windows 专用 API）

---

## 3. 配置文件详解 (SdkConfig.cs)

`SdkConfig.cs` 是接入方**唯一需要修改的文件**。所有常量来自后台「软件管理」页面。

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `ApiUrl` | `const string` | ✅ | API 入口地址，必须以 `/api/index.php` 结尾 |
| `AppKey` | `const string` | ✅ | 软件标识（字母数字组合），每个软件独立 |
| `RespSignPubKey` | `const string` | ✅ | 响应签名公钥（PEM 格式），验签服务端响应 |
| `TlsCertSha256` | `const string` | ❌ | TLS 证书指纹（64 位 hex），仅 HTTPS 生效，防中间人 |
| `ProtectStrictPolicy` | `const bool` | ❌ | 防护严格模式开关（`false`=宽松/默认，`true`=严格） |
| `DebugLog` | `const bool` | ❌ | 调试日志开关（发布时置 `false`） |

> 协议 3.1 起通信密钥由 ECDH P-256 握手临时协商，客户端**不再持有任何静态对称密钥**（3.0 的 AesKey/SignSalt 已彻底移除）。

### 安全建议

- ⚠ `RespSignPubKey` 为空时所有请求会返回配置错误，拒绝连接
- 生产环境务必配置 `TlsCertSha256`，防止中间人攻击
- 不要将含真实公钥/域名的配置提交到公开仓库

---

## 4. 核心接口

### 4.1 Client 主类

```csharp
public sealed class Client : IDisposable
```

**构造函数**：

```csharp
var client = new Client(new ClientOptions {
    ApiUrl = "https://example.com/api/index.php",
    AppKey = "SWBFE6879E94DD",
    MachineId = "",              // 留空自动生成随机值
    OsInfo = "Windows",
    ClientVersion = "1.0.0",
    ResponseSignPublicKey = "...", // PEM 公钥
    TlsCertSha256 = "...",        // 可选
    RequireResponseSignature = true,
    ConnectTimeoutMs = 8000,
    ReceiveTimeoutMs = 15000,
    UseSystemProxy = false,
    AutoUpdateEnable = true,
    AllowInsecureUpdate = false,
    AutoUpdateOptional = false,
});
```

**ClientOptions 完整字段**：

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `ApiUrl` | `string` | `""` | API 入口地址 |
| `AppKey` | `string` | `""` | 软件标识 |
| `MachineId` | `string` | `""` | 机器码，留空自动生成 |
| `OsInfo` | `string` | `"Windows"` | 操作系统信息 |
| `ClientVersion` | `string` | `"1.0.0"` | 客户端版本号 |
| `ResponseSignPublicKey` | `string` | `SdkConfig.RespSignPubKey` | 响应验签公钥 |
| `TlsCertSha256` | `string` | `SdkConfig.TlsCertSha256` | TLS 证书指纹 |
| `RequireResponseSignature` | `bool` | `true` | 是否强制验证响应签名 |
| `ConnectTimeoutMs` | `int` | `8000` | 连接超时（毫秒） |
| `ReceiveTimeoutMs` | `int` | `15000` | 接收超时（毫秒） |
| `UseSystemProxy` | `bool` | `false` | 是否使用系统代理（默认关闭，防本地代理劫持） |
| `AutoUpdateEnable` | `bool` | `true` | 是否启用自动更新 |
| `AllowInsecureUpdate` | `bool` | `false` | 是否允许 HTTP 更新（默认仅 HTTPS） |
| `AutoUpdateOptional` | `bool` | `false` | 非强制更新是否自动应用 |

**Client 属性与方法**：

| 成员 | 类型 | 说明 |
|------|------|------|
| `ConfigValid` | `bool` | 配置是否完整 |
| `ConfigError` | `string` | 配置错误描述 |
| `MachineId` | `string` | 当前机器码 |
| `DeviceName` | `string` | 主机名 |
| `ClientVersion` | `string` | 客户端版本 |
| `LoginMethod` | `string` | 服务端下发的登录方式 |
| `Options` | `ClientOptions` | 原始配置 |
| `SetUiHandler(UiHandler)` | `void` | 设置 UI 提示处理器 |
| `DefaultAlert` | `Action<string,string>?` | 全局默认弹窗（静态，未设 UiHandler 时使用） |
| `GraceTicket` | `string` | 当前离线宽限票据 |
| `GraceUntil` | `long` | 宽限到期 Unix 秒 |
| `NeedRelogin` | `bool` | 是否需要重新登录 |
| `HeartbeatRunning` | `bool` | 心跳线程是否运行中 |
| `Dispose()` | `void` | 停止心跳 |

### 4.2 初始化 (Init)

```csharp
public InitResult Init();
```

初始化是登录的前置步骤。SDK 会向服务端发送 `client_ver` 和 `machine_id`，获取：
- 服务端时间、站点名称、心跳间隔
- 注册开关、维护模式
- 登录方式（password / code / username_code）
- 版本检查信息（是否需更新、强制更新）
- 离线宽限配置（公钥、前缀、算法）
- 设备指纹开关与组件列表
- 公告列表

**InitResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Ok` | `bool` | 是否成功 |
| `Msg` | `string` | 错误消息 |
| `ServerTime` | `long` | 服务端 Unix 秒 |
| `SiteName` | `string` | 站点名称 |
| `HeartbeatInterval` | `int` | 心跳间隔（秒，默认 60） |
| `SessionTtl` | `long` | 会话 TTL |
| `RegisterEnable` | `bool` | 是否允许注册 |
| `MaintainMode` | `bool` | 是否维护中 |
| `LoginMethod` | `string` | 登录方式 |
| `NeedUpdate` | `bool` | 是否需要更新 |
| `ForceUpdate` | `bool` | 是否强制更新 |
| `Latest` | `string` | 最新版本号 |
| `MinVer` | `string` | 最低版本 |
| `UpdateUrl` | `string` | 更新包下载地址 |
| `UpdateNote` | `string` | 更新说明 |
| `FileHash` | `string` | 更新包哈希 |
| `FileSize` | `long` | 更新包大小 |
| `SelfFileHash` | `string` | 自身文件哈希（完整性校验用） |
| `SelfFileSize` | `long` | 自身文件大小 |
| `GraceEnable` | `bool` | 离线宽限开关 |
| `GraceSeconds` | `int` | 宽限秒数 |
| `GracePublicKey` | `string` | 宽限验签公钥 |
| `GraceAlgorithm` | `string` | 宽限算法 |
| `GraceKid` | `string` | 宽限密钥 ID |
| `GracePrefix` | `string` | 票据前缀（默认 G1） |
| `AppKey` | `string` | 服务端确认的 AppKey |
| `SoftwareId` | `int` | 软件 ID |
| `SoftwareName` | `string` | 软件名称 |
| `DeviceFpEnable` | `bool` | 设备指纹开关 |
| `DeviceFpComponents` | `List<string>` | 指纹组件列表 |
| `Notices` | `List<Notice>` | 公告列表 |

### 4.3 登录 (Login)

```csharp
public LoginResult Login(string account, string secret);
```

根据 `Init` 下发的 `LoginMethod` 自动适配请求格式：

| LoginMethod | account 参数 | secret 参数 | 说明 |
|-------------|-------------|-------------|------|
| `password` | 用户名 | 密码 | 账密登录（默认） |
| `code` | 卡密 | （不用） | 卡密直登，单框输入 |
| `username_code` | 用户名 | 激活码 | 用户名+激活码 |

登录成功后 SDK 会自动采集设备指纹（board/cpu/disk/bios/mac/gpu）并随请求提交。

**LoginResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Ok` | `bool` | 是否成功 |
| `Code` | `int` | 业务码（0=成功，负值=本地错误） |
| `Msg` | `string` | 消息 |
| `Token` | `string` | 会话令牌 |
| `ExpireAt` | `long` | 到期 Unix 秒 |
| `Ttl` | `long` | 有效期秒数 |
| `LoginMethod` | `string` | 实际使用的登录方式 |
| `AccountCreated` | `bool` | 是否自动创建了账号 |
| `User` | `UserInfo` | 用户信息 |
| `DeviceRisk` | `List<string>` | 设备风险标签 |
| `GraceTicket` | `string` | 离线宽限票据 |
| `GraceUntil` | `long` | 宽限到期 Unix 秒 |
| `FeatureKey` | `string` | 功能密钥（用于 NF1 数据包解密） |
| `NeedRelogin` | `bool` | 是否需要重新登录 |

**UserInfo 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `UserId` | `int` | 用户 ID |
| `Username` | `string` | 用户名 |
| `Nickname` | `string` | 昵称 |
| `VipExpire` | `long` | VIP 到期 Unix 秒（-1=永久） |
| `VipText` | `string` | VIP 文本描述 |
| `Points` | `int` | 积分 |
| `MaxDevices` | `int` | 最大设备数 |
| `Status` | `int` | 状态（1=正常） |
| `GroupId` | `int` | 用户组 ID |

### 4.4 心跳保活 (Heartbeat)

```csharp
// 手动心跳
public Response Heartbeat(string token);

// 自动心跳（后台线程）
public void StartHeartbeat(string token, HeartbeatCb callback, int intervalMs = 0);
public void StopHeartbeat();
```

**心跳回调委托**：

```csharp
public delegate void HeartbeatCb(int code, string msg, HeartbeatInfo hb);
```

**HeartbeatInfo 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Remain` | `int` | 剩余秒数（-1=未知） |
| `Online` | `bool` | 是否在线 |
| `ForceOffline` | `bool` | 是否被强制下线 |
| `HasNotice` | `bool` | 是否有新公告 |
| `NeedRelogin` | `bool` | 是否需要重新登录 |
| `Kick` | `bool` | 是否被踢下线 |
| `NeedActivate` | `bool` | 是否需要激活 |
| `NextInterval` | `int` | 下次心跳间隔（秒） |
| `GraceTicket` | `string` | 宽限票据（心跳续期） |
| `GraceUntil` | `long` | 宽限到期 |
| `FlashNotices` | `List<Notice>` | 闪现公告列表 |

**心跳线程特性**：
- 后台线程（`IsBackground = true`），不阻止进程退出
- 支持 `NextInterval` 动态调整间隔
- 自动处理闪现公告（`AutoFlash` 开关）
- 回调在心跳线程执行，UI 操作需自行 `BeginInvoke`
- `StopHeartbeat()` 等待最多 3 秒确保线程退出

**典型用法**：

```csharp
client.StartHeartbeat(login.Token, (code, msg, hb) => {
    if (hb.Kick || hb.NeedRelogin || hb.ForceOffline) {
        // 被踢下线，回登录界面
        BeginInvoke(() => { /* 处理踢下线 */ });
    }
});
```

### 4.5 登出 (Logout)

```csharp
public Response Logout(string token);
```

登出后销毁服务端会话，本地状态重置为 `Ready`。

### 4.6 设备管理

```csharp
// 查看当前账号绑定的设备列表
public Response Devices(string token);

// 解绑设备
public Response UnbindDevice(string token, string machineId = "", 
                             string password = "", bool all = false);

// 激活码激活
public Response Activate(string token, string code);
```

**UnbindDevice 参数**：

| 参数 | 说明 |
|------|------|
| `machineId` | 指定要解绑的机器码（空=解绑当前设备） |
| `password` | 解绑需要的密码验证（服务端策略） |
| `all` | `true` = 解绑所有设备 |

### 4.7 用户信息

```csharp
public Response Userinfo(string token);
```

登录后可随时查询最新用户信息。

### 4.8 公告系统

```csharp
// 查询公告
public Response GetNotices(int id = 0);
public static List<Notice> ParseNoticeList(string decrypted);

// 闪现公告（type=3，确认后不再显示）
public List<Notice> FetchFlashNotices();
public List<Notice> FlashNotices();       // 获取 + 自动弹窗 + 标记已读
public void MarkNoticeRead(long id);
public bool IsNoticeRead(long id);
public void ClearNoticeReads();
public void SetAutoFlash(bool on);

// 弹窗公告（type=2，每次登录提示）
public List<Notice> PopupNotices();
```

**公告类型**：

| Type | 说明 |
|------|------|
| 1 | 普通公告（列表展示） |
| 2 | 弹窗公告（每次登录提示） |
| 3 | 立即公告（闪现，确认后不再显示） |
| 4 | 列表公告（公告栏展示） |

**已读记录存储路径**：`%APPDATA%\NebulaSDK\notices_<app_key>.txt`，自动保留 30 天。

### 4.9 版本检查与自动更新

```csharp
// 手动版本检查
public Response CheckVersion(string version, string channel = "stable");

// 自动更新：检测 → 下载 → SHA256/大小校验 → 替换重启
public UpdateResult AutoUpdate(bool exitWhenApplied = true);

// 只下载不替换
public UpdateResult DownloadUpdate();
```

**UpdateResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `State` | `UpdateState` | 更新状态 |
| `Msg` | `string` | 消息 |
| `Version` | `string` | 新版本号 |
| `NewFile` | `string` | 下载的文件路径 |
| `VerifyHash` | `string` | 校验哈希 |
| `VerifySize` | `long` | 校验大小 |
| `Force` | `bool` | 是否强制更新 |

**UpdateState 枚举**：

| 值 | 说明 |
|----|------|
| `NoUpdate` | 已是最新 |
| `Downloaded` | 已下载待安装 |
| `NeedConfirm` | 需用户确认 |
| `Applied` | 已替换并重启 |
| `Failed` | 失败 |
| `Disabled` | 自动更新未启用 |

**安全机制**：
- 更新包必须通过 SHA256 和大小双校验，不匹配自动删除
- 默认仅允许 HTTPS 更新地址（`AllowInsecureUpdate = false`）
- 替换通过批处理脚本实现：等进程退出 → `move` → `start` → 自删
- 同 host 的 HTTPS 更新地址复用证书指纹锁定

### 4.10 离线宽限 (Grace)

```csharp
// 离线宽限票据校验
public GraceResult CheckOffline(string ticket, string token);

// 获取当前宽限信息
public string GraceTicket { get; }
public long GraceUntil { get; }
public string GetGracePublicKey();
```

**GraceResult 字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `Ok` | `bool` | 是否通过 |
| `Code` | `OfflineError` | 错误码 |
| `Msg` | `string` | 消息 |
| `RemainSec` | `int` | 剩余秒数 |
| `UntilTs` | `long` | 到期 Unix 秒 |
| `PayloadUserid` | `int` | 票据载荷用户 ID |
| `PayloadVipExpire` | `long` | 票据载荷 VIP 到期 |

**票据格式**：`G1.<payload-b64url>.<signature-b64url>`

**校验流程**（`Offline.VerifyGraceTicket`）：
1. 解析票据分段与载荷
2. 验签（ES256/RS256，签名对象 = `G1.<payload>`）
3. 机器码摘要校验（SHA256 前 16 位）
4. 会话令牌摘要校验
5. 有效期校验（容忍 ±120 秒时钟偏差）

### 4.11 功能密钥数据包 (NF1)

```csharp
// 加密（开发期使用）
string pack = Feature.Seal("核心数据明文", featureKey);

// 解密（登录拿到 feature_key 后调用）
bool ok = Feature.Open(pack, featureKey, out string data, out string error);
```

**NF1 格式**：`NF1.<base64(IV+AES-256-CBC密文)>.<HMAC-SHA256>`

**安全机制**：
- 密钥派生：AES Key = `SHA256(featureKey + "|nebula-feature-aes")`，MAC Key = `SHA256(featureKey + "|nebula-feature-mac")`
- Encrypt-then-MAC：先验签后解密，防篡改
- IV 随机生成（`RandomNumberGenerator`）
- 恒定时间比较（`ConstantTimeEquals`），防时序侧信道

### 4.12 自身完整性校验

```csharp
// 一站式完整性校验（init 成功后调用）
public bool EnforceSelfIntegrity();
```

校验当前 exe 文件的 SHA256/MD5 哈希和大小是否与服务端 `init` 下发的一致。失败时 SDK 自动弹窗提示并返回 `false`。

---

## 5. 安全防护功能

### 5.1 运行时防护 (RuntimeProtection)

`NebulaRuntimeProtection.cs` 提供完整的运行时反调试、反虚拟机/沙箱、代码补丁自检。

#### 加固开关（在 `SdkConfig.cs` 中配置）

| 开关 | 默认 | 说明 |
|------|------|------|
| `Harden` | `false` | ★ 一键全开（= 防护等级3 + 混淆 + 壳标记 + 运行时多样性） |
| `ProtectLevel` | `0` | 运行时防护等级：`0` 关闭 / `1` 基础 / `2` 标准 / `3` 严格 |
| `ProtectAction` | `1` | 命中后处置：`0` 记录 / `1` 回调 / `2` 降级 / `3` 弹窗退出 |
| `ProtectStrictPolicy` | `false` | 疑似环境是否也拦截（`true` 会误伤 VM 用户） |
| `TimingThresholdMs` | `50.0` | 时序异常阈值（毫秒） |

#### 检测能力（三个等级）

| 检测项 | 等级 | 权重 | 误报风险 |
|--------|------|------|----------|
| `IsDebuggerPresent` | 1 | 100（铁证） | 无 |
| `PEB.BeingDebugged`（NtQuery 三重检测） | 1 | 100（铁证） | 无 |
| 进程堆标志异常 | 1 | 35 | 极低 |
| `CheckRemoteDebuggerPresent` | 2 | 100（铁证） | 无 |
| `ProcessDebugPort` / `DebugObjectHandle` | 2 | 100（铁证） | 无 |
| `ProcessDebugFlags == 0` | 2 | 80 | 无 |
| 线程硬件断点 `Dr0-Dr7` | 2 | 70 | 低 |
| 调试/逆向工具进程（x64dbg, IDA 等） | 2 | 60 | 低 |
| 调试器窗口（OllyDbg, x64dbg 等） | 3 | 60 | 低 |
| 关键代码执行时序异常 | 3 | 30 | 中 |
| frida 等注入框架模块 | 2 | 70 | 无 |
| 关键 API 首字节被改（inline hook） | 2 | 20/45 | 中 |
| 受保护代码段被改写（`GuardCode`） | 2 | 90 | 无 |
| CPUID/WMI 虚拟机厂商 | 1 | 30 | 高（Hyper-V/VBS 也置位） |
| 注册表虚拟机痕迹 | 1 | 35 | 无 |
| BIOS/主板厂商字段 | 1 | 40 | 低 |
| 网卡 MAC OUI 属虚拟网卡 | 1 | 45 | 低 |
| 虚拟机增强工具进程 | 2 | 30 | 无 |
| 虚拟机/沙箱模块 | 2 | 30/60 | 无 |
| WDAG 隔离环境账号 | 2 | 60 | 无 |
| 虚拟机驱动文件痕迹 | 3 | 25 | 无 |
| 机器配置异常偏低 | 3 | 15 | 中 |
| 开机时间 < 5 分钟 | 3 | 20 | 中 |

#### 判定规则

- `Debugged`：命中任一铁证或弱线索累计 ≥ 90 分
- `Virtualized`：虚拟机线索累计 ≥ 60 分
- `Sandboxed`：命中沙箱特征（Sandboxie/Cuckoo/WDAG）
- `Hooked`：命中注入模块或 ≥2 个关键 API 被改写
- `Clean`：以上全部没命中

#### 使用方式

**方式 A：SDK 自动（推荐）**

```csharp
// 在 SdkConfig.cs 中设置 ProtectLevel = 2（标准）

// 在程序启动时执行检测
var report = RuntimeProtection.Scan();
if (!report.Clean)
{
    Console.WriteLine(report.Summary());
    Console.WriteLine(report.Detail());
}

// 设置回调上报到服务端
RuntimeProtection.SetCallback(r => {
    // sendToServer(r.Score, r.Debugged, r.Virtualized, r.Sandboxed, r.Hooked);
});

// 启动后台巡检（每 5 秒检测一次）
RuntimeProtection.StartWatchdog(5000);
// 程序退出时停止
RuntimeProtection.StopWatchdog();
```

**方式 B：检测 + 处置一步到位**

```csharp
var report = RuntimeProtection.ScanAndEnforce(out bool mayContinue);
if (!mayContinue)
{
    // action=2 时进入降级模式，拒绝后续业务
    // action=3 时已弹窗退出，不会走到这里
}
```

**方式 C：兼容旧接口**

```csharp
var result = RuntimeProtection.Check(new RuntimeProtection.CheckOptions
{
    StrictPolicy = SdkConfig.ProtectStrictPolicy,
    CheckHardwareBreakpoints = true,
    LogFile = "nebula_protect.log",
});
if (result.HasThreat) { /* 处理 */ }
```

#### 代码补丁自检（`GuardCode`）

```csharp
// 程序启动时登记关键函数地址
RuntimeProtection.GuardCode(
    Marshal.GetFunctionPointerForDelegate(myVerifier), 256);

// 之后 Scan() 会自动比对哈希，检测是否被 patch
bool ok = RuntimeProtection.VerifyGuardedCode();
```

#### Report 字段

| 字段 | 说明 |
|------|------|
| `Clean` | 是否一切正常 |
| `Debugged` / `Virtualized` / `Sandboxed` / `Hooked` | 四个分类结论 |
| `Score` | 累计风险分 |
| `StrongHits` / `WeakHits` / `VmScore` | 各类线索计分 |
| `Flags` | 命中位掩码（`Flag` 枚举） |
| `Reasons` | 命中的具体项（中文，可直接打日志） |
| `Summary()` / `Detail()` | 一行摘要 / 明细字符串 |

### 5.2 代码混淆 (Obfuscate)

`NebulaObfuscate.cs` 提供多维度代码混淆能力。

#### 字符串加密

```csharp
// 编译期加密（配合代码生成器使用）
byte[] enc = Obfuscate.EncodeForCompile("敏感字符串", 42);
var msg = Obfuscate.Encoded(enc, 42).Decrypt();

// 运行期加密
byte key;
string encrypted = Obfuscate.EncryptRuntime("动态数据", out key);
string decrypted = Obfuscate.DecryptRuntime(encrypted, key);
```

#### 间接调用（打散调用图）

```csharp
// 通过 volatile 委托间接调用，阻止编译器内联与静态分析
Obfuscate.Vcall(MyFunction, arg1, arg2);
var result = Obfuscate.VcallR(MyFunction, arg1, arg2);
```

#### 不透明谓词 / 虚假分支

```csharp
// 恒为 true/false，形态随启动变化（RuntimeDiverse=true 时）
if (!Obfuscate.OpaqueTrue() && Obfuscate.OpaqueTrue())
{
    Obfuscate.DeadBranch();  // 永不到达的干扰代码
}
```

### 5.3 擦除型敏感字符串 (SecureString)

`NebulaSecureString.cs` 提供运行时随机源和擦除型敏感字符串。

```csharp
// 配置区密钥用 SecureString 包裹
private static readonly SecureString s_appKey = new("SWBFE6879E94DD");
string key = s_appKey.Str();  // 用时才解码

// RuntimeDiverse=true 时每次启动密钥随机
```

### 5.4 TLS 证书指纹锁定

```csharp
// SdkConfig.cs
public const string TlsCertSha256 = "f31dc7cd4dbed7b9b6034bae7577452a6e50102ff3121775e64698b76f08b80d";
```

- 仅对 HTTPS 生效
- 通过 `SslStream` 自定义校验回调比对服务器证书 SHA256
- 服务器更换证书后需更新此值
- 自动更新包下载：同 host 复用指纹锁定，跨 host 由哈希/大小校验兜底

### 5.5 通信协议（Nebula 3.1）

**① 会话握手（明文请求，仅此一步不走加密）**：

```
请求： { app_key, eph_pub, nc, ts, mhash }
响应： { code:0, proto:31, sid, eph_pub, ns, ts_s, sign }
```

- 客户端生成临时 ECDH P-256 密钥对，`eph_pub` 为公钥（base64），`nc` 为 16 字节客户端随机数
- 服务端返回其临时公钥 `eph_pub`、随机数 `ns`、会话 ID `sid`
- `sign` = 服务端对 `sid|eph_pub_S|ns|nc_b64|ts_s` 的 ES256 签名（用内置公钥先验签，防伪造服务器）

**② 密钥派生**：

- 共享密钥 = ECDH(本端私钥, 服务端公钥) 的 X 坐标（32 字节大端）
- `sk_enc` = `HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-enc", 32)`
- `sk_mac` = `HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-mac", 32)`
- IV 前缀 = `SHA256(nc‖ns)` 前 4 字节；完整 IV = 前缀 ‖ `seq`（8 字节大端）

**③ 业务请求信封**（会话内全部接口）：

```
{ proto:31, sid, seq, t, data, mac, app_key }
```

- `data` = `base64( IV[12] + AES-256-GCM(业务JSON) + tag[16] )`
- `mac` = `hex( HMAC-SHA256( sid|seq|t|sha256(data), sk_mac ) )`
- `seq` 单调递增，服务端拒绝旧序号 → 防重放
- 会话失效（5002/5004）时 SDK 自动重握手并重试一次

**④ 响应信封**：

```
{ data, sig, code }
```

- `sig` = 服务端对 `data|sid` 的 ES256 签名（先验签后解密）
- `data` 与请求同格式（AES-256-GCM），解密用会话密钥

> 3.0 的静态 AES_KEY/SIGN_SALT 信封已彻底移除；客户端 exe 内不存在任何可复用的对称通信密钥。

---

## 6. VMP / 壳保护集成（VMProtect · Themida · 自定义壳）

### 6.0 C# 加固体系总览

Nebula C# SDK 采用三层加固体系，各层独立开关，也可一键全开：

| 加固层 | 配置开关 | 代码接口 | 文件 |
|--------|----------|---------|------|
| ① 壳标记 | `ShellEnable` | `Shell.BeginVM()` 等 | `NebulaShell.cs` |
| ② 代码混淆 | `ObfStrings` | `Obfuscate.*` | `NebulaObfuscate.cs` |
| ③ 运行时防护 | `ProtectLevel` | `RuntimeProtection.Scan()` | `NebulaRuntimeProtection.cs` |
| ④ 运行时多样性 | `RuntimeDiverse` | `SecureString` / `RuntimeRand` | `NebulaSecureString.cs` |
| ★ 一键全开 | `Harden = true` | 自动启用以上全部 | `SdkConfig.cs` |

### 6.1 壳标记（`NebulaShell.cs`）

`Shell` 类提供统一的壳标记接口。开启 `ShellEnable=true` 后：
- 自动探测 `VMProtectSDK32.dll` / `VMProtectSDK64.dll` 是否已加载
- 探测 Themida / WinLicense 的 `SecureEngineSDK` DLL
- 未检测到壳 SDK 时，所有标记退化为空操作（零开销）

#### 标记强度

| 方法 | 强度 | 用在哪 |
|------|------|--------|
| `Shell.BeginUltra()` | 最强最慢 | 全程序只标 1~2 处（如卡密校验总入口） |
| `Shell.BeginVM()` | 强（虚拟化） | 授权判定、密钥派生、关键常量比较 |
| `Shell.BeginMutate()` | 中（只变异指令） | 高频函数（网络收发、状态机） |
| `Shell.BeginScope()` | 仅划范围 | Themida 按区域处理 |

#### 使用示例

```csharp
// 在关键函数中插入壳标记
public bool VerifyLicense(string ticket, string token)
{
    Shell.BeginVM("verify_license");           // 虚拟化保护开始
    var result = client.CheckOffline(ticket, token);
    Shell.End();                                // 结束

    Shell.BeginMutate("update_ui");            // 变异保护（高频）
    UpdateUiState(result.Ok);
    Shell.End();
    return result.Ok;
}

// 最高强度：只标 1~2 处
public LoginResult DoLogin(string account, string secret)
{
    Shell.BeginUltra("login_entry");
    var r = client.Login(account, secret);
    Shell.End();
    return r;
}
```

#### VMProtect 工具函数

```csharp
// 检测当前进程是否已被 VMProtect 加壳
bool protected = Shell.IsProtected();

// VMProtect 内置调试器检测（比 SDK 自己的更难绕过）
bool debugged = Shell.IsDebuggerPresent(checkKernelMode: false);

// VMProtect 内置虚拟机检测
bool vm = Shell.IsVirtualMachinePresent();

// VMProtect 镜像 CRC 校验（检测程序是否被补丁）
bool crcOk = Shell.IsValidImageCRC();
```

#### VMProtect 授权系统（可选）

`Shell` 类还封装了 VMProtect 自带的序列号 / HWID 系统：

```csharp
// 设置序列号
int state = Shell.SetSerialNumber("serial-key-from-vmp");
Console.WriteLine(Shell.SerialStateText(state));

// 获取硬件 ID
string hwid = Shell.GetCurrentHWID();

// 序列号状态判断
bool valid = Shell.IsSerialValid(state);
```

### 6.2 怎么让壳真的生效（VMProtect 为例）

1. 在 `SdkConfig.cs` 中设置 `ShellEnable = true`（或 `Harden = true`）
2. 在代码中插入 `Shell.BeginVM()` / `Shell.End()` 标记
3. 编译出 `MyApp.exe`
4. 打开 VMProtect → 加载该 exe → 左侧 `Functions` 会自动列出所有标记区域
5. 勾选需要的项 → 设置 `Compilation Type`：`Virtualization` / `Mutation` / `Ultra`
6. 需要"防补丁"就开 `Options → Check image CRC`
7. 点 `Compile` 生成加壳后的 exe

> ⚠️ 加壳后的 exe 哈希变了，如果后台「版本管理」登记了 `self_file_hash`，必须重新登记，否则会被完整性自校验拦下。

### 6.3 加壳的坑（务必看）

| 坑 | 说明 |
|----|------|
| **别保护 IAT / 导入表完整性** | SDK 依赖 WinHTTP + bcrypt 系统 DLL。壳开了"导入表保护"且配置不当 → 网络请求失败、加密失败 |
| **别把整个 exe 全虚拟化** | 只虚拟化标记出来的核心函数。全量虚拟化会让启动慢十倍 |
| **杀软误报** | 加壳 + 反调试组合极易被国产杀软报毒。发布前务必过一遍主流杀软 |
| **标记段里尽量别放 return** | 个别壳版本对"标记区内提前返回"支持不好 |
| **保留一个未加壳版本** | 用来排查线上崩溃（加壳后崩溃栈基本没意义） |
| **.NET 特殊性** | C# 编译产物是 IL，VMProtect 对 .NET 的保护能力有限。建议配合 ConfuserEx / .NET Reactor 做 IL 层混淆，再叠 VMProtect 原生壳 |

### 6.4 .NET 专用壳保护推荐

.NET 程序的 VMP 集成推荐组合方案：

| 层 | 工具 | 说明 |
|----|------|------|
| IL 层混淆 | **ConfuserEx** / **Obfuscar** / **.NET Reactor** | 控制流混淆 + 字符串加密 + 防反编译 |
| 原生壳保护 | **VMProtect** / **Themida** | 对编译后的 Native exe 做虚拟化 |
| SDK 层 | `Shell.BeginVM()` 标记 + `RuntimeProtection` | 壳标记让加壳工具知道保护哪里 |

#### ConfuserEx 集成示例（.csproj 后编译）

```xml
<Target Name="Obfuscate" AfterTargets="Build">
  <Exec Command="confuser.exe MyApp.dll --config=confuser.crproj" />
</Target>
```

### 6.5 自定义壳挂载（Enigma / Obsidium 等）

有些壳没有统一的标记头文件。C# 版通过运行时 DLL 探测：
如果壳的 SDK DLL 不在系统路径，`Shell` 类的探测会静默失败，
所有标记退化为空操作。可以通过 P/Invoke 加载自定义壳的 DLL：

```csharp
// 在程序启动时加载自定义壳 DLL
[DllImport("kernel32.dll")] static extern IntPtr LoadLibrary(string name);
// 在 Main() 中：
LoadLibrary("MyCustomShell.dll");
// 之后 Shell.BeginVM() 会探测到你的壳
```

---

## 7. 一键全开安全功能

将以下配置全部开启即为"一键全开"模式：

### SdkConfig.cs 一键全开

```csharp
public static class SdkConfig
{
    // 基础配置（必填；协议 3.1 无静态对称密钥，仅此 2 项）
    public const string ApiUrl = "https://your-domain.com/api/index.php";
    public const string AppKey = "YOUR_APP_KEY";

    // ③ 响应签名公钥（PEM，必填）
    public const string RespSignPubKey =
        "-----BEGIN PUBLIC KEY-----\n" +
        "YOUR_PUBLIC_KEY_HERE\n" +
        "-----END PUBLIC KEY-----\n";

    // ④ TLS 证书指纹锁定（开启 = 防中间人）
    public const string TlsCertSha256 = "your_server_cert_sha256_here";

    // ===== 安全功能一键全开 =====
    // 严格防护模式（VM/沙箱也按威胁处理，会误伤 VM 用户）
    public const bool ProtectStrictPolicy = true;   // ← 全开：true

    // 调试日志（全开时建议临时开启，排查后关闭）
    public const bool DebugLog = true;              // ← 全开：true（稳定后改 false）
}
```

### 程序启动时一键全开

```csharp
// ═══ 运行时防护 ═══
RuntimeProtection.SetCallback(r => {
    // 上报到服务端
    // sendToServer(r.Score, r.Summary());
});
RuntimeProtection.ScanAndEnforce(out bool mayContinue);
if (!mayContinue) Environment.Exit(0);
RuntimeProtection.StartWatchdog(5000);   // 后台巡检

// ═══ 壳标记 ═══
// 在关键函数中插入 Shell.BeginVM() / Shell.End()
// 编译后用 VMProtect / Themida 加壳

// ═══ 代码补丁自检 ═══
RuntimeProtection.GuardCode(
    Marshal.GetFunctionPointerForDelegate(myVerifier), 256);
```

### 一键全开功能清单

| 序号 | 功能 | 配置项/代码 | 效果 |
|------|------|------------|------|
| 1 | **★ 一键全开** | `Harden = true` | 防护等级3 + 混淆 + 壳标记 + 运行时多样性 |
| 2 | TLS 证书指纹锁定 | `TlsCertSha256` 填入 64 位 hex | 防中间人攻击 |
| 3 | 响应签名强制验证 | `RequireResponseSignature = true` | 防伪造服务器响应 |
| 4 | 运行时防护 | `ProtectLevel = 3` | 反调试/反VM/反沙箱全开 |
| 5 | 命中处置 | `ProtectAction = 2` | 命中即降级 |
| 6 | 后台巡检 | `StartWatchdog(5000)` | 每 5 秒检测一次 |
| 7 | 壳标记 | `Shell.BeginVM()` / `Shell.End()` | VMProtect/Themida 保护区域 |
| 8 | 代码混淆 | `Obfuscate.*` | 字符串加密 + 间接调用 + 不透明谓词 |
| 9 | 擦除型敏感字符串 | `SecureString` | 密钥不明文常驻内存 |
| 10 | 代码补丁自检 | `GuardCode()` | 检测函数是否被 patch |
| 11 | 完整性自校验 | `EnforceSelfIntegrity()` | 防程序文件被篡改 |
| 12 | 自动更新 | `AutoUpdateEnable = true` | 自动修复安全漏洞 |
| 13 | 仅 HTTPS 更新 | `AllowInsecureUpdate = false` | 防更新包被劫持 |
| 14 | 不走系统代理 | `UseSystemProxy = false` | 防本地代理调试 |

### 功能开关速查表

| 功能 | 开启方式 | 关闭方式 |
|------|---------|---------|
| **★ 一键全开** | `Harden = true` | `Harden = false` |
| 运行时防护 | `ProtectLevel = 1/2/3` | `ProtectLevel = 0` |
| 命中处置 | `ProtectAction = 2` (降级) | `ProtectAction = 0` (只记录) |
| 严格策略 | `ProtectStrictPolicy = true` | `ProtectStrictPolicy = false` |
| 壳标记 | `ShellEnable = true` | `ShellEnable = false` |
| 代码混淆 | `ObfStrings = true` | `ObfStrings = false` |
| 运行时多样性 | `RuntimeDiverse = true` | `RuntimeDiverse = false` |
| TLS 指纹锁定 | `TlsCertSha256 = "64位hex"` | `TlsCertSha256 = ""` |
| 响应签名验证 | `RequireResponseSignature = true` | `RequireResponseSignature = false` |
| 后台巡检 | `StartWatchdog(5000)` | `StopWatchdog()` |
| 完整性校验 | 调用 `EnforceSelfIntegrity()` | 不调用 |
| 自动更新 | `AutoUpdateEnable = true` | `AutoUpdateEnable = false` |
| HTTPS 强制 | `AllowInsecureUpdate = false` | `AllowInsecureUpdate = true` |
| 系统代理 | `UseSystemProxy = false` | `UseSystemProxy = true` |
| 调试日志 | `DebugLog = true` | `DebugLog = false` |

---

## 8. 完整接入示例

以下是从零到完整运行的接入流程（WinForms 登录窗口）：

```csharp
using Nebula.Sdk;

// ════════════════════════════════════════════════════════════════════
// 第 1 步：配置 SdkConfig.cs（唯一需修改的文件）
//   填入 ApiUrl, AppKey, RespSignPubKey, TlsCertSha256（3.1 无静态对称密钥）
// ════════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════════
// 第 2 步：创建客户端（建议持久化 machineId）
// ════════════════════════════════════════════════════════════════════
var client = NebulaFactory.CreateDefaultClient(
    machineId: GetStableMachineId(),  // 注册表 MachineGuid 摘要
    osInfo: "Windows",
    clientVersion: "1.0.3"            // 与服务端版本检查对齐
);

// 设置 UI 提示处理器（可选；不设则用 DefaultAlert）
client.SetUiHandler((kind, msg) =>
{
    // kind: "integrity" | "version" | "maintain" | "kick" | "flash" | "popup"
    MessageBox.Show(msg, kind, MessageBoxButtons.OK, MessageBoxIcon.Information);
});

// ════════════════════════════════════════════════════════════════════
// 第 3 步：运行时防护检查（init 前）
// ════════════════════════════════════════════════════════════════════
var protectResult = RuntimeProtection.Check(new RuntimeProtection.CheckOptions
{
    StrictPolicy = SdkConfig.ProtectStrictPolicy,
    CheckHardwareBreakpoints = true,
    LogFile = "nebula_protect.log",
});
if (protectResult.HasThreat && SdkConfig.ProtectStrictPolicy)
{
    // 严格模式：高严重度威胁直接退出
    Environment.Exit(0);
}

// ════════════════════════════════════════════════════════════════════
// 第 4 步：初始化（建议后台线程）
// ════════════════════════════════════════════════════════════════════
var init = client.Init();
if (!init.Ok) { /* 处理初始化失败 */ return; }

// 完整性自校验
if (!client.EnforceSelfIntegrity()) { /* 程序被篡改 */ return; }

// 维护模式提示
if (init.MaintainMode) client.MaintainAlert();

// 版本检查
if (init.ForceUpdate) client.AutoUpdate();  // 强制更新：自动下载+替换+重启
if (!client.VersionAlert()) { /* 版本过低 */ return; }

// 公告
client.PopupNotices();
client.FlashNotices();

// ════════════════════════════════════════════════════════════════════
// 第 5 步：登录
// ════════════════════════════════════════════════════════════════════
var login = client.Login(account, secret);
if (!login.Ok) { /* 处理登录失败 */ return; }

// ════════════════════════════════════════════════════════════════════
// 第 6 步：启动心跳保活
// ════════════════════════════════════════════════════════════════════
client.StartHeartbeat(login.Token, (code, msg, hb) =>
{
    if (hb.Kick || hb.NeedRelogin || hb.ForceOffline)
    {
        // 被踢下线：回登录界面
    }
});

// ════════════════════════════════════════════════════════════════════
// 第 7 步：使用功能密钥数据包（可选）
// ════════════════════════════════════════════════════════════════════
if (login.FeatureKey.Length > 0)
{
    // 用 feature_key 解密服务端下发的加密数据
    if (Feature.Open(encryptedPack, login.FeatureKey, out var data, out var err))
    {
        // data 为解密后的明文
    }
}

// ════════════════════════════════════════════════════════════════════
// 第 8 步：退出时清理
// ════════════════════════════════════════════════════════════════════
client.StopHeartbeat();
client.Logout(login.Token);
client.Dispose();
```

---

## 9. 错误码参考

### 本地错误码（`Error` 枚举，负值）

| 值 | 枚举 | 说明 |
|----|------|------|
| 0 | `Ok` | 成功 |
| -1 | `Network` | 连接失败/超时/证书指纹不匹配 |
| -2 | `Envelope` | 信封校验失败（HMAC/签名/解密） |
| -3 | `HttpStatus` | HTTP 状态码非 200 |
| -4 | `Config` | 配置缺失 |
| -5 | `Crypto` | 本地密码学操作失败 |

### 离线宽限错误码（`OfflineError` 枚举）

| 值 | 枚举 | 说明 |
|----|------|------|
| 0 | `Ok` | 通过 |
| -1 | `Signature` | 票据验签失败 |
| -2 | `Format` | 票据格式错误 |
| -3 | `Binding` | 票据与机器/会话不匹配 |
| -4 | `Expired` | 离线宽限已到期 |
| -5 | `Disabled` | 服务端未开启离线宽限 |

### 服务端业务码（正值）

| 码 | 说明 |
|----|------|
| 0 | 成功 |
| 1001 | 参数错误 |
| 1002 | 未登录或令牌无效 |
| 1003 | 令牌已过期 |
| 1004 | 权限不足 |
| 2001 | 用户名或密码错误 |
| 2002 | 账号已被封禁 |
| 2003 | 账号被锁定 |
| 2004 | 账号已过期，需激活 |
| 3001 | 卡密不存在 |
| 3002 | 卡密已被使用 |
| 3003 | 卡密已作废 |
| 3004 | 卡密已过期 |
| 3005 | 激活码尚未绑定账号 |
| 3006 | 激活码已绑定其他账号 |
| 3007 | 用户名已被注册 |
| 4001 | 设备数量已达上限 |
| 4002 | 设备未绑定 |
| 4003 | 缺少机器码 |
| 4004 | 设备已被拉黑 |
| 4005 | 设备指纹异常 |
| 4006 | 异地登录已拦截 |
| 5001 | 请求过于频繁 |
| 5002 | 签名校验失败 |
| 5003 | 请求已过期 |
| 5004 | 重复请求 |
| 6001 | 版本过低，需强制更新 |
| 6002 | 服务器维护中 |
| 9999 | 服务器内部错误 |

---

## 10. 文件清单

| 文件 | 职责 |
|------|------|
| `SdkConfig.cs` | 接入方配置区（唯一需修改的文件，含加固开关） |
| `NebulaClient.cs` | 客户端主类（`Client`、`ClientOptions`、`NebulaFactory`） |
| `NebulaTypes.cs` | 公共结果类型与错误码 |
| `NebulaEnvelope.cs` | 通信信封（请求加密 + 响应验签解密） |
| `NebulaCrypto.cs` | 密码学（AES/HMAC/SHA256/MD5/非对称验签） |
| `NebulaHttp.cs` | HTTP 传输（POST + TLS 证书指纹锁定） |
| `NebulaDevice.cs` | 设备身份（机器码 + 指纹采集） |
| `NebulaJson.cs` | 极简 JSON 解析器 |
| `NebulaLog.cs` | 调试日志 |
| `NebulaOffline.cs` | 离线宽限票据（验签 + 绑定校验） |
| `NebulaUpdate.cs` | 自动更新（下载 + 校验 + 自替换重启） |
| `NebulaStoreFeatureIntegrity.cs` | 已读记录 + 完整性自校验 + 功能密钥包 (NF1) |
| `NebulaRuntimeProtection.cs` | 运行时防护（反调试/反VM/反沙箱/代码补丁自检/后台巡检） |
| `NebulaObfuscate.cs` | 代码混淆（字符串加密 + 间接调用 + 不透明谓词） |
| `NebulaShell.cs` | **壳标记集成**（VMProtect / Themida / 自定义壳 P/Invoke） |
| `NebulaSecureString.cs` | **擦除型敏感字符串 + 运行时随机源** |

---

> 📖 文档版本：2026-09-30 | SDK 版本：1.0.3 | 适配 .NET 10 (net10.0-windows)
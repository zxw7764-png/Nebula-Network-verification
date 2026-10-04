# Nebula C++ SDK 接入文档

> 📚 本文属 Nebula 文档中心，主索引见 [../README.md](../README.md)；
> 相关文档：[SDK 加固指南](SDK_PROTECTION.md) · [API 接口](../docs/API.md) · [报文示例](../docs/API_RAW_EXAMPLES.md)

> 适用对象：`sdk/nebula_sdk.hpp`（header-only 伞头，内部按职责拆分为 `sdk/nebula/` 下 19 个子头）  
> 协议版本：**Nebula 3.1（ECDH 会话 + AES-256-GCM，唯一协议）**，与 `docs/API.md` 严格对齐；
> 3.0 静态密钥信封已移除，旧版服务端无法连接（握手阶段即失败提示）。  
> ⚠️ **SDK ≥ 3.1.1** 才能连接服务端 **≥ 2.65.22**（响应方向加密改用独立派生密钥，旧 SDK 解密响应失败）。

---

## 1. 概述

Nebula C++ SDK 是 Nebula 网络验证系统的 Windows 客户端接入库，**header-only、零第三方依赖**（仅 Windows 系统库）。内置：

- **通信协议（3.1，唯一协议）**：进程启动即 ECDH P-256 握手临时协商会话密钥，业务请求走 **AES-256-GCM** 信封 + seq 单调防重放；客户端**零静态对称机密**（无需配置 AES_KEY / SIGN_SALT），堆扫描只能拿到当次会话的临时密钥
- **响应防伪造**：服务端用私钥对每条响应（含握手响应）签名（**ES256**，环境不支持时自动回落 **RS256**），客户端用内置公钥验签——逆向出全部对称密钥也无法伪造响应
- **响应方向独立密钥（≥3.1.1）**：响应体解密密钥 `sk_enc_rsp = HKDF(sk_enc, info="nebula31-enc-rsp")`，与请求方向密钥域分离——即使请求方向密钥意外泄露也无法解密响应
- **完整流程**：init（初始化）→ login（登录，三种方式自动适配）→ heartbeat（心跳保活）→ logout（登出）
- **业务接口**：激活、设备列表、设备解绑、用户信息、公告、版本检查、在线人数
- **内置提示（开箱即用）**：版本过期 / 发现新版本 / 服务器维护 / 被踢下线 / 完整性校验失败，全部由 SDK 弹中文提示窗，接入方**零 UI 代码**
- **可完全自定义**：一行 `setUiHandler` 接管所有提示，用你自己的 UI 呈现
- **完整性自校验（防篡改）**：客户端启动时自动比对自身 exe 的哈希与大小，被修改即拒绝运行
- **设备指纹**：自动采集真实电脑主机名（`GetComputerNameW`）与硬件指纹（主板/CPU/硬盘/BIOS/GPU 序列号 + 主网卡 MAC，WMI 实现），登录时自动上报，支持虚拟机识别与漂移容忍
- **离线宽限**：断网时用服务端签发的票据本地验签，宽限期内可继续使用

## 2. 环境要求

| 项目   | 要求                                                                                                |
| ---- | ------------------------------------------------------------------------------------------------- |
| 操作系统 | Windows 7 及以上（仅 Windows）                                                                          |
| 工具链  | Visual Studio / MSVC（C++17）                                                                       |
| 链接库  | 全部为系统库，头文件内已 `#pragma comment` 自动链接：`winhttp` `bcrypt` `advapi32` `crypt32` `iphlpapi` `wbemuuid` |

> 建议 MSVC 打开 `/utf-8` 编译选项（属性 → C/C++ → 命令行），保证源码中文常量按 UTF-8 处理。

## 3. 五分钟接入

### 方式 A：内置配置区一行接入（推荐）

打开 **`sdk/nebula/client/config.hpp`**（SDK 中★唯一需要修改的文件★）的顶部「接入方配置区」，把以下项改成你自己软件的值（①②在后台「软件管理」行点「复制」获取，③在「系统设置 → 系统 → 响应签名公钥 → 复制 C++ 代码」）：

```cpp
namespace nebula { namespace cfg {
inline const std::string kApiUrl   = NEBULA_STR("http://your-domain.com/api/index.php"); // ① API 入口
inline const SecureString kAppKey  { NEBULA_STR("SWXXXXXXXX") };                          // ② 软件标识
inline const std::string kRespSignPubKey = NEBULA_STR("-----BEGIN PUBLIC KEY-----\n...");  // ③ 响应验签公钥（必填）
inline const std::string kTlsCertSha256  = NEBULA_STR("");                                 // ④ 证书指纹（可选）
} }
```

> **3.1 起不再需要 AES_KEY / SIGN_SALT**：通信密钥由 ECDH 握手临时协商，客户端零静态对称机密。config.hpp 已不再包含任何对称密钥字段。
> **③ 是必填项**：留空则所有请求直接失败（故意设计，不给"不校验"留口子）。
> **④ 只在 `https://` 生效**：填了它 SDK 会同时拒绝 `http://` 地址。
> 所有值一律写在 `NEBULA_STR("...")` 里，开启混淆后编译期即被加密。

之后入口处一行工厂调用即可：

```cpp
#include "nebula_sdk.hpp"   // 只需这一个入口（内部自动包含 nebula/ 下各子头）

auto c = nebula::createDefaultClient(/*machine_id*/ "", /*os_info*/ "Windows", /*client_version*/ "1.0.1");
```

> 子头分布在 `sdk/nebula/` 下，请**连同整个 `nebula/` 目录一起拷进工程**，不要只拷伞头。

### 方式 B：Options 结构体构造

```cpp
#include "nebula_sdk.hpp"

int main()
{
    // 1. 创建客户端
    nebula::Client::Options o;
    o.api_url    = "http://api.example.com/api/index.php";
    o.app_key    = "SWXXXXXXXX";   // 软件的 app_key（必填，标识所属软件）
    o.machine_id = "";             // 留空自动生成稳定机器码
    o.os_info    = "Windows";      // 操作系统标识，随登录上报
    o.client_version = "1.0.1";    // 客户端版本号，随 init/login/heartbeat 上报
    // o.response_sign_public_key 默认取 cfg::kRespSignPubKey
    nebula::Client c(o);

    // 2. 初始化：拿会话密钥、登录方式、公告、版本策略
    auto ir = c.init();
    if (!ir.ok) return 1;                     // ir.msg 有失败原因

    // 3. 内置提示（可选，见第 6 节）：版本过期/维护中/完整性校验失败自动弹窗
    if (!c.enforceSelfIntegrity()) return 1;  // exe 被篡改 → SDK 弹窗，退出
    if (!c.versionAlert())          return 1; // 版本过低（强制更新）→ SDK 弹窗，退出
    c.maintainAlert();                        // 维护中 → SDK 弹窗（仍可尝试登录，服务端兜底 6002）

    // 4. 登录（按服务端下发的登录方式自动组装：password / username_code / code 卡密直登）
    auto lr = c.login("账号或卡密", "密码或激活码");
    if (!lr.ok) return 1;                     // lr.code / lr.msg 有错误码与原因

    // 5. 心跳保活（默认间隔用 init 下发的 heartbeat_interval）
    c.startHeartbeat(lr.token, [&c](int code, const std::string& msg,
                                     const nebula::HeartbeatInfo& hb) {
        if (hb.kick || hb.need_relogin || hb.force_offline)
            c.kickAlert(msg);                 // 被踢 / 顶号 → SDK 弹窗，随后应回登录界面
    });

    // ... 你的主程序业务 ...

    // 6. 退出前停心跳并登出
    c.stopHeartbeat();
    c.logout(lr.token);
    return 0;
}
```

## 4. Client 构造参数（Options）

```cpp
nebula::Client::Options o;
o.api_url = "..."; o.app_key = "...";
nebula::Client c(o);
```

| 字段                       | 必填 | 说明                                                               |
| ------------------------ | -- | ---------------------------------------------------------------- |
| `api_url`                | ✔  | API 入口，如 `http://api.example.com/api/index.php`（`?action=xx` 形式） |
| `app_key`                | ✔  | 软件标识（如 `SWDEFAULT`）。**必填**：没有默认通用软件，账号/卡密/设备均按软件隔离               |
| `response_sign_public_key` | ✔* | 响应验签公钥，默认取 `cfg::kRespSignPubKey`；强制验签开启时必填                   |
| `machine_id`             |    | 稳定机器码                                                            |
| `os_info`                |    | 操作系统标识，随登录上报                                                     |
| `client_version`         |    | 客户端版本号。**每次发版必须更新**，服务端据此做版本拦截、更新提示与完整性自校验                       |
| `tls_cert_sha256`        |    | 服务端证书 SHA256 指纹（https 防中间人）                                      |
| `aes_key` / `sign_salt`  |    | 【已废弃】3.0 遗留字段，3.1 协议不使用                                          |

> 通信密钥由 ECDH 握手临时协商（3.1），客户端不再持有任何跨会话对称机密。

## 5. 流程 API

### 5.1 init · 初始化

```cpp
nebula::Client::InitResult ir = c.init();
```

请求前 SDK 自动完成 ECDH 握手建立 3.1 会话（首个业务请求触发，全程自动）。成功后 `ir.ok = true`，并返回：

| 字段                                                                                   | 说明                                                             |
| ------------------------------------------------------------------------------------ | -------------------------------------------------------------- |
| `site_name`                                                                          | 站点名称                                                           |
| `heartbeat_interval`                                                                 | 建议心跳间隔（秒）                                                      |
| `register_enable` / `maintain_mode`                                                  | 是否开放注册 / 维护模式                                                  |
| `login_method`                                                                       | 登录方式：`password` / `username_code` / `code`（卡密直登），login 会自动适配   |
| `need_update` / `force_update` / `latest` / `min_ver` / `update_url` / `update_note` | 版本策略                                                           |
| `file_hash` / `file_size`                                                            | 最新版安装包的哈希与字节数（下载更新包后比对用）                                       |
| `self_file_hash` / `self_file_size`                                                  | **客户端自身版本**登记的哈希与字节数（完整性自校验用，仅客户端版本 == 最新版时下发；未登记为 `""` / `0`） |
| `notices`                                                                            | 公告列表（`id` / `title` / `content` / `type` / `type_text`）        |
| `grace_enable` / `grace_seconds` / `grace_public_key`                                | 离线宽限配置                                                         |

### 5.2 login · 登录

```cpp
nebula::Client::LoginResult lr = c.login(account, secret);
```

按 init 下发的 `login_method` 自动组装：

| 方式              | account | secret   |
| --------------- | ------- | -------- |
| `password`      | 用户名     | 密码       |
| `username_code` | 用户名     | 激活码      |
| `code`（卡密直登）    | 卡密      | 任意（自动置空） |

成功时 `lr.ok = true`：`lr.token`（后续所有接口的凭证）、`lr.user`（`user_id` / `username` / `vip_expire` / `vip_text` / `points` / `max_devices` / `group_id`…）、`lr.account_created`（卡密直登自动建号时为 true）、`lr.feature_key`（功能密钥，见 13.2；后台未启用时为空串）。失败时 `lr.code` / `lr.msg`，错误码见 `docs/API.md` 1.4（本地码：`-1` 网络 / `-2` 验签解密 / `-3` HTTP）。

### 5.3 heartbeat · 心跳保活

```cpp
c.startHeartbeat(token, callback, interval_ms = 0);   // 0 = 用 init 下发的间隔
c.stopHeartbeat();
```

回调在 **SDK 内部心跳线程**执行（不要在其中操作 UI 句柄），签名 `(int code, std::string msg, HeartbeatInfo hb)`。`HeartbeatInfo`：

| 字段              | 说明                   |
| --------------- | -------------------- |
| `remain`        | 会员剩余秒数               |
| `online`        | 是否在线                 |
| `kick`          | 被强制下线（后台踢出，status=3） |
| `force_offline` | 顶号下线（他处登录，status=2）  |
| `need_relogin`  | 会话失效，需重新登录           |
| `need_activate` | 账号过期待激活              |
| `grace_ticket`  | 最新离线宽限票据（SDK 已自动缓存）  |

收到 `kick / force_offline / need_relogin` 时应停止业务并回登录界面；提示可用 `c.kickAlert(msg)`（第 6 节）。

### 5.4 logout · 登出

```cpp
c.logout(token);   // 服务端销毁会话；成功后 Client 状态回到 READY
```

## 6. 内置提示与自定义 UI

SDK 把所有用户可见提示内置为四个方法，**默认直接弹出中文提示窗**（`MessageBoxW` + UTF-16，中文不乱码），接入方零 UI 代码：

| 方法                            | 时机        | 默认行为                                                   | 返回值                  |
| ----------------------------- | --------- | ------------------------------------------------------ | -------------------- |
| `bool enforceSelfIntegrity()` | init 后    | exe 被篡改时弹「程序文件已被修改，请从官方渠道重新下载」                         | `false` = 校验失败，应退出程序 |
| `bool versionAlert()`         | init 后    | 强制更新：弹「当前版本过低（当前版本），请升级到 X 后使用」；可选更新：弹「发现新版本 X，建议尽快升级」 | 强制更新时 `false`，应中止登录  |
| `void maintainAlert()`        | init 后    | 弹「服务器维护中，请稍后再试」                                        | —（登录仍由服务端 6002 兜底）   |
| `void kickAlert(msg)`         | 心跳被踢 / 顶号 | 弹服务端下线原因（`msg` 为空时弹默认文案）                               | —                    |

### 自定义提示 UI

不想用默认弹窗时，init 之前设置处理器，SDK 全部提示改为回调（不再弹默认窗）：

```cpp
c.setUiHandler([](const char* kind, const std::string& msg) {
    // kind: "integrity" 完整性校验失败 / "version" 版本更新
    //       "maintain" 维护中   / "kick" 被踢下线
    // msg:  UTF-8 中文文案，可用返回值自行渲染任意 UI
});
```

回调在调用线程执行；不设置则始终走默认弹窗。

### 弹窗公告（每次登录提示）

后台类型选 **弹窗公告** 发布（type=2）。登录后一行调用，SDK 拉取并逐条弹窗展示（无已读机制，每次登录都会提示）；设置了 `setUiHandler` 时回调 `kind="popup"`：

```cpp
c.popupNotices();
```

> 公告栏（登录窗口滚动公告）只显示 **列表公告**（type=4，init 直接下发）；弹窗/立即公告走 notice 接口由上述方法处理。

### 立即下发公告（看过即不再显示）

后台「软件管理 → 客户端公告」选择类型 **立即公告** 发布（type=3）。SDK 按公告 ID 在本地（`%APPDATA%\NebulaSDK\notices_<app_key>.txt`，按软件区分）记录已读，用户确认过后不再显示。

**方式 A：一行内置（推荐）**

```cpp
// 登录成功后调用：拉取未读立即公告 → 逐条弹窗（标题+内容）→ 确认后自动标记已读
// 设置了 setUiHandler 时改为回调 kind="flash"（每条一次，msg = 标题\n\n内容），回调返回即视为已读
c.flashNotices();
```

**心跳自动下发（默认开启）**

程序运行期间新发布的立即公告，会随下一次心跳自动下发并由 SDK 弹出（同样看过即不再显示），无需接入方写任何代码。不想要自动弹出时：

```cpp
c.setAutoFlash(false);   // 关闭后 hb.flash_notices 原样交给心跳回调，自行展示与 markNoticeRead
```

心跳回调里可用 `hb.flash_notices`（本轮服务端下发的立即公告）与 `c.isNoticeRead(id)` 自行过滤。

> 调试 / 强制重发：`c.clearNoticeReads()` 清空本地已读记录，全部立即公告会重新弹出。

**方式 B：完全自定义 UI**

```cpp
auto list = c.fetchFlashNotices();     // 只拉未读的立即公告，不弹窗不标记
for (auto& n : list) {
    // ……用你自己的 UI 展示 n.title / n.content……
    c.markNoticeRead(n.id);            // 用户确认一条后标记，之后不再下发显示
}
```

> 已读记录按软件（app_key）隔离，30 天前的旧记录自动清理。

### 纯校验（不要弹窗）

需要完全自己处理提示时，可用 namespace 级函数拿到原因字符串：

```cpp
std::string err = nebula::verifySelfIntegrity(ir.self_file_hash, ir.self_file_size);
// err 为空 = 通过；非空 = 失败原因（自己决定怎么提示）
```

## 7. 完整性自校验（防篡改）

**原理**：客户端启动时计算**自身 exe** 的 MD5/SHA256 与字节数，与服务端「版本管理」里登记的值比对，不一致即判定被篡改。

**启用方式（后台操作，无需改代码）**：

1. 编译发布客户端后，计算 exe 的哈希与大小：
   - 哈希：`certutil -hashfile 你的程序.exe SHA256`（64 位 hex；填 32 位 MD5 也可，SDK 自动按长度识别算法）
   - 大小：文件属性里的字节数
2. 后台「软件管理 → 版本管理」发布**该版本号**的记录，填入文件哈希与文件大小并发布
3. 客户端启动调用 `c.enforceSelfIntegrity()` 即自动比对

**规则**：

- 仅当客户端版本号 == 最新已发布版本时才下发校验数据（旧版本客户端跳过校验，由强制更新机制引导升级）
- 该版本未登记哈希/大小 → 服务端下发空值，客户端跳过校验，不会误拦
- ⚠️ 哈希/大小必须是**该版本 exe 的真实值**：每次重新编译 exe 都会变化，发版前必须重新登记，否则新包会被自己的校验拦下

## 8. 设备与指纹（自动上报，无需接入方处理）

- **设备名**：构造 Client 时自动取真实电脑主机名（`GetComputerNameW` → UTF-8）
- **硬件指纹 device_fp**：首次登录时自动懒采集——主板 / CPU / 硬盘 / BIOS 序列号与 GPU 名称（WMI，MD5 前 16 位 hex）+ 主网卡裸 MAC（供虚拟机 OUI 识别），已过滤 OEM 占位值
- 后台「设备管理」可查看指纹完整度、识别虚拟机、按指纹做漂移容忍绑定

## 9. 业务 API（登录后）

| 方法                                                             | 对应接口     | 说明                                               |
| -------------------------------------------------------------- | -------- | ------------------------------------------------ |
| `c.activate(token, code)`                                      | activate | 激活卡密 / 续费（`lr` 后调用）                              |
| `c.devices(token)`                                             | devices  | 当前账号的设备列表                                        |
| `c.unbindDevice(token, machine_id="", password="", all=false)` | unbind   | 解绑设备：缺省解当前设备；`all=true` 解绑全部；需要密码校验时传 `password` |
| `c.userinfo(token)`                                            | userinfo | 刷新用户信息（点数 / 到期 / 设备上限等）                          |
| `c.getNotices(id=0)`                                           | notice   | 拉取公告（白名单接口）                                      |
| `c.checkVersion(version, channel="stable")`                    | version  | 手动版本检查（白名单接口）                                    |
| `c.online()`                                                   | online   | 当前在线人数（白名单接口）                                    |

返回值统一为 `nebula::Response`：`code`（0 成功）、`msg`、`raw`（解密后的业务 JSON 原文，自行取字段）。

## 10. 离线宽限（可选）

服务端开启离线宽限后，登录 / 心跳响应会持续下发签名票据（SDK 自动缓存）。客户端断网时用缓存票据本地 ES256 验签：

```cpp
auto gr = c.checkOffline(c.graceTicket(), lr.token);
if (gr.ok) {
    // gr.remain_sec = 宽限剩余秒数，允许继续使用
} else {
    // gr.code: -1 验签失败 / -2 格式错误 / -3 机器码或会话不匹配
    //          -4 已到期   / -5 未启用
}
```

## 11. 自动更新（可选 · 全自动下载 + 替换 + 重启）

服务端「版本管理」发布新版本后，SDK 可**全自动**完成升级：检测 → 下载 → 校验 → 覆盖自身 exe → 重启。接入方只需在启动时调一行。

```cpp
auto ir = c.init();                       // 必须先 init（版本信息随 init 下发）
if (!ir.ok) return 1;

auto up = c.autoUpdate();                 // 检测 → 下载 → 校验 → 替换 → 本进程退出重启
if (up.state == nebula::UpdateState::Applied) return 0;   // 即将重启，别再往下走
if (up.state == nebula::UpdateState::Failed) { /* up.msg 是原因，可提示手动下载 */ }
// 其余情况（NoUpdate / NeedConfirm）继续正常启动流程
```

### 更新策略

| 服务端「版本管理」设置 | SDK 行为 |
| --- | --- |
| 强制更新（`force_update`） | 自动下载、替换、**免确认重启**（服务端本就不让旧版继续用） |
| 可选更新 | 默认只弹提示（`NeedConfirm`）；想自动处理则设 `Options::auto_update_optional = true` |
| 无新版本 | 直接返回 `NoUpdate`，零网络开销 |

### 三步式 API（想自己控制时机时用）

```cpp
auto r = c.downloadUpdate();              // 只下载+校验，拿到本地路径
if (r.state == nebula::UpdateState::Downloaded) {
    // ...你的业务：等用户保存数据 / 等空闲时机...
    std::string err = c.applyDownloadedUpdate(r);   // 替换并重启
    if (!err.empty()) { /* 失败原因 */ }
}
```

### 安全设计（重要）

| 机制 | 说明 |
| --- | --- |
| **强制哈希校验** | 服务端未登记 `file_hash` 时**直接拒绝更新**——否则等于给中间人留了投毒通道 |
| **替换前二次校验** | 下载到替换之间再验一次 hash，防 TOCTOU 掉包 |
| **默认只接受 https** | 更新地址为 `http://` 时拒绝；本地/内网测试可开 `Options::allow_insecure_update` |
| **同目录落盘** | 更新包与脚本都放在 exe 同目录，保证 `move` 不跨卷 |
| **可选 TLS 指纹锁定** | 复用 `kTlsCertSha256`，与业务请求同一套校验 |

> 校验不通过**绝不替换**，会删除临时文件并返回 `Failed`。

### 为什么必须"退出后才替换"

Windows 不允许覆盖正在运行的 exe（映像被占用，无法解除）。SDK 的做法是：
生成一个 `nebula_upd_<随机>.bat` → 由它轮询等待本进程退出 → `move /Y` 覆盖 → `start` 启动新版 → 自删。
脚本用 `SW_HIDE` 隐藏窗口启动，用户无感知。

### 接入建议

- **放在启动流程最前面**（`init` 之后、登录之前），避免用户登录完再被打断
- 强制更新时 `autoUpdate()` 会直接结束进程，**返回值应视为"不会返回"的正常路径**
- 编译期可整体关闭：工程预处理器加 `NEBULA_AUTO_UPDATE=0`（连代码都不编译进去）

## 12. 编译与常见问题

- **拷贝方式**：把 `nebula_sdk.hpp` + `nebula_protect.hpp` + `nebula/` 目录一起放进工程，`#include "nebula_sdk.hpp"` 即可，系统库自动链接（`winhttp` `bcrypt` `advapi32` `crypt32` `iphlpapi` `wbemuuid` `user32` `oleaut32`）
- **中文乱码**：提示窗全部走宽字符 API；建议 MSVC 开 `/utf-8`
- **URL 形式**：支持 `<base>?action=xx` 与 `<base>/index.php?action=xx` 两种入口写法
- **超时设置**：`c.setTimeouts(连接毫秒, 接收毫秒)`，默认 8000 / 15000
- **心跳线程**：回调在 SDK 线程执行，不要在其中直接操作 SFML / 窗口句柄，先设标志位
- **错误码**：业务码完整表见 `docs/API.md` 1.4（1001 参数 / 1004 软件无效 / 2001 密码 / 2002 封禁 / 3001-3008 卡密 / 4001-4005 设备 / 5001-5004 频率与签名 / 6001 版本过低 / 6002 维护中）

## 13. 客户端加固（可选 · 默认全部关闭）

加固实现位于 **`sdk/nebula/protect/`**（`shell.hpp` / `obfuscate.hpp` / `runtime.hpp`），由伞头自动包含，提供三类加固能力：

| 能力 | 开关宏（默认关闭） | 说明 |
| --- | --- | --- |
| 壳标记 | `NEBULA_SHELL_ENABLE 1` | 在 `post()` 的加密签名段、`checkOffline()` 的验签段插入 VMProtect / Themida 标记，加壳时直接虚化这些函数 |
| 核心代码混淆 | `NEBULA_OBF_STRINGS 1` | `NEBULA_STR("...")` 编译期字符串加密、间接调用、不透明谓词 |
| 运行时防护 | `NEBULA_PROTECT_LEVEL 1|2|3` | 反调试（API/PEB/NT/硬件断点/窗口/时序）+ 反虚拟机沙箱 + API 劫持与代码补丁自检 |

**不定义任何宏 → 完全等价于不加固版本（零开销、零风险）**；一键全开：

```
项目属性 → C/C++ → 预处理器 → NEBULA_HARDEN=1
```

```cpp
auto c = nebula::createDefaultClient();
c->setProtectAction(2);                        // 命中即降级（0只记录/1只回调/2降级/3弹窗退出）
c->setProtectCallback([](const nebula::protect::Report& r){
    OutputDebugStringA((r.summary() + " | " + r.detail()).c_str());   // 建议上报到自己的服务端
});
c->enableProtection(0, 5000);                  // 启动自检 + 每 5 秒后台巡检
```

开启 `NEBULA_PROTECT_LEVEL>=1` 后，`Client::init()` 会自动先自检再连服务端；
默认策略是**只回调上报，不打断正常用户**。

> 完整操作手册（各等级查什么、权重与误报风险、加壳步骤与坑、误报收场办法）
> 见 **[SDK_PROTECTION.md](SDK_PROTECTION.md)**。

### 13.1 授权门卫（可选）：内置登录判定保护

SDK 提供可选的**内置登录判定** `Client::loginAndGuard()`：把「发起登录 → 判定成功/失败」整段收进 SDK，并在壳虚拟化区路由回调，接入层不再暴露一眼可 patch 的裸 `if(jz/jnz)` 分支。**判定代码（`lr.ok`）真正实现在 SDK 内部。可用可不用，不改变任何协议与业务逻辑。**

```cpp
c.loginAndGuard(account, secret,
    [&](nebula::Client::LoginResult& lr){ StartMain(std::move(c)); },  // 成功 → 进主界面
    [&](nebula::Client::LoginResult& lr){ ShowLoginFailed(lr.msg); }); // 失败 → 提示
```

- **想用**：判定跳转被壳虚拟化（需 `NEBULA_SHELL_ENABLE=1` + VMP 加壳），patch 难度明显提高。
- **不用**：完全可跳过，保留原有的 `LoginResult lr = c.login(...); if(lr.ok)` 即可——未定义 `NEBULA_SHELL_ENABLE` 时 `NEBULA_MARK_*` 是空宏，此调用零开销、等价于直接把 `lr.ok` 走分支。

### 13.2 功能密钥（可选）：数据防破解

> 原理：「下发解密密钥，不下发验证结果」。传统验证的信任边界是客户端里的一个
> `if(lr.ok)` 分支，patch 掉即绕过；功能密钥把边界移到**数据**上——核心数据加密后
> 随程序分发，解密密钥只在 login 成功响应中由服务端下发（登录失败 / 被踢 / 过期后
> 密钥不出现）。patch 掉登录判定也拿不到密钥，**密文数据永远解不开**。

**四步用法**（头文件 `nebula/client/feature.hpp`，伞头已自动包含）：

```cpp
// ① 后台「软件管理」为本软件设置功能密钥（可一键随机生成）
// ② 开发期：把核心数据加密成数据包，随程序分发（资源文件 / 内嵌常量）
std::string pack = nebula::feature::seal(coreData, "后台设置的那串密钥");
//    ★ 发布后源码中不再保留密钥明文，只有 pack

// ③ 运行期：登录成功后用服务端下发的密钥打开
nebula::Client::LoginResult lr = c.login(account, secret);
if (lr.ok && !lr.feature_key.empty()) {
    std::string data, err;
    if (nebula::feature::open(pack, lr.feature_key, data, err)) {
        StartMain(data);        // data = 核心数据明文
    } else {
        // err：数据被篡改或密钥不对 —— 按破解处理
    }
}

// ④ 服务器 PHP 侧制作数据包（与 SDK 格式互通，已对拍验证）：
//    openssl_encrypt('aes-256-cbc') + hash_hmac('sha256')，格式见 docs/API.md 2.18
```

**数据包格式 `NF1`**：`NF1.<base64(iv[16]+AES-256-CBC)>.<hex(HMAC-SHA256)>`，
encrypt-then-MAC（先恒定时间验签后解密），AES/HMAC 密钥由功能密钥域分离派生。

**安全边界**：密钥经通信信封加密传输（抓包拿不到明文），但最终要进客户端内存参与
解密——它大幅提高破解成本，不是绝对防御；配合 13.1 授权门卫 + VMP 加壳分层使用效果最佳。

---


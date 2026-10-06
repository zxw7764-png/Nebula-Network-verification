# Nebula 网络验证 · 接口文档

所有客户端接口统一入口：

```
POST http://<域名>/api/index.php?action=<接口名>
```

（配置了 `.htaccess` 后也可用 `POST http://<域名>/api/<接口名>`）

> ### 不想手写协议？
>
> **C++ SDK 可在仓库发行处（Releases）下载 `sdk.zip`**（header-only），把 `sdk/nebula/` 目录 + `nebula_sdk.hpp` 一起拖进项目即可，  
> 无需 OpenSSL / libcurl（用系统自带的 `bcrypt.dll` / `winhttp.dll`，仅 Windows + MSVC）。
> 配置只需改一个文件：`sdk/nebula/client/config.hpp`。
>
> ```cpp
> #include "nebula_sdk.hpp"
>
> auto c = nebula::createDefaultClient("machine_id", "Windows", "1.0.1");
> c->init();
> auto lr = c->login("用户名", "密码");
> c->startHeartbeat(lr.token, /* 心跳回调 */, 60000);
> ```
>
> 本文档描述的是**协议层**细节，适合需要自行实现客户端（其他语言 / 特殊需求）的场景。  



---

## 一、通信协议（Nebula 3.1 —— ECDH 会话 + AES-256-GCM）

> **3.0 静态密钥信封（AES-256-CBC + HMAC + 会话盐 k）已完全移除。**
> 客户端不再持有任何跨会话对称机密：通信密钥每次进程启动由 ECDH 握手临时协商，
> 会话结束/过期即废。旧版 3.0 信封请求会被服务端拒绝（`1001`）。

### 1.0 握手：建立 3.1 会话

`action=handshake`（明文 JSON，公开接口，独立频控 10 次/分钟）。

**请求：**

```json
{
  "app_key": "SWxxxx",
  "eph_pub": "<base64(65字节非压缩P-256点: 0x04||X||Y)>",   // 客户端每次进程启动新生成的临时公钥
  "nc":      "<base64(16字节随机数)>",
  "ts":      1726000000,
  "mhash":   "<sha256(machine_id) hex>"                     // 只传哈希，防撞库
}
```

**响应（明文 JSON，带服务端长期私钥签名）：**

```json
{
  "code": 0,
  "proto": 31,
  "sid":    "<32位hex会话ID>",
  "eph_pub":"<base64(服务端临时公钥点)>",
  "ns":     "<base64(16字节随机数)>",
  "ts_s":   1726000000,
  "sign":     "<base64(ES256签名)>",
  "sig_kid":  "<密钥标识>",
  "sig_algo": "ES256"
}
```

**客户端校验与派生（必须严格按此顺序）：**

1. 验签：`sign` 的签名对象 = `sid|eph_pub|ns|nc(b64)|ts_s`，用内置公钥（`kRespSignPubKey`）校验——防止 MITM 替换服务端临时公钥；
2. ECDH：`shared = ECDH(eph_priv_C, eph_pub_S)` 的 X 坐标（32 字节大端）；
3. HKDF-SHA256 派生（盐 = `nc‖ns`）：
   - `sk_enc = HKDF(shared, info="nebula31-enc", 32)`
   - `sk_mac  = HKDF(shared, info="nebula31-mac", 32)`
4. GCM IV 前缀：`iv_prefix = SHA256(nc‖ns)[0..4)`（两端独立可算）。

会话有效期 6 小时（无请求即作废）；同一 `mhash` 只保留最新会话。会话失效（`5002`）后客户端应清空会话并重新握手。

### 1.1 请求格式（业务接口）

所有业务接口（init/login/heartbeat/…）请求体为 JSON，统一 3.1 信封：

```json
{
  "proto": 31,
  "sid":   "<握手返回的会话ID>",
  "seq":   1,
  "t":     1726000000,
  "data":  "<base64(iv[12] + AES-256-GCM密文 + tag[16])>",
  "mac":   "<HMAC-SHA256 hex>",
  "app_key": "SWxxxx"
}
```

| 字段      | 说明                                                                 |
| ------ | ------------------------------------------------------------------ |
| `seq`  | **严格单调递增**的会话内序号（从 1 开始）；服务端原子 UPDATE 校验，重复/乱序 → `5004` 防重放        |
| `iv`   | 12 字节 = `iv_prefix(4) ‖ seq 大端(8)` —— seq 单调保证 IV 永不重复（GCM 硬性要求）    |
| `data` | 业务参数 JSON 的 AES-256-GCM 密文：`base64(iv[12] + 密文 + tag[16])`          |
| `mac`  | `HMAC-SHA256(sk_mac, sid + "\|" + seq + "\|" + t + "\|" + sha256(data))` |
| `t`    | 秒级时间戳（用握手返回的 `ts_s` 校准），与服务端时差 ≤ 60 秒                              |
| `app_key` | **软件标识（必填）**，服务端用它选定软件并隔离数据。无效 → `1004`                        |

### 1.2 加密参数

| 项目   | 值                                        |
| ---- | ---------------------------------------- |
| 算法   | **AES-256-GCM**（AEAD 认证加密，无填充预言机问题）      |
| 密钥   | `sk_enc`（ECDH + HKDF 派生，会话级临时密钥）         |
| IV   | `iv_prefix(4) ‖ seq 大端(8)`，共 12 字节       |
| 完整性  | GCM tag + 外层 `mac`（绑定 sid/seq/t，防字段换序重放） |

### 1.3 响应格式

会话活跃时（业务请求的响应）返回 3.1 GCM 信封：

```json
{
  "proto": 31,
  "sid":   "<会话ID>",
  "data":  "<base64(iv[12] + AES-256-GCM密文 + tag[16])>",
  "code":  0,
  "sig":      "<base64(ES256签名)>",
  "sig_kid":  "<密钥标识>",
  "sig_algo": "ES256"
}
```

- 响应 IV 与请求对称（`iv_prefix ‖ 请求seq`）；响应方向使用**独立会话密钥** `sk_enc_rsp = HKDF(sk_enc, "", "nebula31-enc-rsp", 32)`（SDK ≥ 3.1.1 / 服务端 ≥ 2.65.22 起请求、响应密钥方向隔离），客户端用 `sk_enc_rsp` 解密；
- `sig` 为**响应防伪签名**：签名对象 = `data|sid`，服务端长期私钥签名（与握手响应同一对密钥），客户端内置公钥验签。私钥仅存服务端，逆向出客户端全部数据也无法伪造响应；
- `sig_algo` 为 `ES256`（ECDSA P-256，默认）或 `RS256`（运行环境不支持 EC 时自动回落）。

**无会话时的错误响应**（握手前出错，如频控 `5001`、app_key 无效 `1004`）为明文 JSON `{code, msg, time}`，客户端据此提示即可。

解密 `data` 后得到业务响应：

```json
{
  "code": 0,
  "msg":  "登录成功",
  "time": 1726000000,
  "data": { }
}
```

### 1.3.1 明文白名单接口

以下接口**无需登录、无敏感数据**，支持明文直接调用（不带 3.1 信封）：

| 接口        | 说明                 |
| --------- | ------------------ |
| `handshake` | 3.1 会话握手（见 1.0 节） |
| `notice`  | 获取公告列表             |
| `version` | 版本校验               |
| `online`  | 在线人数               |

> **`init` 自 3.1 起不再明文放行**——必须先握手、走 3.1 信封调用。

这一组接口同时还满足：允许 GET 请求、不受最低版本强制更新拦截、不计入每日调用配额。

> 白名单在 `config/config.php` 的 `security.plain_whitelist` 中配置。
> 其余接口**必须**携带完整 3.1 信封（`sid/seq/t/data/mac`），否则返回 `1001 不支持的信封格式`。

### 1.4 业务状态码

| code | 含义                                |
| ---- | --------------------------------- |
| 0    | 成功                                |
| 1001 | 参数错误                              |
| 1002 | 未登录 / 令牌无效                        |
| 1003 | 令牌过期                              |
| 1004 | app_key 无效或软件已停用（卡密直登模式下调用注册也返回此码） |
| 2001 | 用户名或密码错误                          |
| 2002 | 账号已被封禁                            |
| 2003 | 账号被锁定                             |
| 2004 | 账号已过期，需激活                         |
| 3001 | 卡密不存在                             |
| 3002 | 卡密已被使用                            |
| 3003 | 卡密已作废                             |
| 3004 | 卡密已过期                             |
| 3005 | 激活码尚未绑定账号                         |
| 3006 | 该激活码已绑定其他账号（用户名与绑定账号不一致）          |
| 3007 | 该用户名已被注册，不能再绑定此激活码                |
| 4001 | 设备数量超限                            |
| 4002 | 设备未绑定 / 已被解绑                      |
| 4003 | 缺少机器码                             |
| 4004 | 设备已被拉黑                            |
| 4005 | 设备指纹异常，已拒绝登录（疑似伪造机器码 / 模拟器虚拟机）    |
| 4006 | 异地登录已拦截（已绑定设备换了 IP，需后台开启「异地登录拦截」） |
| 5001 | 请求过于频繁                            |
| 5002 | 签名校验失败                            |
| 5003 | 请求已过期                             |
| 5004 | 重复请求                              |
| 6001 | 版本过低需强制更新                         |
| 6002 | 服务器维护中                            |
| 9999 | 服务器内部错误                           |

---

## 二、客户端接口


### 2.1 init · 初始化

拉取服务器配置、公告、版本信息。**建议客户端启动第一步调用。**

**请求参数**

| 参数         | 类型     | 必填 | 说明                |
| ---------- | ------ | -- | ----------------- |
| client_ver | string | 否  | 客户端当前版本，如 `1.0.0` |
| machine_id | string | 否  | 机器码               |

**响应示例**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "server_time": 1726000000,
    "site_name": "Nebula 网络验证",
    "heartbeat_interval": 60,
    "session_ttl": 3600,
    "register_enable": true,
    "maintain_mode": false,
    "grace": {
      "enable": true,
      "seconds": 3600,
      "max_seconds": 7200,
      "algorithm": "ES256",
      "kid": "3f9a1c2b",
      "public_key": "-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----",
      "ticket_prefix": "G1",
      "usage": "心跳失败时，本地用 public_key 验签后可在 until 前离线运行"
    },
    "crypto": { "proto": 31, "kex": "ECDH-P256", "algo": "AES-256-GCM", "resp_sign": "ES256" },
    "version": {
      "client_ver": "1.0.0",
      "latest": "1.1.0",
      "min": "1.0.0",
      "need_update": true,
      "force_update": false,
      "update_url": "https://example.com/app.exe",
      "update_note": "修复若干问题"
    },
    "notices": [
      { "id": 1, "title": "欢迎使用", "content": "系统已上线", "type": 4 }
    ],
    "runtime_protection": {
      "enabled": true,
      "level": 2,
      "modules": 127,
      "action": 4,
      "medium_action": 1,
      "high_action": 3,
      "critical_action": 4,
      "watchdog_ms": 3000,
      "strict": false,
      "policy_id": 1,
      "policy_version": 3
    }
  }
}
```

> `notices` 仅下发 **列表公告**（type=4，公告栏展示用，按归属软件过滤）；弹窗公告（type=2）与立即公告（type=3）由客户端经 `notice` 接口配合 SDK `popupNotices()` / `flashNotices()` 处理。

**`data.runtime_protection` · 运行时防护策略（后台「防护配置 → 防护策略」配置）**

`heartbeat` 响应同样携带本对象，支持运行中动态调整。SDK ≥ 3.1.1 自动解析并应用，无需接入方代码。

| 字段 | 说明 |
| --- | --- |
| `enabled` / `level` | 总开关与防护等级（0-3）。下发 `enabled:false` 或 `level:0` 时客户端**真关闭**（停止全部检测与看门狗）；仅"从未收到策略"才回落编译期默认 |
| `modules` | 检测模块位掩码，随防护等级整档下发（1反调试 2反VM/沙箱 4API钩子 8代码补丁 16代码完整性 32模块守卫 64内存守卫 128进程守卫 256时序 512环境痕迹；等级 0/1/2/3 = 0 / 19 / 127 / 1023）；SDK 端与编译期能力取交，服务端不能提权 |
| `action` | 全局兜底处置编码（SDK 优先用下面三档） |
| `medium_action` / `high_action` / `critical_action` | 按命中事件严重级别分别执行的处置：`0`记录 `1`回调上报 `2`降级 `3`弹窗退出 `4`吊销会话。后台策略的「中危/高危/严重动作」下拉即对应这三个字段 |
| `watchdog_ms` | 看门狗巡检间隔（毫秒） |
| `strict` | `true` = 严格策略：疑似环境（VM/Hook）也按处置动作拦截 |
| `policy_id` / `policy_version` | 策略 ID 与版本（每次修改 +1），供排障 |

| `data.grace` 字段           | 说明                             |
| ------------------------- | ------------------------------ |
| `enable`                  | 服务端是否开启离线宽限                    |
| `seconds` / `max_seconds` | 单次宽限时长 / 硬上限（秒）                |
| `algorithm`               | 票据签名算法，固定 `ES256`（ECDSA P-256） |
| `kid`                     | 密钥标识，轮换后变化；客户端可据此发现需要重新拉取公钥    |
| `public_key`              | **验签公钥**（PEM），客户端缓存后本地验签，无需联网  |
| `ticket_prefix`           | 票据固定前缀（`G1`），用于快速识别            |
| `usage`                   | 用法提示文案                         |

> 离线宽限票据的完整使用方式见 [2.16 离线宽限协议](#216-离线宽限协议)。

---

### 2.2 register · 注册

> 登录方式为 `code`（卡密直登）时本接口**自动关闭**，返回 `1004 当前仅支持激活码登录，无需注册`。  
> 官网侧（`/web/api.php?action=register`）行为一致。

**请求参数**

| 参数       | 类型     | 必填 | 说明                 |
| -------- | ------ | -- | ------------------ |
| username | string | 是  | 3-32 位字母/数字/下划线/中文 |
| password | string | 是  | 6-64 位             |
| email    | string | 否  | 邮箱                 |

**响应**

```json
{ "code": 0, "msg": "注册成功", "data": { "user_id": 10, "username": "testuser" } }
```

---


### 2.3 login · 登录

> **登录方式由后台「系统设置 → 登录方式」单选决定**，客户端按 `init` 下发的  
> `data.login` 规格（`method` / `fields`）直接提交对应字段。  
> 三种方式**互斥**，不存在「一次请求塞多个字段、服务端猜」的兼容逻辑：  
> 字段与该方式不匹配时直接返回 `1001`。

`init` 下发的登录规格：

```json
"login": {
  "method": "password",
  "label": "用户名 + 密码",
  "need_username": true,
  "need_password": true,
  "need_code": false,
  "fields": ["username", "password"]
}
```

| method          | 含义        | 必填字段                   |
| --------------- | --------- | ---------------------- |
| `password`      | 用户名 + 密码  | `username`, `password` |
| `username_code` | 用户名 + 激活码 | `username`, `code`     |
| `code`          | 激活码（卡密直登） | `code`                 |

**公共请求参数**

| 参数          | 类型     | 必填    | 说明                                               |
| ----------- | ------ | ----- | ------------------------------------------------ |
| machine_id  | string | **是** | 机器码，用于设备绑定                                       |
| device_name | string | 否     | 设备名称                                             |
| os_info     | string | 否     | 系统信息                                             |
| client_ver  | string | 否     | 客户端版本                                            |
| device_fp   | object | 否     | 设备指纹组件（见 [2.14 设备指纹](#214-设备指纹可选)）。不上报时行为与旧版完全一致 |

**各登录方式的字段**

| 参数       | 类型     | password | username_code | code | 说明         |
| -------- | ------ | :------: | :-----------: | :--: | ---------- |
| username | string |     ✅    |       ✅       |   —  | 用户名        |
| password | string |     ✅    |       —       |   —  | 密码         |
| code     | string |     —    |       ✅       |   ✅  | 激活码，不区分大小写 |

**激活码登录的语义（`username_code` / `code` 共用）**

| 卡密状态            | 行为                                                                         |
| --------------- | -------------------------------------------------------------------------- |
| 已绑定账号           | 直接登录该账号。`username_code` 方式下若填写的用户名与绑定账号不一致 → `3006`                        |
| 未绑定             | **建号并激活**后登录：`code` 方式自动生成用户名（`card_` + 卡密派生）；`username_code` 方式使用用户填写的用户名 |
| 未绑定 + 用户名已被注册   | 拒绝 `3007`（防止持卡人借未绑定的卡密登录进他人账号）                                             |
| 已作废 / 已过期 / 不存在 | `3003` / `3004` / `3001`                                                   |

> 注意：`code`（卡密直登）方式下注册功能会**自动关闭**（`register` 返回 `1004`），  
> 因为该方式不依赖账号体系，注册出来的账号没有可用密码。

**成功响应**

```json
{
  "code": 0,
  "msg": "登录成功",
  "data": {
    "token": "a1b2c3...",
    "expire_at": 1726003600,
    "ttl": 3600,
    "login_method": "password",
    "feature_key": "a1b2c3d4e5f60718293a4b5c6d7e8f90",
    "account_created": false,
    "user": {
      "user_id": 10,
      "username": "testuser",
      "nickname": "testuser",
      "vip_expire": 1728592000,
      "vip_text": "2026-10-11 12:00:00",
      "points": 0,
      "max_devices": 2,
      "status": 1,
      "group_id": 1
    },
    "vip": { "valid": true, "code": 0, "msg": "ok", "expire_at": 1728592000, "points": 0 },
    "device": {
      "machine_id": "a1b2c3d4",
      "auto_bound": true,
      "max_devices": 2,
      "bound_count": 1
    },
    "grace": {
      "ticket": "G1.eyJ2IjoxLCJ1IjoxMCwibSI6ImExYjJjM2Q0...<base64url>.<sig-base64url>",
      "until": 1726007200,
      "seconds": 3600,
      "issued_at": 1726003600,
      "server_time": 1726003600,
      "algorithm": "ES256",
      "kid": "3f9a1c2b",
      "machine_bind": "a1b2c3d4e5f6a7b8",
      "public_key": "-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----"
    }
  }
}
```

| 新增字段              | 说明                                                           |
| ----------------- | ------------------------------------------------------------ |
| `login_method`    | 本次登录实际生效的方式                                                  |
| `account_created` | 是否由本次登录自动建号（激活码方式首次登录为 `true`）                               |
| `feature_key`     | 功能密钥（见 [2.18 功能密钥协议](#218-功能密钥协议)）。后台未配置时为空串；**只在 login 成功响应下发，init 不下发** |
| `grace`           | 离线宽限票据（见 [2.16 离线宽限协议](#216-离线宽限协议)）。服务端关闭该能力或账号未激活时为 `null` |

**设备超限响应（code 4001）**

```json
{
  "code": 4001,
  "msg": "设备数量已达上限",
  "data": {
    "max_devices": 1,
    "bound_count": 1,
    "devices": [ { "machine_id": "...", "device_name": "...", "last_seen": "..." } ]
  }
}
```

---


### 2.4 heartbeat · 心跳

客户端需按 `heartbeat_interval`（默认 60 秒）定时上报。

**请求参数**

| 参数         | 类型     | 必填 | 说明   |
| ---------- | ------ | -- | ---- |
| token      | string | 是  | 登录令牌 |
| machine_id | string | 是  | 机器码  |

**正常响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "online": true,
    "server_time": 1726000060,
    "next_interval": 60,
    "remain": 2591960,
    "remain_text": "30天",
    "vip_expire": 1728592000,
    "points": 0,
    "session_ttl": 3600,
    "force_offline": false,
    "has_notice": false,
    "grace": {
      "ticket": "G1.eyJ2IjoxLCJ1IjoxMCwibSI6ImExYjJjM2Q0...<base64url>.<sig-base64url>",
      "until": 1726007260,
      "seconds": 3600,
      "issued_at": 1726003660,
      "server_time": 1726003660,
      "algorithm": "ES256",
      "kid": "3f9a1c2b",
      "machine_bind": "a1b2c3d4e5f6a7b8"
    }
  }
}
```

**离线宽限票据字段**

| 字段                          | 类型     | 说明                                                   |
| --------------------------- | ------ | ---------------------------------------------------- |
| `ticket`                    | string | 完整票据，形如 `G1.<payload-b64url>.<sig-b64url>`；客户端原样缓存   |
| `until`                     | int    | **宽限截止时间**（Unix 秒），到此时间必须重新联网校验                      |
| `seconds`                   | int    | 本次宽限时长（秒），等于 `until - issued_at`                     |
| `issued_at` / `server_time` | int    | 签发时间 / 服务端当前时间（可用于校正本地时钟）                            |
| `algorithm`                 | string | 固定 `ES256`                                           |
| `kid`                       | string | 密钥标识，与 `init` 下发的 `kid` 对比可判断是否需重新拉公钥                |
| `machine_bind`              | string | **机器码摘要**（`sha256(machine_id)` 前 16 字节），客户端须用本地机器码核对 |
| `public_key`                | string | 仅 `login` 附带；`heartbeat` 不重复下发，客户端用缓存的那份即可           |

> `heartbeat` 每次都会**刷新**票据，客户端成功收到即覆盖旧票据；票据本身绑定当前会话令牌，  
> 因此一旦被踢下线，旧票据在最迟 `until` 之后即彻底失效（详见 [2.16](#216-离线宽限协议)）。

**被踢下线（需立即处理）**

```json
{ "code": 1002, "msg": "账号已在其他设备登录", "data": { "need_relogin": true } }
```

```json
{ "code": 1002, "msg": "账号已被强制下线", "data": { "need_relogin": true } }
```

> `1002` 按会话失效原因区分文案：`账号已在其他设备登录`＝同账号在其他设备登录顶号；  
> `账号已被强制下线`＝管理员强制下线 / 封禁 / 设备解绑等系统操作；`会话已退出`＝主动登出。

```json
{ "code": 4002, "msg": "当前设备已被解绑", "data": { "kick": true, "need_relogin": true } }
```

```json
{ "code": 2004, "msg": "账号已过期", "data": { "kick": true, "need_activate": true } }
```

> 客户端收到带 `kick` 或 `need_relogin` 的响应时，应停止业务功能并回到登录界面。

---

### 2.5 activate · 激活卡密

**请求参数**

| 参数         | 类型     | 必填 | 说明   |
| ---------- | ------ | -- | ---- |
| token      | string | 是  | 登录令牌 |
| machine_id | string | 否  | 机器码  |
| code       | string | 是  | 激活码  |

**响应**

```json
{
  "code": 0,
  "msg": "激活成功：增加时长 30天",
  "data": {
    "card_type": 1,
    "detail": "增加时长 30天",
    "user": { "user_id": 10, "vip_expire": 1731196800, "vip_text": "2026-11-10 12:00:00", "points": 0 }
  }
}
```

---

### 2.6 unbind · 解绑设备

**请求参数**

| 参数         | 类型     | 必填 | 说明                |
| ---------- | ------ | -- | ----------------- |
| token      | string | 是  | 登录令牌              |
| machine_id | string | 否  | 目标机器码，不传则解绑当前设备   |
| password   | string | 否  | 账号密码，用于二次验证       |
| all        | bool   | 否  | `true` 则解绑该账号全部设备 |

> 每账号每日最多解绑 3 次，超出需联系管理员。

**响应**

```json
{
  "code": 0,
  "msg": "设备解绑成功",
  "data": { "machine_id": "a1b2c3d4", "bound_count": 0, "devices": [] }
}
```

---

### 2.7 devices · 设备列表

**请求参数**：`token`

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "max_devices": 2,
    "bound_count": 1,
    "current": "a1b2c3d4",
    "devices": [
      {
        "id": 5,
        "machine_id": "a1b2c3d4",
        "device_name": "Windows PC",
        "os_info": "Windows 10 x64",
        "ip": "1.2.3.4",
        "status": 1,
        "status_text": "正常",
        "bind_at": "2026-09-01 10:00:00",
        "last_seen": "2026-09-10 08:30:00",
        "online": true
      }
    ]
  }
}
```

---

### 2.8 userinfo · 用户信息

**请求参数**：`token`

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "user": { },
    "group_name": "默认用户组",
    "vip": { "valid": true },
    "remain": 2591960,
    "remain_text": "30天",
    "register_time": "2026-09-01 10:00:00",
    "last_login": "2026-09-10 08:00:00",
    "device": { "max_devices": 2, "bound_count": 1 }
  }
}
```

---

### 2.9 notice · 公告

无需登录，可用 `GET`。

**请求参数**：`id`（可选，查单条）

> 仅下发「客户端公告」：`type=2` 弹窗公告（客户端弹出窗口展示）、`type=3` 立即公告（弹出展示，客户端确认后本地记为已读、不再显示）、`type=4` 列表公告（客户端公告栏展示）。
> 官网门户公告（type=1）只在官网首页展示，不下发给客户端。归属软件为 0 时全部软件通用，否则仅归属软件的客户端可见。

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      {
        "id": 1,
        "title": "系统公告",
        "content": "内容...",
        "type": 4,
        "type_text": "列表公告",
        "created_at_text": "2026-09-10 08:00:00"
      }
    ],
    "total": 1
  }
}
```

---

### 2.10 version · 版本校验

**请求参数**

| 参数      | 类型     | 必填 | 说明                            |
| ------- | ------ | -- | ----------------------------- |
| version | string | 是  | 客户端当前版本                       |
| channel | string | 否  | `stable` / `beta`，默认 `stable` |

**响应**

```json
{
  "code": 0,
  "msg": "发现新版本",
  "data": {
    "current": "1.0.0",
    "latest": "1.1.0",
    "min": "1.0.0",
    "channel": "stable",
    "need_update": true,
    "force_update": false,
    "download_url": "https://example.com/app.exe",
    "file_hash": "sha256...",
    "file_size": 10485760,
    "changelog": "修复若干问题"
  }
}
```

**完整性比对（客户端必做）**：下载更新包完成后，本地计算文件哈希/大小并与响应比对，不一致即丢弃文件并提示重新下载，不得执行：

- 文件哈希：`file_hash` 为 32 位 hex（MD5）或 64 位 hex（SHA256，按长度识别算法）
- 文件大小：`file_size` 字节（`0` = 后台未填写，跳过该项比对）

C++ SDK 已内置工具：`Bcrypt::fileHashHex(路径, 是否SHA256)` 与 `Bcrypt::fileSizeBytes(路径)`，且 `init()` 的 `version` 对象已带出 `file_hash` / `file_size`（`InitResult` 同名字段）。

**客户端完整性自校验（防篡改）**：响应中的 `self_file_hash` / `self_file_size` 是**客户端上报版本号**在「版本管理」里登记的哈希与字节数（服务端按 `software_id + client_ver + channel` 查已发布记录）。客户端启动时计算**自身 exe** 的哈希/大小与之比对，不一致即判定文件被篡改，应弹窗并拒绝运行。该版本未登记、未发布或未填哈希时，两字段为 `''` / `0`，客户端跳过校验。SDK 已内置 `nebula::verifySelfIntegrity(InitResult&)` 一键校验。

> ⚠️ 发布版本时填写的 `file_hash` / `file_size` 必须是**该版本客户端 exe 的真实值**——一旦登记并发布，所有自报该版本号的客户端都会被强制比对，值填错会把正常客户端一并拦截。

---

### 2.11 online · 在线人数

公开接口，**无需登录**，也无需会话密钥 `k`，可直接 GET 或明文调用。

**请求参数**：无

**响应**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "online": 128,
    "timeout": 180,
    "server_time": 1789024346
  }
}
```

| 字段          | 类型  | 说明                      |
| ----------- | --- | ----------------------- |
| online      | int | 当前在线会话数                 |
| timeout     | int | 在线判定窗口（秒），超过该时长未心跳即视为离线 |
| server_time | int | 服务端时间戳                  |

**统计口径**

统计的是 `nb_sessions` 中 `status = 1` 且 `last_active` 在心跳超时  
（`policy.heartbeat_timeout`，默认 180 秒）内的**会话数**，与后台首页  
「在线会话」完全一致。

> 同一账号多设备登录会分别计数。若要「去重用户数」或「在线设备数」，  
> 属于另一套口径，需要单独加接口。

**轮询建议**：客户端/官网展示用的话 30~60 秒拉一次即可，受 IP 限流保护  
（客户端 120 次/分，官网 240 次/分）。

**官网入口**

官网侧对应 `GET /web/api.php?action=online`，返回**明文 JSON**（结构同上，  
无加密信封），同样无需登录、不校验 CSRF：

```json
{"code":0,"msg":"ok","time":1789024346,"data":{"online":128,"timeout":180,"server_time":1789024346}}
```

---


### 2.12 download · 客户端下载地址（官网）

> 该接口**仅在官网侧提供**（`GET /web/api.php?action=download`）。  
> 客户端不需要单独调用它 —— 客户端从 `init` / `version` 响应的  
> `download_url` / `file_hash` / `file_size` 字段获取同一份信息。

公开接口，**无需登录**、不校验 CSRF、维护模式下照常放行，返回**明文 JSON**。

**请求参数**：无

**响应（已配置下载地址）**

```json
{
  "code": 0,
  "msg": "ok",
  "time": 1789024865,
  "data": {
    "configured": true,
    "version": "1.0.0",
    "download_url": "https://dl.example.com/nebula/NebulaMenu_1.0.0.zip",
    "file_name": "NebulaMenu_1.0.0.zip",
    "file_size": 19000000,
    "file_hash": "68fe07199e140572ed7d7e681109a5edf52d52faa98a9c96c116556aa21d2180",
    "changelog": ""
  }
}
```

| 字段           | 类型     | 说明                     |
| ------------ | ------ | ---------------------- |
| configured   | bool   | 是否已配置**可用**的下载地址       |
| version      | string | 最新发布版本号                |
| download_url | string | 下载地址；未配置（或被安全校验拒绝）时为空串 |
| file_name    | string | 从地址末段解析出的文件名，供前端另存展示   |
| file_size    | int    | 安装包字节数，`0` 表示未填写       |
| file_hash    | string | 安装包 SHA256，供客户端校验完整性   |
| changelog    | string | 更新说明                   |

**数据来源**：`nb_versions` 表 stable 渠道最新已发布（`status = 1`）记录；  
该表无记录时回落 `config.php` 的 `version.update_url`。

**安全约束**：只回传 `http://` / `https://` 开头的地址。若后台误填  
`javascript:` 等伪协议，一律按「未配置」处理（`configured = false`、  
`download_url` 置空），避免前端直接跳转执行。

> 下载地址在后台「版本管理」页填写（字段：下载地址 / 文件哈希 / 文件大小）。

> **与其他入口同源**：官网首页展示的「最新版本」与首页在线人数条上的版本号，  
> 服务端渲染与前端刷新都取自同一份数据（`Version::latest('stable')`）。  
> 因此官网首页、下载按钮文案、客户端 `version` 接口三者不会出现版本号不一致的情况。

---

### 2.13 logout · 退出

**请求参数**：`token`、`machine_id`（必填，2026-10-06 起强制设备绑定校验）

| 参数        | 类型   | 必填 | 说明                     |
| --------- | ---- | -- | ---------------------- |
| token     | string | 是  | 业务会话令牌                 |
| machine_id | string | 是  | 当前设备机器码（与令牌绑定设备一致）    |

> 令牌无效 / 缺少 machine_id / 机器码不匹配时返回真实错误码（`1002` 等）并携带 `need_relogin=true`，
> **不再返回虚假的 `logout=true`** —— 避免客户端误以为会话已注销而服务端 Session 仍在生效（2026-10-06 修复）。

---


### 2.14 设备指纹（可选）

`machine_id` 是客户端自己算的字符串，伪造成本极低：改一个字节就是"新设备"。  
`device_fp` 让客户端再上报**多个硬件组件**的特征串，服务端据此做加权指纹校验，  
用于识别机器码伪造、模拟器/虚拟机和一机多号。

> C++ SDK（`sdk/nebula_sdk.hpp`，配置区在 `sdk/nebula/client/config.hpp`）**已内置自动采集**（WMI 硬件序列号取哈希 + 主网卡裸 MAC，
> `device_name` 自动取真实电脑主机名），使用 SDK 时无需手动构造本字段。

**上报格式**（`login` 请求中的可选字段，对象或 JSON 字符串均可）：

```json
"device_fp": {
  "board": "9f2c…",        // 主板
  "cpu":   "1a7b…",        // CPU
  "disk":  "c3d4…",        // 系统盘序列号
  "bios":  "55ee…",        // BIOS/UEFI
  "mac":   "001122334455", // 主网卡（给裸 MAC 时可用于识别虚拟机网卡）
  "gpu":   "77aa…"         // 显卡
}
```

> 每个组件填**该硬件的哈希或特征串**（服务端只做等值比较与相似度计算，  
> 不关心具体算法）。`mac` 若直接给 12 位裸 MAC，会额外匹配常见虚拟机网卡 OUI。

**组件权重与判定规则**

| 组件         | 权重 | 换掉后是否容忍             |
| ---------- | -: | ------------------- |
| `board` 主板 | 30 | ❌ 核心组件，变化即判定疑似伪造机器码 |
| `cpu` CPU  | 25 | ❌ 核心组件，变化即判定疑似伪造机器码 |
| `disk` 系统盘 | 20 | ✅ 容忍（升级 SSD 属正常）    |
| `bios`     | 15 | ✅ 容忍                |
| `mac` 网卡   | 10 | ✅ 容忍（换网卡/虚拟网卡最常见）   |
| `gpu` 显卡   | 10 | ✅ 容忍                |

- 核心组件（`board` / `cpu`，可通过 `device_fp.core_components` 调整）双方都上报时，  
  只要有一个不一致 → 记 `fp_changed`。
- 核心组件缺失时，回落到**加权相似度**与 `device_fp.drift_threshold` 比较。

**风险标记**

| 标记               | 含义        | 触发条件                                                 |
| ---------------- | --------- | ---------------------------------------------------- |
| `vm`             | 疑似模拟器/虚拟机 | 设备名/系统信息命中虚拟化关键词，或网卡 OUI 命中 VMware/VirtualBox/QEMU 等 |
| `low_entropy`    | 硬件信息过少    | 上报组件数少于 `device_fp.min_components`                   |
| `same_value`     | 组件值雷同     | 多个组件值完全相同（批量伪造特征）                                    |
| `fp_changed`     | 机器码疑似伪造   | 同一 machine_id 下核心组件发生变化                              |
| `multi_fp`       | 同账号多机器指纹  | 同一账号窗口内出现的不同指纹数超过 `max_fp_per_user`                  |
| `shared_machine` | 同一机器多账号   | 同一指纹关联的账号数超过 `max_user_per_fp`                       |

命中标记会随 `login` 响应的 `data.device.risk` 数组返回，并写入 `nb_logs`  
（`action = device_risk`）。**默认仅记录不拦截**；需要硬拦截时在 `config/config.php` 打开：

```php
'device_fp' => [
    'block_on_drift' => true,  // 疑似伪造机器码时拒绝登录（返回 4005）
    'block_vm'       => true,  // 命中模拟器/虚拟机时拒绝登录（返回 4005）
],
```

`init` 响应的 `data.device_fp` 会下发组件清单与权重，客户端可据此决定采集哪些项。

> 未上报 `device_fp` 的客户端**不受任何影响**：服务端跳过全部指纹逻辑，  
> 行为与升级前逐字节一致，因此各对接方可以按自己的节奏升级。

---

### 2.15 限流与枚举防护

除全局「单 IP 每分钟请求数」外，卡密相关接口另有三层防护：

| 维度                         | 作用                     | 配置项                                                       |
| -------------------------- | ---------------------- | --------------------------------------------------------- |
| 单 IP（`activate` / `login`） | 防止单个来源高频请求             | `rate_limit_per_min` / `login_attempt_per_min`            |
| **单卡号**                    | 防止同一张卡被反复试探、多账号轮番尝试同一卡 | `card_try_limit`（默认 8 次）  
`card_try_window`（默认 600 秒）    |
| **来源枚举**                   | 防止「每次换一个卡号」的随机枚举       | `card_miss_limit`（默认 30 次）  
`card_miss_window`（默认 600 秒） |

- 单卡限流键内只存卡密的 **SHA-256 摘要**，不落明文，限流表泄露也不会泄露卡密。
- 枚举计数只累计「卡密不存在」（`3001`）的失败，不影响正常业务。
- 生效范围：`activate`、`login`（`username_code` / `code` 两种激活码方式）、  
  `/agent/` 的激活码注册。
- 超限统一返回 `5001`。

---


### 2.16 离线宽限协议

**目的**：服务器抖动、网络波动或临时宕机时，已登录的客户端不必立刻掉线，  
可凭一张**服务端私钥签名的离线宽限票据**在本地验签后继续运行一段时间，  
避免「服务端一抖，全体用户掉线」引发投诉。

#### 票据结构

```
G1.<payload-b64url>.<signature-b64url>
```

| 段         | 说明                                                                                 |
| --------- | ---------------------------------------------------------------------------------- |
| `G1`      | 固定前缀（`ticket_prefix`），同时是签名数据的开头                                                   |
| payload   | 载荷 JSON 的 **base64url**（无填充）编码                                                     |
| signature | 对字符串 `G1.<payload-b64url>` 做 **ES256（ECDSA P-256 + SHA-256）** 签名，DER 编码后 base64url；服务端运行环境不支持 EC 时自动回落 **RS256（RSA-2048）** |

#### 载荷字段

| 字段  | 说明                                                     |
| --- | ------------------------------------------------------ |
| `v` | 载荷版本，当前为 `1`                                           |
| `u` | 用户 ID                                                  |
| `m` | **机器码摘要** = `sha256(machine_id)` 的十六进制前 16 位；未绑机器码时为空串 |
| `k` | **会话令牌摘要** = `sha256(token)` 的十六进制前 16 位；票据只对本次登录有效    |
| `e` | 会员到期时间（Unix 秒），`-1` 表示永久                               |
| `i` | 签发时间（Unix 秒）                                           |
| `g` | **宽限截止时间**（Unix 秒），到点即失效（可容忍 `clock_skew` 秒偏差）         |
| `d` | 宽限时长（秒）= `g - i`                                       |
| `n` | 随机数，防止票据字节完全可预测                                        |

> 载荷**不含**用户名、密码等任何敏感信息；机器码与令牌只以摘要形式出现，  
> 即使票据泄露也无法反推原始值。

#### 客户端本地验签流程

1. 从 `init`（或 `login`）拿到 `public_key`（PEM）与 `ticket_prefix`，**缓存到本地**。
2. 每次成功调用 `login` / `heartbeat` 后，用响应里的 `grace.ticket` **覆盖**本地票据。
3. 当 `heartbeat` 请求**失败**（超时、连接被拒、返回 5xx 且非业务错误）时：
   1. 校验 `grace` 处于开启状态，且本地存在票据；
   2. 按 `.` 切分得到 3 段，第一段必须等于 `G1`；
   3. 用缓存的 `public_key` 对 `G1.<payload-b64url>` 做 ES256 验签，失败 → 判定被篡改，回普通模式；
   4. base64url 解码载荷，校验 `m == sha256(本地机器码)前16位`、`k == sha256(当前token)前16位`；
   5. 用 **服务端时间** 为准（可借 `server_time` 校正本地时钟），若 `now > g + clock_skew` → 宽限已到期；
   6. 全部通过 → **允许继续离线运行**，直到 `until`；期间持续重试心跳，一旦恢复立即用新票据刷新。

**参考判断逻辑（伪代码）**

```
ok = verify_es256(pubkey, "G1." + payload_b64, sig)
     && payload.m == sha16(machine_id)
     && payload.k == sha16(token)
     && now <= payload.g + clock_skew
if ok: run_offline_until(payload.g)
else:  return_to_login()
```

#### 安全边界

| 场景                | 结果                                                                                           |
| ----------------- | -------------------------------------------------------------------------------------------- |
| 票据被篡改 / 伪造        | 验签失败，本地不采纳                                                                                   |
| 换机器               | `m` 不匹配，本地不采纳                                                                                |
| 换会话（被踢后重新登录，令牌变化） | `k` 不匹配，旧票据立即失效                                                                              |
| 账号到期              | 签发时 `until` 已被账号有效期**钳制**（`clamp_to_vip`），最迟随会员到期同时失效                                        |
| 封号 / 强制下线         | 宽限**只延长离线运行**，一旦联网即被服务端拒绝；封禁的最终生效延迟上限为 `grace.seconds`                                       |
| 服务端关闭该能力          | `grace.enable=false` 或 `grace.seconds=0`，`login` / `heartbeat` 的 `grace` 为 `null`，客户端按普通模式处理 |

#### 配置项（`config.php` → `grace`）

| 配置             | 默认                      | 说明                                     |
| -------------- | ----------------------- | -------------------------------------- |
| `enable`       | `true`                  | 总开关                                    |
| `seconds`      | `3600`                  | 单次宽限时长（秒），建议为心跳间隔的 15~30 倍；`0` = 关闭    |
| `max_seconds`  | `7200`                  | 硬上限，防止 `seconds` 配得过大；`0` = 不限制        |
| `clamp_to_vip` | `true`                  | 宽限截止是否被账号到期时间钳制（强烈建议保持 `true`）         |
| `clock_skew`   | `120`                   | 客户端时钟允许偏差（秒）                           |
| `key_file`     | `config/grace_keys.php` | 私钥文件；删除后自动重新生成（等价于**轮换密钥**，此前所有票据立即失效） |

> **密钥轮换**：只要更换了 `key_file`（或删除让其重新生成），`kid` 随之改变，  
> 客户端下次 `init` 时比对 `kid` 不一致即会重新拉取公钥；旧票据在此期间自然全部失效。

> **兼容性**：本协议为**纯增量**能力。客户端完全不处理 `grace` 字段时，  
> 行为与升级前一致（网络失败即掉线）；服务端未启用时也不会下发该字段。

---


### 2.17 官网互动接口

> 入口：`POST /web/api.php?action=<接口名>`，**明文 JSON**。  
> 认证方式为浏览器会话 Cookie（`NBWEBSID`），与后台管理会话互不干扰。  
> 写接口需带 `X-CSRF` 请求头（值取自页面注入的 `window.__NB_WEB__.csrf`）。

**接口一览**

| action     | 说明                                   |  登录 | CSRF |
| ---------- | ------------------------------------ | :-: | :--: |
| `plan`     | 价格套餐列表（仅 `status=1`，按 `sort` 倒序）     |  —  |   免  |
| `shot`     | 客户端截图列表（仅 `status=1`，只放行 http/https） |  —  |   免  |
| `msg_list` | 留言板列表（仅已审核通过的；分页，每页 10 条主楼）          |  —  |   免  |
| `msg_post` | 发布留言 / 回复                            |  ✔  |   ✔  |
| `msg_like` | 点赞 / 取消点赞（切换语义）                      |  ✔  |   ✔  |
| `fb_types` | 反馈类型选项                               |  —  |   免  |
| `fb_list`  | 我的反馈列表（仅返回本人）                        |  ✔  |   免  |
| `fb_post`  | 提交反馈                                 |  ✔  |   ✔  |

**`msg_list` 响应结构**（主楼 + 两层回复，一次取回避免 N+1）

```json
{"code":0,"data":{"list":[{"id":12,"username":"星尘","content":"…","likes":3,
  "reply_to":"","mine":false,"liked":false,"created_at":"2026-09-10 20:00:00",
  "replies":[{"id":13,"username":"官方客服","content":"…","reply_to":"星尘",
              "likes":0,"mine":false,"liked":false,"created_at":"…"}]}],
  "total":1,"page":1,"pages":1}}
```

- `mine` / `liked` 仅在登录时有意义；匿名访问恒为 `false`
- 分页作用于**主楼**条数，回复随主楼一并返回

**`msg_post` 参数与规则**

| 参数          | 说明                               |
| ----------- | -------------------------------- |
| `content`   | 必填，按**字符**计数（`mb_strlen`），上限 500 |
| `parent_id` | 选填，`0` 为主楼；回复时必须是**已审核通过**的主楼 ID |

- 落库 `status = 0`（待审核），响应 `{"id":12,"pending":true}`
- 只允许两层：`parent_id` 指向的留言其 `parent_id` 必须为 `0`，否则报「只支持两层回复」
- 限流：单账号 **3 条 / 分钟**

**`msg_like` 参数**：`id`（留言 ID，必须是已审核通过的）

- 响应 `{"liked":true,"likes":4}`；重复调用即取消点赞
- 靠 `nb_message_likes(message_id,user_id)` 唯一键兜底并发；  
  取消时用 `GREATEST(likes,1)-1`，计数永不为负
- 限流：单账号 **30 次 / 分钟**

**`fb_post` 参数与规则**

| 参数        | 说明                                                        |
| --------- | --------------------------------------------------------- |
| `type`    | `1` 功能建议 / `2` 问题反馈 / `3` 卡密订单 / `4` 其他；非法值**回落为 4** 而非报错 |
| `title`   | 必填，≤ 60 字                                                 |
| `content` | 必填，≤ 2000 字                                               |
| `contact` | 选填，≤ 100 字，便于客服回访                                         |

- 限流：单账号 **5 条 / 小时**
- 响应附带最新反馈列表，前端可直接重绘

**`fb_list` 响应结构**（状态与回复可见性）

```json
{"code":0,"data":{"list":[{"id":3,"type":2,"type_text":"问题反馈",
  "title":"…","content":"…","contact":"","status":2,"status_text":"已回复",
  "reply":"…","reply_admin":"客服A","replied_at":"2026-09-10 20:10:00",
  "created_at":"2026-09-10 20:00:00"}],"types":{"1":"功能建议","2":"问题反馈",
  "3":"卡密/订单","4":"其他"}}}
```

- 状态：`0` 待处理 / `1` 处理中 / `2` 已回复 / `3` 已关闭
- **隐私规则**：只有状态为 `2` 或 `3` 时才下发 `reply` / `reply_admin`，  
  其余状态返回空串 —— 避免前端显示「空回复框」，也避免回复草稿外泄

**审核策略：先审后显示**

留言与回复提交后均为「待审核」，只有后台通过（`status = 1`）才会出现在 `msg_list`  
与官网首屏。前端收到 `pending:true` 后会提示「已提交，通过审核后展示」，  
避免用户误以为发送失败而重复提交。

**优雅降级**：若站点尚未执行 `install/migrate_web_interact.php`，  
上述接口一律返回空列表（`list: []`），官网区块显示「暂无内容」，**不会整页报错**。  
`WebInteract::tableReady()` 按请求缓存 `SHOW TABLES` 结果，避免重复查询。

---

### 2.18 功能密钥协议

> **功能密钥（Feature Key）是本系统给接入方的「数据防破解」增强能力**：  
> 服务端把一把随机密钥与软件绑定，**只在 login 成功响应中下发**（`data.feature_key`）。  
> 接入方用它加解密随程序分发的核心数据包 —— 结果是：**patch 掉登录判定、或登录失败/被踢/过期时，  
> 客户端拿不到密钥，核心数据永远停留在密文状态**，从「保护验证结果」升级为「保护数据本身」。

**原则：下发密钥，不下发验证结果**

传统网络验证的信任边界在客户端：登录成功与否只是一个分支判断，逆向者 patch 掉分支即可绕过。  
功能密钥把这个边界移到数据上：

| 场景                     | 无功能密钥           | 有功能密钥               |
| ------------------------ | ----------------- | ------------------------ |
| 登录成功                   | 显示核心数据        | 解密核心数据后显示         |
| patch 掉登录分支           | 直接显示核心数据      | **数据是密文，无法显示**     |
| 登录失败 / 被踢 / 会话过期   | 数据明文留在内存/资源 | 密钥从未到达，数据保持密文 |
| 离线宽限期间                 | 同上               | 宽限票据有效期内可继续用已解密数据 |

**配置（管理后台）**

后台「软件管理 → 编辑软件 → 功能密钥」：留空 = 未启用（login 响应中为空串）；  
点「生成随机」填入 16 字节随机 hex（或任意 ≤128 字符的强随机串）。每个软件独立一把。

**NF1 数据包格式（服务端/SDK 双端实现一致）**

```
数据包 = "NF1." + base64( iv[16] + AES-256-CBC(明文) ) + "." + hex( HMAC-SHA256("NF1." + base64段, macKey) )

aesKey = SHA256( feature_key + "|nebula-feature-aes" )   // 域分离派生
macKey = SHA256( feature_key + "|nebula-feature-mac" )
```

- **encrypt-then-MAC**：先对密文段做 HMAC，打开时**先验签（恒定时间比较）后解密**；
- 密钥域分离：同一把 feature_key 派生出的 AES 钥与 HMAC 钥互不相关；
- 格式带 `NF1` 版本前缀，未来换算法可平滑升级。

**C++ SDK 用法（`nebula/client/feature.hpp`）**

```cpp
// ① 发布前：把核心数据（配置表、关卡数据、算法参数等）加密成数据包随程序分发
std::string pack = nebula::feature::seal(coreData, /*发布时填入的*/ featureKey);
//    → 把 pack 写入资源文件 / 内嵌常量。发布后工程里【不再保留 featureKey 明文】

// ② 运行期：登录成功后用服务端下发的密钥打开
nebula::LoginResult lr = client.login(...);
if (lr.ok) {
    std::string data, err;
    if (nebula::feature::open(pack, lr.feature_key, data, err)) {
        // data 即核心数据明文
    } else {
        // err：密钥不对 / 数据被篡改 —— 按破解处理
    }
}
```

> PHP 侧可用 `openssl_encrypt('aes-256-cbc', ...)` + `hash_hmac('sha256', ...)`  
> 按上述 NF1 格式制作数据包；SDK 与 PHP 已做格式对拍（互通验证通过）。

**安全边界（务必理解）**

- 功能密钥随 login 响应**经通信信封（AES+HMAC+会话密钥）加密传输**，抓包拿不到明文；
- 但密钥最终要进入客户端内存参与解密 —— **它提高的是破解成本与门槛，不是绝对防御**：  
  对手若完整逆向客户端并 dump 运行期内存，仍可能取到已解密数据；
- 推荐组合拳：功能密钥（数据加密）+ VMProtect（客户端加壳）+ 离线宽限（防断网轰炸）分层纵深。
- **v2.65.30 增量（C++ SDK）**：新增 `SecureBuffer` 敏感缓冲区（内存加密、访问时解密）、
  `openSecure`（运行期才解包核心数据）与 `wipeFeatureKey` / `secureWipe`（用后立即清空密钥与明文）。
  推荐时序：`login` 成功 → `openSecure` 打开数据包 → 使用完毕 → `wipeFeatureKey` + `secureWipe`
  抹掉内存中的密钥与明文，降低运行期 dump 的收益。

### 2.19 运行时安全接口（Runtime Security）

客户端 SDK 内置运行时防护引擎（代码完整性 / 补丁检测 / 内联 Hook / 模块注入 / 调试器检测等）
与服务端联动，均需 **3.1 会话信封**：

| action | 说明 | 认证 |
| ----- | ---- | ---- |
| `runtime_policy` | 拉取当前生效的运行时安全策略快照：`policy_id` / `policy_version` / `protection_level` / 各检测项开关（`detect_code_integrity`、`detect_code_patch`、`detect_inline_hook`、`detect_module_injection`、`detect_manual_map`、`detect_debugger`、`detect_process_access_risk`）/ 处置动作（`medium_action` / `high_action` / `critical_action`）。策略同时随 `init` / `heartbeat` 下发，客户端可用本接口主动刷新 | 会话 |
| `runtime_security_event` | SDK 防护引擎上报安全事件：需有效 `token` + `machine_id`；会话为 `BLOCKED` 状态直接拒绝（7001，`need_relogin`）；事件经白名单校验 → 去重 → 风险评分 → 处置（REPORT / TERMINATE / REVOKE_SESSION），响应 `accepted` + `deduped` + `runtime{status,risk_level,risk_score,action}`；频率超限返回 7004；**2.65.32 起**：sticky 硬证据事件（代码篡改 / 受保护代码失败 / 手动映射）到达即自动冻结——设备永久拉黑 + 该用户已激活卡密一次作废 | 业务会话 |

策略默认档动作：MEDIUM→REPORT、HIGH→TERMINATE、CRITICAL→REVOKE_SESSION（后台策略可覆盖）；
管理端对应接口见 §3.2「运行时安全（Runtime Security）」分组。

---

## 三、管理后台接口

入口：`POST /admin/index.php?action=<接口名>`  
页面入口：`GET /admin/home.php`（或 `/admin/`，输出管理界面）

### 3.1 认证方式

登录成功后建立**管理员会话**（存表 `nb_admin_sessions`），凭证双通道下发：

- **HttpOnly Cookie**（P1-09）：`nb_admin_sid=<会话ID>`；JS 读不到，XSS 无法窃取；浏览器自动携带
- **响应体**：返回 `token`（Cookie 被禁用 / 旧客户端回退用 `X-Token` 头）与 **`session_key`**（第二因子，只走 JS 内存，服务端仅存其 SHA-256 摘要）

后续请求需携带：

```
nb_admin_sid:   <HttpOnly Cookie，自动携带>
X-Token:        <管理员token>                  // Cookie 不可用时回退
X-Session-Key:  <登录时下发的随机第二因子>            // P0-02：Cookie 冒用 / CSRF 诱导下仍可防线
X-CSRF:         <CSRF令牌>                     // 写操作必需
```

> 管理端默认返回**明文 JSON**，便于前端 JS 处理。如需加密，请求体加 `"encrypt": 1`。

#### 会话密钥（X-Session-Key，P0-02）

`session_key` 仅在登录时下发一次且不进 Cookie：攻击者即使诱导浏览器带 Cookie 发请求（CSRF）
也拿不到 JS 持有的 `session_key`。**已绑定 session_key 的会话必须同时提供 token + session_key
才放行**；历史会话（sk_hash 为 NULL）不校验，保证升级不踢人。会话失效（1003）后 HttpOnly Cookie 会被一并清除。

#### CSRF 令牌

所有**写操作**必须携带 CSRF 令牌（`X-CSRF` 头或 body 的 `csrf` 字段）。  
令牌由页面入口 `/admin/home.php` 注入到 `window.__NB__.csrf`，并绑定当前 PHP session cookie。

- 只读接口（列表、详情、统计、导出等）**豁免** CSRF 校验，但仍要求有效会话
- 令牌缺失或错误返回 `code=1006`

> 如果你的客户端不是浏览器（如脚本、自动化工具），需先 `GET /admin/home.php`  
> 并保持 cookie，再从中提取 CSRF 令牌，后续请求同时带上 cookie、令牌与登录下发的 `session_key`。


### 3.2 接口清单

| action                | 说明                                                                                                                                                                                                                                                                                                                            | 权限          |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------- |
| `login`               | 管理员登录                                                                                                                                                                                                                                                                                                                         | 公开          |
| `logout`              | 退出                                                                                                                                                                                                                                                                                                                            | 登录          |
| `profile`             | 获取/修改个人信息、改密码、登录历史                                                                                                                                                                                                                                                                                                            | 登录          |
| `dashboard`           | 首页统计（用户/卡密/设备/API/趋势）                                                                                                                                                                                                                                                                                                         | 登录          |
| **用户管理**              |                                                                                                                                                                                                                                                                                                                               |             |
| `user_list`           | 用户列表（分页/搜索/筛选/排序）                                                                                                                                                                                                                                                                                                             | 登录          |
| `user_detail`         | 用户详情（含设备、卡密、日志、会话）                                                                                                                                                                                                                                                                                                            | 登录          |
| `user_save`           | 新增 / 编辑用户                                                                                                                                                                                                                                                                                                                     | 操作员+        |
| `user_delete`         | 删除用户（需二次密码）                                                                                                                                                                                                                                                                                                                   | 超管          |
| `user_kick`           | 强制下线 / 重置密码 / 解锁 / 清空设备                                                                                                                                                                                                                                                                                                       | 操作员+        |
| `user_batch_op`       | 批量：封禁/解封/加时长/加点数/下线/删除                                                                                                                                                                                                                                                                                                        | 操作员+（删除仅超管） |
| `user_import`         | 从 CSV 批量导入用户                                                                                                                                                                                                                                                                                                                  | 操作员+        |
| `user_export`         | 导出用户（csv/txt）                                                                                                                                                                                                                                                                                                                 | 登录          |
| **代理商管理**             |                                                                                                                                                                                                                                                                                                                               |             |
| `agent_list`          | 代理商列表（含发货统计与按卡类型额度；`all=1` 只取下拉选项）                                                                                                                                                                                                                                                                                            | 登录          |
| `agent_detail`        | 代理商详情（档案 + 按类型额度单价 + 统计 + 最近卡密/批次/操作记录）                                                                                                                                                                                                                                                                                       | 登录          |
| `agent_save`          | 新增/编辑/删除/启停/按类型充值(`op=grant`, `types`)/重置密码(`op=reset_password`)                                                                                                                                                                                                                                                              | 操作员+        |
| **代理商激活码**            |                                                                                                                                                                                                                                                                                                                               |             |
| `agent_code_list`     | 激活码列表（含发货规格、已用次数、已注册代理；`usable=1` 只看可用码）                                                                                                                                                                                                                                                                                      | 登录          |
| `agent_code_save`     | 生成(`op=generate`)/编辑(`op=update`)/启停(`op=toggle`)/删除(`op=delete`)                                                                                                                                                                                                                                                             | 操作员+        |
| **代理商充值卡密**           |                                                                                                                                                                                                                                                                                                                               |             |
| `agent_recharge_list` | 充值卡密列表（余额充值 / 张数额度；`kind` / `status` / `usable` 过滤）                                                                                                                                                                                                                                                                           | 登录          |
| `agent_recharge_save` | 生成(`op=generate`)/启停(`op=toggle`)/删除(`op=delete`)                                                                                                                                                                                                                                                                             | 操作员+        |
| **卡密管理**              |                                                                                                                                                                                                                                                                                                                               |             |
| `card_list`           | 卡密列表                                                                                                                                                                                                                                                                                                                          | 登录          |
| `card_generate`       | 批量生成卡密                                                                                                                                                                                                                                                                                                                        | 操作员+        |
| `card_detail`         | 卡密详情（绑定设备、使用记录）                                                                                                                                                                                                                                                                                                               | 登录          |
| `card_update`         | 编辑未使用的卡密                                                                                                                                                                                                                                                                                                                      | 操作员+        |
| `card_export`         | 导出卡密（txt/csv）                                                                                                                                                                                                                                                                                                                 | 登录          |
| `card_void`           | 作废卡密（单张/按批次）                                                                                                                                                                                                                                                                                                                  | 操作员+        |
| `card_batch_op`       | 批量作废 / 批量延长有效期 / 删除批次                                                                                                                                                                                                                                                                                                         | 操作员+        |
| `card_batch_list`     | 卡密批次列表                                                                                                                                                                                                                                                                                                                        | 登录          |
| **发卡商城**              |                                                                                                                                                                                                                                                                                                                               |             |
| `shop_goods_list`     | 发卡商品列表（含分类、上架状态、库存口径四元组）                                                                                                                                                                                                                                                                                                      | 登录          |
| `shop_goods_save`     | 商品新增 / 编辑（必选分类，价格与卡规格精确匹配库存）                                                                                                                                                                                                                                                                                                  | 操作员+        |
| `shop_goods_delete`   | 删除商品（单个 `id` 或批量 `ids` 数组 / 逗号分隔）                                                                                                                                                                                                                                                                                             | 操作员+        |
| `shop_goods_toggle`   | 商品上架 / 下架                                                                                                                                                                                                                                                                                                                     | 操作员+        |
| `shop_goods_import`   | 商品批量导入                                                                                                                                                                                                                                                                                                                        | 操作员+        |
| `shop_goods_options`  | 商品表单选项（分类列表、卡类型 / 时长 / 设备数可选值）                                                                                                                                                                                                                                                                                                | 登录          |
| `shop_goods_upload`   | 商品图片上传（仅图片含 gif，≤5MB，`getimagesize` 校验）                                                                                                                                                                                                                                                                                       | 操作员+        |
| `shop_cards_list`     | 发卡卡密库存列表                                                                                                                                                                                                                                                                                                                      | 登录          |
| `shop_card_delete`    | 删除发卡卡密                                                                                                                                                                                                                                                                                                                        | 操作员+        |
| `shop_order_list`     | 发卡订单列表（状态过滤）                                                                                                                                                                                                                                                                                                                  | 登录          |
| `shop_order_op`       | 订单操作：人工收款确认发货 / 关闭订单                                                                                                                                                                                                                                                                                                          | 操作员+        |
| `shop_setting_save`   | 发卡网设置（模式 built/external、易支付参数、站点地址 `shop_site_url`、装修项、分类、标签标题等）                                                                                                                                                                                                                                                              | 仅超管         |
| **文件管理**              |                                                                                                                                                                                                                                                                                                                               |             |
| `files_integrity`     | 文件完整性：`op=build` 重建 sha256 基准（存 `data/file_baseline.json`）/ `op=check` 比对出改动、新增、缺失三类                                                                                                                                                                                                                                          | 仅超管         |
| `files_scan`          | Webshell 挂马扫描（14 条特征评分，≥20 分判定可疑）                                                                                                                                                                                                                                                                                             | 仅超管         |
| `file_view`           | 查看文件内容（前 64KB）                                                                                                                                                                                                                                                                                                                | 仅超管         |
| `file_delete`         | 删除文件：单个 `id` 或批量 `files` 数组（≤200，Deleter 密码一次确认，运行必需文件硬保护）                                                                                                                                                                                                                                                                    | 仅超管         |
| **设备与会话**             |                                                                                                                                                                                                                                                                                                                               |             |
| `device_list`         | 设备列表                                                                                                                                                                                                                                                                                                                          | 登录          |
| `device_unbind`       | 解绑设备 / 批量解绑 / 按用户 / 清理离线                                                                                                                                                                                                                                                                                                      | 操作员+        |
| `device_ban`          | 拉黑机器码（可设时长）                                                                                                                                                                                                                                                                                                                   | 操作员+        |
| `session_list`        | 在线会话                                                                                                                                                                                                                                                                                                                          | 登录          |
| `session_kick`        | 踢出会话（单个/批量/按用户）                                                                                                                                                                                                                                                                                                               | 操作员+        |
| **运营**                |                                                                                                                                                                                                                                                                                                                               |             |
| `notice_list`         | 公告列表                                                                                                                                                                                                                                                                                                                          | 登录          |
| `notice_save`         | 公告增删改                                                                                                                                                                                                                                                                                                                         | 操作员+        |
| `version_list`        | 版本列表                                                                                                                                                                                                                                                                                                                          | 登录          |
| `version_save`        | 版本增删改                                                                                                                                                                                                                                                                                                                         | 操作员+        |
| `group_list`          | 用户组列表                                                                                                                                                                                                                                                                                                                         | 登录          |
| `group_save`          | 用户组增删改                                                                                                                                                                                                                                                                                                                        | 操作员+        |
| **官网运营（互动模块）**        |                                                                                                                                                                                                                                                                                                                               |             |
| `message_list`        | 官网留言板列表（`status` / `kw` 过滤，返回待审计数）                                                                                                                                                                                                                                                                                            | 登录          |
| `message_op`          | 留言审核：`pass` / `reject`（可带理由）/ `delete`（级联清理回复与点赞）/ `batch`（批量通过/驳回/删除，单次上限 200）                                                                                                                                                                                                                                               | 操作员+        |
| `feedback_list`       | 用户反馈列表（`status` / `type` / `kw` 过滤，返回各状态计数）                                                                                                                                                                                                                                                                                   | 登录          |
| `feedback_reply`      | 反馈处理：`reply`（置为已回复，记录回复人与时间）/ `close` / `reopen` / `delete` / `batch_close`                                                                                                                                                                                                                                                   | 操作员+        |
| `plan_list`           | 价格套餐列表                                                                                                                                                                                                                                                                                                                        | 登录          |
| `plan_save`           | 套餐增删改：`save` / `delete` / `toggle`（`price` 为字符串，支持「面议」）                                                                                                                                                                                                                                                                       | 操作员+        |
| `screenshot_list`     | 客户端截图列表                                                                                                                                                                                                                                                                                                                       | 登录          |
| `screenshot_save`     | 截图增删改：`save` / `delete` / `toggle`；`url` 强制 http/https，拒绝 `javascript:` / `data:`                                                                                                                                                                                                                                             | 操作员+        |
| **数据大屏与分析**           |                                                                                                                                                                                                                                                                                                                               |             |
| `bigscreen`           | 数据大屏：实时在线、今日 / 累计概览、在线曲线、代理销量排行、卡密类型分布、运行时状态；`op=data`（默认）返回数据，`op=flush_cache` 清空缓存键（操作员+）                                                                                                                                                                                                                                   | 登录          |
| `analytics`           | 留存复购：D1/D3/D7 队列留存、用户与代理复购率、DAU/WAU/MAU 活跃分层、充值趋势                                                                                                                                                                                                                                                                             | 登录          |
| **日志与设置**             |                                                                                                                                                                                                                                                                                                                               |             |
| `log_list`            | 业务日志查询                                                                                                                                                                                                                                                                                                                        | 登录          |
| `audit_list`          | 审计日志（谁改了什么）                                                                                                                                                                                                                                                                                                                   | 登录          |
| `audit_detail`        | 审计详情（字段级变更明细）                                                                                                                                                                                                                                                                                                                 | 登录          |
| `stat_overview`       | API 调用统计                                                                                                                                                                                                                                                                                                                      | 登录          |
| `setting_get`         | 读取设置（含 `login_methods_options` 登录方式、`agent_modes_options` 代理商控量模式可选项、`effective` 策略项当前生效值与来源）                                                                                                                                                                                                                                 | 登录          |
| `setting_save`        | 保存设置（白名单含 `login_methods` / `single_login` / `geo_block` / `heartbeat_interval` / `heartbeat_timeout` / `unbind_per_day` / `rate_limit_per_min` / `agent_enable` / `agent_unit_price` / `agent_entry_key` / `contact` / `web_message_board` / `ip_blacklist` 等；`ip_blacklist` 归安全档，逐行 `inet_pton` 校验，支持单 IP 与 CIDR 段，非法行整单拒绝） | 操作员+        |

#### 补录接口（v2.65.x 新增，与上表同属 3.2 权限体系）

| action                  | 说明                                                                                | 权限          |
| ----------------------- | --------------------------------------------------------------------------------- | ----------- |
| **设备拉黑**               |                                                                                   |             |
| `device_ban_list`       | 设备拉黑名单（含来源 / 解除时间，可手动解除）                                                         | 登录          |
| `device_unban`          | 解除设备拉黑                                                                             | 操作员+        |
| **用户组 / 商家 / 小游戏 / 模板** |                                                                                   |             |
| `group_batch`           | 用户组批量操作（启停 / 删除）                                                                     | 操作员+        |
| `seller_list`           | 购买商家列表                                                                             | 登录          |
| `seller_save`           | 购买商家增删改（上架 / 下架 / 删除）                                                                  | 操作员+        |
| `game_list`             | 小游戏排行榜列表                                                                           | 登录          |
| `game_del`              | 删除小游戏排行记录                                                                          | 操作员+        |
| `template_list`         | 界面模板列表（官网 / 发卡网换肤）                                                                     | 操作员+        |
| `template_save`         | 界面模板增删改 / 应用换肤                                                                       | 操作员+        |
| `tpl_sections_get`      | 读取模板布局（Layout 顺序）与自定义区块（sections）                                                  | 操作员+        |
| `tpl_sections_save`     | 写入模板布局与自定义区块                                                                        | 操作员+        |
| `shop_sw_get`           | 读取发卡网界面模板设置                                                                         | 操作员+        |
| `shop_sw_save`          | 保存发卡网界面模板设置                                                                         | 操作员+        |
| **软件管理（多软件分站）**       |                                                                                   |             |
| `software_list`         | 多软件列表（分站配置）                                                                         | 仅超管         |
| `software_save`         | 新增 / 编辑软件（分站文案 / Logo / 主题色 / 模板覆盖）                                                  | 仅超管         |
| `software_delete`       | 删除软件（名下有用户 / 卡密 / 设备时拒绝）                                                             | 仅超管         |
| `software_batch`        | 软件批量操作（启停 / 删除）                                                                      | 仅超管         |
| `software_web_get`      | 读取软件官网分站内容（总站 + 分软件覆盖）                                                                | 操作员+        |
| `software_web_save`     | 保存软件官网分站内容                                                                          | 操作员+        |
| `web_upload`            | 官网图片上传（背景图等，`getimagesize` 校验）                                                           | 操作员+        |
| **管理员账号**              |                                                                                   |             |
| `admin_list`            | 管理员账号列表（含权限清单）                                                                      | 仅超管         |
| `admin_save`            | 新增 / 编辑 / 启停管理员、配置自定义权限清单                                                             | 仅超管         |
| **安全 / 维护 / 更新**      |                                                                                   |             |
| `sec_report`            | 安全审计报告（cron 巡检产物，列表 / 详情 / 下载）                                                         | 仅超管（默认）    |
| `security_check`        | 安全自检（只读扫描，结果在数据概览页卡片展示）                                                             | 仅超管         |
| `grace_rotate_keys`     | 离线宽限密钥轮换（删除旧私钥重新生成，旧票据全部失效）                                                          | 仅超管         |
| `resp_sign_rotate_keys` | 响应签名密钥轮换（ES256 私钥重生成）                                                                    | 仅超管         |
| `system_maintenance`    | 系统维护（健康巡检 / 数据备份 / 缓存处理）                                                               | 仅超管         |
| `system_update_check`   | 检查系统更新（只读，结果缓存 6h，可手动刷新）                                                              | 仅超管         |
| `system_update_do`      | 一键更新（下载 → SHA-256 校验 → 备份 → 覆盖 → 升级版本号）                                                   | 仅超管         |
| **运行时安全（Runtime Security）** |                                                                             |             |
| `rt_overview`           | 运行时安全总览（事件计数 / 风险分布 / 策略状态）                                                            | 仅超管（可勾选）   |
| `rt_event_list`         | 运行时安全事件列表（`level` / `event_type` / `handled` 过滤）                                           | 仅超管（可勾选）   |
| `rt_event_detail`       | 事件详情（载荷 / 风险评分 / 处置动作）                                                                    | 仅超管（可勾选）   |
| `rt_event_handle`       | 标记事件处理状态（0 未处理 / 1 已处理 / 2 误报）                                                             | 仅超管（可勾选）   |
| `rt_event_batch`        | 批量标记事件状态                                                                           | 仅超管（可勾选）   |
| `rt_policy_list`        | 运行时策略列表（版本 / 启用 / 等级）                                                                    | 仅超管（可勾选）   |
| `rt_policy_detail`      | 策略详情（检测项开关、处置动作）                                                                      | 仅超管（可勾选）   |
| `rt_policy_save`        | 新建 / 编辑运行时策略                                                                        | 仅超管（可勾选）   |
| `rt_policy_delete`      | 删除策略                                                                               | 仅超管（可勾选）   |
| `rt_risk_devices`       | 风险设备列表                                                                             | 仅超管（可勾选）   |
| `rt_risk_sessions`      | 风险会话列表                                                                             | 仅超管（可勾选）   |
| `rt_session_unblock`    | 解除会话封锁                                                                             | 仅超管（可勾选）   |
| `rt_device_unblock`     | 解除设备封锁                                                                             | 仅超管（可勾选）   |

> 权限列说明：`登录`=任意已登录管理员；`操作员+`=role 2 / role 1（权限点在 role 2 默认矩阵内）；
> `仅超管`=默认仅 role 1（权限点在 role 2/3 默认矩阵之外，如 `settings.business` / `settings.security` /
> `settings.infra` / `admin.manage`）；`可勾选`=超管可在账号自定义权限清单中单独授予 role 2/3。
> 审计类（`audit_list` / `audit_detail` / `sec_report`）权限点为 `audit.read`，默认矩阵不含 → 默认仅超管。

#### 登录方式配置项 `login_methods`

| 取值              | 含义           | 客户端/官网登录字段              |
| --------------- | ------------ | ----------------------- |
| `password`      | 用户名 + 密码（默认） | `username` + `password` |
| `username_code` | 用户名 + 激活码    | `username` + `code`     |
| `code`          | 激活码（卡密直登）    | `code`                  |

- 三选一，**互斥**；同时作用于客户端 `/api/login` 与官网 `/web/api.php?action=login`。
- 出厂默认写在 `config/config.php` 的 `policy.login_methods`，后台保存后以数据库 `nb_settings` 为准。
- 客户端通过 `init` 的 `data.login` 获知当前方式；切换后客户端需重新 `init`。
- `code` 方式下 `register` 自动关闭（返回 `1004`）。

#### 策略项取值优先级（`lib/Policy.php`）

以下策略项由 `Policy` 统一取值，**数据库优先、配置文件回退**：

| 设置键                   | 说明                                    | 取值接口                          | 出厂默认    |
| --------------------- | ------------------------------------- | ----------------------------- | ------- |
| `single_login`        | 同账号单点登录（后登录踢掉先登录）                     | `Policy::singleLogin()`       | `false` |
| `geo_block`           | 异地登录拦截（已绑定设备换 IP 即拒绝，返回 `4006`）       | `Policy::geoBlock()`          | `false` |
| `heartbeat_interval`  | 心跳间隔（秒），经 `init` / `heartbeat` 下发给客户端 | `Policy::heartbeatInterval()` | `60`    |
| `heartbeat_timeout`   | 离线判定（秒），后台在线状态、清理僵尸设备共用               | `Policy::heartbeatTimeout()`  | `180`   |
| `unbind_per_day`      | 单账号每日解绑次数上限，`0` = 不限制                 | `Policy::unbindPerDay()`      | `3`     |
| `rate_limit_per_min`  | 单 IP 每分钟最大请求数                         | `Policy::rateLimitPerMin()`   | `120`   |
| `session_ttl`         | 登录态有效期（秒）                             | `Policy::sessionTtl()`        | `3600`  |
| `default_max_devices` | 单卡默认最大设备数                             | `Policy::defaultMaxDevices()` | `1`     |

**判定规则**（`Setting::isSet()` 只查数据库，语义明确）：

- 数据库中**不存在**该键 → 使用 `config/config.php` 里的出厂值（**未保存过的站点行为与升级前一致**）
- 数据库中**已存在**该键 → 一律以库中值为准（即使值是 `0` / 空串也算「已配置」）

`setting_get` 返回的 `data.effective` 会逐项给出 `config` / `db` / `effective` / `source`  
四个字段，后台「系统设置 → 安全设置」下方&#x7684;**「当前生效值」面板**据此展示，  
可一眼确认改动是否已落到运行时。

> 补边界：`heartbeat_interval` / `heartbeat_timeout` / `rate_limit_per_min` 为 `0` 或非数字时  
> 回落默认值；`unbind_per_day` 为负时归零（不限制）。

#### `bigscreen` — 数据大屏

**请求**

```json
{
  "op": "data",     // data（默认）| flush_cache
  "days": 7,        // 趋势曲线天数（默认 7）
  "hours": 24       // 在线曲线小时数（默认 24）
}
```

`op=flush_cache` 会清空业务缓存键（心跳缓冲与统计缓冲除外），返回 `{ "count": <清空的键数> }`。

**响应 `data` 主要字段**

| 字段             | 说明                                                                                     |
| -------------- | -------------------------------------------------------------------------------------- |
| `realtime`     | 实时在线数、在线设备数、在线用户数、`timeout`（离线判定秒数）                                                    |
| `today`        | 今日新增用户 / 激活 / 登录 / API 调用与失败 / 充值 / 新增代理                                               |
| `total`        | 累计用户、有效用户、卡密（未用/已用）、代理、在线设备                                                            |
| `online_curve` | 在线曲线（来源 `nb_online_stats` 快照），元素含 `t` / `label` / `online` / `devices`                 |
| `curves`       | 趋势曲线：激活数 / 新增用户 / API 调用与失败，元素含 `date` / `label` 及各计数                                  |
| `agent_rank`   | 代理销量排行：`agent_id` / `name` / `generated` / `used` / `unused` / `voided`                |
| `type_dist`    | 卡密类型分布：`type` / `name` / `total` / `used`                                              |
| `recent_logs`  | 最近 12 条业务日志                                                                            |
| `runtime`      | 运行状态：`cache`（驱动/命中）、`heartbeat`（缓冲积压/落库统计）、`stat_buffer`（统计缓冲条数）、`cache_files`（文件缓存占用） |

> `runtime` 是排查缓存 / 聚合问题的第一现场：心跳「缓冲不落库」属正常（等 cron 或阈值触发），  
> 若长时间持续增长则说明 cron 未运行。

#### `analytics` — 留存复购

**请求**

```json
{ "days": 30, "cohort_days": 7 }
```

**响应主要字段**

| 字段                  | 说明                                                                                                     |
| ------------------- | ------------------------------------------------------------------------------------------------------ |
| `retention.list[]`  | 队列留存，元素含 `date` / `label` / `total` / `age` / `d1` / `d3` / `d7`（队列未满 N 天时该列为 `null`）                  |
| `retention.summary` | 汇总留存率 `d1` / `d3` / `d7`（%）与纳入统计的队列数 `cohorts`                                                         |
| `repurchase.user`   | 用户复购：`buyers`（激活过卡的用户）/ `repeat`（≥2 张）/ `rate`(%) / `once` / `two_to_five` / `six_plus` / `three_plus` |
| `repurchase.agent`  | 代理复购：`agents`（兑换过充值卡的代理）/ `repeat`（≥2 次）/ `rate`(%) / `once` / `five_plus`                             |
| `tier`              | 活跃分层：`dau` / `wau` / `mau` / `inactive`（活跃口径 = 有登录记录的去重用户数）                                            |
| `dau`               | 每日活跃（`date` / `label` / `users` / `logins`）                                                            |
| `recharge_trend`    | 每日充值次数（`date` / `label` / `count`）                                                                     |

> `retention` / `repurchase` / `tier` 的口径说明随响应一并返回（`meta` 字段），便于前端展示提示。

### 3.3 角色权限（RBAC）

权限判断统一走 `lib/AdminPermission.php` 的 **ACTION_PERM** 表 + **ROLE_MATRIX** 角色矩阵：
入口 `admin/index.php` 对每个 action 做粗粒度校验（`AdminPermission::requireAction`），handler
内部（如 `setting_save`）再按安全 / 业务 / 基础设施分档细分。**所有 action 必须登记权限点才可访问
（默认拒绝）**；未登记的 action 一律拒绝。

| role | 名称    | 权限              |
| ---- | ----- | --------------- |
| 1    | 超级管理员 | 全部权限；`admin.manage`（管理员账号与权限配置）为超管专属硬权限，即使出现在自定义清单也不生效 |
| 2    | 操作员   | 默认矩阵：用户读/改、卡密读、代理读、设备读、会话踢出、内容管理、站点展示设置；**不能**发卡密、碰代理资金、改业务/安全/基础设施设置、看审计日志、删用户、封设备、批量导入、导出卡密 |
| 3    | 只读    | 默认矩阵：仅各域 `.read` 与内容查看，无任何写权限点 |

**权限自定义**：`nb_admins.permissions` 为超管给账号逐项勾选的自定义权限清单；非 NULL 即
完全覆盖角色默认矩阵（老账号不勾选时行为不变）。super admin（role=1）不查矩阵直接放行，
保证即使矩阵漏配也不会把超管锁在门外。

**默认矩阵（权限点全集）**：`user.read/edit/import/delete`、`card.read/export/generate/void`、
`agent.read/edit/recharge_code`、`device.read/manage/ban`、`session.kick`、`content.manage`、
`settings.site`、`audit.read`、`rt_security.*` —— 其中 role 2 默认持有【用户读/改、卡密读、代理读、
设备读、会话踢出、内容管理、站点展示设置】，role 3 默认仅各 `.read`；`settings.business` /
`settings.security` / `settings.infra` / `admin.manage` / `audit.read` / `rt_security.*`
默认不在 role 2/3 矩阵（超管可在自定义清单中勾选授予）。

> 审计类（`audit_list` / `audit_detail` / `sec_report`）默认仅超管可见；如需开放给 role 2/3，
> 需在账号自定义权限清单中勾选 `audit.read`。

> 权限在**接口层**校验（`AdminPermission::requireAction`），不依赖前端隐藏按钮；即使直接构造
> 请求，未授权角色也会被拒绝（403）。旧 `AdminAuth::READONLY_ACTIONS` 已废弃，仅为兼容保留。

### 3.3.1 批量操作接口说明

#### `user_batch_op` — 用户批量操作

```json
{
  "op": "status | add_days | add_points | kick | delete",
  "ids": [1, 2, 3],
  "value": 0,          // status 时: 0封禁 1正常 2冻结
                       // add_days 时: 天数（可为负）
                       // add_points 时: 点数（可为负，结果不小于0）
  "password": "xxx"    // 仅 op=delete 需要，二次密码确认
}
```

单次最多 500 个用户。`delete` 仅超管可用，且需密码确认。

#### `user_import` — 用户批量导入

```json
{
  "content": "用户名,密码,昵称,邮箱,会员天数,点数,设备上限\nuser1,pass123456,昵称,,30,100,2",
  "dup": "skip",       // skip=跳过已存在  update=更新已存在
  "default_days": 0    // CSV 未指定天数时的默认值
}
```

返回：`{ ok, update, skip, fail, errors[] }`

#### `card_batch_op` — 卡密批量操作

```json
{ "op": "void | extend | delete_batch", "ids": [1,2,3], "days": 30, "batch_id": 5 }
```

- `void`：批量作废（仅影响未使用的卡密）
- `extend`：批量延长卡密自身有效期（永久卡不受影响）
- `delete_batch`：删除批次记录（未使用的卡密一并删除，已使用的保留）

#### `device_ban` — 拉黑机器码

```json
{ "device_ids": [1,2], "reason": "异常刷接口", "days": 7 }
```

`days=0` 表示永久。拉黑同时会解绑设备并踢下线。

### 3.3.2 审计日志

`audit_list` 参数：

| 参数                      | 说明               |
| ----------------------- | ---------------- |
| `keyword`               | 搜索操作人/目标/摘要/IP   |
| `action`                | 按动作筛选            |
| `admin_id`              | 按操作人筛选           |
| `date_from` / `date_to` | 日期范围（YYYY-MM-DD） |

`audit_detail` 返回字段级变更：

```json
{
  "audit": { "admin_name": "admin", "action_text": "编辑用户", "created_at": "..." },
  "changes": [
    { "field": "points", "label": "点数", "old": "10", "new": "110" },
    { "field": "vip_expire", "label": "会员到期", "old": "未激活", "new": "2026-12-31 00:00:00" }
  ]
}
```

密码类字段只记录「已设置/已修改」，不记录明文或哈希。

### 3.4 示例：管理员登录

```bash
curl -X POST "http://127.0.0.1/admin/index.php?action=login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin888"}'
```

响应：

```json
{
  "code": 0,
  "msg": "登录成功",
  "data": {
    "token": "9f8e7d6c...",
    "expire_at": 1726007200,
    "admin": { "id": 1, "username": "admin", "nickname": "admin", "role": 1, "role_text": "超级管理员" }
  }
}
```

### 3.5 示例：生成卡密

```bash
curl -X POST "http://127.0.0.1/admin/index.php?action=card_generate" \
  -H "Content-Type: application/json" \
  -H "X-Token: 9f8e7d6c..." \
  -d '{
    "count": 100,
    "type": 1,
    "duration": 2592000,
    "max_devices": 2,
    "prefix": "VIP",
    "name": "国庆活动批次",
    "expire_days": 0
  }'
```

参数说明：

| 参数          | 说明                              |
| ----------- | ------------------------------- |
| count       | 生成数量，1-10000                    |
| type        | 1时长卡 2点数卡 3次数卡 4永久卡             |
| duration    | 时长卡=秒数；点数/次数卡=数值；永久卡忽略          |
| max_devices | 激活后设备上限，1-99                    |
| group_id    | 激活后进入的用户组 ID，0=不换组（保持注册时的默认用户组） |
| prefix      | 卡密前缀，仅字母数字                      |
| expire_days | 卡密本身有效期（天），0=永久有效               |
| name        | 批次备注名                           |

响应（只返回前 50 条预览）：

```json
{
  "code": 0,
  "msg": "成功生成 100 张卡密",
  "data": {
    "batch_id": 3,
    "count": 100,
    "codes": ["VIP-AB12-CD34-EF56-GH78", "..."],
    "preview_count": 50,
    "type": 1,
    "duration": 2592000,
    "expire_at": 0
  }
}
```

> 完整卡密请通过 `card_export` 导出。

---

## 四、代理商后台接口（`/agent/`）

代理商（分销）拥有**完全独立**的后台，与主管理后台互不可见：

| 项目        | 主管理后台                      | 代理商后台                    |
| --------- | -------------------------- | ------------------------ |
| 页面入口      | `/admin/`（安装时强制改名）         | `/agent/`                |
| 接口入口      | `/admin/index.php?action=` | `/agent/api.php?action=` |
| 账号表       | `nb_admins`                | `nb_agents`              |
| 会话表       | `nb_admin_sessions`        | `nb_agent_sessions`      |
| 会话 Cookie | `PHPSESSID`                | `NBAGSID`                |
| 登录字段      | `username` + `password`    | `username` + `password`  |

### 4.1 认证方式

与主后台一致：登录拿 `token`，后续请求带 `X-Token`，写操作再带 `X-CSRF`。  
CSRF 令牌由页面入口 `/agent/` 注入到 `window.__NBAG__.csrf`（绑定 `NBAGSID` 会话）。

### 4.2 接口清单

| action          | 说明                                                                       | CSRF       |
| --------------- | ------------------------------------------------------------------------ | ---------- |
| `login`         | 代理登录，返回 `token` 与代理资料                                                    | 豁免         |
| `register`      | **凭激活码自助注册**（`code` / `username` / `password` / `password2` / `contact`） | 豁免（另加独立限流） |
| `logout`        | 退出并作废当前 token                                                            | 需要         |
| `profile`       | 我的资料 + 各卡类型额度/单价 + 余额 + 发货统计                                             | 豁免         |
| `dashboard`     | 概览（资料 + 统计 + 最近 10 条操作记录）                                                | 豁免         |
| `card_generate` | 生成卡密（按**该卡类型**的额度/单价扣减）                                                  | 需要         |
| `card_list`     | 我生成的卡密（按 `agent_id` 强制过滤）                                                | 豁免         |
| `card_export`   | 导出我名下的卡密（txt/csv）                                                        | 需要         |
| `card_void`     | 作废我名下**未使用**的卡密                                                          | 需要         |
| `batch_list`    | 我的批次                                                                     | 豁免         |
| `recharge`      | 兑换充值卡密（余额充值 / 张数额度），`code` 为卡密                                           | 需要         |
| `password`      | 修改自己的登录密码                                                                | 需要         |

### 4.3 代理商注册（激活码）

代理商有两种来源：**主管理员在后台创建**，或**凭「代理商激活码」自助注册**。

- 主后台「业务管理 → 代理商激活码」生成激活码，码上写死了这些规格：  
  **每种卡类型激活后进入的用户组**（可按类型分别指定）、**设备上限**、是否允许作废、控量模式、  
  **代理生成卡密的固定前缀**、**注册后赠予余额**、**每种卡类型的额度/单价**、可用注册次数（`max_uses`，1 = 一次性）、有效期
- 注册接口把上述规格一次性复制到 `nb_agents` 与 `nb_agent_types`，  
  **代理商登录后无法自改** —— 因此「卡密激活后进入哪个用户组」始终由主管理员决定
- 用户组取值优先级：**该卡类型的 `group_id`（`nb_agent_types.group_id`）→ 代理兜底组（`nb_agents.group_id`）→ `0`（不换组）**
- **卡密前缀**：激活码 / 代理档案上的 `card_prefix` 非空时，代理生成卡密一律强制使用此前缀  
  （`card_generate` 忽略代理提交的同名字段）；为空则不限制，仍由代理在 `/agent/` 自行填写
- **注册后赠予余额**（`nb_agent_codes.init_balance`，单位：分）：仅控量模式为 **2（余额计费）** 时生效，  
  注册时写入 `nb_agents.balance`，代理到手即可发货；其它模式一律写 0
- 注册成功后 `nb_agents.reg_code` 记录所用激活码，便于对账；主后台激活码列表直接显示「该码注册了哪些代理」
- 同一激活码并发注册用**带条件的 UPDATE** 消费次数（`used_count < max_uses`），不会超发；  
  注册过程任一步失败会把次数退回
- 失败码：`2004` 账号已被占用、`2005` 激活码无效/已停用、`2006` 可用次数已用完、`2007` 激活码已过期、  
  `6003` 后台已关闭自助注册
- 限流：同一 IP 10 分钟最多 5 次注册尝试

### 4.4 代理商充值卡密（续费 / 加量）

与「注册激活码」分工不同：**激活码用于开户**，**充值卡密用于给已有代理续费 / 加量**。  
主后台「业务管理 → 代理商激活码 → 充值卡密」页签批量生成，代理商在 `/agent/` → 「充值卡密」自助兑换。

| kind | 名称   | 兑换效果                                                                        | 适用模式   |
| ---- | ---- | --------------------------------------------------------------------------- | ------ |
| 1    | 余额充值 | `nb_agents.balance += amount`（分）                                            | 2 余额计费 |
| 2    | 张数额度 | 对**一种或多种**卡类型分别 `nb_agent_types.quota_total += quota`（`quota = -1` 表示设为不限量） | 1 张数额度 |

- 表 `nb_agent_recharge_codes`：`code` / `kind` / `amount`（分）/ `card_type` / `quota` /  
  **`quota_map`（多卡类型张数 JSON，如 `{"1":10,"2":-1}`）** /  
  `status` / `max_uses` / `used_count` / `expire_at` / `last_agent_id` / `last_used_at`
- **多卡类型张数**：`kind=2` 时优先读 `quota_map`，为空则回退旧字段 `card_type` + `quota`（单类型，兼容存量卡密）；  
  单类型卡密生成时也会顺带回写 `card_type` / `quota`。取值规则：`0` = 该类型不充值（不入库），  
  `-1` = 该类型设为「不限量」，小于 `-1` 收敛为 `-1`，非法卡类型直接丢弃；至少要有一种类型有效，否则拒绝生成
- 生成参数：`count`（≤200）、`prefix`（默认 `RCG`，形如 `RCG-XXXX-XXXX`）、`kind`、  
  `amount_yuan`（kind=1）、**`quota_map`（kind=2，多类型）** 或 `card_type` + `quota`（kind=2，单类型旧写法）、  
  `max_uses`（>1 可当通用充值码）、`expire_days`（0 = 永久）、`remark`
- 兑换：`AgentRecharge::redeem()` 在**同一事务**内先带条件 UPDATE 扣次数  
  （`status=1 AND used_count < max_uses`，防并发重复兑换），再按 `quota_map` 逐项入账，  
  最后写代理日志（`action=recharge`，明细形如「时长卡 +10 张、点数卡 设为不限量」）
- 已是「不限量」的类型再充值仍保持不限量（`IF(quota_total < 0, -1, ...)`）
- 已兑换过的卡密**不可删除**，只能停用（保留追溯）；停用 / 过期 / 次数用尽的卡密兑换时会被拒绝
- 代理端在「充值卡密」页可看到当前余额与各类型额度，兑换成功后即时刷新
- 升级脚本：`php install/migrate_agent_recharge.php`（建表）+ `php install/migrate_recharge_quota_map.php`（多类型，均可重复执行）

### 4.5 控量模式（`nb_agents.charge_mode`）与按卡类型计费

**额度与单价按卡类型分别配置**（`nb_agent_types`：`agent_id + card_type` 唯一），  
`nb_agents.quota_total / unit_price` 为历史字段，自 v1.1 起不再参与计费。

| 值 | 名称   | 生成时的扣减规则                                                               |
| - | ---- | ---------------------------------------------------------------------- |
| 1 | 张数额度 | 扣该卡类型的 `quota_used`；该类型 `quota_total = -1` 表示不限，`enabled = 0` 表示不开放此类型 |
| 2 | 余额计费 | 扣 `balance`（分）：**该卡类型的单价** × 张数；类型单价为 0 时回落到「系统设置 → 代理商默认单价」           |
| 3 | 不限量  | 不扣减，仅记录归属与日志（仍要求该类型已开放）                                                |

- 额度/余额的扣减使用**带条件的 UPDATE** 保证并发安全；生成失败（如卡密去重后为 0 张）会自动补偿退回
- 代理**不能**自定义「设备上限」「激活用户组（按卡类型）」「卡密固定前缀」与「各类型额度/单价」——统一取代理档案（或激活码预设），  
  避免越权发放高权限卡密
- 余额计费下 `Agent::typeList()` 会为每种卡类型给出 **`can_make` / `can_make_text`**  
  （= `balance ÷ 该类型单价`，不足一张按 0 计），代理端「发货规格」据此显示每种卡还能生成多少张
- 主后台「编辑代理商」与「生成激活码」表单都提供按卡类型的**额度/单价/激活用户组**矩阵；  
  充值走 `agent_save` 的 `op=grant`，提交 `types: {"4": 2, "1": 1}` 表示给永久卡 +2 张、时长卡 +1 张

### 4.6 生成卡密请求示例

```json
{
  "count": 10,
  "type": 1,
  "duration": 30,
  "prefix": "VIP",
  "expire_days": 0,
  "name": "双十一批次",
  "remark": ""
}
```

- `type`：1 时长卡 / 2 点数卡 / 3 次数卡 / 4 永久卡（必须为该代理**已开放**的类型）
- `duration`：时长卡填**天数**（服务端换算为秒），其他类型填原始数值，永久卡可传 0
- 单次最多 500 张；生成限流：每分钟 10 次、每天 100 次

响应中的 `data.codes` 只返回前 50 条预览，`data.cost` 会说明本次扣的是哪个类型的额度或多少钱。

### 4.7 归属性

代理生成的卡密写入 `nb_cards.agent_id`，批次写入 `nb_card_batches.agent_id`：

- 主后台「卡密管理」可用 `agent_id` 筛选来源（`''` 全部 / `0` 官方直发 / `>0` 指定代理）
- `card_export` 同样支持 `agent_id` 参数
- 主后台删除代理商时，若其名下有卡密会被拒绝（避免归属变成野指针），需改为「禁用」
- 删除激活码时，若该码已被注册使用同样会被拒绝，需改为「停用」

### 4.8 相关系统设置

| 设置项                     | 说明                                             |
| ----------------------- | ---------------------------------------------- |
| `agent_enable`          | 是否开放代理商后台（关闭后 `/agent/` 与接口全部拒绝）               |
| `agent_register_enable` | 是否开放代理商**自助注册**（关闭后只能由管理员在后台创建账号）              |
| `agent_unit_price`      | 代理商默认单价（元/张），某卡类型未单独定价时使用；为 0 时「余额计费」模式拒绝生成该类型 |
| `agent_entry_key`       | 可选入口密钥，填写后需先访问 `/agent/?k=密钥`，否则返回仿真 404       |

---

## 五、发卡网前台接口（`/shop/`）

发卡网前台为独立入口，无需后台会话。接口入口 `/shop/api.php?action=`，  
买家会话为独立 Cookie（HttpOnly + SameSite=Lax）；登录方式跟随后台 `login_methods`  
配置，登录/注册/找回均带图形验证码与 IP 限流。

| action           | 说明                                  | 认证        |
| ---------------- | ----------------------------------- | --------- |
| `info`           | 商店信息：商品、分类、装修（标题/横幅/主题色/公告）、登录方式    | 公开        |
| `order`          | 创建订单：卡规格四元组精确匹配库存，内置（返回支付参数）或人工收款模式 | 公开（游客可下单） |
| `auth`           | 登录 / 注册 / 卡密直登（`loginBy` 自动建号激活）    | 公开        |
| `captcha`        | 图形验证码                               | 公开        |
| `reclaim_lookup` | 找回密码第一步：凭激活码查询账号（限流每小时 5 次）         | 公开        |
| `reclaim_save`   | 找回密码第二步：验证码校验通过后重置密码                | 公开        |
| `account`        | 我的账号：购买的卡密、会员时长                     | 登录        |
| `activate`       | 激活卡密（委托绑定到当前账号）                     | 登录        |
| `query`          | 订单查询：凭不可枚举订单号（miss 封禁 40 次）或「凭证+密码」 | 公开        |

支付回调 `/shop/notify.php`（易支付 POST）：MD5 验签 + 金额比对 + 幂等，  
自动发货走事务 `FOR UPDATE` 取未使用卡密，缺货自动转人工。

---

## 六、安全说明

1. **IV 构造与防重放**：IV = `SHA256(nc‖ns)` 前 4 字节前缀 + 8 字节大端 `seq`，同一会话内随 seq 严格递增保证不重复；服务端以条件 UPDATE（`seq < ?`）原子递增会话序号，抓包重发同一报文（seq 不前进）直接拒绝（5004）。
2. **签名保护范围**：请求 MAC 覆盖 `sid|seq|t|SHA256(data)`（HMAC-SHA256，密钥为握手派生的 sk_mac）；服务端响应另有 ES256 签名（覆盖 `data|sid`），伪造/篡改响应在客户端内置公钥验签即失败。
3. **时间同步**：客户端需保证系统时间准确，与服务端时差超过 `time_window` 会被拒绝（默认 300 秒）。
4. **限流策略**：
   - 单 IP 每分钟 120 次通用接口调用
   - 单 IP 每分钟 10 次登录尝试
   - 单 IP 每分钟 20 次激活尝试
   - 单账号每日 3 次设备解绑
5. **密码存储**：bcrypt（cost 10），兼容历史 md5 哈希自动升级。  
   管理员密码要求至少 10 位且同时包含字母、数字、大写字母与符号（v2.65.30 起强制）；改密后该账号所有旧会话立即失效。
6. **管理端防护**：
   - **CSRF**：写操作校验令牌（`X-CSRF`），只读接口豁免
   - **入口密钥**：可配置 `admin.entry_key`，访问后台需带 `?k=密钥`，错误时返回 404 不暴露后台
   - **IP 白名单**：可限制后台访问来源 IP
   - **二次密码确认**：删除类敏感操作需重新输入管理密码
   - **登录防爆破**：连续失败 N 次锁定账号（默认 5 次 / 15 分钟），失败尝试记录来源 IP
   - **审计追溯**：所有写操作记录操作人、IP、UA、时间及字段级变更前后值
   - **会话隔离**：管理端令牌存独立表（`nb_admin_sessions`），与用户会话互不影响
   - **代理商隔离**：代理商使用 `nb_agents` + `nb_agent_sessions` + `NBAGSID` 会话，  
     仅能操作 `agent_id` 归属自己的卡密（作废、导出均做强校验），且无法自定义设备上限、用户组与各类型额度单价
   - **激活码即开户凭证**：代理自助注册必须持有主管理员生成的激活码，  
     注册次数用条件 UPDATE 消费（不会超发），已注册过的码禁止删除（保留追溯）
   - **密钥不落地**：3.1 无任何静态对称密钥，会话密钥由 ECDH 握手即态派生、仅存于服务端 `nb_hsessions`（客户端同等保存在内存），会话过期即失效
7. **跨域**：默认关闭（`cors_origins` 为空数组）。需要跨域时填具体域名，**不要用 `*`**，  
   因为管理端使用凭据（cookie + token），通配来源会导致凭据泄露。
8. **前端源码保护**：
   - 界面逻辑按模块拆分到 `admin/assets/js/`，不内联在页面里
   - 接口地址、会话 key、CSRF 令牌由 PHP 动态注入，不硬编码在静态 JS 中
   - 敏感配置（密钥、盐值）从不下发到前端
9. **生产环境建议**：
   - 使用 HTTPS，避免密钥在传输中暴露
   - 将 `enforce_crypto` 保持为 `true`
   - 修改后台目录名（`mv admin manage_xxxx`）
   - 配置后台入口密钥（`admin.entry_key`）
   - 开启管理端 IP 白名单
   - 关闭 `debug` 与 `log.record_raw`
   - 删除 `install` 目录
10. **离线宽限票据**：
    - 用 **ECDSA P-256（ES256）** 非对称签名，服务端只持有**私钥**，客户端只拿到**公钥**——  
      公钥泄露无法伪造票据，与「对称密钥下发到客户端」的方案有本质区别。
    - 私钥落盘在 `config/grace_keys.php`，位于部署模板已 deny 的 `config/` 目录内；  
      切勿把该文件泄露给客户端或提交到公开仓库。
    - 票面绑定 `user_id + 机器码摘要 + 会话令牌摘要 + 会员到期`，换机器 / 换会话 / 篡改任一字段均验签失败。
    - 宽限只延长**离线**运行时间，联网后立即以服务端判定为准，不会成为「永久绕过封号」的后门。
    - 需要**立即使所有已下发票据失效**时：删除 `config/grace_keys.php`（服务端会自动重新生成密钥），  
      或把 `grace.seconds` 设为 `0` 关闭该能力。
11. **缓存与聚合**：
    - 缓存只存**派生数据与计数**（在线缓冲、心跳缓冲、统计缓冲、热点配置），不缓存明文密码等敏感字段。
    - Redis 未设密码时只监听内网 / 本机（`127.0.0.1`），切勿把无密码 Redis 暴露到公网。
    - 文件缓存落在 `logs/cache`，依赖部署模板对 `logs/` 目录的整目录 deny；  
      若自行改动缓存目录，务必同步补充 deny 规则。
    - 心跳缓冲 / 统计缓冲**不落业务数据**，即使缓存整体丢失，也只会造成少量在线时长统计偏差，  
      不影响登录、激活、计费等核心链路（每个请求的判定仍实时读库）。

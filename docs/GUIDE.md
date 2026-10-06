# Nebula 网络验证

一套基于 PHP + MySQL 的网络验证（授权）系统后端，提供客户端 API 与管理后台 API。官方提供 **C++、Python 与 C#** 三套协议同规格的开箱即用 SDK（**可在仓库发行处 Releases 下载**），易语言等其他语言依据接口文档直接对接。本文档为完整使用指南。

## 📚 文档中心

全部功能文档统一放在 **`docs/`** 文件夹（三套 SDK 的接入文档随各自 SDK 分发包提供），点标题跳转：

| 文档                                                     | 说明                                                                      |
| ------------------------------------------------------ | ----------------------------------------------------------------------- |
| [docs/ARCHITECTURE.md](ARCHITECTURE.md)           | 架构设计文档（分层架构 / 请求生命周期 / 关键链路时序图 / 安全设计对照）                                |
| [docs/API.md](API.md)                             | 客户端 API 完整接口文档（协议、加签、各接口字段、离线宽限协议）                                      |
| [docs/API\_RAW\_EXAMPLES.md](API_RAW_EXAMPLES.md) | 请求 / 响应原始报文示例（手写协议对接逐字节参照）                                              |
| sdk/SDK.md（随 SDK 分发包提供）                             | C++ SDK 接入文档（初始化 / 登录 / 心跳 / 内置提示 / 完整性自校验）                             |
| sdk/SDK_PROTECTION.md（随 SDK 分发包提供）                  | C++ SDK 客户端加固指南（壳标记 / 代码混淆 / 反调试 / 反虚拟机，**默认关闭，按需开启**）                  |
| sdk-py/README.md（随 SDK 分发包提供）                    | Python SDK 接入文档（协议同规格 / 内置 Pygame 登录界面 / 完整性自校验 / 自动更新 / 功能密钥 NF1）      |
| sdk-c#/NebulaSDK.md（随 SDK 分发包提供）                    | C# SDK 接入文档（.NET 10 / WinForms；登录 / 心跳 / 离线宽限 / 功能密钥 NF1 / 运行时防护与字符串混淆） |
| [docs/TEMPLATE.md](TEMPLATE.md)                   | 界面模板开发文档（目录规范 / 小游戏 / 交互音效 / 布局与自定义区块）                                  |

> 界面模板使用与后台可视化编辑（换肤 / 布局与自定义区块 / 小游戏参数）的操作入口在管理后台  
> 「官网运营 → 界面模板 / 官网内容 / 小游戏与排行榜」，开发规范见 docs/TEMPLATE.md。

## 功能一览

| 模块         | 说明                                                                                                                         |
| ---------- | -------------------------------------------------------------------------------------------------------------------------- |
| 🔐 登录验证    | 账号密码登录，bcrypt 加密，登录失败锁定，防爆破                                                                                                |
| 💓 心跳机制    | 客户端定时上报，服务端实时下发剩余时长、踢下线指令；高频写入经缓存聚合后批量落库                                                                                   |
| 🛰️ 离线宽限   | 服务端签发 ECDSA 离线票据，客户端本地验签，服务器抖动时允许短暂离线运行                                                                                    |
| ⚡ 缓存层      | 统一缓存抽象（扩展 Redis / 内置 RESP 套接字 / 文件三级降级），热点数据与统计缓冲外置                                                                        |
| 🎫 激活码     | 时长卡 / 点数卡 / 次数卡 / 永久卡，批量生成、导出、作废                                                                                           |
| 🖥️ 设备绑定   | 机器码绑定，设备数上限，自动绑定，用户自助解绑，管理端强制解绑；可选多硬件加权指纹（漂移容忍 / 模拟器虚拟机识别 / 一机多号）                                                          |
| 👤 用户管理    | 增删改查、封禁冻结、重置密码、强制下线、清空设备                                                                                                   |
| 🤝 代理商分销   | 独立后台 + 激活码自助注册，按卡类型配置额度/单价与激活用户组；充值卡密自助续费，卡密归属可对账                                                                          |
| 📢 公告系统    | 普通 / 弹窗 / 重要三级，支持定时生效                                                                                                      |
| 🔄 版本验证    | 最低版本强制更新，多渠道（stable/beta），更新包哈希校验                                                                                          |
| 📊 API 统计  | 按天 / 按接口 / 按 IP 聚合，调用量、失败率、平均耗时                                                                                            |
| 📉 数据大屏    | 实时在线数、激活曲线、代理销量排行、卡密类型分布，纯 SVG 零依赖图表，30s 自动刷新                                                                              |
| 📈 留存复购    | D1/D3/D7 队列留存、用户与代理复购率、DAU/WAU/MAU 活跃分层、充值趋势                                                                               |
| 📝 日志风控    | 全量操作日志，异常 IP 识别，请求限流，单卡尝试限流与卡密枚举防护                                                                                         |
| 👥 用户组     | 分组管理，差异化设备数上限；可按卡类型指定激活后归属                                                                                                 |
| 🛒 发卡商城    | 内置发卡网 `/shop/`（易支付 / 码支付 / V免签自动发货 / 人工收款 / 微信支付官方 / 微信JSAPI / 支付宝官方），商品化发卡（系统卡密 / 外部卡密池导入）、6 种商品展示样式、分类页签、订单批量处理；支持跳转外链模式 |
| 🌐 官网门户    | `/web/` 独立官网：注册登录 / 激活 / 设备管理 / 留言板 / 用户反馈 / 价格套餐 / 购买商家 / 效果展示，文案与主题色、背景图后台可配                                             |
| 🧩 多软件分站   | 多软件各自独立官网（`?app=` 识别），分站文案 / Logo / 主题色 / 背景图 / 模板可单独覆盖总站；总站白页模式严格分流                                                       |
| 🎨 界面模板    | 官网与发卡网各内置 7 套 UI 模板（小清新 / 极简留白 / 樱花 / 午夜蓝金 / 素雅纸感 / 动漫霓虹 / 可爱马卡龙），一键换肤，优先于主题色                                              |
| 🛡️ IP 黑名单 | 单 IP / CIDR 段（IPv4/IPv6）黑名单，命中后全站所有页面与接口以自定义错误页拦截                                                                          |
| 🗂️ 文件管理   | 文件完整性基准校验（sha256 全站比对）+ Webshell 特征挂马扫描 + 受保护文件查看/删除                                                                       |
| 🔒 通信加密    | Nebula 3.1：ECDH P-256 会话握手 + AES-256-GCM 信封 + seq 防重放（客户端零静态对称机密）                                                       |
| 📮 响应防伪造   | 服务端私钥对每条响应签名（ES256），客户端内置公钥验签，伪造服务器在握手阶段即被识别                                                                    |
| 🗝️ 功能密钥   | 软件级 Feature Key 仅登录成功响应下发；NF1 加密数据包（AES-256-CBC + HMAC，encrypt-then-MAC）随程序分发，patch 掉登录判定也解不开核心数据                          |
| 🔄 协议升级    | 3.0 静态密钥信封已完全移除；会话失效（5002/5004）SDK 自动重握手，密钥不再需要人工轮换                                                                        |
| 🏢 多租户     | 软件归属代理商（owner_agent_id），管理员可绑定为租户管理员（agent_id）——总后台仅可见归属软件及其用户/卡密/设备数据，越权写操作直接拒绝，默认拒绝式隔离                                   |
| 🩺 安全审计    | 每日自动巡检（cron）：暴力破解嫌疑（同 IP）、撞库嫌疑（同账号）、密钥重置追踪、代理商卡密突增，异常写报告文件并记日志                                                             |
| 📦 在线更新    | 对接 update-system 版本服务器，后台一键自动下载、校验、备份并安装更新包；支持强制更新封锁                                                                       |

---

## 目录结构

```
Nebula网络验证/
├── api/                    客户端 API
│   ├── index.php           统一入口（路由 + 加密解析 + 限流）
│   └── handlers/           各接口实现
│       ├── init.php        初始化
│       ├── register.php    注册
│       ├── login.php       登录
│       ├── heartbeat.php   心跳
│       ├── activate.php    激活卡密
│       ├── unbind.php      解绑设备
│       ├── devices.php     设备列表
│       ├── userinfo.php    用户信息
│       ├── notice.php      公告
│       ├── version.php     版本校验
│       ├── online.php      在线人数（公开接口）
│       └── logout.php      退出
├── admin/                  管理后台
│   ├── home.php            页面入口（输出 HTML 骨架 + 注入运行时参数）
│   ├── index.php           API 入口（路由 + 认证 + CSRF + 限流）
│   ├── AdminAuth.php       管理员认证类
│   ├── assets/             前端资源（按模块拆分）
│   │   ├── css/main.css    样式表
│   │   └── js/
│   │       ├── app.js          应用入口（登录/启动）
│   │       ├── core/
│   │       │   ├── api.js      请求封装（含 CSRF、下载）
│   │       │   ├── state.js    全局状态与运行时配置
│   │       │   ├── ui.js       提示/模态框/分页/批量选择
│   │       │   ├── util.js     通用工具函数
│   │       │   ├── chart.js    纯 SVG 图表（面积/折线/排行/环形，零依赖）
│   │       │   └── router.js   菜单/路由/页面注册
│   │       └── pages/          各功能页面（一页一文件）
│   │           ├── dashboard.js  数据概览
│   │           ├── stat.js       API 统计
│   │           ├── user.js       用户管理（批量/导入导出）
│   │           ├── card.js       卡密管理（批量/编辑/详情/关联商品生成）
│   │           ├── batch.js      卡密批次
│   │           ├── device.js     设备管理（批量/拉黑）
│   │           ├── device_ban.js 设备拉黑名单
│   │           ├── session.js    在线会话（批量踢出）
│   │           ├── agent.js      代理商管理（按类型额度/单价、启停、充值）
│   │           ├── agent_code.js 代理商激活码（生成/编辑/启停，含按类型预设）
│   │           ├── software.js   软件管理（多软件分站）
│   │           ├── notice.js     公告管理
│   │           ├── version.js    版本管理
│   │           ├── group.js      用户组
│   │           ├── portal_web.js 官网内容（总站 + 分软件覆盖：文案/主题色/背景/模板）
│   │           ├── message.js    留言板审核
│   │           ├── feedback.js   用户反馈处理
│   │           ├── plan.js       价格套餐 / 发卡商品陈列
│   │           ├── screenshot.js 客户端截图
│   │           ├── seller.js     购买商家
│   │           ├── shop.js       发卡订单（批量发货/关闭/删除）
│   │           ├── shop_goods.js 发卡商品（分类归属/外部卡密导入/批量）
│   │           ├── shop_setting.js 发卡网配置（开关/支付/外观/界面模板）
│   │           ├── files.js      文件管理（完整性校验/Webshell 扫描）
│   │           ├── log.js        操作日志
│   │           ├── audit.js      审计日志（变更明细）
│   │           ├── setting.js    系统设置（页签式）
│   │           ├── system_update.js 系统更新（在线版本检查 + 一键更新）
│   │           └── profile.js    个人中心
│   └── handlers/           各管理接口
├── agent/                  代理商后台（独立入口，与 admin/ 互不可见）
│   ├── index.php           页面入口（登录页 + 激活码注册页 + 主界面骨架）
│   ├── api.php             API 入口（路由 + 认证 + CSRF + 限流）
│   ├── inc/session.php     会话引导（NBAGSID，页面与接口共用）
│   ├── assets/             前端资源（css/main.css + js/agent.js，自包含）
│   └── handlers/           各代理接口（登录/注册/生成/卡密/批次/改密）
├── web/                    官网门户（注册/激活/留言板/反馈/套餐/截图）
│   ├── index.php           官网首页（多软件 ?app= 分站识别 + 总站白页）
│   ├── api.php             官网 API 入口
│   ├── inc/portal.php      引导文件（站点信息 / webSetting 分软件覆盖取值）
│   └── assets/             前端资源（site.css 深空样式 + ui-templates.css 界面模板）
├── shop/                   发卡网前台（内置商店 / 外链 302 跳转）
│   ├── index.php           商店页（6 种商品展示样式 + 界面模板换肤）
│   ├── api.php             商店 API 入口（下单/查询/登录）
│   ├── notify.php          支付异步回调（易支付等）
│   └── assets/             前端资源（shop.css + ui-templates.css 界面模板）
├── lib/                    核心库
│   ├── bootstrap.php       引导文件（所有入口必须先加载）
│   ├── Config.php          配置读取
│   ├── Database.php        PDO 封装
│   ├── Crypto.php          AES 加解密 + HMAC 签名 + 防重放
│   ├── Response.php        统一响应
│   ├── Logger.php          业务日志与统计（含统计缓冲）
│   ├── Cache.php           缓存层（Redis / 内置 RESP / 文件三级降级）
│   ├── Heartbeat.php       心跳聚合（缓冲 + 批量落库）
│   ├── Grace.php           离线宽限票据（ECDSA ES256 签发/验签）
│   ├── Audit.php           管理端审计日志（变更前后对比）
│   ├── SecReport.php       每日安全审计报告（暴力破解 / 撞库 / 密钥重置 / 卡密突增巡检）
│   ├── Tenant.php          多租户数据隔离（租户管理员仅见归属软件数据，默认拒绝）
│   ├── RateLimit.php       限流器
│   ├── Session.php         会话管理
│   ├── Device.php          设备绑定
│   ├── Auth.php            账号认证
│   ├── Card.php            激活码
│   ├── Agent.php           代理商（认证/计费/统计）
│   ├── LoginMethod.php     登录方式规格（客户端与官网同源）
│   ├── Version.php         版本取值（init/version/官网下载同源）
│   ├── Setting.php         系统设置
│   ├── AdminPermission.php RBAC 权限表（未登记即拒绝）
│   ├── RuntimePolicy.php · RuntimeGuard.php     运行时防护策略下发 / 心跳遥测合并
│   ├── RuntimeRiskEngine.php · RuntimeEventService.php  事件风险评估 / 上报入库与自动处置
│   ├── Shop.php · ShopAuth.php  发卡商城与前台鉴权
│   ├── Pay.php             支付渠道对接
│   ├── Util.php            工具函数
│   └── ……                   完整类库清单见 docs/ARCHITECTURE.md 第 10 节
├── config/
│   └── config.php          全局配置（数据库、密钥、策略、后台保护）
├── install/
│   ├── install.php         网页安装向导
│   ├── install.lock        安装锁（安装后生成，存在则禁止重装）
│   ├── schema.sql          数据库结构（41 张表，全新安装一键建库）
│   ├── migrate.php         统一迁移执行器（schema_version 版本登记，status / run / baseline）
│   ├── _cli_guard.php      CLI 守卫（install/ 下脚本仅限命令行执行）
│   ├── clear_logs.php      日志清理工具（--dry-run 预演 / --yes 执行）
│   └── nginx.conf.example  Nginx 部署配置示例
├── sdk/ · sdk-py/ · sdk-c#/      三套 SDK 分发包（C++ / Python / C#）
│                              ⚠ 源码不入库，发行版随仓库 Releases 提供（接入文档随包），
│                              接入文档随包内提供（SDK.md / SDK_PROTECTION.md / README.md / NebulaSDK.md）
├── docs/
│   ├── ARCHITECTURE.md     架构设计文档（分层架构 / 关键链路时序图 / 安全设计对照）
│   ├── API.md              完整接口文档
│   ├── API_RAW_EXAMPLES.md 请求/响应原始报文示例
│   └── TEMPLATE.md         界面模板开发文档
├── logs/                   日志目录
├── cron.php                定时清理任务
└── .htaccess               安全规则与路由重写
```

> `install/migrate_*.php` 为按版本拆分的升级迁移脚本，**随「更新包」分发、不随「空白安装包」分发**；  
> 全新安装由 `schema.sql` 直接建库到基线版本，升级统一走 `php install/migrate.php run`。

---

## 环境要求

| 项目      | 要求                                                 |
| ------- | -------------------------------------------------- |
| PHP     | ≥ 8.0（`str_contains` / `str_starts_with` 需 8.0）    |
| 扩展      | `pdo_mysql`、`openssl`、`json`、`mbstring`            |
| 数据库     | MySQL 5.7+ / MariaDB 10.3+                         |
| 缓存（可选）  | Redis 5.0+（无 `redis` 扩展也能用，见「缓存与心跳聚合」）；不部署则自动走文件缓存 |
| Web 服务器 | Apache（含 mod_rewrite）或 Nginx                       |

> `openssl` 扩展同时用于通信加密与**离线宽限票据签名**（ES256），必须启用。  
> 缓存与 Redis 均为**可选**：不部署时心跳 / 统计自动回落逐次写库，功能不受影响。

---

## 安装部署

### 方式一：网页安装向导（推荐）

1. 将整个项目上传到 Web 根目录
2. 确保 `config/` 与 `logs/` 目录**可写**
3. 浏览器访问 `http://你的域名/install/install.php`
4. 按向导填写数据库信息与管理员账号
5. 安装完成后**立即删除 `install` 目录**
6. 记录向导第 3 步显示的响应签名公钥（客户端 `kRespSignPubKey` 需要用到；3.1 起无静态对称密钥）

### 方式二：手动安装

1. 导入数据库结构：

```bash
mysql -u root -p < install/schema.sql
```

1. 编辑 `config/config.php`，填写数据库信息：

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'nebula_auth',
    'user' => 'root',
    'pass' => '你的密码',
],
```

1. 通信安全（可选）：Nebula 3.1 采用 **ECDH 会话握手 + AES-256-GCM 信封**，客户端零静态对称机密，**无需填写任何通信密钥**；按需配置 `security` 段（见下文「配置说明 → 通信安全」），如关闭 `enforce_crypto` 或调整明文白名单

1. 手动创建管理员（把 `<bcrypt哈希>` 换成实际值）：

```bash
php -r "echo password_hash('admin888', PASSWORD_BCRYPT), PHP_EOL;"
```

```sql
INSERT INTO nb_admins (username, password, nickname, role, status, created_at)
VALUES ('admin', '<bcrypt哈希>', 'admin', 1, 1, UNIX_TIMESTAMP());
```

1. 删除 `install` 目录

### 安装后自检

用浏览器打开后台首页，以管理员账号登录，逐页确认数据正常加载即可。  
如需排查，可查看 `logs/` 目录下的运行日志。

---

## 安全配置（重要）

后台涉及敏感操作，建议按下面几项加固。

### 1. 修改后台目录名

把 `admin/` 改成别的名字（如 `manage_x9k2/`），可大幅降低被扫描器命中的概率。

```bash
mv admin manage_x9k2
```

同时更新 `config/config.php` 中的 `admin.path`。

### 2. 启用入口密钥

配置后，访问后台页面必须带 `?k=密钥`，首次访问成功后写入 cookie，后续免带。

```php
// config/config.php
'admin' => [
    'entry_key' => '换成一串足够长的随机字符',
    // ...
],
```

生成方式：`php -r "echo bin2hex(random_bytes(24));"`

访问方式：`https://你的域名/admin/home.php?k=你的密钥`

> 密钥错误时返回 **404**，不暴露后台存在。

### 2.1 代理商后台（可选功能）

代理商使用**独立入口** `/agent/`。账号有两种来源：

1. 主管理员在「业务管理 → 代理商」直接创建；
2. 主管理员在「业务管理 → 代理商激活码」生成激活码，  
   代理商打开 `/agent/#reg` 填码自助注册。

代理商只能看到自己名下的卡密，无法触达后台任何其他数据。

**激活码决定了代理商的全部发货规格**（注册后代理不可自改）：

- **按卡类型分别指定激活后进入的用户组** —— 永久卡、时长卡、点数卡、次数卡可各进不同用户组  
  （如永久卡进 VIP 组、时长卡进普通组）；某类型不指定时使用激活码上的「兜底用户组」
- 卡密**设备上限**、是否允许代理作废卡密、控量模式
- **代理生成卡密的固定前缀** —— 填了以后该代理生成的卡密强制带此前缀（代理端不可修改），  
  便于按渠道 / 代理做码段识别与对账；留空则仍由代理在 `/agent/` 自行填写
- **每种卡类型的额度（配额模式）或单价（余额模式）** —— 永久卡可以单独定价、单独配额，时长卡/点数卡/次数卡同理
- **注册后赠予余额（仅余额计费模式）** —— 代理商注册到手即有此余额，可直接发货；  
  代理端「发货规格」会按余额显示每种卡还能生成多少张
- 可用注册次数（1 = 一次性；大于 1 时同一个码可注册多个代理）与激活码有效期

其他要点：

- 不需要代理商时，在「系统设置 → 代理商设置」把「开放代理商后台」关掉即可（接口一并拒绝）；  
  只想禁止自助注册就关「开放代理商自助注册」
- 与主后台共用目录名策略的思路，也可给代理商入口加一层密钥：  
  「系统设置 → 代理商设置 → 代理商入口密钥」填值后，必须先访问  
  `https://你的域名/agent/?k=密钥`，否则返回 404
- 另有独立的限流（单 IP 240 次/分钟；单代理生成 10 次/分钟、100 次/天；注册尝试 5 次/10 分钟）

### 3. 开启 IP 白名单（可选）

只允许特定 IP 访问后台，适合有固定出口 IP 的场景：

```php
'admin' => [
    'ip_whitelist_enable' => true,
    'ip_whitelist' => ['1.2.3.4', '::1'],
],
```

### 4. 敏感操作二次密码确认

删除用户、批量删除等操作会要求重新输入管理密码，防止令牌被盗后直接删库：

```php
'admin' => [
    'require_password_confirm' => true,   // 默认开启
],
```

### 5. 跨域白名单

同域部署时保持为空数组（默认），完全关闭 CORS：

```php
'security' => [
    'cors_origins' => [],   // 需要跨域时填具体域名，不要用 '*'
],
```

### 6. 修改默认密码

首次登录后立刻在「个人中心」修改密码。新密码要求：**至少 10 位，同时包含字母、数字，且必须含大写字母与符号**（v2.65.30 起强制）。

### 内建的安全机制

| 机制      | 说明                                                   |
| ------- | ---------------------------------------------------- |
| CSRF 防护 | 所有写操作校验令牌，只读接口豁免                                     |
| 登录防爆破   | 连续失败 N 次锁定账号（可配），失败日志记录来源 IP                         |
| 会话隔离    | 管理端令牌存独立表，改密后所有旧会话立即失效                               |
| 接口限流    | 按 IP 限制请求频率，防止刷接口                                    |
| 审计追溯    | 所有写操作记录操作人、IP、时间、字段变更前后值                             |
| 输出转义    | 前端所有数据渲染前做 HTML 转义，防 XSS                             |
| 安全响应头   | `nosniff` / `X-Frame-Options` / `Referrer-Policy`    |
| 密钥不落地   | 3.1 无静态对称密钥：会话密钥由 ECDH 握手即态派生，仅存于服务端 `nb_hsessions`，会话过期即失效 |
| 离线票据防伪  | 离线宽限票据由服务端私钥签名，客户端公钥验签；票面绑定账号 / 机器码 / 会话 / 有效期，篡改即失效 |

---

### Nginx 配置参考

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /www/nebula;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 客户端 API 美化路由
    location ~ ^/api/([a-z_]+)$ {
        rewrite ^/api/([a-z_]+)$ /api/index.php?action=$1 last;
    }

    # 管理 API 美化路由
    location ~ ^/admin/([a-z_]+)$ {
        rewrite ^/admin/([a-z_]+)$ /admin/index.php?action=$1 last;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 保护敏感目录
    location ~ ^/(config|lib|logs|install)/ {
        deny all;
    }
    location ~ \.(sql|log|lock|md)$ {
        deny all;
    }
}
```

---

## 快速验证

安装完成后，可用以下命令测试接口是否正常：

```bash
# 1. 测试公开接口（明文白名单接口，无需加密；init 自 3.1 起不再明文允许）
curl "http://127.0.0.1/api/index.php?action=online"

# 2. 测试管理端登录
curl -X POST "http://127.0.0.1/admin/index.php?action=login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin888"}'

# 3. 用返回的 token 查看统计
curl -X POST "http://127.0.0.1/admin/index.php?action=dashboard" \
  -H "X-Token: 上一步返回的token"
```

---

## 客户端对接

### 方式一：用现成 SDK（推荐）

三套 SDK（C++ / Python / C#）发行版可在**仓库发行处（Releases）下载**，含完整接入文档与示例工程。

C++ SDK 为 header-only：**把整个 `sdk/` 目录（聚合入口 `nebula_sdk.hpp` + `nebula/` 子模块）拖进项目即可**，无需预编译。  
📘 完整接入文档见包内 **`sdk/SDK.md`**（初始化/登录/心跳/内置提示/完整性自校验/离线宽限全说明）。  
🛡️ 需要防破解时再看包内 **`sdk/SDK_PROTECTION.md`**：同目录的  
`sdk/nebula_protect.hpp` 提供壳标记（VMProtect/Themida）、代码混淆、反调试/反虚拟机检测，  
**默认全部关闭**，加一行 `NEBULA_HARDEN=1` 即可全开。

```cpp
#include "nebula_sdk.hpp"

nebula::Client c(nebula::Client::Options{
    .api_url  = "http://你的服务器/api/index.php",
    .app_key  = "SW你的软件标识",              // 必填（后台软件管理获取）
    .response_sign_public_key = "<响应签名公钥 PEM>",  // 必填（后台系统设置→安全）
    .client_version = "1.0.1",
});
auto ir = c.init();                    // 初始化：自动握手建会话，下发心跳间隔、登录方式、版本策略
if (!c.enforceSelfIntegrity()) return 1;   // 完整性自校验：exe 被篡改 → SDK 弹窗，退出
if (!c.versionAlert())          return 1;  // 版本过期提示（强制更新中止 / 可选更新提醒）
c.maintainAlert();                         // 维护模式提示（全部内置弹窗，也可 setUiHandler 自定义）
auto lr = c.login("用户名", "密码");        // 登录（按服务器登录方式自动组装）
c.startHeartbeat(lr.token, [&c](int code, const std::string& msg,
                                 const nebula::HeartbeatInfo& hb) {
    if (hb.kick || hb.need_relogin) c.kickAlert(msg);   // 被踢/顶号 → SDK 弹窗
}, 0);                                     // 0 = 用 init 下发的心跳间隔
c.stopHeartbeat();
c.logout(lr.token);
```

- 内部已封装 Nebula 3.1 协议：ECDH P-256 会话握手、AES-256-GCM 信封、seq 防重放、ES256 响应验签（客户端零静态对称机密）
- 内置**提示体系**：版本过期 / 可选更新 / 维护中 / 被踢顶号 / 完整性校验失败全部 SDK 自动弹窗，  
  `setUiHandler(kind, msg)` 一行接管为自定义 UI；提示中文走宽字符窗口不乱码
- 内置**完整性自校验**：后台版本管理登记当前版本哈希/大小后，客户端启动自动比对自身 exe，被篡改即拒绝运行
- 内置**硬件指纹自动采集**：登录时自动上报 `device_fp`（主板/CPU/系统盘/BIOS/显卡取哈希、  
  主网卡裸 MAC 供虚拟机 OUI 识别，OEM 占位值自动过滤），**无需调用方处理**；  
  `device_name` 也自动取真实电脑主机名
- 仅 Windows（VS/MSVC 工具链），系统自带 `bcrypt` / `WinHTTP` 已 `#pragma comment` 自动链接，**不需要 OpenSSL 或 libcurl**
- 另提供 `activate` / `devices` / `unbindDevice` / `userinfo` / `getNotices` / `checkVersion` / `online` / `checkOffline`（离线票据本地校验）等接口

📘 其他语言：**C# SDK**（.NET 10 / WinForms，协议与 C++ 同规格）与 **Python SDK**（内置 Pygame 登录界面）均可在仓库发行处（Releases）下载，接入文档见包内 `NebulaSDK.md` / `README.md`。

### 方式二：手写协议

按 **Nebula 3.1** 协议实现（详见 `docs/API.md`）：

- **会话握手**：明文请求 `{ app_key, eph_pub, nc, ts, mhash }` → 服务端返回 `{ sid, eph_pub, ns, ts_s, sign }`，先验 ES256 签名（对象 `sid|eph_pub_S|ns|nc|ts_s`）
- **密钥派生**：ECDH 共享 X 坐标 → HKDF（salt=`nc‖ns`，info=`nebula31-enc` / `nebula31-mac`）派生加解密与 MAC 密钥；IV = `SHA256(nc‖ns)` 前 4 字节 ‖ seq 大端 8 字节
- **业务信封**：`{ proto:31, sid, seq, t, data, mac, app_key }`，`data = base64(IV[12] + AES-256-GCM 密文 + tag[16])`
- **响应**：先验 `sig`（对象 `data|sid`）再 GCM 解密；seq 单调递增防重放

### 对接要点

1. **响应签名公钥**：客户端内置服务端公钥（后台「系统设置 → 安全」），留空应拒绝连接（防伪造服务器）
2. **机器码**：建议采集主板序列号 + CPU ID + 硬盘序列号，做 SHA256 后取前 16-32 字节
3. **时间同步**：客户端系统时间需准确，与服务器时差不超过 300 秒
4. **心跳线程**：登录成功后启动独立线程，按 `heartbeat_interval` 上报，收到 `kick` 标志立即停止业务
5. **seq 递增**：会话内每个请求的 seq 必须单调递增，服务端拒绝旧序号
6. **会话失效重试**：收到 5002/5004 时重新握手再重试一次即可
7. **离线宽限**（可选）：保存 `login` / `heartbeat` 响应中的 `grace` 票据，连同握手前内置的公钥一起缓存；  
   网络失败时本地验签，未过期即可继续运行，成功后用新票据覆盖即可（详见 `docs/API.md` 的「离线宽限协议」）

---

## 定时任务

`cron.php` 负责清理过期会话、僵尸设备、过期日志与统计，并承担两项聚合落库工作：

1. **心跳缓冲落库**——把缓存中累积的 `devices.last_seen` 批量写回（必须跑在「清理僵尸设备」之前）
2. **统计缓冲落库**——把缓存中累积的 `api_stats` 调用计数批量落库
3. 其余：清理过期会话 / 僵尸设备 / 限流记录 / 日志 / API 统计 / nonce / 旧文件日志，写入在线快照表 `nb_online_stats`，缓存维护

> 没有配置 cron 也不会积压：心跳与统计都会在累计到阈值时「机会式」自动落库；  
> 但**强烈建议配置 cron**，以保证在线曲线完整、清理及时。

**Linux（crontab）**

```
* * * * * /usr/bin/php /path/to/yanzheng/cron.php >> /path/to/yanzheng/logs/cron.log 2>&1
```

**Windows（计划任务）**

- 程序：`C:\php\php.exe`
- 参数：`D:\xiangmu\yanzheng\cron.php`
- 触发器：每 1 分钟

**或用外部服务定时访问**

```
https://你的域名/cron.php?key=<security.cron_secret，或自动生成的 logs/cron_secret.txt>
```

---

## 数据大屏与留存复购

后台侧边栏新增两页：

- **📉 数据大屏**：实时在线数、今日激活/新增、在线曲线、代理销量排行、卡密类型分布、运行时状态（缓存驱动 / 心跳缓冲积压），默认 30s 自动刷新，可一键清理缓存
- **📈 留存复购**：D1 / D3 / D7 队列留存、用户与代理复购率、DAU / WAU / MAU 活跃分层、充值趋势

曲线数据来自 `nb_online_stats` 快照表——由 `cron.php` 每分钟写入一行；未配 cron 时曲线会有缺口，但实时数值仍准确。

---

## 配置说明

编辑 `config/config.php`：

### 数据库

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'nebula_auth',
    'user' => 'root',
    'pass' => '',
    'prefix' => 'nb_',      // 表前缀
],
```

### 缓存与心跳聚合

```php
'cache' => [
    // auto  = 扩展 redis → 内置 RESP 套接字 → 文件 → 关闭（推荐）
    // redis = 只用 Redis（连不上会继续降级到文件，不中断业务）
    // file  = 只用文件缓存（仅适合单机）
    // none  = 关闭缓存（心跳/统计自动回落逐次写库）
    'driver'      => 'auto',
    'prefix'      => 'nb:',
    'default_ttl' => 300,
    'redis' => [
        'host' => '127.0.0.1', 'port' => 6379,
        'password' => '', 'database' => 0,
        'timeout' => 1.0,              // 连接超时（秒）
        'reprobe_seconds' => 60,       // 连接失败负缓存（秒）
    ],
    // 文件缓存落在 logs/cache：部署模板已整目录 deny，无需改服务器配置
    'file' => ['dir' => __DIR__ . '/../logs/cache'],
],

'heartbeat' => [
    'aggregate'       => true,   // 心跳聚合总开关（缓存不可用时自动失效）
    'refresh_seconds' => 120,    // 同设备 last_seen 最少间隔多少秒才落库
    'flush_batch'     => 200,    // 单次最多刷多少台
    'flush_every'     => 30,     // 每多少次心跳机会式落库
    'buffer_ttl'      => 86400,  // 缓冲区保留时长（秒）
    'stale_seconds'   => 86400,  // 超过该时长未心跳的项丢弃
],
```

> - `heartbeat.refresh_seconds` **必须小于** `policy.heartbeat_timeout`，否则设备会被误判离线。
> - **多机部署必须用 Redis**（或共享存储），否则各机器缓存不共享，在线数与心跳缓冲会不一致。
> - 无 PHP `redis` 扩展也能用——内置 RESP 套接字客户端直连 Redis 协议，零依赖。

### 离线宽限期

```php
'grace' => [
    'enable'       => true,      // 总开关
    'seconds'      => 3600,      // 单次下发的离线宽限时长（秒），0 = 关闭
    'max_seconds'  => 7200,      // 硬上限（秒），0 = 不限制
    'clamp_to_vip' => true,      // 宽限截止被账号到期时间钳制（强烈建议 true）
    'clock_skew'   => 120,       // 客户端时钟允许偏差（秒）
    'key_file'     => __DIR__ . '/grace_keys.php',  // 私钥文件，可删除以轮换
    'openssl_config' => '',      // openssl.cnf 路径，留空自动探测
],
```

> - 私钥首次使用时**自动生成**到 `config/grace_keys.php`（Web 不可访问，切勿外泄）。
> - 删除该文件会**自动重新生成**一套密钥，此前签发的所有离线票据立即失效。
> - 客户端内置公钥（经 `init`/`login` 下发）本地验签，无需联网即可放行。
> - 票面绑定 `user_id + 机器码摘要 + 会话令牌摘要 + 会员到期时间 + 宽限截止`，任何一项被篡改都会验签失败。

### 通信安全

```php
'security' => [
    'enforce_crypto' => true,    // 是否强制加密（调试时可设 false）
    'time_window'    => 300,     // 时间戳容差（秒）
    'plain_whitelist'=> ['notice', 'version', 'online'],  // 允许明文的接口（init 自 3.1 起不再明文允许）
],
// 说明：3.1 无静态 aes_key / sign_salt；会话密钥由 ECDH 握手派生，
// 签名用服务端 ES256 私钥，客户端验签公钥经 init/login 下发。
```

### 业务策略

```php
'policy' => [
    'default_max_devices' => 1,      // 默认设备上限
    'heartbeat_interval'  => 60,     // 心跳间隔（秒）
    'heartbeat_timeout'   => 180,    // 离线判定（秒）
    'session_ttl'         => 3600,   // 会话有效期（秒）
    'rate_limit_per_min'  => 120,    // 单 IP 每分钟限流
    'login_attempt_per_min' => 10,   // 登录尝试限流
    'login_fail_threshold'  => 5,    // 失败几次锁定
    'login_lock_seconds'    => 900,  // 锁定时长（秒）
    'trial_seconds'         => 0,    // 未激活试用时长，0=关闭
],
```

### 版本控制

```php
'version' => [
    'min_client_version'    => '1.0.0',  // 低于此版本强制更新
    'latest_client_version' => '1.0.0',
    'force_update'          => false,
    'update_url'            => '',
    'update_note'           => '',
],
```

### 系统更新

后台「系统 → 系统更新」页面对接独立的 update-system 版本服务器，实现一键自动更新。

- **版本检查**：每次进入后台自动检查最新版本（6 小时缓存，可手动刷新）。
- **一键更新**：自动下载更新包 → SHA-256 校验 → 备份当前文件 → 解压覆盖 → 更新版本号。
- **强制更新**：当当前版本低于服务端设定的最低支持版本时，后台弹出不可关闭的封锁弹窗，必须更新后才能使用。
- **保护目录**：`config/`、`logs/`、`data/`、`uploads/` 等目录不会被更新覆盖。
- **更新服务地址**：后台「系统设置 → 基础设施」中可配置 `update_server`（**默认留空**，未配置时更新检查与一键更新不生效；需自行部署 update-system 版本服务器后填入地址）。

> 也可通过后台「版本管理」动态配置，优先级高于配置文件。

---

## 安全建议

| 项目         | 建议                                         |
| ---------- | ------------------------------------------ |
| 传输         | 生产环境务必启用 HTTPS                             |
| 加密         | 保持 `enforce_crypto = true`                 |
| 后台入口       | 修改 `config.php` 中 `admin.path`，改为不易猜测的名称   |
| IP 白名单     | 后台仅允许固定 IP 访问（`admin.ip_whitelist_enable`） |
| 调试开关       | 生产环境关闭 `debug` 和 `log.record_raw`          |
| install 目录 | 安装完成后立即删除                                  |
| 数据库        | 使用独立低权限账号，不要用 root                         |
| 备份         | 定期备份数据库与 `config/config.php`               |

---

## 常见问题

**Q: 返回「签名校验失败」**

确认响应签名公钥与服务端一致；若是请求 MAC 失败，检查会话密钥派生（HKDF info=`nebula31-enc`/`-mac`，salt=`nc‖ns`）；确认 MAC hex 为小写。

**Q: 返回「请求已过期」**

客户端系统时间不准，与服务器时差超过 `time_window`。同步系统时间，或适当调大该值。

**Q: 返回「重复请求」**

nonce 被重复使用。确保每次请求生成新的随机 nonce（≥8 位）。

**Q: 数据库连接失败**

检查 `config/config.php` 中的数据库配置；确认 PHP 已安装 `pdo_mysql` 扩展（`php -m | grep pdo_mysql`）。

**Q: 心跳接口返回 1002 / 4002**

令牌失效或设备被解绑。客户端应捕获 `need_relogin` 标志并回到登录界面。

**Q: 卡密生成很慢**

生成 10000 张约需数秒，属正常（每张都要做唯一性检查）。如需更快可增大卡密长度或减少段数。

## 发布新版本 / 更新包上架

版本发布链路：**改代码 → bump 版本号 → 写 CHANGELOG → 打包 → 上传远程版本服务器 → 推仓库 → SDK Release**。

> **版本节奏约定（2.65.35 起）**：MINOR（如 2.66.0）= 新功能 / 协议 / 安全架构级改动；
> PATCH（如 2.65.36）= bug / 安全补丁 / 小优化。**PATCH 永不抬高 `min_version`**（站点只弹普通更新提示）；
> 只有「协议 / 信封 / 密钥派生」类 MINOR 才抬 `min_version` 触发强制更。
> 完整决策表见 `.catpaw/skills/nebula-development/SKILL.md` §2.5。

> ⚠️ **发版前必检**：① 本次版本号必须**高于** `https://mmbr.serv00.net/api/version.php` 返回的 `latest_version`
>（远程不允许重复发布同一版本，上传即 400）；② `git log` 与 `CHANGELOG.md` 顶部逐条核对（提交/版本号/build 对齐）。
> CI 自动发版已内置版本预检，不满足直接失败。

### 1. 版本号与更新日志

- 修改 `lib/bootstrap.php` 的 `NB_VERSION`（+1），确保仓库空白包与本地开发站两份同步。
- 在 `CHANGELOG.md` 顶部追加对应条目（格式见现有条目）。

### 2. 制作更新包

```bash
python D:/phpstudy_pro/WWW/update-system/pack.py 2.65.34 \
    "D:/phpstudy_pro/WWW/yanzheng" \
    "D:/phpstudy_pro/WWW/update_2.65.34.zip"
```

自动生成 `manifest.json`（product=`nebula-verification`）并排除 `config/`、`storage/`、`install/install.php` 与
`*.lock`/`*.log`；产物含 SHA-256 校验值。

### 3. 上传远程版本更新服务器（API）

> ⚠️ 上传目标是**远程服务器 `https://mmbr.serv00.net`**（update-system 部署在根目录），不是本地。
> 发布用专用账号（超级管理员）：`xiaomihu` / `zxweq967423`。

```bash
curl -u xiaomihu:zxweq967423 \
     -F "update_file=@D:/phpstudy_pro/WWW/update_2.65.34.zip" \
     -F "release_notes=版本说明（可选，建议后台补填）" \
     https://mmbr.serv00.net/api/upload.php
```

- 成功返回 `{"code":0,"msg":"版本 X 已发布","data":{"download_url":"https://mmbr.serv00.net/releases/<v>/update.zip"}}`；
- 版本号已存在会拒绝重复发布；`manifest.json` 的 product 与服务器不一致也拒绝；
- 验证：`curl "https://mmbr.serv00.net/api/version.php?product=nebula-verification&version=当前版本&channel=stable"` 应返回新版本信息。

### 4. SDK 发行版（Release）

- 三语言 SDK 以 Release zip 分发：`Nebula-CPP-SDK-v2.65.34.zip` / `Nebula-CSharp-SDK-v2.65.34.zip` / `Nebula-Python-SDK-v2.65.34.zip`；
- 发布到 GitHub（Release 附件）与 Gitee（`attach_files` 接口上传附件）双平台，tag 形如 `v2.65.34-sdk-release`；
- 详见 `.catpaw/skills/nebula-development/SKILL.md` 的发布规则（唯一权威）。

## 许可

本项目仅供学习与自用，请勿用于非法用途。使用本系统进行软件授权时，请确保符合当地法律法规。

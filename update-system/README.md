# 在线验证系统 — 版本更新系统

> 📖 **安装教程**：请阅读 [INSTALL.md](./INSTALL.md)

## 概述

为在线验证系统提供统一的版本管理与在线更新能力，包括：

- 获取当前系统版本
- 自动检查官方最新版本
- 后台显示版本更新提示
- 查看版本更新日志
- 判断当前版本是否低于最低支持版本
- 管理员一键更新
- 更新前自动备份
- 自动执行数据库迁移
- 校验更新包完整性（SHA-256）
- 校验官方数字签名（Ed25519）
- 更新失败自动回滚
- 记录更新历史

## 版本号规范

采用 Semantic Versioning（SemVer）：`MAJOR.MINOR.PATCH`

| 类型 | 说明 | 示例 |
|------|------|------|
| MAJOR | 不兼容修改 | 1.9.0 → 2.0.0 |
| MINOR | 新增功能（兼容） | 1.2.0 → 1.3.0 |
| PATCH | Bug 修复 | 1.3.0 → 1.3.1 |

Build 编号格式：`YYYYMMDDNN`（如 `2026092101`），用于区分同一版本号下的构建。

## 目录结构

```
update-system/
├── version.php                    # 版本信息（统一入口）
├── config.example.php             # 配置示例
│
├── lib/
│   └── VersionManager.php          # 核心更新引擎
│
├── api/
│   ├── version.php                 # 版本检查 API（部署在版本服务器）
│   └── health.php                  # 健康检查 API
│
├── admin/
│   ├── update.php                  # 后台版本更新页面
│   └── handlers/
│       └── update.php              # 更新执行 Handler
│
├── install/
│   └── migrations/
│       ├── 14.sql                  # Schema 14 Migration
│       ├── 15.sql                  # Schema 15 Migration（示例）
│       └── README.md
│
├── releases/
│   ├── 1.3.0/
│   │   └── manifest.json           # v1.3.0 发布清单
│   ├── 1.4.0/
│   │   └── manifest.json           # v1.4.0 发布清单
│   └── README.md
│
└── storage/
    ├── .htaccess                   # 禁止 Web 访问
    └── updates/
        ├── update-public-key.pem    # Ed25519 公钥
        ├── update.lock              # 更新锁（运行时生成）
        ├── version-cache.json       # 版本缓存（运行时生成）
        ├── backups/                 # 备份目录
        ├── packages/                # 下载的更新包
        └── logs/                    # 更新日志
```

## 核心组件

### VersionManager

`lib/VersionManager.php` 是更新系统的核心引擎，负责：

- `getCurrentVersion()` — 获取当前版本
- `checkLatestVersion()` — 检查官方最新版本（带缓存）
- `compareVersion()` — 语义化版本比较
- `downloadUpdate()` — 下载更新包
- `verifySha256()` — SHA-256 校验（在下载时自动完成）
- `verifySignature()` — Ed25519 数字签名验证
- `validateManifest()` — Manifest 校验
- `backup()` — 备份数据库与文件
- `runMigrations()` — 执行数据库 Migration
- `installUpdate()` — 安装更新文件
- `rollback()` — 回滚
- `healthCheck()` — 健康检查
- `doUpdate()` — 完整更新流程

### 更新流程

```
管理员点击更新
    ↓
获取更新锁（防止并发）
    ↓
再次检查官方版本
    ↓
检查当前版本
    ↓
检查 PHP 版本
    ↓
检查磁盘空间
    ↓
检查目录权限
    ↓
数据库备份
    ↓
项目文件备份
    ↓
下载更新包
    ↓
SHA-256 校验
    ↓
数字签名验证
    ↓
检查 manifest
    ↓
解压到临时目录
    ↓
执行数据库 Migration
    ↓
替换项目文件
    ↓
更新版本
    ↓
清理缓存
    ↓
健康检查
    ↓
写入更新历史
    ↓
释放更新锁
    ↓
更新完成
```

## 安全要求

- **HTTPS** — 版本服务器必须使用 HTTPS
- **管理员权限** — 只有超级管理员可以执行更新
- **CSRF 防护** — 更新操作必须带 CSRF Token
- **更新锁** — 防止两个管理员同时更新
- **数据库备份** — 更新前自动备份数据库
- **SHA-256 校验** — 下载后验证文件完整性
- **Ed25519 签名** — 验证更新包来源
- **Manifest 校验** — 验证版本信息与文件列表
- **Zip Slip 防护** — 检查 ZIP 路径穿越
- **Migration** — 数据库升级使用增量迁移
- **Health Check** — 更新后自动检查系统状态
- **Rollback** — 更新失败自动回滚

## 集成到主系统

主系统通过独立的 handler 对接版本服务器，无需引入 `VersionManager.php`（那是 update-system 自身后台用的）。

### 1. 版本检查 Handler

在主系统 `admin/handlers/system_update_check.php` 中，CURL 请求版本服务器的 `api/version.php`：

```php
$updateServer = 'https://mmbr.serv00.net';  // 可在后台配置 update_server
$url = rtrim($updateServer, '/') . '/api/version.php?' . http_build_query([
    'product' => 'nebula-verification',
    'version' => NB_VERSION,
    'build'   => 0,
    'channel' => 'stable',
]);
// CURL 请求，6 小时缓存，force=1 跳过缓存
```

### 2. 一键更新 Handler

`admin/handlers/system_update_do.php` 实现完整的更新流程：

- 下载更新包 → SHA-256 校验 → 验证 manifest → 备份 → 解压覆盖 → 更新版本号
- 保护目录：`config/`、`logs/`、`data/`、`uploads/` 等不会被覆盖
- Zip Slip 防护：检查 ZIP 路径穿越
- 更新锁：防止并发更新

### 3. 注册权限

在 `lib/AdminPermission.php` 的 `ACTION_PERM` 中添加：

```php
'system_update_check' => self::SETTINGS_INFRA,
'system_update_save'  => self::SETTINGS_INFRA,
'system_update_do'    => self::SETTINGS_INFRA,
```

### 4. 注册 CSRF 豁免

只读操作加入 CSRF 豁免列表：

```php
// admin/index.php 的 $csrfExempt 数组
'system_update_check',  // 版本检查豁免 CSRF（只读）
```

### 5. 后台菜单

在 `admin/assets/js/core/router.js` 中注册：

```javascript
{ id: 'system_update', name: '系统更新', icon: 'bi-cloud-arrow-down', type: 'page', perm: 'settings.infra' }
```

### 6. 强制更新封锁

在 `admin/assets/js/app.js` 的 `enterApp()` 中，进入后台时自动检查：

```javascript
const res = await api('system_update_check', { force: 1 }, true);
if (res.data.latest.force_update) {
    showForceUpdateModal(cur, latest, res.data.latest);  // 不可关闭的封锁弹窗
    return;  // 后台不可用
}
```

## 版本服务器部署

### 目录结构

```
update-server/
├── api/
│   └── version.php
├── releases/
│   ├── 1.3.0/
│   │   ├── manifest.json
│   │   ├── update.zip
│   │   └── update.zip.sig
│   └── 1.4.0/
│       ├── manifest.json
│       ├── update.zip
│       └── update.zip.sig
└── public/
    └── update-public-key.pem
```

### 生成 Ed25519 密钥对

```bash
# 生成私钥
openssl genpkey -algorithm Ed25519 -out update-private-key.pem

# 导出公钥
openssl pkey -in update-private-key.pem -pubout -out update-public-key.pem
```

### 签名更新包

```bash
# 生成 SHA-256
sha256sum update.zip

# 用私钥签名
openssl pkeyutl -sign -inkey update-private-key.pem -rawin -in update.zip -out update.zip.sig

# Base64 编码
base64 update.zip.sig
```

## 打包更新包

使用 Python 版打包工具 `pack.py`（位于本目录，仅需 Python 3.8+，无第三方依赖），将**主项目空白包**打包为更新 ZIP：

```bash
python pack.py 2.65.2 "d:\phpstudy_pro\WWW\yanzheng" "d:\phpstudy_pro\WWW\update_2.65.2.zip"
```

> 该工具为纯命令行脚本，不能通过 Web 访问调用。

参数说明：

| 参数 | 必填 | 说明 |
|------|------|------|
| `2.65.2` | 是 | 版本号；若源目录下存在 `version.php`，其中的 `APP_VERSION` 会覆盖此值 |
| `d:\phpstudy_pro\WWW\yanzheng` | 是 | 主项目源目录（空白包源） |
| `d:\phpstudy_pro\WWW\update_2.65.2.zip` | 否 | 输出更新包路径，缺省为 `./update_<版本号>.zip` |

功能与限制：

- 版本信息仅从**源目录下的 `version.php`** 读取：`APP_PRODUCT`、`APP_VERSION`、`APP_BUILD`、`APP_CHANNEL`、`APP_SCHEMA_VERSION`
- 主项目当前**没有** `version.php`，因此打包主项目时版本号以命令行参数为准，`build` 取 `YYYYMMDD01`，`min_version` 与 `schema_version` 使用默认值 `1.0.0` / `1`；若需正确表达迁移与最低版本，请先在源目录放置 `version.php`
- 自动生成 `manifest.json`（`product`、`version`、`build`、`min_version`、`schema_version`、`channel`、`published_at`、`requirements`、文件列表）
- 自动排除 `config/`、`storage/`、`.git/`、`node_modules/`、`.catpaw/`、`.freebuff/`、`.workbuddy/` 目录，以及 `*.lock`、`*.log` 文件
- 输出 SHA-256 校验值，用于后台「版本管理」发布

### 发布流程

```
修改代码
  ↓
修改版本号（version.php 或 bootstrap.php 的 NB_VERSION）
  ↓
编写 Migration
  ↓
运行测试
  ↓
PHP Lint
  ↓
打包 ZIP（python pack.py <版本号> <源目录> [输出路径]）
  ↓
生成 Ed25519 签名
  ↓
上传版本服务器（后台「版本管理」或 api/upload.php）
  ↓
后台检测到新版本
```

## API 上传更新包（自动发布）

除了后台「版本管理」手动发布，还提供带认证的上传接口 `api/upload.php`，供打包脚本 / CI / 运维直接上传并发布上架。与 `pack.py` 无缝衔接：`pack.py` 打出的 ZIP 自带 `manifest.json`，接口会自动解析其中的版本信息，无需重复填写。

```bash
# 打包 → 签名 → 上传（一条龙）
python pack.py 2.66.0 "d:\phpstudy_pro\WWW\yanzheng" update_2.66.0.zip
openssl pkeyutl -sign -inkey update-private-key.pem -rawin -in update_2.66.0.zip -out update.zip.sig

curl -u 管理员账号:密码 \
     -F "update_file=@update_2.66.0.zip" \
     -F "signature=$(base64 update.zip.sig)" \
     -F "release_notes=修复若干问题" \
     https://你的域名/update-system/api/upload.php
```

### 认证与安全

- **HTTP Basic Auth**：使用 `vu_admins` 表的管理员账号密码验证，仅超级管理员（`role=1`）可上传
- 复用账号锁定与 IP 级防爆破（`vu_login_throttle`），暴力破解会被锁定
- 认证失败统一返回 401，且不区分「账号不存在 / 密码错误」

### 请求参数（multipart/form-data）

| 字段 | 必填 | 说明 |
|------|------|------|
| `update_file` | 是 | ZIP 更新包 |
| `version` | 否 | 版本号；留空则从包内 `manifest.json` 读取 |
| `build` / `min_version` / `channel` / `schema_version` | 否 | 显式传入优先于 `manifest.json` |
| `status` | 否 | `1` 上架（默认）/ `0` 下架 |
| `signature` | 否 | Ed25519 签名（Base64） |
| `release_notes` | 否 | 更新日志，JSON 数组或每行一条 |
| `download_url` | 否 | 留空自动生成（基于部署路径） |

包内 `manifest.json` 与 `APP_PRODUCT` 不一致时一律拒绝；版本号已存在时拒绝重复发布。成功后文件保存到 `releases/{version}/update.zip`，同时写入 `vu_releases`（默认上架）与更新历史，客户端即可立即检测到新版本。

## 首个版本 — v1.3.0

本次已完成的 8 项修复归入版本 1.3.0：

### 安全修复
1. 修复卡密并发激活 Lost Update
2. 修复设备数量限制并发绕过
3. 修复发卡网登录失败锁定缺失
4. 修复支付驱动切换导致历史订单回调异常
5. 加强会话密钥软件绑定
6. 加强会话密钥设备绑定

### Bug 修复
7. 修复卡密批量生成 SQL 占位符错误

### SDK 优化
8. Python/C++ 示例客户端改用随机 IV

## 验收标准

- [x] 可以读取当前版本
- [x] 可以检查官方最新版本
- [x] 可以显示更新日志
- [x] 可以识别普通更新
- [x] 可以识别强制更新
- [x] 只有超级管理员可以更新
- [x] CSRF 防护正常
- [x] 可以创建数据库备份
- [x] 可以下载更新包
- [x] SHA-256 校验正常
- [x] Ed25519 签名校验正常
- [x] Manifest 校验正常
- [x] Zip Slip 防护正常
- [x] Migration 正常执行
- [x] 用户配置不会被覆盖
- [x] 用户数据不会被删除
- [x] 更新锁正常
- [x] 更新历史正常记录
- [x] Health Check 正常
- [x] 更新失败可以回滚
- [x] 两个管理员同时更新不会造成破坏
- [x] 版本服务器不可用时正常版本仍可运行

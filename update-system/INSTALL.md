# 在线版本更新系统 — 安装教程

> 完全独立的版本更新系统，不依附于任何项目。

---

## 目录

1. [环境要求](#1-环境要求)
2. [快速安装（三步）](#2-快速安装三步)
3. [安装向导详细说明](#3-安装向导详细说明)
4. [安装后配置](#4-安装后配置)
5. [安全加固](#5-安全加固)
6. [故障排除](#6-故障排除)

---

## 1. 环境要求

| 项目 | 要求 |
|------|------|
| PHP | ≥ 8.0 |
| MySQL | ≥ 5.7 / MariaDB ≥ 10.3 |
| PHP 扩展 | `pdo_mysql`、`openssl`、`curl`、`zip`、`json` |
| Web 服务器 | Nginx / Apache |
| 磁盘空间 | ≥ 100MB |

---

## 2. 快速安装（三步）

### 第一步：上传文件

将 `update-system/` 整个目录上传到服务器。

### 第二步：访问安装向导

浏览器打开：

```
http://你的域名/update-system/install/install.php
```

### 第三步：按向导填写配置

1. 环境检测通过后，填写数据库信息和管理员账号
2. 点击「确认安装」
3. 安装完成后**立即删除 `install/` 目录**

---

## 3. 安装向导详细说明

安装向导分为三步：

### 步骤一：环境检测 + 配置填写

安装向导会自动检测：
- PHP 版本是否 ≥ 8.0
- 必需扩展是否安装（`pdo_mysql`、`openssl`、`curl`、`zip`、`json`）
- `config/` 和 `storage/` 目录是否可写

检测全部通过后，填写以下信息：

| 字段 | 说明 | 示例 |
|------|------|------|
| 数据库地址 | MySQL 主机 | 127.0.0.1 |
| 端口 | MySQL 端口 | 3306 |
| 数据库名 | 不存在会自动创建 | version_update |
| 数据库用户名 | 有 CREATE 权限 | root |
| 数据库密码 | - | - |
| 表前缀 | 同库多系统可改 | vu_ |
| 站点名称 | 显示在后台 | 在线版本更新系统 |
| 管理员账号 | 3-32 位字母数字下划线 | admin |
| 管理员密码 | 至少 8 位，不能用 admin888 | - |

### 步骤二：确认安装

显示配置摘要，点击「确认安装」执行：

1. 创建数据库（如不存在）
2. 执行 `install/schema.sql` 建表
3. 创建管理员账号
4. 自动生成 `config/config.php`（含数据库密码）
5. 创建存储目录（`storage/updates/backups`、`packages`、`logs`）
6. 写入 `install/install.lock`（防止重装）

### 步骤三：安装完成

显示管理员账号密码和快捷入口链接：
- 后台登录：`/update-system/admin/login.php`
- 版本更新页面：`/update-system/admin/update.php`
- 版本检查 API：`/update-system/api/version.php`

> ⚠️ **安装完成后必须删除 `install/` 目录！**

```bash
rm -rf update-system/install/
```

---

## 4. 安装后配置

### 4.1 配置版本服务器地址

编辑 `config/config.php`，修改 `update_server`：

```php
'update_server' => 'https://update.example.com',
```

留空则不检查自身更新。

### 4.2 放置 Ed25519 公钥

将你的公钥文件放到：

```
update-system/storage/updates/update-public-key.pem
```

### 4.3 配置 Nginx（推荐）

```nginx
# 禁止访问敏感目录
location ~ ^/update-system/(config|lib|storage|install)/ {
    deny all;
    return 404;
}

# 禁止访问后台 handlers
location ~ ^/update-system/admin/handlers/ {
    deny all;
    return 404;
}
```

### 4.4 管理后台入口

安装完成后访问：

```
http://你的域名/update-system/admin/login.php
```

输入安装时设置的管理员账号密码即可登录。

---

## 5. 安全加固

### Apache

`.htaccess` 已随系统提供，自动生效：
- 禁止访问 `config/`、`lib/`、`storage/`、`install/` 目录
- 禁止访问 `admin/handlers/` 目录
- 禁止下载 `.sql`、`.pem`、`.lock` 等敏感文件

### Nginx

在站点配置中添加：

```nginx
location ~ ^/update-system/(config|lib|storage|install)/ {
    deny all;
    return 404;
}
location ~ ^/update-system/admin/handlers/ {
    deny all;
    return 404;
}
```

### 权限检查清单

- [ ] `install/` 目录已删除
- [ ] `config/config.php` 不可被 Web 直接访问
- [ ] `storage/` 目录不可被 Web 直接访问
- [ ] `storage/updates/backups/` 和 `packages/` 目录可写
- [ ] Ed25519 公钥已放置（如需签名验证）

---

## 6. 故障排除

### Q: 安装向导显示"config 目录可写 ✗ 失败"

```bash
# Linux
chmod 755 update-system/config/
chown www-data:www-data update-system/config/

# Windows (phpStudy)
# 右键 config 目录 → 属性 → 安全 → 添加 Everyone → 完全控制
```

### Q: 安装时"数据库连接失败"

1. 确认 MySQL 服务正在运行
2. 确认地址/端口/账号/密码正确
3. 确认数据库用户有 CREATE 权限

### Q: 安装后访问后台跳转到安装向导

说明 `config/config.php` 未生成成功：
1. 检查 `config/` 目录是否可写
2. 手动复制 `config/config.example.php` 为 `config/config.php` 并修改

### Q: 版本检查返回"未配置版本服务器地址"

编辑 `config/config.php`，填写 `update_server`：

```php
'update_server' => 'https://你的版本服务器地址',
```

### Q: 登录失败"账号或密码错误"

1. 确认账号密码与安装时填写的一致
2. 多次失败后会锁定 15 分钟
3. 如忘记密码，可通过 MySQL 直接重置：

```sql
UPDATE vu_admins SET password = '新密码的bcrypt哈希' WHERE username = 'admin';
```

生成 bcrypt 哈希：

```php
echo password_hash('新密码', PASSWORD_BCRYPT);
```

### Q: Windows (phpStudy) 特殊注意

1. 目录权限：右键 → 属性 → 安全 → 添加 `Everyone` → 完全控制
2. `exec()` 被禁用时，健康检查中的 PHP 语法检查会跳过（不影响更新）
3. 路径分隔符由 PHP 自动处理

---

## 文件结构

```
update-system/
├── version.php                      # 版本信息
├── .htaccess                         # Apache 安全规则
│
├── config/
│   ├── .htaccess                     # 禁止访问
│   └── config.example.php            # 配置模板
│
├── lib/
│   ├── bootstrap.php                 # 统一引导文件
│   ├── DB.php                        # 数据库封装
│   ├── AdminAuth.php                 # 后台认证
│   └── VersionManager.php            # 更新引擎
│
├── api/
│   ├── version.php                   # 版本检查 API
│   └── health.php                    # 健康检查 API
│
├── admin/
│   ├── login.php                     # 登录页
│   ├── update.php                    # 版本更新页面
│   └── handlers/
│       └── update.php                # 更新执行 Handler
│
├── install/
│   ├── install.php                   # 安装向导
│   ├── schema.sql                    # 建表脚本
│   └── migrations/
│       └── 2.sql                     # Migration 示例
│
├── releases/
│   ├── 1.0.0/manifest.json           # 发布清单
│   └── README.md
│
└── storage/
    ├── .htaccess                     # 禁止访问
    └── updates/
        ├── update-public-key.pem     # Ed25519 公钥
        ├── backups/                  # 备份目录
        ├── packages/                 # 下载的更新包
        └── logs/                     # 更新日志
```

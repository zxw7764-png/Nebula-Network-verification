# Nebula 网络验证

> **当前版本：2.66.4.8**（详见 [CHANGELOG.md](CHANGELOG.md)，顶部为本版变更）

一套基于 PHP + MySQL 的网络验证（授权）系统后端，提供客户端 API 与管理后台 API。
通信协议为 **Nebula 3.1**：ECDH P-256 会话握手 + AES-256-GCM 信封 + seq 防重放，客户端零静态对称机密。

官方提供 C++ / Python / C# 三套协议同规格的 SDK（**可在仓库发行处 Releases 下载**），易语言等其他语言依据接口文档直接对接。

## 📚 详细文档

本文档只做索引与速览，**完整说明请进入详细文档**：

| 文档 | 内容 |
| --- | --- |
| **[docs/GUIDE.md](docs/GUIDE.md)** | **完整使用指南**（功能一览 / 快速部署 / 管理后台 / 客户端对接 / 常见问题） |
| [docs/API.md](docs/API.md) | 客户端 API 完整接口文档（3.1 协议、握手、各接口字段、离线宽限协议） |
| [docs/API_RAW_EXAMPLES.md](docs/API_RAW_EXAMPLES.md) | 请求 / 响应原始报文示例（手写协议对接逐字节参照） |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | 架构设计文档（分层架构 / 请求生命周期 / 安全设计对照） |
| [docs/TEMPLATE.md](docs/TEMPLATE.md) | 界面模板开发文档（目录规范 / 小游戏 / 布局与自定义区块） |
| [CHANGELOG.md](CHANGELOG.md) | 版本更新日志 |

## ⚡ 速览

- **环境**：PHP 8.0+ / MySQL 5.7+（建议 8.0），虚拟主机或云服务器均可
- **安装**：上传到 Web 根目录 → 访问 `install/install.php` 向导 → 完成后立即删除 `install` 目录
- **客户端对接**：SDK 在仓库发行处（Releases）下载，或按 [docs/API.md](docs/API.md) 手写协议（ECDH 握手 → GCM 信封）
- **管理后台**：浏览器访问 `admin/index.php`（部署后建议改名后台目录）

> 界面模板与官网内容运营入口在管理后台「官网运营」，开发规范见 [docs/TEMPLATE.md](docs/TEMPLATE.md)。

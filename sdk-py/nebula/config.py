# -*- coding: utf-8 -*-
"""
Nebula SDK (Python) · 接入方配置区
------------------------------------------------------------------------------
这是接入方【唯一需要修改的文件】。
所有密钥均可在管理后台「软件管理」中查看。
"""

# ① API 入口地址（http:// 或 https://，强烈建议 https）
kApiUrl = "http://your-domain.com/api/index.php"

# ② 后台「软件管理」对应软件的 app_key
kAppKey = "SWXXXXXXXX"

# ③ 响应签名公钥（PEM；后台「系统设置 → 安全」可复制）。
#    ★ 未配置时 SDK 拒绝连接（防伪造服务器设计）。
kRespSignPubKey = ""

# ④ TLS 证书指纹锁定（SHA256 hex；留空 = 使用系统标准证书校验）。
kTlsCertSha256 = ""

# 请求超时（秒）
kTimeoutSeconds = 15

# 客户端版本号（服务端据此判断强制更新）
kClientVersion = "1.0.3"

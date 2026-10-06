# API 契约测试套件（tests/contract）

针对 Nebula 客户端 API（`docs/API.md`）的自动化契约测试：**独立 3.1 协议客户端**（`nbtest.py`，
仅依赖 `cryptography`）直接走真实 HTTP 与本地服务端交互，断言各接口字段 / 错误码 / 状态机与防重放行为。

## 覆盖范围

| 用例 | 验证点 |
| --- | --- |
| handshake 成功+验签 | ECDH 协商、ES256 响应验签（防伪造服务器） |
| 无效 app_key / 超窗时间戳 | 1004 / 5003 |
| 信封 seq 重放 | 5004 拒绝（防重放） |
| notice 明文 + 软件隔离 | list/total 结构；越权单条 1001 |
| init 契约 | server_time / app_key / software / heartbeat_interval / login / device_fp |
| login 错误密码 | 2001 |
| 注册→登录→业务→登出 全链路 | userinfo / devices / logout 的 machine_id 强制；登出后 token 失效 |
| logout 缺 machine_id | 拒绝（2.65.34 契约） |
| logout 无效 token | 报错 + need_relogin |
| version | 字段齐全与 need_update 语义 |
| online | 在线数 |

## 运行

```bash
# 1. 确保本地服务端可用（Apache + MySQL，phpstudy 启动；库 321 / 前缀 nb_）
# 2. 确认 config.json：
#      api_base    = http://127.0.0.1/api/index.php
#      app_key     = 测试软件 SW（后台「软件管理」复制，或本地库 nb_softwares）
#      machine_id  = 任意测试机器码
# 3. 运行：
python tests/contract/test_core.py            # 汇总模式
python tests/contract/test_core.py -v         # 详细模式
```

## 规则与注意

- 每个用例新建握手会话（服务端临时密钥每请求生成，无状态依赖）；注册用例会真实创建一个 `contract_<ts>` 用户。
- 测出的失败分两类：**契约失败**（提交为本项目的 API 兼容性问题）与**环境失败**（app_key 未配置 /
  本地服务未启动 / 数据库迁移未跑）。运行前先手工 `curl "http://127.0.0.1/api/index.php?action=online"` 验证环境。
- 单人维护下建议：每次发版（尤其协议/信封/字段变更）前跑一遍，确保 `docs/API.md` 描述与实现一致。
- 后台 handler 级契约（RBAC / 租户隔离 / 导出）不在本套件当前范围（需管理员会话），见 SKILL.md 的「攻击者清单」。

## 依赖

仅 `cryptography`（本项目 Python 3.13 venv 自带）。无第三方服务依赖。
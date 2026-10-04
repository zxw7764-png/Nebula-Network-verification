# Nebula SDK (Python)

Nebula 网络验证的 Python 接入 SDK,内置一套与官方界面规格一致的 Pygame 桌面登录示例:

> ⚠️ **本 SDK ≥ 3.1.1** 才能连接服务端 **≥ 2.65.22**(响应方向加密改用独立派生密钥
> `sk_enc_rsp = HKDF(sk_enc, info="nebula31-enc-rsp")`,旧 SDK 解密响应失败)。

- `nebula/` —— SDK 本体(协议实现,无 UI 依赖,可单独引用)
- `nebula/ui/` —— 桌面登录界面(440×420 无边框登录窗 + 800×500 主窗)

## 目录结构

```
nebula-py/
├── main.py                    # 示例入口:登录窗 → 主窗 循环
├── nebula/
│   ├── config.py              # 接入方配置(唯一需要修改的文件)
│   ├── crypto.py              # HKDF / SHA256 / 恒定时间比较等密码学原语
│   ├── envelope.py            # 3.1 协议:ECDH P-256 会话握手 + AES-256-GCM 信封
│   │                          #   (懒握手 / seq 防重放 / 会话失效自动重握手)
│   ├── client.py              # init / login / heartbeat / logout + 机器码
│   │                          #   + 完整性自校验 / 自动更新 / 公告 / 内置提示
│   ├── feature.py             # 功能密钥数据包 NF1(seal / open,防破解)
│   └── ui/
│       ├── theme.py           # 界面规格:14 项主题配色 + 布局常量
│       ├── drawing.py         # 圆角矩形 / 渐变背景 / 装饰 / 自绘标题栏
│       ├── login_window.py    # 登录窗(输入/粘贴/焦点/登录方式联动/错误码翻译表)
│       └── main_window.py     # 主窗 + 心跳接管(被踢/顶号自动回登录)
├── docs/                      # 界面截图
├── test_smoke.py              # SDK 联调自测(init→登录正负向→功能密钥→心跳→登出)
├── test_ui_headless.py        # 界面主循环 headless 测试(dummy 显存驱动)
└── tools/screenshot.py        # 界面截图生成脚本(离屏渲染)
```

## 环境要求

- **Python 3.10 ~ 3.13**(⚠ Python 3.14 暂不可用:pygame 尚无对应版本的预编译包,
  pip 会回退源码编译并因 `distutils.msvccompiler` 被移除而失败)
- `pygame >= 2.6`(界面层)
- `cryptography >= 42`(SDK 层)

```bash
pip install pygame cryptography    # 仅 Python ≤ 3.13 可用
```

## 快速开始

1. **配置** `nebula/config.py`:填 API 地址、app_key、响应签名公钥(管理后台
   「软件管理」/「系统设置 → 安全」可查)。**未配置公钥时 SDK 拒绝连接**(防伪造服务器)。
   协议 3.1 起通信密钥由 ECDH 握手临时协商,客户端不再持有任何静态对称密钥。
2. **运行**:`python main.py`
3. 登录流程:启动即后台 init(不卡界面)→ 按服务器下发的登录方式输入(用户名+密码 /
   用户名+激活码 / 卡密直登,界面自动切换)→ 登录成功进入主窗并接管心跳;
   被踢下线/顶号时自动回到登录界面重新登录。

## SDK 独立使用(不带界面)

```python
from nebula import create_default_client, feature

client = create_default_client()
if client.init().ok:
    lr = client.login("用户名", "密码")
    if lr.ok:
        client.start_heartbeat(lr.token, lambda hb: print(hb.remain_text))
        # 功能密钥:解密随程序分发的核心数据
        data, err = feature.open_pack(open("core.dat", "rb").read(), lr.feature_key)
```

## 功能密钥(NF1 数据包)

制作(开发期,也可用服务端工具生成):

```python
from nebula import feature
pack = feature.seal("核心逻辑数据".encode(), "你的功能密钥")
```

运行期:登录成功 `lr.feature_key` 拿到密钥 → `feature.open_pack(pack, lr.feature_key)`。
格式 `NF1.<b64(iv+AES-256-CBC)>.<HMAC>`,encrypt-then-MAC,先验签后解密,与服务端互通。

## 界面规格

| 项目 | 说明 |
|---|---|
| 登录窗 | 440×420 无边框,自绘标题栏(36px,顶部霓虹条,最小化/关闭悬停态) |
| 主窗 | 800×500 无边框,欢迎信息 + 会员/积分/设备上限 + 功能区占位 |
| 主题 | 14 项配色(深蓝渐变底 + 霓虹绿强调色),见 `ui/theme.py` |
| 输入框 | 圆角双描边,聚焦高亮,500ms 闪烁光标,密码 ● 掩码 |
| 交互 | Ctrl+V 粘贴(过滤控制字符,64 字上限)/ Backspace / Return / Tab / Escape |
| 登录方式 | password / username_code / code 三种,标签与必填项按 init 下发自动联动 |
| 错误提示 | 完整错误码翻译表(-1 ~ 9999),服务端消息优先 |
| 会话 | 心跳保活,kick / 顶号 / 会话失效提示后 1.5 秒回登录界面 |
| 凭证 | 登录成功保存 `credentials.ini`(账号/密码/方式),下次启动自动回填 |
| 拖拽 | 标题栏系统级拖拽(ReleaseCapture + WM_NCLBUTTONDOWN,系统接管) |

## 启动流水线与内置能力

登录窗后台线程依次执行:

1. **init** —— 自动 ECDH 握手建立会话(懒握手)→ 拉取配置 / 公告 / 版本
2. **自身完整性自校验** —— 服务端「版本管理」登记了 `file_hash`/`file_size` 时,
   计算自身 exe(SHA256/MD5 自动识别)+ 字节数比对;不一致弹「程序文件已被修改」并退出。
   ⚠ 重新打包发版前必须在后台重新登记,否则新包会被自己拦下
3. **维护提示** —— 维护模式以状态栏文字提示(登录请求仍会被服务端以 6002 拒绝)
4. **自动更新** —— 强制更新自动「下载 → hash+size 强制校验 → cmd 替换脚本 → 退出重启」
   (仅接受 https 更新地址);失败进入「重试更新」模式(主按钮变重试);
   可选更新默认只提示,`auto_update_optional = True` 时同样自动安装
5. **版本提示** —— 强制更新兜底拦截 / 发现新版本提醒
6. **公告** —— 弹窗公告(每次登录提示)+ 立即公告(看过即不再显示,已读记录存
   `%APPDATA%\NebulaSDK\notices_<app_key>.txt`,30 天自动清理)+ 列表公告(公告栏)

SDK 独立使用时各能力可单独调用:

```python
client = create_default_client()
client.auto_update_optional = False          # 可选更新不自动装(默认)
client.allow_insecure_update = False         # 拒绝 http 更新地址(默认)
client.set_ui_handler(lambda kind, msg: log(msg))   # 替换内置系统弹窗(可选)

ir = client.init()
if ir.ok:
    if not client.enforce_self_integrity():  # 自校验(失败已弹窗)
        return
    up = client.auto_update()                # 全自动更新(Applied 后进程退出重启)
    if up.state == UpdateState.Applied:
        return
    client.version_alert()                   # 版本提示(强制更新返回 False)
    client.popup_notices()                   # 弹窗公告(type=2)
    client.flash_notices()                   # 立即公告(type=3,自动标记已读)
```

弹窗默认用系统 `MessageBoxW`(标题 = 软件名 + 后缀,如「XXX - 公告」);
`set_ui_handler` 后所有提示改走接入方回调,kind 取值:
`popup / flash / version / update / maintain / kick / integrity`。

## 打包成 exe(无控制台窗口)

在与运行相同的环境装 PyInstaller 后一条命令构建:

```bash
python -m pip install pyinstaller
python -m PyInstaller --noconfirm --clean --onefile --windowed --name Nebula main.py
```

产物 `dist/Nebula.exe`(单文件,双击无黑窗):

- `credentials.ini` 自动生成在 **exe 同目录**(开发态在 `nebula/` 旁),升级覆盖 exe 不丢凭证
- 完整性自校验与自动更新在打包态工作正常:校验/替换目标即 exe 本身
- ⚠ 发版前记得在后台「版本管理」登记该 exe 的哈希与大小,否则自校验会拦自己

## 测试

```bash
# ① SDK 联调(需可用的服务端与软件配置)
python test_smoke.py          # 15 项断言:init/登录正负向/功能密钥三态/心跳/登出

# ② 界面 headless(dummy 驱动真跑主循环 + 后台 init 线程)
python test_ui_headless.py

# ③ 重新生成界面截图
python tools/screenshot.py
```

## 安全注意

- 传输安全:协议 3.1 —— ECDH P-256 会话握手 + AES-256-GCM + seq 防重放,
  客户端 exe 内不存在任何静态对称密钥(逆向 dump 堆也拿不到可复用的通信密钥)。
- `credentials.ini` 明文保存账号密码,介意可自行去掉保存逻辑。
- 防破解建议使用功能密钥方案:核心数据 NF1 加密随程序分发,
  patch 掉登录判定也拿不到密钥,密文数据永远解不开。

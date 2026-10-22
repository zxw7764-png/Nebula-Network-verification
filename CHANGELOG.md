# 更新日志（CHANGELOG）

本文件记录 Nebula 网络验证系统各版本的变更。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循语义化版本（`主.次.修订`）。每次发版请在本文件顶部追加条目，并同步
`lib/bootstrap.php` 的 `NB_VERSION`；发布到版本更新系统时，把对应条目整理为 `release_notes`。

## [未发布] - 2026-10-22

### 文档与官网（加密客户端生成器说明补齐）

- **新文档** `docs/SEALER.md`：加密客户端生成器（`sealer/` 下 `NebulaSealClient.exe` / 加载壳 / VMProtect 壳加固）正式说明，覆盖目录与文件、界面三页填写（基础信息 / 安全策略 / 壳加固 / 程序图标）、成品明文边界、打包与交付、配置自动保存、常见问题；同步到 `yanzheng/docs/SEALER.md` 发布包。
- **主文档索引补齐**：`README.md`、`docs/GUIDE.md`、`docs/ARCHITECTURE.md` 均加入 `SEALER.md` 链接与组件说明；`docs/API.md` 开头补充指向 `SEALER.md` 的提示（面向已有 exe 的封装场景）。
- **官网同步（`update-system/`）**：
  - `index.php` 产品构成区块新增「加密客户端生成器」卡片；核心功能区块首张卡改为该工具说明。
  - `docs.html` 新增第 7 个标签页「加密客户端生成器」（`d7`），含完整在线说明与目录锚点；现有 `docs.js` 的 `#(d\d)-h` 锚点匹配可识别 `d7`，JS 无需改动。

## [未发布] - 2026-10-21

### 后台 CSP 合规（内联事件属性全部清掉）

- **问题**：软件管理 →「功能密钥」右侧的「生成随机」点击无反应。控制台报 `Executing inline event handler violates the following Content Security Policy directive 'script-src 'self' 'nonce-…' 'unsafe-eval''`（`JSEventHandlerForContentAttribute`，栈 `openModal @ ui.js:28` ← `swEdit @ software.js:224`）。根因是按钮写成了内联 `onclick="…"`，而后台 CSP 的 `script-src` 只放行 `self` + nonce + `unsafe-eval`，**没有 `unsafe-inline`** —— 内联事件属性一律被拦截；`openModal` 用 `innerHTML` 注入时就会触发该拦截。
- **修法**：**不放开 `unsafe-inline`**（那等于拆掉 CSP 对抗 XSS 的主防线），改为按钮带 `id="swFeatureKeyGen"`，在 `openModal()` 之后用 `addEventListener` 绑定；随机源走 `crypto.getRandomValues(new Uint8Array(16))` → 32 位小写十六进制。
- **同类问题一并清理**（全站扫描后剩下的内联事件属性与 `javascript:` 伪协议，共 4 处）：
  - `pages/system_update.js`：授权码输入框的 `oninput="this.value=…"` 改为事件绑定（统一小写、只保留 `[0-9a-f]`）；
  - `pages/device.js`：硬件指纹单元格 `<a href="javascript:;">` 改为 `<span data-fp>` —— `javascript:` 导航同样受 `script-src` 管辖，点了就报违规；
  - `pages/agent_code.js`：代理注册数 `<a href="javascript:;">` 改为 `<span data-act="detail">`，顺带修好这个**原本没绑定任何点击处理**的死链接，现在可直接打开该激活码的详情。
- **核验**：`grep` 全后台 `assets/js`，内联事件属性 0 处、`javascript:` 伪协议 0 处；4 个文件 `node --check` 通过；密钥生成实测 32 位十六进制、200 次无重复。
- **`NB_VERSION` 2.66.4.12 → 2.66.4.13**：只改后台 JS 也必须 +1，否则浏览器按 `?v=` 命中旧模块缓存。

### 登录器 UI（flet 独立界面 · 简约小清新）

- **新目录** `sealer/login_ui/`：`nebula_login.py` 是**独立进程**的登录窗（flet 1.0 / Flutter 渲染，无边框圆角 + 奶白暖橘配色）；`tools/fake_shell.py` 假壳联调脚本；`tools/shot.py` 窗口定位与截图工具（纯 ctypes GDI，手写 PNG 编码，不依赖 Pillow / pywin32）。
- **进程隔离是设计前提**：UI 只跟加载壳通过命名管道交换「单行 JSON」，**不解析封包、不做 ECDH/握手/签名、不接触 `feature_key` 与载荷密钥**。UI 被调试、被替换、崩溃，都拿不到任何能解锁载荷的东西。
- **公告由 init 下发**：`site_name` 与 `notices[{title,content}]` 全部来自壳转发的**主服务端 init 响应**，客户端不写死任何公告；`cfg` 消息只负责标题 / 站点 / 版本 / 登录方式。
- **登录方式**：`password` / `username_code` / `code`（卡密），由 init 的 `login_method` 决定，卡密模式自动收成单输入框。
- **本轮 UI 修正**：去掉与 `TextField.label` 重复的外层标签；输入框与主按钮统一拉伸到同宽；用户开始改输入时清掉上一次错误提示；焦点默认落在账号框（`autofocus`）；「显示密码」改为自绘 `suffix_icon`（颜色随状态变化），不再用 `can_reveal_password` 的默认图标；`border_color` / `focused_border_color` 等 flet 1.0 **已废弃**属性改回非废弃的 `border={ControlState.*}` 状态字典。窗口 402×470（逻辑）。
- **状态**：界面与管道联调已通（`login_ui/tools/preview_login.png` 为实测截图，502×587 物理像素）；**加载壳侧的管道服务端、登录器释放与内置 Win32 窗口回落尚未接入**。

### 打包器（程序图标：可跟随目标程序 / 自选 .ico）

- **新增**：加密后的成品可以显示自己的图标，不再统一是加载壳那张脸。三个来源：
  - `auto`（默认）—— 从**要加密的那个 exe** 里抠图标（PE 资源 `RT_GROUP_ICON` + 各 `RT_ICON`，纯 Python 解析，不依赖 `pefile`）；源程序没有图标资源时自动退回加载壳自带图标，**不报错、不中断打包**；
  - `custom` —— 指定 `.ico` 文件（多尺寸，建议含 256×256）；
  - `none` —— 保持加载壳原图标。
- **写入时机是硬约束**：图标必须在**加壳之前**写进加载壳 PE 资源段（Win32 `BeginUpdateResource`/`UpdateResource`，ctypes 调用，不依赖 pywin32），随后由 VMProtect 连着一起压缩。成品阶段（尾部已追加密文与配置）再动资源表会重建 `.rsrc`，壳按绝对偏移读配置的那条路直接断掉。
- **兼容 VMP**：实测 9 帧图标（16/32/48/64/256，含 8bpp 与 32bpp）经 VMProtect 压缩后全部保留；配置仍为内嵌态（`NBCFG1` 扫不到），`init ok=1`。
- **新文件** `packer/icon.py`：`read_ico()` / `read_exe_icon()` / `set_exe_icon()` / `preview_png()` / `describe()`。预览要把 ico 里的 DIB 帧转 PNG（1/4/8/24/32bpp 全支持，含 AND mask 兜底），用 zlib 手工编码 PNG，界面缩略图不引入 Pillow。
- **CLI**：`seal.py pack` 新增 `--icon {auto,none}` 与 `--icon-file <x.ico>`（给了文件就优先用文件）；打包输出新增「程序图标」一行。
- **GUI**：基础信息页新增「程序图标」卡片 —— 三段式切换 + 实时缩略图 + 「选择 .ico」；模式与路径随配置一起自动保存；完成弹窗标注图标来源。
- **踩坑**：`EnumResourceNamesW` / `EnumResourceLanguagesW` 不显式声明 `argtypes` 时，`HMODULE` 会按 C `int` 传，64 位下直接 `OverflowError: int too long to convert`。已在 `_load_kernel32()` 里补齐。

### 加载壳 / 打包器（配置搬进壳镜像 · VMProtect 真正保护配置）

- **问题**：此前是「先给加载壳加壳，再把配置追加到成品尾部 overlay」。VMProtect 只处理传给它的 PE 本体，**overlay 完全不碰** —— 于是加了壳的成品里 `strings` 照样能搜到 `api_url`、`app_key`、TLS 证书指纹、响应验签公钥，`seal.py info` 直接打印。也就是说加壳只保护了壳的代码，**配置一点没保护**。
- **改法**：配置改在**加壳之前**写进加载壳镜像的 `.rdata` 配置槽（`kCfgSlot*`：magic `NBCFG1`、32 字节头 + 16KB JSON 区，头内含长度与 CRC32），再交给 VMProtect 压缩加固，最后才追加加密载荷。`.rdata` 被压进虚拟机段后，成品里再也扫不到这些字符串。
- **VMP 选项**：`default.vmp` 的 `Options` 由 `726016` 改为 **`726272`**（= `726016 | 0x100` 打包压缩）。实测这一位是配置能否藏住的关键：只开虚拟化时 `MachineGuid`、`nebula-seal-builtin-v1` 等壳内字符串仍明文可见，加上 `0x100` 后全部消失。自定义项目缺这一位时打包器会告警，不静默降级。
- **踩坑（排查了一轮）**：`g_cfgSlot` 是 `const` 数组，打包器在**编译之后**才往里写，编译器对此一无所知 → `/O2` 把初始值（magic 命中、`length=0`）**常量折叠**进判定代码，运行时永远读到空槽并报「封包配置缺失」。修复：一律经 `volatile` 指针读取（新抽出的 `parseCfgSlot()`），强制真实访存。
- **兼容性**：`Trailer.flags` 新增 `0x0002`（配置内嵌）；内嵌成功时 `cfg_off=0`、`cfg_len=0`。`readPackage()` 三级回落：本镜像槽 → 尾部配置区（老包 / 配置超 16KB）→ 目标文件里的槽（只服务于 `selftest` / `e2e` 这类外部校验工具，运行时不会走到）。
- **CLI**：`info` 遇到内嵌包显示「内嵌于加载壳镜像（VMP 保护，静态不可读）」；`verify` 新增 `--app-key`，内嵌包在 builtin 模式下用它派生载荷密钥。GUI 完成弹窗标注「配置已进壳镜像 / 配置在尾部明文」。
- **附带修复**：`selftest` 在 builtin 模式下用「换错密钥」验证 GCM 认证会必然误报「错误密钥也能解密」（builtin 的 ikm 来自配置里的 `app_key`，与传入密钥无关）；改为翻转 `tag` 验证认证确实生效，现输出「全部通过」。
- **实测**（`GTA5_Enhanced_已加密.exe`，5,827,200 B；stub 583,680 → 加壳后 5,715,968）：
  - 明文扫描：`SW130C9B911FE5`、`yz.baige.fun`、`BEGIN PUBLIC KEY`、`f31dc7cd…`、`api_url`、`resp_pubkey`、`sec_sig`、`NBCFG1`、`MachineGuid`、`nebula-seal-builtin-v1` —— **全部未找到**；
  - 旧的静态提取脚本（按 trailer 偏移读配置区）已失效（`cfg_len=0`，且壳镜像内无明文槽）；
  - 运行验证：`封包读取成功 api=https://yz.baige.fun/... app_key=SW130C9B911FE5` → `机器码：0ba327a115e350f2（来源：MachineGuid）` → `域名证书：已锁定` → `init 返回 ok=1`；
  - 未加壳对照包（693,888 B）同样 `init ok=1`，说明落地路径与是否加壳无关。
- **文档**：`sealer/build_py/使用说明.txt` 的「成品里哪些内容是明文的」与「壳加固」两节按新行为重写，并补两条 FAQ（`strings` 还能不能搜到配置、`Options` 缺 `0x100` 的告警）；同时说明加壳只挡**静态提取**，运行时动态 dump 内存仍能拿到配置。

### 加载壳 stub（安全配置签名校验时序修复 · 去掉每个包必现的误报）

- **修复：正常包每次启动都误报「安全配置签名校验失败（配置区可能被修改）」**。签名校验写在 `unlock()` 里（需要 ikm：server 模式 = 登录后下发的 `feature_key`），而 `main.cpp` 在**登录前**就调 `applySecurity()` 应用本地策略 —— 此时 `verified` 仍是默认 `false`，被当成「签名无效」，于是 `sec_mode=off/custom` 的包在登录前被静默忽略、还附赠一条像是被篡改的日志，排查时极容易误判。
- **修复**：`SecurityConfig` 新增 `checked`（是否已完成校验），与 `verified`（校验结果）分离；`verifySecuritySignature()` 抽出为独立函数，并在两处调用：`readPackage()` 解析配置后（builtin 模式的 ikm 只依赖包内 `app_key`，此时就能校验）、`unlock()` 拿到 `feature_key` 后（server 模式）。`applySecurity()` 现在区分三种情况：未校验 → 「待登录后校验，先按服务端策略执行」；校验失败 → 「签名无效，已回落服务端策略」；通过 → 正常应用。
- **验证**（同一份 builtin 包三种变体）：正常包无任何签名告警；只改 `sec_mode=off` 不重签 → 正确报「签名无效，已回落服务端策略」；改 `off` 并用包内 `app_key` 重算签名 → 「运行时防护已关闭」（印证 builtin 模式签名只防随手改，已在 `使用说明.txt` 写明）。
- **文档**：`sealer/build_py/使用说明.txt` 新增「成品里哪些内容是明文的」——当时配置区（API/软件标识/TLS 指纹/安全策略/验签公钥）确实明文存放于文件尾部、`seal.py info` 即可读取；builtin 模式下载荷密钥由 `app_key` 派生，`seal.py verify --app-key … --dump` 就能离线解出原始 exe。（配置本身已在后续「配置搬进壳镜像」中改为由 VMP 保护，但 builtin 模式的载荷密钥性质未变。）敏感软件请改用功能密钥模式。

### 加载壳 stub（机器码稳定 · 修复「每次登录后台多一台设备」）

- **根因**：`stub/login_window.cpp` 构造 `Client::Options` 时用的是 `nebula::device::createMachineId()` ——这是 SDK 的**随机临时机器码**（`randomHex(8)`），只在调用方没有持久化机器码时兜底。壳每次启动都重新随机，于是后台设备列表里同一账号每登录一次就多一条（主机名/CPU/网卡/磁盘指纹全相同，只有机器码不同），设备名额很快被吃满，只能手动解绑。
- **修复**：壳内新增 `stableMachineId(appKey, &source)`，取值优先级：
  1. `HKLM\SOFTWARE\Microsoft\Cryptography\MachineGuid` 的 SHA-256 前 16 位 hex（重装系统才变）。32 位壳在 64 位系统上会被重定向到 `Wow6432Node`（该键不存在），所以先试 `KEY_WOW64_64KEY` 再试 32 位视图与默认视图；
  2. `MachineGuid` 读不到时用硬件指纹（主板/CPU/磁盘/BIOS/网卡，`device::collectFingerprintJson()`）摘要；
  3. 两者都取不到才随机生成一次并落盘 `%LOCALAPPDATA%\NebulaSeal\machine_<app_key>.id`，后续启动读回。
- **与源码版对齐**：算法与 `login/登录/UI/LoginWindow.cpp::GetStableMachineId` 逐字节一致，同一台机器上「加密版 / 源码版」算出同一个机器码，不会出现同一台机器两个设备记录。
- **可观测**：`loader.log` 新增 `机器码：<id>（来源：MachineGuid/硬件指纹/本地缓存）`，排障时一眼能看出取到哪一档。
- **验证**：本机连跑两次新壳，日志机器码均为 `0ba327a115e350f2`（来源 MachineGuid），init 均成功；打包产物 `seal.py verify` 通过。

### SDK 3.1.2（模块守卫误报修复 · 弱环境线索不再触发拦截 · 安全提示按动作区分）

- **修复：Windows 系统懒加载 DLL 被判「模块注入」**（`module_guard.hpp`）。`buildModuleBaseline()` 在首个 HTTP 请求**之前**建立基线，而 WinHTTP 一联网就会让系统懒加载 `nsi.dll`（NSP）与 `dhcpcsvc.dll`（DHCP），`init` 之后的复检必然把它们当成「新增模块」（各 10 分），再被服务端 `MODULE_INJECTION`（60/HIGH）放大成高危事件 —— 现象就是「程序正常启动却被判注入 / 被策略拦下」。现改为：
  - `ModuleInfo` 新增 `path`（`GetModuleFileNameW`）与 `system_image` 字段；
  - 新增 `isSystemImagePath()`：位于 `%SystemRoot%` 之下的模块直接并入基线，**不产生事件**；
  - 新增 `isLazyLoadSystemModule()` 白名单（`nsi` / `dhcpcsvc` / `dnsapi` / `winhttp` / `uxtheme` / `textshaping` / `twinapi.appcore` 等 40+ 个系统组件），路径取不到时兜底；
  - 事件 `reason` 附带模块路径，后台研判时能直接看出加载来源；
- **修复：`ModuleAdded` 无条件映射成 `MODULE_INJECTION`**（`client.hpp mapReportToEventType`）。语义上「新增」≠「注入」：
  - 已知注入框架（`frida-agent` / `scylla` / `lordpe` …）改用独立标记 `Flag::InjectionFramework`（新增 bit 24），仍映射 `MODULE_INJECTION`，`aggregateSeverity` 直接判 `High`；
  - 普通第三方新增模块改映射 `ABNORMAL_MODULE`（服务端 30/MEDIUM），且仅在累计分 ≥ 40 时上报，10 分弱线索不再产生事件；
- **修复：虚拟机 / 弱环境线索单独把处置级别推到 `MEDIUM`**（`runtime.hpp resolveSeverity`）。新增 `Report::env_weak_score`，`addHit` 按 `Kind::Vm` / `Kind::EnvWeak` 分别累计；定级改用 `score - vm_score - env_weak_score`。这些线索只描述机器形态（Hyper-V、虚拟网卡 OUI、驱动残留、低配、开机过短），不是侵入证据，服务端也没有对应事件类型 —— 此前一台开了 Hyper-V 的开发机每次启动都是 100+ 分 → `MEDIUM` → 被后台「中危动作」直接吊销会话，与事实完全不符。分数仍完整保留在 `report.score` 与事件详情里供后台研判；
- **错误码**：新增 `UnexpectedModule(2006)`、`Environment(3003)`；`securityErrorCode` 兜底由 `CodeTamper(2002)` 改为 `Environment(3003)`，VM 线索单列 `3002`，避免弱线索被读成「程序被改过」；
- **弹窗正文按生效动作分档**（`securityPopupMessage(code, action)`）：`RevokeSession(4)` →「本次会话已被安全策略吊销，请重启程序后重新登录」；`Terminate(3)` →「程序已被安全策略终止」；`Degrade(2)` →「程序功能已被安全策略限制（降级）」。此前无论哪种动作正文都固定是「程序无法继续运行」，用户无法区分「策略拦截」与「程序崩溃/网络故障」；
- **新增 `protect::securityActionLabel(action)`**：处置动作中文标签（仅记录/上报/降级/终止程序/吊销会话），接入方状态栏、日志、自绘 UI 可直接复用；
- **加载壳 `stub` 拦截提示**：「按策略中止」路径的状态栏文案改为「正文首行 + 处置动作」，完整弹窗文案（含错误码）逐行写入 `loader.log`；
- 同步 `sdk-cpp`、`login/登录/sdk/` 两份 C++ 副本与 `SDK.md` / `SDK_PROTECTION.md`（新增 13.0.3 模块守卫判定表）；三语言 SDK 版本号统一 `3.1.2`（Python / C# 无模块守卫，行为不变）；
- **验证**：MSVC 14.51 编译通过；冒烟测试 `test_module_fix` 在加载 `nsi.dll` + `dhcpcsvc.dll` 后 `scanModules()` 返回 **0 个事件**，纯 VM 线索（105 分）判定为 `Info`（动作「仅记录」），注入框架仍为 `High`。

### SDK（安全提示按「处置动作」区分 · 错误码不再一律 2002）

- **弹窗正文按生效动作分档**（`protect::securityPopupMessage(code, action)`）：`RevokeSession(4)` →「本次会话已被安全策略吊销，请重启程序后重新登录」；`Terminate(3)` →「程序已被安全策略终止」；`Degrade(2)` →「程序功能已被安全策略限制（降级）」。此前无论服务端下发哪种动作，正文都固定是「程序无法继续运行」，用户无法区分「策略拦截」与「程序崩溃/网络故障」——把中危动作设成 `REVOKE_SESSION` 时尤其误导；
- **新增 `protect::securityActionLabel(action)`**：处置动作中文标签（仅记录/上报/降级/终止程序/吊销会话），接入方状态栏、日志、自绘 UI 可直接复用，不必自造第二套措辞；
- **错误码分类修正**（`securityErrorCode`）：兜底由 `CodeTamper(2002)` 改为新增的 `Environment(3003)`；虚拟机线索单列 `VirtualMachine(3002)`、运行期新增模块单列 `UnexpectedModule(2006)`。此前「虚拟网卡 OUI + 懒加载系统 DLL」这类弱线索会显示成 `NBL-RT-2002 代码篡改`，看起来像程序被改过；
- **加载壳 `stub` 拦截提示**：「按策略中止」路径的状态栏文案改为「正文首行 + 处置动作」，完整弹窗文案（含错误码）逐行写入 `loader.log`，排障时不用再猜是哪一档动作触发的；
- 同步 `sdk-cpp`、`login/登录/sdk/` 两份 C++ 副本与 `SDK.md`。

## [2.66.4.12] - 2026-10-19

### 安全（密码强度校验口径统一 · 修复两处漏网入口）

- **后端漏网入口修复**：代理管理两处密码校验（新增代理初始密码、重置代理密码）仍是裸 `strlen($pass) < 8`，未走统一策略 —— `aaaaaaaa` 这类全同字符弱口令可被直接设置。现改为 `Util::passwordIssue()`，错误原因直接透传中文提示。至此全项目 8 处密码校验入口 100% 统一；
- **后台前端预检与后端对齐**（`admin/assets/js/pages/agent.js`）：新增 `passIssue()` 镜像后端普通档规则（8-64 位、须含字母和数字、弱口令黑名单、全同字符拦截），新增代理与重置密码两条链路提交前预检；此前前端仅卡长度 `p.length < 8`，`aaaaaaaa` 前端放行、提交后才被后端拒绝（提示不同步）；
- **个人中心改密前端预检收紧**（`admin/assets/js/pages/profile.js`）：后端此处走**管理员严格档**（≥10 位 + 须额外含大写字母或符号），前端却只卡 `length < 8`，已补齐完整规则与占位提示文案；
- **一致性验证**：从两个 JS 文件直接抽取实际代码与后端 `Util::passwordIssue` 逐条比对，24 条密码 × 2 档 = 48 项判定与文案完全一致；
- README 版本号同步至本版（`lib/bootstrap.php` 为单一版本源）。

### SDK（统一安全提示出口 · 加载壳不再自造弹窗）

- **新增 `nebula::protect::securityPopup()` / `securityPopup(report)`**：防护命中弹窗的唯一实现（标题=init 下发软件名、正文只给 `NBL-RT-xxxx`、`MB_TOPMOST`）。`enforce()` 的 `action >= 3` 分支改为调用它，行为不变、实现合一；
- **新增 `nebula::alertBox(msg, suffix, icon)`**：无 Client 场景（init 之前）的统一提示窗，标题按「Nebula - 后缀」拼；`Client::uiAlert` 的默认实现改为转调它，接入方 `setUiHandler` 接管能力不受影响；
- **加载壳 `stub` 删除自带 `MessageBoxW`**：init / 登录中 / 启动前 / 巡检四类拦截统一走 `securityPopup()`，被踢下线改用 `Client::kickText()` + `uiAlert("kick")`，登录/解锁/启动失败走 `alertBox`，登录窗内状态栏文案也直接取 SDK 安全提示首行 —— 同一份策略不再出现「SDK 一套文案、壳另一套文案」；
- 同步 `sdk-cpp`、`login/登录/sdk/` 两份 C++ 副本，`SDK.md` 新增「统一安全提示」小节，`SDK_PROTECTION.md` 补充接线约定。

### 加密客户端（打包器 UI · 域名证书指纹 + 日志收起）

- **基础信息页新增「域名证书指纹」**：对应 SDK 的 `Options::tls_cert_sha256` —— 原先前端没有入口，服务端证书无法锁定。输入支持 openssl 的冒号格式，打包时统一归一化为小写无分隔 64 位十六进制写入配置区；配了指纹却用 `http://` 直接拒绝打包（SDK 届时也会拒绝请求）；旁边「取指纹」命令条单击即复制；
- **壳侧接线**：`Config::tls_pin` → `Options::tls_cert_sha256`，加载时再归一化并校验长度（非 64 位直接报配置错误，不再表现为「网络连不上」）；启动日志新增「域名证书：已锁定 / 未锁定」，排障时能一眼区分指纹填错与网络问题；
- **运行日志新增「收起」**：与「清空」并排，收起后只留标题栏（高度 156px → 33px），按钮在「收起 / 展开」间切换；每次开始打包自动展开，保证过程可见；
- `seal.py` 打包日志 / CLI 新增域名证书状态输出，`--tls-pin` 参数；`使用说明.txt` 补充该项说明。

### 加密客户端（修复打包后缺 cryptography 依赖）

- **现象**：点「一键加密」后日志报 `意外错误: No module named 'cryptography'`，出不了包；
- **根因**：`seal.py` 是以**数据文件**（`--add-data seal.py:packer`）放进包内的，PyInstaller 只静态分析入口 `app.py` 的 import 链，看不到 `seal.py` 里那两处函数内延迟导入的 `AESGCM`，于是 `cryptography` 及其 Rust 扩展没被收集；
- **修复**：`app.py` 顶部显式 `from cryptography.hazmat.primitives.ciphers.aead import AESGCM`（用 try/except 包住，源码模式缺依赖时仍能开界面），打包命令同时加 `--hidden-import cryptography --hidden-import cryptography.hazmat.primitives.ciphers.aead` 双保险；
- **验证**：exe 体积 70.4MB → 74.2MB（+3.7MB，`_rust.pyd` 已入包）；实测两条路径均出包成功 ——
  不勾加壳：GTA5_Enhanced.exe（111,104 字节）→ 659,453 字节成品；勾选加壳：→ 6,755,837 字节成品，弹窗标注「已加壳」；
- 使用说明补充该问题与「配置自动保存」两节。

### 加密客户端（界面配置自动保存 / 恢复）

- **所有填写与开关改动即存盘**：目标程序、加载壳、输出路径、验证 API、软件标识、功能密钥、证书指纹、登录窗标题、版本号、公告、响应公钥，以及安全策略（来源 / 等级 / 10 项检测位掩码 / 三档动作 / 严格模式 / 巡检开关与间隔）、壳加固（自动加壳开关 / VMProtect 路径 / 项目文件），改动后 0.7 秒自动写入 `NebulaSealClient.ini`；下次打开原样恢复，不用重复填；
- **配置文件位置**：优先工具同目录（绿色版习惯，可随目录一起拷走）；目录不可写时自动退到 `%APPDATA%\NebulaSealClient\NebulaSealClient.ini`；
- **实现要点**：
  - 输入框走 0.7 秒防抖（避免每敲一个字符就写一次盘），开关 / 下拉 / 勾选 / 分段按钮即时触发；
  - 写盘用 `写临时文件 + os.replace` 原子替换，中途断电不会留下半截配置；
  - 公钥这类多行文本按 `\n` 转义成单行存储，读回时还原，ini 里一行一个键、便于人眼检查；
  - 启动恢复期间置 `_loading` 标志，默认值初始化不会把旧配置覆盖掉；
  - 恢复时做存在性校验：上次的目标程序 / 加载壳 / VMProtect 若已删除，只记一条日志并忽略，不会卡住启动；
  - 左下角状态闪一下「已自动保存」再回到「就绪」，打包进行中不打扰；
- **实测**：23 项字段往返一致（含中文标题/公告、多行 PEM、位掩码 682、`watch_ms=4500`）；改动 → 防抖落盘 → 重开恢复全链路通过；真机输入 `https://yz.baige.fun/api/index.php` 与证书指纹后重开，两项均自动回填，安全策略页检测项全勾、动作三档、严格模式、巡检间隔 2000ms 全部一致。

### 加密客户端（壳加固改为「随加密自动加壳」· 勾选生效）

- **加壳并入加密流程**：原先要先在「壳加固」页手动点「给当前壳加壳」，再回基础信息页选中加壳产物打包，多一步且容易忘；现在改为一个复选框「加密时自动给加载壳加壳」，勾上后点「一键加密」即可，加壳 + 写配置 + 出包一次完成；
- **顺序固定为「先给壳加壳 → 再写配置」**：`pack()` 在读完原始壳后调 VMProtect 命令行，加壳产物再拼载荷密文与配置区。反过来对成品加壳行不通 —— VMP 会压缩/重排尾部 overlay，壳按绝对偏移就读不到配置了（实测加壳后成品里连 trailer 魔数都不在文件末尾）；
- **重复加壳保护**：按 PE 段名识别（`.nb` / `.vmp*`），传入的壳本身已加壳时直接跳过并提示，不会壳上加壳；
- **模板中性化**：随附 `vmp\default.vmp` 原为第三方工具的项目文件，内含他人桌面绝对路径与产品名，且虚拟机段名沿用 `.xb`（该工具缩写）。现改为 `InputFileName=""` / `OutputFileName=""`（打包时动态写入临时路径）、段名改为 `.nb`（实测产物段名 `.nb0` / `.nb1`，识别正常），`seal.py` 内置兜底模板与 `is_vmpped()` 判定同步更新，源码注释里也不出现第三方产品名；
- **实测**：原版壳 547,328 → 加壳 6,670,336 字节，成品 7,090,170 字节，登录窗正常、封包读取成功（`stub=6670336`）；不勾选时壳保持 547,328，成品 967KB，两条路径均验证可运行；
- `seal.py`：新增 `--vmp` / `--vmp-project` 参数，`pack()` 新增 `vmp_exe` / `vmp_project` 入参与 `vmpped` / `stub_raw_size` 返回字段，CLI 输出「加载壳加固」状态；VMProtect 命令行与模板随包分发在 `packer_flet/vmp/`（`VMProtect_Con.exe` + `default.vmp`，压缩 + 入口点/虚拟化）；
- 界面：壳加固页改为开关式（默认关），VMP 路径与项目文件自动探测、可手动改；勾选但文件不存在时打包前拦截并跳回该页；
- **图标链路核实**：`flet pack -i logo.ico` 已把 7 个尺寸（16/24/32/48/64/128/256）写入 exe 的 `RT_GROUP_ICON`，逐帧字节数与 `logo.ico` 完全一致；运行时窗口类图标（`GCLP_HICON`）导出渲染同为该 logo，任务栏显示正常。若某个历史路径下仍显示 Windows 默认白色窗口图标，属系统图标缓存（`%LocalAppData%\Microsoft\Windows\Explorer\iconcache*.db`）未失效，与包本身无关；换新文件名即可立即正确，或结束资源管理器 → 删除 `iconcache*.db` → 重启资源管理器。

### 加密客户端（界面框架迁移 Flet · 品牌与细节统一）

- **界面框架由 Web 换成 Flet（Flutter 渲染）**：不再启动本地 HTTP 服务、不再借 Edge `--app` 开窗口。启动即出界面，没有白屏、端口占用、Edge 版本差异；打包逻辑仍调 `packer/seal.py` 的 `pack()`，与命令行同一份实现。源码在 `sealer/packer_flet/`（`app.py` + `winpick.py` + `assets/`）；
- **全局字体统一微软雅黑**：`ft.Theme(font_family="Microsoft YaHei")`，日志、命令条等原来写死 `Consolas` 的地方一并收口，中文不再回退到宋体、西文字形也统一；
- **程序图标统一**：窗口标题栏 / 任务栏走 `window.icon`，exe 文件图标走 `flet pack -i assets/logo.ico`（16→256 七个尺寸），侧栏品牌位换成同一张 logo（PNG 已抠掉白底，透明背景直接融进侧栏）；
- **胶囊开关调小**：`Switch(scale=0.78)` 并把说明文字从 `label` 移到 `Row` 里，开关不再吞掉一整行高度，文字也不跟着被缩小；
- **窗口打开居中**：`Window.center()` 在 Flet 1.0 是协程、且需等客户端建出窗口才生效，改为 `page.run_task` 延迟调用；窗口尺寸按桌面工作区自适应（125%/150% 缩放下原本 780 会顶出屏幕），首屏自动回到顶部；
- **侧栏默认选中「基础信息」**：初始化补一次 `goto(0)`，此前只设了页面可见性、没设高亮态，打开时导航项全是未选中；
- **证书指纹输入提示改为真实格式**（如 `f31dc7cddbed…`，64 位小写十六进制）；openssl 输出的带冒号写法仍然接受并自动归一化。

## [2.66.4.11] - 2026-10-10

### 安全（更新授权门禁加固 · 采纳静态审计建议）

- **发行包静态直链封禁**：`update-system/.htaccess` 整目录拒绝 `releases/`，并新增 `releases/.htaccess` 纵深防御（双层）。更新包只能经 `api/download.php` 限时令牌下载；Nginx 部署需在 server 块补 `location ^~ /releases/ { deny all; }`；
- **门禁异常 fail-closed**：`api/version.php` 授权服务异常/DB 不可用时不再退回公开下载地址 —— 新增 `storage/license_gate.on` 粘性标志，门禁曾开启而状态不可读时直接拒绝下发 `download_url`（`License::gateEnabled()` 重构）；
- **授权码改 POST 传输**：后台 `system_update_check` 不再把 license_key 拼查询字符串，重定向仅允许 HTTPS 且锁定最终主机必须为配置的更新服务器；`api/version.php` 同步支持 POST 读取；
- **配置写入校验**：后台 `license_manage` 与安装器的 config.php 写入失败时明确报错（授权已在服务器绑定，需手动补写 license_key 或解绑重试），不再谎报激活成功；
- **试用授权域名唯一约束**：`licenses.domain` 改可 NULL + 唯一索引 `uk_domain`（NULL=未绑定），并发「先查后插」不再可能为同一域名重复领取；`update-system/install/schema.sql` 已更新，存量库执行 `update-system/migrate_uk_domain.php`（幂等，CLI 或本机访问）；
- update-system 版本 1.2.2 → 1.2.3。

## [2.66.4.10] - 2026-10-10

### 安全（登录时序加固）

- **login 能力检查前移**：`require_runtime_guard` 强制策略的客户端能力检查从「创建会话 + 设备校验/绑定之后」前移到所有写入操作之前 —— 能力不符的登录被 7002 拒绝时不再残留会话记录、不再刷写设备 IP 或占用绑定名额（审计发现，原 186/213 行时序问题）；
- **nginx 配置示例**：`install/nginx.conf.example` 补充隐藏目录拒绝规则（`.git`/`.svn`/`.env`，放行 `/.well-known`），防止仓库目录直接部署时源码泄露（纵深防御）；
- **RateLimit 文档化 fail-open 语义**：类注释明确「存储异常时放行」的取舍，提示运维对限流存储做健康监控；
- README 版本号以 `lib/bootstrap.php` 的 `NB_VERSION` 为单一版本源同步。

## [2.66.4.9] - 2026-10-10

### SDK 发行版（纯 DLL SDK v2.2 · 四端全家桶）

- **新增第四种 SDK 形态：NebulaSDK 纯 DLL 黑盒**（`Nebula-DLL-SDK-v2.66.4.zip`，x86/x64 二进制分发，面向易语言/Delphi 等）：
  - 完整 Nebula 3.1 协议封装（ECDH P-256 + AES-256-GCM + ES256 验签），纯 Windows CNG 实现、零第三方依赖，`__stdcall` 公开 API，调用方零密码学代码；
  - **运行时安全策略对齐三端**：自动捕获 login/init/heartbeat 下发的 `runtime_policy`（`Nebula_GetRuntimePolicy`）；`Nebula_SecurityCheck` 有限自检并按服务端策略处置（REPORT/POPUP/TERMINATE/REVOKE_SESSION）；`Nebula_ReportRuntimeEvent` 事件上报（失败入队随心跳补发）；登录自动声明 `capabilities.runtime_guard`；
  - 可选自动心跳线程 `Nebula_SetAutoHeartbeat`（默认关闭）；内置提示框 API + 反调试 + 敏感内存清零 + Release 加固。
- **修复（v2.1）**：登录请求 `const char*` 实参被隐式转 `bool`（`"code": true`）导致登录必败（3001/1001）；Feature 解密密钥派生对齐生态（`|nebula-feature-aes/-mac`）；响应 sid 一致性校验。
- **四端全家桶 Release**：tag `v2.66.4-dll-sdk-release`（GitHub + Gitee），DLL v2.2 + 三语言源码包（版本号统一 2.66.4）。

## [2.66.4.8] - 2026-10-06

### 优化（试用弹窗）
- 门户「免费获取试用授权码」弹窗文案改为**动态读取后台配置**（trial_days / trial_daily_limit），
  不再硬编码"7 天 / 每日限 3 枚"；修改后台试用配置后前台立即生效。
- 新增 GET /api/license.php 接口返回当前试用配置，供前端弹窗拉取。

## [2.66.4.7] - 2026-10-06

### 优化（文案）
- 授权缺失提示改为指向后台「系统更新」页的授权激活卡片（不再让用户手改 config.php）。

## [2.66.4.6] - 2026-10-06

### 修复（授权）
- `license_manage` 激活成功后的审计日志参数类型错误（$old 传 null，Audit::log
  要求 array）导致激活成功但接口 500 —— 改为空数组。

## [2.66.4.5] - 2026-10-06

### 修复（授权）
- `license_manage` 激活时若 config.php 为老模板缺 `license_key` 键，**自动在
  update_server 后插入该键**并继续激活，不再要求手动改配置（老站直接可用）。

## [2.66.4.4] - 2026-10-06

### 优化（文案）
- 授权卡片未激活提示精简：「但后续开启更新门禁后将无法在线获取新版本」→「但后续将无法在线获取新版本」。

## [2.66.4.3] - 2026-10-06

### 优化（文案）
- 后台「系统更新」授权卡片提示精简：「但后续开启更新门禁后无法在线获取新版本」→「但后续将无法在线获取新版本」。

## [2.66.4.2] - 2026-10-06

### 修复（登录风暴 / 更新弹窗复现 / 授权卡片）
- **登录后立即「登录已过期」风暴**：`system_update_check` 的授权错误误用 1002
  （前端 `AUTH_FAIL_CODES` 成员，会清空会话令牌并触发登出）→ 改为 1001。
  触发场景：更新门禁开启且本站未激活时，登录后强制检查即中招。
- **升级后「重新检查」又弹更新提示**：版本检查缓存改为按当前版本隔离
  （`system_update_check_<ver>`），且一键更新完成后主动清检查缓存 +
  `opcache_reset()`（共享主机 opcache 时间戳校验延迟会短暂跑旧代码）。
- **授权卡片渲染加固**：状态获取失败/超时不再中断卡片渲染（骨架先上、状态后填）。

## [2.66.4.1] - 2026-10-06

### 优化（安装器 / 版本约定）
- **版本节奏修订（首个四段号版本）**：大版本（新功能/协议/安全架构）= 三段号
  `X.Y.Z`；小版本（bug/补丁/小优化）= 四段号 `X.Y.Z.0`。版本比较与校验逻辑
  （系统更新页 compareVersion、system_update_do、download.php）均已支持四段号。
- 安装器新增官方门户文字跳转（新标签页）：标题下「访问官方网站与文档 ↗」+
  授权码提示中的「官方门户」链接。

## [2.66.4] - 2026-10-06

### 修复（发版流程）
- 2.66.2 的「授权激活」卡片代码（license_manage 接口 / 系统更新页前端 /
  权限登记）遗漏同步到发布仓库，导致升级到 2.66.2 的站点看不到该卡片 —— 本版补齐。

## [2.66.3] - 2026-10-06

### 优化（安装器）
- 授权提示精简：「但后续开启更新门禁后无法在线获取新版本」→「但后续无法在线获取新版本」。

## [2.66.2] - 2026-10-06

### 优化（授权 / 后台）
- 安装器提示文案微调：「官方开启更新门禁后」→「后续开启更新门禁后」。
- **后台「系统更新」页新增「授权激活」卡片**：显示当前授权状态（已配置/未激活、
  脱敏授权码、绑定域名）；授权过期或更换授权码时可直接重新填写激活，
  激活成功自动写入 config.php 并刷新更新检查结果。
- 新增后台接口 `license_manage`（status/activate，SETTINGS_INFRA 权限域）。

## [2.66.1] - 2026-10-06

### 优化（安装器 / 授权）
- **授权激活码移至安装向导第一页**：环境检测页直接填写（选填），格式即时过滤校验；
  安装完成后**自动绑定当前域名激活**并写入 config.php，第三页显示激活结果，
  未成功时保留「重新激活」入口（宽限模式，不阻断使用）。
- 配套 update-system v1.2.1：门户新增「免费获取试用授权码」（每日 3 枚、7 天有效）、
  说明文案与 FAQ 同步授权体系；授权管理页门禁开关改自定义弹窗、新增批量启用/停用/删除、
  行操作改样式化按钮。

## [2.66.0] - 2026-10-06

### 新增（授权体系 —— 源码分发的授权锚点）
- **安装器授权激活步骤**：安装成功页可输入官方授权码激活（调 update-system
  `api/license.php` 激活并绑定部署站域名），成功写入 `config.php` 的 `license_key`；
  未激活不影响使用（宽限模式），仅影响后续在线更新获取。
- **config.php 新增 `license_key` 配置**（默认空，随官方安装包自带该键）。
- **system_update_check 携带授权**：检查更新时自动带上本站 `license_key` + 部署域名，
  服务器开启更新门禁而本站未授权时给出明确错误与指引（不再静默无下载地址）。
- 配套的 update-system v1.2.0：授权码表/管理页/激活验签 API/更新门禁/限时签名下载
  （详见 update-system 仓库 CHANGELOG）。门禁默认关闭，存量部署不受影响。

## [2.65.40] - 2026-10-06

### 安全（后台子账号密码强度统一）
- 后台「用户管理」新建/编辑用户（`admin/handlers/user_save.php`）与批量导入
  （`admin/handlers/user_import.php`）的密码校验从「仅长度 ≥ 6」升级为统一
  `Util::passwordIssue()`：长度 8-64 + 必须含字母和数字 + 弱口令黑名单 + 全同字符拦截，
  与后台管理员改密、发卡网、代理端口的密码策略对齐。
- 仅作用于「设置新密码」链路，已有弱密码用户照常登录。
- 全面审计（2026-10-06，总分 94/A）确认的最后一处密码策略扣分项，本轮修复。

## [2.65.39] - 2026-10-06

### 安全（三端 SDK 运行时安全策略对齐 C++）

- **Python SDK 补齐服务端 runtime 策略层（对齐 C++/C#）**：
  - 登录自动携带 `capabilities: {runtime_guard:true}` —— 服务端开启 `require_runtime_guard` 强制策略时才放行（此前 7002 被拒）；
  - 新增 `nebula/protect.py`：`finite_detect()` ctypes 有限反调试/反VM·沙箱/时序自检（可靠不误报）+ `client.report_runtime_event()` 事件上报；
  - 事件失败入队、随心跳补发（对齐 C++ heartbeat 前 flush）。
- **C# SDK 补齐 `ReportRuntimeEvent`**：把 `RuntimeProtection.Scan()` 结果上报到 `runtime_security_event`，
  网络失败入队、心跳前补发。
- 三端保持：客户端自报 `risk_level/risk_score` 仅作 telemetry，处置等级由服务端 `RuntimeRiskEngine` 二次定级（防自报压缩处置）。

## ## [2.65.38] - 2026-10-06

### 修复（系统更新 · 后台目录自动映射）

- **更新包含 `admin/` 但站点后台改名成 `nbXXXX` 时，后台代码整段漏更**：原逻辑仅依赖
  `config admin.path`（默认 admin，未配置就写到根目录 admin/，真后台得不到更新）
- 改为：`admin.path` 配置优先；未配置时**自动探测站点根下的后台目录**（特征 `AdminAuth.php` +
  `assets/js/app.js` + `handlers/`），支持多个后台目录同步更新（备份与覆盖均按映射多份执行）
- 效果：今后任何发版，后台前端与 handlers 都会与站根一起更新，无需手工同步

## ## [2.65.37] - 2026-10-06

### 变更（版本号规范）

- **版本号双档格式**：大版本 `V主.次.修订`（如 `V2.66.0`）= 功能 / 协议 / 安全架构级；小版本 `V主.次.修订.小修`（如 `V2.66.0.1`）= 日常修复 / 安全补丁
- `min_version` 只写大版本三段，小版本永不抬高；前端比较与上传接口已支持 4 段

### 修复（前台图表 & 两个新页面视觉打磨）

- **全局图表修复**：`core/chart.js` 刻度浮点尾巴（全 0 数据时 Y 轴曾显示 0.30000000000000004 这类读数）→ 收敛显示；`fmtNum` 小数统一 2 位
- **页面打磨**：卡片同行等高、图表与表格高度统一、空态占满卡片主体（解决图表下方大面积空白）
- **留存复购页布局调整**：图类在上（逐日活跃 / 代理充值走势），表与指标在下（留存队列 / 复购率）
- 配套视觉增强已合并在 2.65.36：全局设计 token（间距/圆角/阴影/焦点环/动效）、`:focus-visible` 焦点可见、`prefers-reduced-motion` 支持、数据表吸顶表头、导航键盘可达（tabindex + Enter/Space）、active 主色指示条、非纯颜色选中反馈

## ## [2.65.36] - 2026-10-06

### 新增（后台 · 管理页面补齐）

- **数据大屏页（bigscreen）**：原接口存在但一直无前端页面（功能不可达），现补齐 —— 实时在线 / 今日概览 / 累计总量三组卡片，在线曲线（近 N 小时）、新增·激活·API 调用曲线（近 N 天）、代理销量排行 TOP10、卡密类型分布、缓存与心跳运行状态、最近动态；30s 自动刷新，支持清空缓存（超管）
- **留存复购页（analytics）**：同样补齐前端页面 —— 留存概览与 D1/D3/D7 留存队列、活跃分层（今日/7 天/30 天/沉默）、逐日活跃与登录曲线、用户与代理商复购率、代理充值走势
- 两个接口此前在 README 宣称存在但后台没有入口，现菜单「总览 → 数据大屏 / 留存复购」可访问（user.read 权限档）

## ## [2.65.35] - 2026-10-06

### 新增（后台 · 更新提示）

- **登录后新版本提示**：进入后台时检测到有新版本（未低于最低版本线）时，弹出可关闭的普通提示框（「稍后再说」/「前往系统更新」），不再静默错过更新
- **强制弹窗收窄**：不可关闭的强制封锁弹窗仅在「当前版本低于 `min_version`（最低支持版本）」时触发（`force_update`），一般性新版本不再锁后台

## [2.65.34] - 2026-10-06

### 安全修复（多租户隔离全链路闭合 · 攻击者视角审计）

- **租户隔离补全（21 个后台 handler/lib）**：此前租户管理员可通过 ID 枚举跨租户读取/操作数据，本次全链路闭合：
  - `user_detail` / `user_kick` / `user_batch_op` / `user_export`：跨租户 IDOR / 批量越权 / 整库导出（用户、设备、会话、卡密记录）
  - `card_export` / `device_ban` / `device_ban_list`：卡密导出、机器码拉黑、黑名单列表的租户范围过滤
  - `plan_save` / `shop_goods_save` / `shop_goods_delete` / `shop_goods_import`：官网套餐与发卡商品的软件归属校验
  - `software_web_get` / `software_web_save`：官网分站配置跨软件读写
  - `agent_list` / `agent_detail` / `agent_save`：代理商资产（卡密/批次/额度/余额）跨租户读写
  - `group_save` / `group_batch`：用户组为全局对象，仅平台管理员可管理（租户管理员拒绝）
  - `session_list`：在线会话按租户范围过滤
  - 批量操作（用户/设备/商品）遵循「整单拒绝」：混合 ID 中任一越权即整体拒绝，杜绝边界枚举侧信道
- **公告软件隔离**：`notice` 接口按 app_key 归属软件过滤（software_id=0 通用），单条查询同样过滤
- **logout 如实返回**：服务端在令牌无效（含缺 machine_id）时返回真实错误，不再虚假返回成功而会话未销毁
- **Runtime 事件服务端定级**：处置等级改由服务端静态表（`RuntimeRiskEngine::EVENT_SCORES`）决定，客户端自报 risk_level/risk_score 仅作 telemetry，防止「自报 CRITICAL 自毁 / 自报 LOW 压低处置」两端滥用
- **速度限制分级**：`api/index.php` 按 action 收紧阈值 —— handshake 20/min、login 30/min、register 10/min（Config `security.*_limit_per_min` 可调），其余接口维持统一限额
- **更新器重定向校验**：`system_update_do` 下载跟随 302 后重新校验最终域名白名单，越界删包中止

### SDK 兼容性修复（C++ / C#）

- `devices()` / `userinfo()` / `logout()` / `unbindDevice()` 补齐 `machine_id`（服务端 2026-10-03 起强制设备绑定校验，旧调用会被拒绝）
- 登录请求在编译/运行期启用 RuntimeGuard 时上报 `capabilities.runtime_guard=true`（服务端强制防护策略 7002 才能放行）
- SDK 版本宏统一 3.1.1：`NEBULA_SDK_VERSION` / `SdkConfig.SdkVersion` 由 3.1.0 修正（此前影响 sdk_version 审计与兼容判断）
- Python 登录器 logout 本就携带 machine_id，无需变更

## [2.65.32] - 2026-10-05

### 新增（运行时安全 · 违规自动冻结闭环）

- **服务端自动冻结**：`RuntimeEventService::handle` 对 sticky 硬证据事件（代码篡改 / 受保护代码失败 / 手动映射等，见 `RuntimeRiskEngine::EVENT_SCORES`）触发自动处置——设备永久拉黑（`Device::addBan`，幂等写 `nb_device_bans` + 解绑 + 踢下线全部会话）+ 该用户已激活卡密一次作废（`Card::freezeByUser`，仅 status=1 的卡）+ 事件标记已处理。目标：本地把客户端 patch 得再干净也没用，事件上报即冻结
- 新增 `Device::addBan()`（与后台 device_ban 等价、`admin_id=0` 系统自动）、`Card::freezeByUser()`（作废 + card_logs 审计）
- 防误杀：调试器 / 沙箱 / 虚拟机等非 sticky 事件不触发冻结，只累计风险分；误杀可后台 `device_unban` 解除拉黑 + 恢复卡密
- **客户端 SDK 配套**（随 SDK 发行包同步）：`NebulaClient::CreateClient` 显式接线 `setProtectAction(3)` / `enableProtection(0,5000)` / `registerCriticalCode(login,256)`；SDK 新增自毁钩子 `protect::setSelfDestructCallback/selfDestruct`（Critical 弹窗前先清零内存凭据）与 `Client::wipeSensitiveData()`；登录主循环每帧 `TickGuard()` 功能侧耦合

## [2.65.31] - 2026-10-04

### 修复（安全自检 · 权限点未登记）

- 2.65.30 新增的 `security_check` handler 漏了在 `AdminPermission::ACTION_PERM` 登记，RBAC 默认拒绝未登记 action → 审计日志出现 `admin_rbac 失败 未登记权限点` 且首页卡片不显示；已登记为 `settings.security` 档

## [2.65.30] - 2026-10-04

### 新增（SDK · 敏感数据内存保护）

- `SecureBuffer`（core/secure_string.hpp）：擦除型字节缓冲 —— 析构/`wipe()` 时 volatile 写擦除（编译器不可优化掉），可选 `VirtualLock` 锁页防止明文被交换到磁盘页面文件
- `feature::openSecure()`：`open()` 的内存保护升级版 —— 核心数据解密进 SecureBuffer，解密临时副本返回前立即擦除；旧 `open()` 保留兼容
- `LoginResult::wipeFeatureKey()`：功能密钥用完立即安全擦除（`nebula::secureZero` + `shrink_to_fit`，幂等）
- 新增 `secureZero(p,n)` / `secureWipe(str)` 通用擦除工具
- SDK_PROTECTION.md §0.0 新增使用说明（推荐时序：login → openSecure → wipeFeatureKey）
- MSVC TU 编译 + 运行时验证：seal/openSecure 往返、错误密钥拒绝、篡改拒绝、擦除幂等 全部通过

### 新增（后台 · 管理面安全加固）

- **管理员密码严格档**：`Util::passwordIssue($plain, true)` —— ≥10 位 + 字母数字 + 必须含大写字母或符号（普通用户仍为 8 位档）；管理员新增/编辑/重置密码、个人中心改密四处入口全部切换
- **安全自检**（新 handler `security_check` + 数据概览页卡片，需 settings.security 权限）：扫描 6 类风险 —— 管理员 2FA 覆盖（danger）、默认账号名 admin（warn）、软件未配置功能密钥（warn）、运行时防护策略覆盖缺口（warn）、备份文件暴露面（info）、密码策略档说明；汇总徽章一屏看清（N 项高危 / N 项注意 / 状态良好）

## [2.65.29] - 2026-10-04

### 修复（防护配置 · 关闭防护不生效）

- **根因（策略匹配回落链）**：策略按「软件专属 > 全局(software_id=0)」匹配；某软件两者都没有时**回落内置默认策略（标准级全开）**。用户把等级 0 策略建在了 SWDEFAULT（software_id=1）上，测试软件无覆盖 → 一直拿内置默认全开策略
- `RuntimePolicy::forSdkClient()`：防护被关闭（enabled/status 关闭或**等级=0**）时下发**完全静默策略**（enabled=false、modules=0、三档动作=0 RECORD、strict=false）——此前等级 0 仍下发 enabled=true + TERMINATE/吊销动作 + strict，语义不一致
- `RuntimePolicy::actionForLevel()`：防护关闭时事件处置回落 `RECORD`，服务端事件自动处置（阻断会话/踢线）同步停用
- 后台「防护策略」编辑弹窗：「软件ID」裸数字框改为**下拉选择**（全局 + 软件列表）；列表页与编辑弹窗补策略匹配规则说明（无匹配 = 内置默认标准级全开）
- 用户数据修正：策略 #13 软件范围 SWDEFAULT → 全局（等级 0 现已覆盖所有软件）

## [2.65.28] - 2026-10-04

### 修复（用户管理列表）

- 删除「风险评分模型」功能后，表头漏删 `<th>风险</th>` 导致列不对齐；已移除并修正 `colspan` 从 11 → 10

## [2.65.27] - 2026-10-04

### 移除（用户管理 · 风险评分模型）

- 删除 `RiskScore` 类及相关功能：不再在登录失败时自动计算用户风险分、不再自动冻结账号
- 移除后台「用户管理」页面的风险评分列和评分详情弹窗
- 移除后台「系统设置」页面的风险评分模型配置项（IP异常权重、账号失败权重、设备异常权重、代理异常权重、自动冻结阈值）
- 删除 `lib/RiskScore.php`、`nb9b6f51/handlers/user_risk.php`

## [2.65.26] - 2026-10-04

### 修复（防护配置 · 列表翻页全坏）

- **翻页点一下就只剩「首尾两个页码」**：rt_security.js 手搓的分页用混合大小写属性 `data-evPage`/`data-rsPage` 等，HTML 属性名会被浏览器转小写，`btn.dataset[prefix]` 读到 undefined → `parseInt(undefined)=NaN` → 页码条只渲染 `i===1 || i===totalPages` 两个按钮，且服务端收到 page=NaN 兜底为第 1 页（翻了没反应）。**四个列表页（安全事件/风险会话/风险设备/防护策略）全部命中**。改用全局共享 `pager()` + `bindPager()`（`data-go` 属性，与设备管理等页面同款，附带「共 N 条，第 x/y 页」）

### 变更（防护配置 · 批量操作与其他页面对齐）

- 安全事件批量操作从独立一行改为**标准工具栏批量框**：`createSelection()` + `checkAllBox()`/`rowCheckBox()` + `.bulk-inline`（选中后在操作区出现「批量已处理/批量误报」下拉+执行，与设备管理页同款交互）；勾选框点击不再冒泡触发行详情

### 变更（SDK · 清理疑似环境策略编译期开关）

- 删除 `config.hpp` 的 `kProtectStrictPolicy` 编译期常量及 `setProtectAction()` 里的 `setSuspiciousPolicy()` 引导调用：strict 策略自 2.65.23 起已完全由服务端 `runtime_protection.strict` 下发、init/heartbeat 自动应用并覆盖，编译期默认值无任何生效场景（反链路排查：定义 → 引导 → 覆盖 → fallback 兜底，全链路无消费点）。SDK_PROTECTION.md §3.7.2 同步注明策略控制权归服务端、宿主手动 `setSuspiciousPolicy()` 会被下一次下发覆盖。需重新编译登录器生效

## [2.65.25] - 2026-10-04

### 修复（风险会话「解除」登出的真正根因）

- **body 参数名 `token` 劫持管理员认证**：`SessionCookie::fromRequest()` 取令牌优先级为 X-Token 头 > **body.token** > Cookie，而「风险会话-解除」请求体恰好携带 `{token: 客户端会话令牌}` —— 入口把客户端令牌当成管理员令牌验证，必然 1003 并 `SessionCookie::clear()` 登出管理员（请求从未到达 handler，2.65.24 的 SQL 修复无法触达）。参数更名为 `session_token`（handler 与前端同步），并全库排查确认无其他 body.token 用法
- 附：同批 [2.65.24] 修复的 handler 内 SQL（MySQL 1235 的 `IN (SELECT...LIMIT)` + `Database::update` 位置参数 HY093）在本根因修复后才开始真正生效

## [2.65.24] - 2026-10-04

### 修复（后台「防护配置」）

- **风险会话「解除」必失败**：`rt_session_unblock` 同步恢复 3.1 会话层的 UPDATE 使用 `IN (SELECT ... LIMIT 1)` 子查询（MySQL 1235 不支持）且占位符与 `Database::update` 命名参数机制不兼容（HY093），两层错误叠加导致点击解除必然 500。改为去掉 LIMIT 的 IN 子查询 + 命名参数
- **业务错误误用 1002 导致后台被登出**：`rt_session_unblock` / `rt_device_unblock` / `rt_event_detail` / `rt_policy_detail` / `rt_policy_save` / `rt_policy_delete` / `file_delete` 的"对象不存在"类业务错误原先返回 1002，与前端 `AUTH_FAIL_CODES=[1002,1003]`（登录过期自动登出）冲突 —— 任何一次对象失配都会把管理员踢回登录页。全部改为 1001
- 后台左侧菜单「运行时安全」更名「防护配置」（含面包屑、子页「运行时策略」→「防护策略」）

### 新增（后台）

- **安全事件批量操作**：事件列表新增复选框 + 全选 + 批量已处理/批量误报（新接口 `rt_event_batch`，单次 ≤500 条，RBAC 沿用 RT_SECURITY_EVENTS，含审计日志；`RuntimeEventService::markHandledBatch()` 单条 UPDATE 完成）

### 修复（SDK 事件详情）

- **事件「模块名」恒为空**：SDK 上报 details 仅含拼接文本 `{"detail": "..."}`，服务端白名单里的 `module_name` 从未被填充。现在 `scan()` 汇总时提取「最高严重级且带模块信息的命中事件」的模块名（`Report::primary_module`），`reportDetailJson()` 输出 `details.module_name`；纯调试器/环境类检测无模块归属时保持为空

## [2.65.23] - 2026-10-04

### 新增（等级即策略：检测模块并入三档防护等级并真实下发）

- 检测模块不再单独勾选，随防护等级整档下发：`RuntimePolicy::levelModuleMask()` 定义各等级预设掩码（关闭=0 / 基础=反调试+反VM沙箱+代码完整性 / 标准=+API钩子+代码补丁+模块守卫+内存守卫 / 严格=+进程守卫+时序+环境痕迹），`forSdkClient()` 新增 `modules` 字段随 `runtime_protection` 下发（init / heartbeat）
- SDK 端按掩码门控各检测模块（`runtime_policy.hpp` ModBit 位定义与服务端严格一致；`effectiveModules()` 与编译期能力取交，服务端不能提权）；四个守卫模块（模块/内存/进程/代码完整性扫描）首次纳入等级控制
- 关闭语义修复：后台选「关闭」下发 `level:0` / `enabled:false` 时客户端**真关闭**（停止全部检测 + 停看门狗）；仅"从未收到策略"才回落编译期默认

### 修复（三档处置动作按选择实际执行）

- **高危/严重动作不生效**：SDK 各守卫事件产生时已定级（注入=High、代码补丁/完整性=Critical），但 scan() 汇总时丢弃事件分级，enforce() 仅靠 flag/score 反推，大量真实命中被降级为 Medium → 只执行中危动作。现在汇总保留事件最高分级（`Report.severity_hint`），处置时与 flag 推导**取更严重者**，后台的中危/高危/严重动作下拉严格对应 `medium_action` / `high_action` / `critical_action` 落地
- 修复 `module_guard.hpp` 默认零加固配置（`NEBULA_PROTECT_LEVEL=0`）下的遗留编译错误（ModuleInfo 结构体被门控在存根引用之外）

### 变更（后台）

- 运行时策略编辑器移除 7 个检测项 + 4 个行为独立勾选框：检测模块改为随防护等级联动的只读展示（本等级检测模块清单 + 说明），违规处置并入中危/高危/严重三档动作、看门狗开关随等级生效；`NB_VERSION` → 2.65.23 刷新缓存

> 注：[2.65.22]（2026-10-03 审计修复）未及记录：响应签名私钥轮换（prod kid=80a54302）、响应加密独立密钥 `sk_enc_rsp=HKDF(sk_enc,info="nebula31-enc-rsp")`（四端同步，SDK ≥ 3.1.1 才能连 2.65.22+ 服务端）、`Session::validate` 增加 requireMachine 强制参数、unbind all 强制密码、FileGuard 拒绝 config/lib/install 目录访问。

## [2.65.21] - 2026-10-03

### 移除（3.0 遗留下线）

- 软件级静态通信密钥体系整体下线：删除「重置密钥 / 平滑轮换」后台入口与 software_reset_keys 接口、Software::resetKeys / rotateKeysGraceful / prevKeys / genAesKey / genSignSalt，软件列表与编辑表单不再暴露 AES_KEY / SIGN_SALT
- 3.1 协议（ECDH P-256 + AES-256-GCM）下客户端与配置文件均无静态对称密钥，上述功能无任何消费方；nb_softwares 表历史列保留不动（无读取方，无害）
- 安全巡检报告移除「密钥重置追踪」段（对应审计动作已不存在）

## [2.65.20] - 2026-10-02

### 新增（3.1 协议，2.65.17–2.65.20 汇总）

- **Nebula 3.1 协议正式上线**：ECDH P-256 握手（SIGMA 简化）+ HKDF 派生会话密钥 + AES-256-GCM 信封 + 单调 seq 防重放；客户端零静态对称机密。请求带 `sid` 自动走 3.1，服务端对旧 3.0 信封返回 1001 由 SDK 回落
- 三端 SDK 同步升级：C++（handshake.hpp，修复请求信封漏前置 IV 导致 GCM 认证失败）、Python（envelope.py 重写，cryptography 库实现）、C#（新增 NebulaSecure31.cs）；废弃的 `kAesKey` / `kSignSalt` 静态占位全部移除

### 修复

- 后台「通信加密」展示文案 3.0 化残留（写死 AES-256-CBC + HMAC-SHA256）→ 改为 ECDH P-256 + AES-256-GCM / ES256
- 后台「响应签名公钥」恒显示「尚未生成」：setting_get 漏发 `resp_sign` 段（公钥实际已生成于 config/resp_sign_keys.php），已补发 algo/kid/public_key
- 操作日志部分条目无操作人（「-」）：模型层 Logger::log 未传管理员上下文 → Logger 增加从 `$GLOBALS['nb_admin']` 兜底补齐，历史空条目无法回补

## [2.65.16] - 2026-09-30

### 修复（风险评分）

- 用户管理「风险评估」弹窗底部「关闭」按钮无效：按钮参数误写 `class`（组件识别 `cls`）且未绑定 `act`，点击无反应；已修复并绑定 closeModal
- 风险权重读取修正：RiskScore 原读 config 文件 security.risk_*（无出厂值，实际恒为 0），改为后台设置优先、出厂默认（30/20/20/30/80）兜底

### 新增（权重可视化配置）

- 系统设置 → 安全策略新增「风险评分模型」区：四维权重 + 自动冻结阈值（0~200 钳制；阈值 0 = 关闭自动冻结），仅超管可改，保存后下轮评分生效

## [2.65.15] - 2026-09-30

### 新增（风险评分模型）

- 新增 lib/RiskScore.php：四维加权评分——IP 异常 +30 / 账号失败 +20 / 设备异常 +20 / 代理异常 +30（权重 security.risk_* 可配），评分缓存 10 分钟
- 自动冻结：总分 ≥ security.risk_freeze_score（默认 80）且账号正常时置 status=2 并写日志 action=risk_freeze；每次客户端登录失败后自动重评
- 后台：用户列表新增「风险」列（≥80 高危红 / ≥40 关注黄，点击查看四维命中明细弹窗）；新增 user_risk 接口（user.read）；管理员改动状态后自动刷新该用户评分缓存

## [2.65.14] - 2026-09-30

### 修复（安全巡检重复落盘）

- SecReport::run 增加 persist 参数：后台实时查看只读不写报告文件/日志，修复每次刷新向 sec_report_*.txt 重复追加同一条异常的问题；cron 每日持久化路径不受影响

## [2.65.13] - 2026-09-30

### 调整（安全巡检独立入口）

- 侧边栏「系统」组新增独立菜单「安全巡检」页：实时巡检 + 立即巡检按钮 + 历史报告文件视图；审计日志页恢复原样

## [2.65.12] - 2026-09-30

### 新增（后台安全报告入口）

- 审计日志页顶部新增「🩺 安全巡检报告」面板：进入页面即实时巡检一次，展示近 24h 异常明细（级别/类型/明细行）与历史报告折叠视图
- 新增 `sec_report` 接口（`audit.read` 权限，自定义权限清单同步）

## [2.65.11] - 2026-09-30

### 新增（工程化 / 下一阶段基建）
- **密钥平滑轮换**：软件通信密钥支持「宽限期双钥并行」轮换（默认 7 天，
  `security.key_grace_days` 可调，0=关闭）。老客户端在宽限期内自动回落旧钥验签，
  在线用户无感知；宽限期外旧钥自动失效。与既有「立即重置」（旧客户端即时失联）并存，
  应急掐断用重置、例行轮换用平滑轮换。
  - 数据库：`nb_softwares` 新增 `aes_key_prev` / `sign_salt_prev` / `keys_rotated_at`
    （老库执行 `install/migrate_key_rotation.php`，新装 schema 已含）
- **多租户（基于代理商体系）**：软件可归属代理商（`softwares.owner_agent_id`），
  管理员可绑定为租户管理员（`admins.agent_id`）——登录总后台仅可见归属软件及其
  用户 / 卡密 / 设备数据，单条写操作越权直接拒绝（4031）。
  隔离逻辑集中在 `lib/Tenant.php`，默认拒绝：无法确定归属的数据不可见。
  （老库执行 `install/migrate_tenant.php`）
- **每日安全审计报告**（`lib/SecReport.php`，cron 第 11 节自动执行，每日一次）：
  暴力破解嫌疑（同 IP 登录失败 ≥10）、撞库嫌疑（同账号）、密钥重置追踪、
  代理商卡密突增；异常写入 `logs/sec_report_<date>.txt` 并记日志，
  `php cron.php --force-sec` 可立即执行
- **发布工具**（仅开发站，不入分发）：`deploy/make_release.php`——
  `pack` 打空白发布包（含逐文件 md5 的 MANIFEST），`diff` 对比两版本生成增量更新包
  （含 DELETED.txt 待删除清单）

## [2.65.3] - 2026-09-29

### 新增（Python SDK）
- **官方 Python SDK（`sdk-py/`）**：协议与 C++ 版同规格——加密信封通信、ES256|RS256 响应验签
  （未配置公钥拒绝连接）、password / username_code / code 三种登录自动适配、心跳接管（被踢 /
  顶号自动回登录）、完整性自校验、自动更新（仅 https 更新地址，hash+size 双校验）、弹窗/立即
  公告（已读记录 30 天清理）、功能密钥 NF1（`feature.seal` / `open_pack`）。
  内置 Pygame 桌面登录界面（440×420 无边框登录窗 + 800×500 主窗），接入方零 UI 代码；
  `nebula/config.py` 为唯一配置文件。接入文档见 [sdk-py/README.md](sdk-py/README.md)。

### 新增（功能密钥）
- **功能密钥（Feature Key）**：「下发解密密钥，不下发验证结果」的数据防破解能力。
  后台「软件管理」可为每个软件配置一把随机密钥（可一键生成，≤128 字符），**只在 login
  成功响应中下发**（`data.feature_key`，init 是免验证接口刻意不下发）；登录失败 /
  被踢 / 会话过期后密钥不出现。接入方用它加解密随程序分发的核心数据包 ——
  patch 掉登录判定分支也拿不到密钥，核心数据永远停留在密文状态。
  - 数据库：`nb_softwares.feature_key` 列（全新安装走 `install/schema.sql`；
    老库升级执行 `install/migrate_feature_key.php`）；
  - 数据包格式 `NF1`：`NF1.<b64(iv+AES-256-CBC)>.<hex(HMAC-SHA256)>`，
    encrypt-then-MAC 先验签后解密，密钥域分离派生（`|nebula-feature-aes` / `|nebula-feature-mac`）；
  - SDK：新增 `nebula/client/feature.hpp`（`seal()` 开发期加密 / `open()` 运行期解密），
    `LoginResult` 新增 `feature_key` 字段，login 解析自动填充；
  - 后台：「软件管理」编辑表单新增功能密钥输入框 + 随机生成按钮，列表返回该字段；
  - 文档：`docs/API.md` 新增「2.18 功能密钥协议」章节（NF1 格式 / PHP 侧制作示例 / 安全边界）。

### 修复（接口）
- `login.php` 成功响应中 `$sw` 未定义（软件识别后未保存引用），功能密钥等按软件
  下发的字段会被 `?? ''` 静默吞成空串 —— 已在入口处捕获 `Software::current()` 修复。


### 修复（支付）
- **微信支付 V3 回调按官方规范完全重写**（`lib/Pay.php` `wechatVerifyNotify`、`shop/wechat_notify.php`）。
  旧实现对标准 V3 通知 100% 无法工作，本次修复：
  - `AEAD_AES_256_GCM` 解密：`base64(ciphertext)` 解码后**末 16 字节为 tag**，
    `associated_data` 作 AAD、12 字节 nonce 作 IV、APIv3 密钥（32 字节）作密钥；
  - 从**解密后的 transaction** 取 `out_trade_no / amount.total / trade_state / transaction_id`
    （外层通知体并无这些字段）；
  - `Wechatpay-*` 请求头验签（验签串 `{ts}\n{nonce}\n{raw}\n`，平台证书 SHA256）；
    CGI 模式 fallback 取头时正确还原连字符（`HTTP_WECHATPAY_*` 下划线 → 连字符）；
  - `Authorization` 请求头改为官方格式
    `WECHATPAY2-SHA256-RSA2048 mchid="..",nonce_str="..",signature="..",timestamp="..",serial_no=".."`；
  - 新增 `trade_state == SUCCESS` 校验、±300 秒重放防护、APIv3 密钥长度校验；
  - 应答规范：成功 `200 + {"code":"SUCCESS"}`，失败 **5xx + FAIL JSON**（触发微信重试，
    旧实现返回 200 会被微信视为应答成功、永不重试）；`trade_no` 改记微信 `transaction_id`。

### 修复（SDK）
- `downloadUpdate()` 的 TLS 指纹锁定只对 **API 同 host** 继承（`tls_cert_sha256`）；
  跨域更新包（文件床 / CDN）不再误继承 API 指纹导致 100% 被拦截，
  仍有 WinHTTP 标准证书链校验 + 下载后强制 hash / 大小校验兜底。
- `Client::options()` 新增运行期访问器（`auto_update_optional` 等策略可在 init 前调整）。
- **内置弹窗标题改用 init 下发的软件名**（`alertTitle()`）：公告 / 版本更新 / 维护 /
  下线通知 / 安全校验共 8 处统一为「软件名 - 主题」，未取到软件名时回退「Nebula 主题」。

### 文档
- `docs/API.md` 新增「支付回调（异步通知）」章节（微信 V3 验签 / 解密 / 应答规范）。
- 新增本更新日志。

## [2.65.1] - 2026-09（服务器先行热修复）

- 仅部署于生产服务器的过渡版本（`lib/bootstrap.php` 版本号已步进，改动未回填本仓库）。
  以服务器部署记录为准；本仓库自 2.65.2 起恢复「代码-版本-日志」同步。

## [2.65.0] - 2026-09

### 新增
- **响应签名（ES256/RS256 双重验签）**：业务响应附带签名，客户端可验证响应来源与完整性。
- **双向自更新模块**：SDK `init` 下发版本信息（`version.{need_update,force_update,latest,update_url,file_hash,file_size,self_file_hash...}`），
  `autoUpdate()` 完成下载 → SHA256/大小校验 → 替换脚本 → 重启；`enforceSelfIntegrity()` 自身完整性校验。
- 数据库迁移 `install/migrations/2.sql`（对应版本 2.65.0，前置 >= 2.64.4）。
- 后台「系统更新」页：对接版本更新系统（update-system），一键下载 → SHA256 校验 → 备份 → 解压覆盖 → 版本号步进。

[2.65.3]: https://gitee.com/xinia/online-verification/commits/master
[2.65.2]: https://gitee.com/xinia/online-verification/commits/master
[2.65.1]: https://gitee.com/xinia/online-verification/commits/master
[2.65.0]: https://gitee.com/xinia/online-verification/commits/master

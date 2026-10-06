# 更新日志（CHANGELOG）

本文件记录 Nebula 网络验证系统各版本的变更。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循语义化版本（`主.次.修订`）。每次发版请在本文件顶部追加条目，并同步
`lib/bootstrap.php` 的 `NB_VERSION`；发布到版本更新系统时，把对应条目整理为 `release_notes`。

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

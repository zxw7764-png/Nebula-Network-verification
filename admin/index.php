<?php
/**
 * 管理后台统一入口
 * 路由: /admin/index.php?action=xxx
 *
 * 认证方式: 请求头 X-Token 或 body.token
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// ------------------------------------------------------------------
// 先读请求体并决定响应编码方式
// 管理端默认返回明文 JSON（便于前端 JS 处理）；传 encrypt=1 才加密。
// 必须在任何 Response::error 之前执行，否则错误响应会以默认加密方式输出，
// 前端 JS 拿到密文后无法读取 msg。
// ------------------------------------------------------------------
$input = Util::input();
Response::setEncrypt(!empty($input['encrypt']));

// ------------------------------------------------------------------
// 会话 Cookie 引导（P1-09）
// 令牌优先从 HttpOnly Cookie 读取（JS 读不到，XSS 无法窃取）。
// 仍兼容 X-Token 头，便于旧客户端与调试。
// ------------------------------------------------------------------
SessionCookie::init(
    'nb_admin_sid',
    'admin.cookie_session',
    'admin.cookie_name',
    'admin.cookie_secure'
);

// ------------------------------------------------------------------
// 入口密钥校验（与页面入口共用一套 cookie）
// 优先读数据库（安装时自动生成），未设置时回落 config.php
// ------------------------------------------------------------------
$entryKey = (string) (Setting::get('admin_entry_key') ?: Config::get('admin.entry_key', ''));
if ($entryKey !== '') {
    $expectCookie = hash('sha256', $entryKey . '|' . Util::ip());
    $cookieVal    = $_COOKIE['nb_entry'] ?? '';
    if (!hash_equals($expectCookie, $cookieVal)) {
        // 不返回 JSON 错误（会暴露后台 API 存在），直接输出仿真 404 页面
        fake_404_exit();
    }
}

// ------------------------------------------------------------------
// 管理端 IP 白名单
// ------------------------------------------------------------------
if (Config::get('admin.ip_whitelist_enable')) {
    $allow = (array) Config::get('admin.ip_whitelist', []);
    if ($allow && !in_array(Util::ip(), $allow, true)) {
        Response::error(1004, 'IP 不在白名单内');
    }
}

$action = $_GET['action'] ?? '';
if ($action === '') {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($base && substr($uri, 0, strlen($base)) === $base) {
        $action = trim(substr($uri, strlen($base)), '/');
    }
}
$action = preg_replace('/[^a-z_]/', '', strtolower($action));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// captcha 由登录页 <img> 原生 GET 拉图，属预期行为
if ($method !== 'POST' && $action !== '' && $action !== 'captcha') {
    Response::error(1001, '请使用 POST 请求');
}

// ------------------------------------------------------------------
// 无需登录的接口
// ------------------------------------------------------------------
$publicActions = ['login', 'captcha', 'ping'];

// ------------------------------------------------------------------
// 认证
// ------------------------------------------------------------------
$admin = null;
if (!in_array($action, $publicActions, true)) {
    // 取令牌：X-Token（旧客户端） > body.token > HttpOnly Cookie（新默认）
    $token = SessionCookie::fromRequest($input);

    if ($token === '') {
        Response::error(1002, '请先登录');
    }

    // ------------------------------------------------------------------
    // 会话密钥（P0-02）：与 token 分离的第二因子。
    // token 只是会话标识，泄露即可被冒用；session_key 只在登录时下发一次，
    // 服务端存摘要。已启用绑定的会话必须同时提供两者才放行。
    // Cookie 模式下这层尤其重要：Cookie 会被浏览器自动携带，
    // 攻击者若能诱导浏览器发请求（CSRF），仍拿不到 JS 持有的 session_key。
    // 历史会话（sk_hash 为 NULL）不校验，保证升级不踢人。
    // ------------------------------------------------------------------
    $sessionKey = $_SERVER['HTTP_X_SESSION_KEY'] ?? ($input['session_key'] ?? '');
    $sessionKey = is_string($sessionKey) ? $sessionKey : null;

    $admin = AdminAuth::check($token, $sessionKey ?: null);
    $GLOBALS['nb_admin'] = $admin; // 供 Tenant 等静态工具类读取当前管理员上下文
    if (!$admin) {
        // 会话失效：把 Cookie 一并清掉，避免浏览器反复带着无效令牌
        SessionCookie::clear();
        Response::error(1003, '登录已过期，请重新登录');
    }

    // ------------------------------------------------------------------
    // RBAC 权限校验（P0-01）
    // 按 lib/AdminPermission.php 的 ACTION_PERM 表逐 action 校验。
    // 取代旧实现「只拦 role=3」的单点判断：旧写法下 role=2 操作员与超管
    // 在业务上完全等同，可以改安全设置、生成卡密、发代理充值卡。
    //
    // 注意：这里只做「有没有资格访问该 action」的粗粒度校验；
    // 更细的分档（例如 setting_save 内部按 security/infra/business 分档）
    // 由对应 handler 自行调用 AdminPermission::require() 完成。
    // ------------------------------------------------------------------
    AdminPermission::requireAction($admin, $action);
}

// ------------------------------------------------------------------
// CSRF 校验：写操作必须带正确的 CSRF 令牌
// 令牌由页面入口（home.php）注入到前端，随 X-CSRF 头或 body.csrf 提交。
// 只读接口（GET 语义）豁免，避免影响正常浏览。
// ------------------------------------------------------------------
$csrfExempt = [
    'login', 'captcha', 'ping',
    'dashboard', 'stat_overview', 'user_list', 'user_detail', 'card_list',
    'agent_list', 'agent_detail', 'agent_code_list',
    'card_batch_list', 'card_detail', 'device_list', 'device_ban_list', 'session_list', 'log_list',
    'audit_list', 'audit_detail', 'notice_list', 'version_list', 'group_list',
    'setting_get', 'profile',
    // 系统更新检查：只读
    'system_update_check',
    // 软件管理：只读列表
    'software_list',
    // 官网互动功能：只读列表接口
    'message_list', 'feedback_list', 'plan_list', 'seller_list', 'screenshot_list',
    // 发卡订单：只读列表
    'shop_order_list',
    // 导出类：只读，不修改数据
    'user_export', 'card_export',
    // Runtime Security：只读列表
    'rt_overview', 'rt_event_list', 'rt_event_detail', 'rt_policy_list', 'rt_policy_detail',
    'rt_risk_devices', 'rt_risk_sessions',
];
if (!in_array($action, $csrfExempt, true)) {
    $csrf = $_SERVER['HTTP_X_CSRF'] ?? ($input['csrf'] ?? '');
    if (!Util::csrfCheck(is_string($csrf) ? $csrf : null)) {
        Response::error(1006, '请求校验失败（CSRF），请刷新页面后重试');
    }
}

// ------------------------------------------------------------------
// 管理端限流
// ------------------------------------------------------------------
if ($action !== '' && $action !== 'ping') {
    if (!RateLimit::hit('admin:' . Util::ip(), 300, 60)) {
        Response::error(5001, '操作过于频繁');
    }
}

// ------------------------------------------------------------------
// 人机风控（防自动化 / 防逆向）
// ------------------------------------------------------------------
// 后台是全站权限最高的一环，一旦被脚本接管（撞库、批量导出卡密、
// 遍历用户）损失远大于前台，因此这里比官网更严：
//   · 判定为自动化 → 直接拒绝并累计风险分（连续命中触发临时封禁）
//   · 判定为可疑   → 放行但收紧限流（300/分 → 30/分）
// 无信号（老前端 / 禁用 JS）一律放行，兜底交给验证码、CSRF 与 RBAC。
// ------------------------------------------------------------------
// 已登录管理员豁免封禁：会话本身已过 token + session_key + CSRF + RBAC
// 四道校验，比风控分可信得多。更重要的是留一条自救通道 —— 万一风控
// 误判把管理员自己的 IP 封了，他仍能进后台把开关关掉。
if (Guard::isBanned() && empty($admin)) {
    Response::error(1008, '检测到异常访问，已被临时限制，请稍后再试');
}

$guard = Guard::assess('admin:' . $action, $input, ['challenge' => false]);
if ($guard['block']) {
    $banned = Guard::punish('admin:' . $action, $guard['score']);
    Logger::log('admin_' . $action, 0, '风控拦截：' . implode(',', $guard['reasons']), ['ip' => Util::ip()]);
    Response::error($banned ? 1008 : 1007, '检测到自动化访问，请求已被拒绝');
}
if ($action !== '' && $action !== 'ping' && !in_array($action, $csrfExempt, true) && Guard::suspicious()) {
    if (!RateLimit::hit('adminsus:' . Util::ip(), 30, 60)) {
        Response::error(5001, '操作过于频繁');
    }
}

// ------------------------------------------------------------------
// 敏感操作二次校验
// ------------------------------------------------------------------
// 会话被 XSS 窃取、共享终端未退出、笔记本被盗 —— 这些场景下 token +
// session_key + CSRF 全都会被一起带走。但「删除软件」「重置通信密钥」
// 属于不可逆动作：换一次钥就会让该软件所有客户端集体失联。
// 因此这类动作额外要求重新输入当前登录密码，让攻击者即使拿到会话
// 也无法完成破坏。
//
// 白名单集中在这里：新增敏感动作只需加一个 action 名，
// 前端对应弹窗把密码放进 confirm_pwd 字段即可。
// ------------------------------------------------------------------
$sensitiveActions = [
    'software_delete',       // 删除软件：卡密/账号数据一并失效
];
if (!empty($admin) && in_array($action, $sensitiveActions, true)) {
    $confirmPwd = $_SERVER['HTTP_X_CONFIRM_PWD'] ?? ($input['confirm_pwd'] ?? '');
    $confirmPwd = is_string($confirmPwd) ? $confirmPwd : '';

    if ($confirmPwd === '') {
        Response::error(1010, '该操作需要输入当前登录密码确认');
    }
    if (!AdminAuth::verifyPassword((int) $admin['id'], $confirmPwd)) {
        Logger::log(
            'admin_' . $action,
            0,
            '敏感操作二次密码校验失败',
            ['admin_id' => (int) $admin['id'], 'username' => $admin['username'] ?? '']
        );
        Response::error(1010, '密码错误，操作已取消');
    }
}

// ------------------------------------------------------------------
// 分发
// ------------------------------------------------------------------
// $requestData 兼容别名：部分 handler（如 rt_*）使用 $requestData 读取参数，
// 与 $input（Util::input()）指向同一份数据，保持双向兼容。
$requestData = $input;

try {
    $handler = __DIR__ . '/handlers/' . $action . '.php';
    if ($action === '' || !is_file($handler)) {
        Response::error(1001, '未知的管理接口: ' . $action);
    }
    require $handler;
} catch (Throwable $e) {
    Logger::log('admin_' . $action, 0, $e->getMessage());
    if (Config::get('debug')) {
        Response::error(9999, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    Response::error(9999, '服务器内部错误');
}

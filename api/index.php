<?php
/**
 * 统一 API 入口
 * 路由: /api/index.php?action=login
 * 或重写: /api/login
 *
 * 支持的所有 action:
 *   init        客户端初始化（获取配置、公告、版本信息）
 *   register    注册
 *   login       登录
 *   heartbeat   心跳
 *   activate    激活卡密
 *   unbind      解绑设备
 *   devices     查询已绑定设备
 *   userinfo    获取用户信息
 *   notice      获取公告
 *   version     版本校验
 *   online      在线人数（公开接口，无需登录）
 *   logout      退出登录
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// ------------------------------------------------------------------
// 路由解析
// ------------------------------------------------------------------
$action = $_GET['action'] ?? '';

// 支持 /api/login 形式（配合 .htaccess 重写）
if ($action === '' && !empty($_SERVER['PATH_INFO'])) {
    $action = trim($_SERVER['PATH_INFO'], '/');
}
if ($action === '') {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($base && substr($uri, 0, strlen($base)) === $base) {
        $action = trim(substr($uri, strlen($base)), '/');
    }
}
$action = preg_replace('/[^a-z_]/', '', strtolower($action));

// ------------------------------------------------------------------
// 调用统计：注册到 shutdown 阶段落库
// ------------------------------------------------------------------
// 为什么不用 try/finally —— Response::send() 内部是 exit()，
// 而 PHP 在 exit() 时【不会】执行 finally 分支，统计会一次都不写库。
// register_shutdown_function 在 exit / 致命错误时同样会执行，可靠得多。
// 注册点放在所有校验之前，保证 限流 / 维护模式 / 版本过低 等早退路径也被计入。
$startMs = (int) (microtime(true) * 1000);
register_shutdown_function(static function () use ($action, $startMs): void {
    Logger::stat(
        $action !== '' ? $action : 'unknown',
        Response::lastCode() === 0,
        (int) (microtime(true) * 1000) - $startMs
    );
});

// 公开只读接口：允许 GET、允许明文、不计入每日配额。
// 注意：init 自 3.1 起不再明文放行 —— 客户端必须先 handshake 再走 3.1 信封调用。
// handshake = Nebula 3.1 ECDH 会话握手（明文 JSON，完整性靠响应 ES256 签名）
$publicActions = ['notice', 'version', 'online', 'handshake'];

// 只允许 POST（查询类接口除外）
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' && !in_array($action, $publicActions, true)) {
    Response::error(1001, '请使用 POST 请求');
}

// ------------------------------------------------------------------
// 读取并解析请求
// ------------------------------------------------------------------
$input      = Util::input();
$enforce    = (bool) Config::get('security.enforce_crypto', true);
$whitelist  = (array) Config::get('security.plain_whitelist', []);
$allowPlain = !$enforce || in_array($action, $whitelist, true) || $action === 'handshake';

// ------------------------------------------------------------------
// 多软件识别：外层明文字段 app_key 指认软件（3.1 会话/握手均按 app_key 归属）。
// 未携带或无效时回落默认软件（兼容旧接入方）。
// ------------------------------------------------------------------
$nbSoftware = Software::resolve($input);
if (!$nbSoftware) {
    Response::error(1004, 'app_key 无效或软件已停用');
}
Software::setCurrent($nbSoftware);

try {
    $parsed      = Crypto::parseRequest($input, $allowPlain);
    $requestData = $parsed['data'];
} catch (CryptoException $e) {
    Logger::log($action ?: 'unknown', 0, 'crypto: ' . $e->getMessage(), ['raw' => $input]);
    $codeMap = [
        'missing_field' => 1001,
        'time_expired'  => 5003,
        'bad_sign'      => 5002,
        'replay'        => 5004,
        'decrypt_fail'  => 5002,
        'bad_json'      => 1001,
    ];
    // 无论请求是明文还是加密信封，响应一律加密（客户端持钥可解，
    // 防止明文响应被抓包直接读取出参/错误细节）。
    // 响应签名盐已在 parseRequest 内按请求盐（主盐/会话盐）设置。
    Response::error($codeMap[$e->reason] ?? 5002, $e->getMessage());
}

// ------------------------------------------------------------------
// 会话强制：3.1 起所有非公开接口必须持 ECDH 会话（sid 信封）。
// 静态密钥/kid 会话盐机制已随 3.0 移除。
// ------------------------------------------------------------------
if (empty($parsed['plain']) && empty($parsed['session31'])) {
    Response::error(5002, '缺少 3.1 会话信封，请先调用 handshake');
}

// ------------------------------------------------------------------
// 维护模式检查（分软件：该软件单独设为「维护中」时只维护它；
// 在线人数照常放行，避免维护页上数字直接归零）
// ------------------------------------------------------------------
if (Policy::maintainModeFor($nbSoftware) && !in_array($action, ['init', 'notice', 'online'], true)) {
    Response::error(6002, Policy::maintainMsgFor($nbSoftware));
}

// ------------------------------------------------------------------
// 客户端版本强制更新检查（公开接口豁免，否则老客户端连在线人数都拿不到）
// ------------------------------------------------------------------
$clientVer = Util::str($requestData, 'client_ver', Util::str($input, 'client_ver', ''));
if ($action !== '' && !in_array($action, $publicActions, true)) {
    // 最低版本线：多软件时用该软件自己的配置，回落全局设置
    $minVer = Software::minVersion($nbSoftware);
    if ($clientVer !== '' && $minVer !== '' && Util::versionCompare($clientVer, $minVer) < 0) {
        Response::send(6001, '客户端版本过低，请更新后使用', [
            'min_version' => $minVer,
            'latest'      => $nbSoftware['latest_version'] ?: Config::get('version.latest_client_version'),
            'update_url'  => $nbSoftware['update_url'] ?: Config::get('version.update_url'),
            'force'       => true,
        ]);
    }
}

// ------------------------------------------------------------------
// IP 限流
// ------------------------------------------------------------------
$limit = Policy::rateLimitPerMin();
if ($action !== '' && !RateLimit::byIp($action, $limit)) {
    Logger::log($action, 0, '请求过于频繁', ['raw' => $input]);
    Response::error(5001, '请求过于频繁，请稍后再试');
}

// ------------------------------------------------------------------
// 每日接口调用配额（按用户组，实现见 lib/Quota.php）
// 只对「使用型」接口计数；登录、公告、在线人数等豁免，
// 否则配额用尽后用户连登录、看公告、退出登录都做不了。
// ------------------------------------------------------------------
$quotaFreeActions = ['init', 'register', 'login', 'logout', 'notice', 'version', 'online', 'handshake', 'runtime_policy', 'runtime_security_event'];
if ($action !== '' && !in_array($action, $quotaFreeActions, true)) {
    $qToken = Util::str($requestData, 'token', Util::str($input, 'token', ''));
    if ($qToken !== '') {
        $qUid = (int) Database::value(
            'SELECT user_id FROM ' . Database::t('sessions') . ' WHERE token = ? AND status = 1',
            [$qToken]
        );
        if ($qUid > 0) {
            $q = Quota::enforce($qUid);
            if (!$q['ok']) {
                Logger::log($action, 0, '每日调用配额已用尽', [
                    'user_id' => $qUid, 'used' => $q['used'], 'quota' => $q['quota'],
                ]);
                Response::send($q['code'], $q['msg'], [
                    'quota' => true,
                    'used'  => $q['used'],
                    'limit' => $q['quota'],
                ]);
            }
        }
    }
}

// ------------------------------------------------------------------
// 分发
// ------------------------------------------------------------------
try {
    $handler = __DIR__ . '/handlers/' . $action . '.php';
    if ($action === '' || !is_file($handler)) {
        Response::error(1001, '未知的接口: ' . $action);
    }
    require $handler;
} catch (Throwable $e) {
    Logger::log($action ?: 'unknown', 0, 'handler error: ' . $e->getMessage());
    if (Config::get('debug')) {
        Response::error(9999, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    Response::error(9999, '服务器内部错误');
}
// 统计由文件顶部的 register_shutdown_function 收尾（见「调用统计」段落）

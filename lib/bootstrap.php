<?php
/**
 * 统一入口引导文件
 * 所有 API 与后台入口都必须先 require 本文件
 */

// ------------------------------------------------------------------
// 基础设置
// ------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Shanghai');

// 迁移脚本（install/migrate_*.php）会先自行 define，这里避免重复定义告警
if (!defined('NB_ROOT')) {
    define('NB_ROOT', dirname(__DIR__));
}
define('NB_START', microtime(true));

// 后台前端静态资源版本号（用于缓存刷新，改前端后递增即可）
if (!defined('NB_VERSION')) {
    define('NB_VERSION', '2.65.32');
}

// ------------------------------------------------------------------
// 跨域与安全响应头
// 说明：CORS 白名单在配置加载后再决定（见文件末尾 configureCors()）
// ------------------------------------------------------------------
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token, X-CSRF, X-Session-Key, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// 安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Opener-Policy: same-origin');
header('X-Permitted-Cross-Domain-Policies: none');
// 显式关闭本系统用不到的浏览器能力，缩小被滥用面（如被注入脚本偷偷调用摄像头/定位）
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()');

// ------------------------------------------------------------------
// HSTS（HTTP 严格传输安全）
// 只在本次请求确实是 HTTPS 时下发：本地 phpstudy 走 http，若也发这个头，
// 浏览器会把本地域名记进 HSTS 表，之后强制跳 https 导致本地开发直接打不开。
// 关闭 includeSubDomains / preload —— 本站不保证所有子域都是 https，
// 贸然包含子域会连带把未启用的子域一起锁死，且 preload 不可回退。
// ------------------------------------------------------------------
$nbHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
if ($nbHttps) {
    header('Strict-Transport-Security: max-age=15552000');
}

// ------------------------------------------------------------------
// CSP（内容安全策略）
// ------------------------------------------------------------------
// script-src 用 nonce 白名单：页面里每个内联脚本都必须带上本次响应的
// 随机 nonce 属性，「攻击者往页面里注入一段脚本标签」这条路直接失效
// （他猜不到 nonce）。这是 CSP 最主要的防 XSS 价值。
//
// unsafe-eval：assets/guard.js 的反调试探针用 new Function('debugger') 构造，
// 属于刻意的隐蔽设计（源码里不出现 debugger 关键字），因此保留 eval 能力。
// nonce 已挡住「注入脚本标签」这条主路径，故这点保留是可接受的权衡。
//
// unsafe-inline（仅 style）：模板与组件大量使用 style="..." 行内样式，
// 行内样式属性不受 nonce 管辖（CSP2 起 nonce 只对内联 <style> 块生效），
// 只能放开。样式注入的危害远低于脚本注入。
if (!defined('NB_CSP_NONCE')) {
    define('NB_CSP_NONCE', bin2hex(random_bytes(16)));
}
header('Content-Security-Policy: ' . implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'nonce-" . NB_CSP_NONCE . "' 'unsafe-eval'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob: https:",
    "font-src 'self' data:",
    "connect-src 'self'",
    "media-src 'self' blob: https:",
    "frame-src 'self' https:",
    "worker-src 'self' blob:",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'self'",
]));

// 抹掉 PHP 自带的 X-Powered-By（暴露 PHP 版本，便于按版本找已知漏洞）
header_remove('X-Powered-By');

// 预检请求直接返回
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------
// 通用辅助函数
// ------------------------------------------------------------------
if (!function_exists('esc_attr')) {
    /** HTML 属性转义 */
    function esc_attr($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('esc_html')) {
    /** HTML 内容转义 */
    function esc_html($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// 自定义 HTTP 错误页（400/403/404/500 共用 web/404.html 模板，数字随状态码替换）
require_once NB_ROOT . '/lib/error_page.php';

if (!function_exists('fake_404_exit')) {
    /**
     * 输出自定义 404 页并终止（沿用历史函数名，内部已换装自定义错误页）。
     * 用于后台入口 token 校验失败等场景：不返回任何暴露后台存在的信息。
     */
    function fake_404_exit(): void
    {
        nb_error_page(404);
        exit;
    }
}

// ------------------------------------------------------------------
// 加载核心库
// ------------------------------------------------------------------
require_once NB_ROOT . '/lib/Config.php';
require_once NB_ROOT . '/lib/Util.php';
require_once NB_ROOT . '/lib/Database.php';
require_once NB_ROOT . '/lib/Cache.php';
require_once NB_ROOT . '/lib/Response.php';
require_once NB_ROOT . '/lib/Crypto.php';
require_once NB_ROOT . '/lib/Logger.php';
require_once NB_ROOT . '/lib/Audit.php';
require_once NB_ROOT . '/lib/AdminPermission.php';
require_once NB_ROOT . '/lib/RateLimit.php';
require_once NB_ROOT . '/lib/Guard.php';
require_once NB_ROOT . '/lib/Captcha.php';
require_once NB_ROOT . '/lib/Setting.php';
require_once NB_ROOT . '/lib/Points.php';
require_once NB_ROOT . '/lib/Software.php';
require_once NB_ROOT . '/lib/Policy.php';
require_once NB_ROOT . '/lib/Session.php';
require_once NB_ROOT . '/lib/DeviceFp.php';
require_once NB_ROOT . '/lib/Device.php';
require_once NB_ROOT . '/lib/Heartbeat.php';
require_once NB_ROOT . '/lib/Auth.php';
require_once NB_ROOT . '/lib/Grace.php';
require_once NB_ROOT . '/lib/RespSign.php';
require_once NB_ROOT . '/lib/Handshake.php';
require_once NB_ROOT . '/lib/LoginMethod.php';
require_once NB_ROOT . '/lib/Card.php';
require_once NB_ROOT . '/lib/Agent.php';
require_once NB_ROOT . '/lib/AgentCode.php';
require_once NB_ROOT . '/lib/AgentRecharge.php';
require_once NB_ROOT . '/lib/Quota.php';
require_once NB_ROOT . '/lib/Deleter.php';
require_once NB_ROOT . '/lib/Version.php';
require_once NB_ROOT . '/lib/WebInteract.php';
require_once NB_ROOT . '/lib/Pay.php';
require_once NB_ROOT . '/lib/Shop.php';
require_once NB_ROOT . '/lib/ShopAuth.php';
require_once NB_ROOT . '/lib/UiTemplate.php';
require_once NB_ROOT . '/lib/SessionCookie.php';
require_once NB_ROOT . '/lib/FileGuard.php';
require_once NB_ROOT . '/lib/Backup.php';
require_once NB_ROOT . '/lib/Health.php';
require_once NB_ROOT . '/lib/SecReport.php';
require_once NB_ROOT . '/lib/Tenant.php';
require_once NB_ROOT . '/lib/Totp.php';
require_once NB_ROOT . '/lib/RuntimePolicy.php';
require_once NB_ROOT . '/lib/RuntimeRiskEngine.php';
require_once NB_ROOT . '/lib/RuntimeEventService.php';
require_once NB_ROOT . '/lib/RuntimeGuard.php';

// ------------------------------------------------------------------
// 载入配置
// ------------------------------------------------------------------
$configFile = NB_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    nb_error_page(500);
}

$config = require $configFile;
Config::load($config);

if (!empty($config['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// ------------------------------------------------------------------
// 跨域：仅允许配置白名单内的来源（不再反射任意 Origin，避免凭据泄露）
// ------------------------------------------------------------------
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = (array) Config::get('security.cors_origins', []);
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

// ------------------------------------------------------------------
// 初始化组件
// ------------------------------------------------------------------
Database::init($config['db']);
Crypto::init($config['security']);
Logger::init($config['log']);
// 缓存层必须在 Database 之后初始化：Redis 不可用时要靠文件缓存降级，
// 文件目录不可写时整个缓存会关闭，调用方自动回落原 DB 路径。
// 后台「系统设置 → 缓存与 Redis」可覆盖 config/config.php 的出厂值：
// 只覆盖「显式保存过」的键（Setting::isSet），没保存过的沿用出厂默认；
// 非法值（驱动名、端口范围）一律忽略，绝不因后台误配把缓存打挂。
$cacheCfg = $config['cache'] ?? [];
try {
    $dv = strtolower(trim((string) Setting::get('cache_driver')));
    if (in_array($dv, ['auto', 'redis', 'file', 'none'], true)) {
        $cacheCfg['driver'] = $dv;
    }
    if (Setting::isSet('cache_redis_host')) {
        $h = trim((string) Setting::get('cache_redis_host'));
        if ($h !== '') { $cacheCfg['redis']['host'] = $h; }
    }
    if (Setting::isSet('cache_redis_port')) {
        $p = (int) Setting::get('cache_redis_port');
        if ($p >= 1 && $p <= 65535) { $cacheCfg['redis']['port'] = $p; }
    }
    if (Setting::isSet('cache_redis_password')) {
        $cacheCfg['redis']['password'] = (string) Setting::get('cache_redis_password');
    }
    if (Setting::isSet('cache_redis_database')) {
        $dbi = (int) Setting::get('cache_redis_database');
        if ($dbi >= 0 && $dbi <= 15) { $cacheCfg['redis']['database'] = $dbi; }
    }
} catch (Throwable $e) {
    // DB 尚未就绪（如安装向导阶段）时只用 config 出厂值，缓存层照常降级
}
Cache::init($cacheCfg);
Response::setEncrypt((bool) ($config['security']['enforce_crypto'] ?? true));

// ------------------------------------------------------------------
// IP 黑名单：命中即按仿真 404 拒绝（全站所有页面与接口，含后台）
// 后台「系统设置 → 安全 → IP 黑名单」维护（ip_blacklist，每行一个 IP 或 CIDR 段）
// 数据库未就绪（安装阶段）时自动跳过，不影响安装向导。
// ------------------------------------------------------------------
try {
    $rawBlacklist = trim((string) Setting::get('ip_blacklist', ''));
    if ($rawBlacklist !== '') {
        $nbClientIp  = Util::ip();
        $nbClientBin = @inet_pton($nbClientIp);
        $nbBlocked = false;
        foreach (preg_split('/\r\n|\r|\n/', $rawBlacklist) as $nbLine) {
            $nbLine = trim($nbLine);
            if ($nbLine === '' || $nbLine[0] === '#') {
                continue;
            }
            // 精确匹配（IPv4/IPv6，IPv6 统一小写比较）
            if (strcasecmp($nbLine, $nbClientIp) === 0) {
                $nbBlocked = true;
                break;
            }
            // CIDR 段匹配（如 1.2.3.0/24、2030:1130::/32）
            if (strpos($nbLine, '/') !== false && $nbClientBin !== false) {
                [$nbNet, $nbBits] = explode('/', $nbLine, 2);
                $nbNetBin = @inet_pton(trim($nbNet));
                $nbBits   = (int) $nbBits;
                if ($nbNetBin === false || strlen($nbNetBin) !== strlen($nbClientBin)) {
                    continue;
                }
                $nbMax = strlen($nbNetBin) * 8;
                if ($nbBits < 0 || $nbBits > $nbMax) {
                    continue;
                }
                $nbBytes = intdiv($nbBits, 8);
                $nbRem   = $nbBits % 8;
                if ($nbRem === 0) {
                    $nbMatch = substr($nbNetBin, 0, $nbBytes) === substr($nbClientBin, 0, $nbBytes);
                } else {
                    $nbMask  = 0xFF << (8 - $nbRem) & 0xFF;
                    $nbMatch = substr($nbNetBin, 0, $nbBytes) === substr($nbClientBin, 0, $nbBytes)
                        && ((ord($nbNetBin[$nbBytes]) & $nbMask) === (ord($nbClientBin[$nbBytes]) & $nbMask));
                }
                if ($nbMatch) {
                    $nbBlocked = true;
                    break;
                }
            }
        }
        if ($nbBlocked) {
            // 命中只记首次日志（10 分钟内不重复刷屏）
            if (RateLimit::hit('ipban:' . $nbClientIp, 1, 600)) {
                Logger::log('ip_blacklist', 0, '黑名单 IP 访问已拦截', ['ip' => $nbClientIp]);
            }
            // 明确告知访客已被封禁（视觉仍是同一套深空错误页）
            nb_error_page(403, '你已被封禁，请联系管理员');
        }
    }
} catch (Throwable $e) {
    // 黑名单检查出错绝不阻断正常访问（例如安装阶段 DB 未建表）
}

// 后台目录名（安装时强制改名，存于数据库；未设置时回落默认 admin）
$adminDirName = preg_replace('/[^A-Za-z0-9_-]/', '', (string) (Setting::get('admin_path') ?: 'admin'));
if ($adminDirName === '' || !is_file(NB_ROOT . '/' . $adminDirName . '/AdminAuth.php')) {
    $adminDirName = 'admin';
}
require_once NB_ROOT . '/' . $adminDirName . '/AdminAuth.php';

// ------------------------------------------------------------------
// 全局异常与错误处理
// ------------------------------------------------------------------
set_exception_handler(function (Throwable $e) {
    Logger::log('exception', 0, $e->getMessage(), [
        'raw' => ['file' => $e->getFile(), 'line' => $e->getLine()],
    ]);
    if (Config::get('debug')) {
        Response::error(9999, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    Response::error(9999, '服务器内部错误');
});

set_error_handler(function ($no, $str, $file, $line) {
    // 用 @ 抑制的错误不应升级为异常。
    // PHP 8 下 @ 会把 error_reporting() 临时置为一个固定的最小掩码，
    // 因此 (error_reporting() & $no) === 0 就表示"这条被 @ 抑制了"。
    // 不这样处理的话，所有 `@unlink()` / `@file_get_contents()` 这类
    // 故意忽略失败的写法都会抛异常，缓存、清理等容错分支会全部失灵。
    if (!(error_reporting() & $no)) {
        return true;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ------------------------------------------------------------------
// 预热响应签名密钥：确保后台访问时 RespSign 公钥已存在，无需依赖首次 API 请求
// ------------------------------------------------------------------
if (class_exists('RespSign')) {
    @RespSign::publicKey();
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (Config::get('debug')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'code' => 9999,
                'msg'  => 'Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line'],
            ], JSON_UNESCAPED_UNICODE);
        }
    }
});

<?php
/**
 * 版本更新系统 - 统一入口引导文件
 * ==================================================================
 * 所有 API 与后台入口都必须先 require 本文件。
 * 独立系统，不依赖外部项目。
 */

// ------------------------------------------------------------------
// 基础设置
// ------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Shanghai');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
define('APP_START', microtime(true));

// 安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

// 后台与安装页：禁止缓存（防敏感页面落入共享缓存）、禁止被嵌入 iframe
if (preg_match('#/(admin|install)/#', $_SERVER['SCRIPT_NAME'] ?? '')) {
    header('Cache-Control: no-store, max-age=0');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

// 预检请求
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------
// 加载版本信息与核心库
// ------------------------------------------------------------------
require_once APP_ROOT . '/version.php';
require_once APP_ROOT . '/lib/DB.php';
require_once APP_ROOT . '/lib/AdminAuth.php';
require_once APP_ROOT . '/lib/VersionManager.php';

// ------------------------------------------------------------------
// 载入配置
// ------------------------------------------------------------------
$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    // 配置不存在 → 系统未安装
    $isApi = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false
          || (function_exists('getallheaders') && stripos(getallheaders()['Accept'] ?? '', 'application/json') !== false);

    if ($isApi) {
        // API 请求返回 JSON 错误
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'code'    => 1002,
            'error'   => '系统未安装',
            'message' => '版本更新系统尚未安装，请先访问安装向导完成配置。',
            'install_url' => '/install/install.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 页面请求跳转到安装向导
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $base = preg_replace('#/(admin|api)$#', '', $base);
    header('Location: ' . $base . '/install/install.php');
    exit;
}

$config = require $configFile;

// ------------------------------------------------------------------
// 初始化数据库
// ------------------------------------------------------------------
if (!empty($config['db'])) {
    DB::init($config['db']);
}

// ------------------------------------------------------------------
// 调试模式
// ------------------------------------------------------------------
if (!empty($config['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// ------------------------------------------------------------------
// 全局异常处理
// ------------------------------------------------------------------
set_exception_handler(function (Throwable $e) use ($config) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 9999,
        'msg'  => !empty($config['debug']) ? $e->getMessage() : '服务器内部错误',
    ], JSON_UNESCAPED_UNICODE);
});

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return true;
    throw new ErrorException($str, 0, $no, $file, $line);
});

<?php
/**
 * 版本检查 API
 * ==================================================================
 * 客户端访问此接口获取官方最新版本信息。
 *
 * 请求方式：GET
 * 请求参数：
 *   product  产品标识
 *   version  当前版本号
 *   build    当前 Build 编号
 *   channel  更新通道
 *
 * 返回格式：JSON
 */

require_once __DIR__ . '/../version.php';
require_once __DIR__ . '/../lib/DB.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

// 尝试连接数据库（版本服务器端）
$configFile = __DIR__ . '/../config/config.php';
$useDb = is_file($configFile);

if ($useDb) {
    $config = require $configFile;
    if (!empty($config['db'])) {
        try {
            DB::init($config['db']);
        } catch (Throwable $e) {
            $useDb = false;
        }
    }
}

$product = $_GET['product'] ?? '';
$version = $_GET['version'] ?? '';
$build   = (int) ($_GET['build'] ?? 0);
$channel = $_GET['channel'] ?? 'stable';

if ($product !== APP_PRODUCT) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '产品标识不匹配'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// 记录 API 调用日志 & 安装日志（不影响主流程）
// ------------------------------------------------------------------
if ($useDb && $product !== '' && $version !== '') {
    try {
        // 确保 api_logs 表存在
        DB::one('SELECT 1 FROM ' . DB::t('api_logs') . ' LIMIT 1');
    } catch (Throwable $e) {
        DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('api_logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(64) NOT NULL DEFAULT '',
            product VARCHAR(64) NOT NULL DEFAULT '',
            version VARCHAR(32) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    try {
        DB::insert('api_logs', [
            'action'     => 'version_check',
            'product'    => $product,
            'version'    => $version,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) { /* 忽略日志写入失败 */ }

    // 安装日志（带机器码去重）
    try {
        DB::one('SELECT 1 FROM ' . DB::t('install_logs') . ' LIMIT 1');
    } catch (Throwable $e) {
        DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('install_logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            machine_id VARCHAR(128) NOT NULL DEFAULT '',
            product VARCHAR(64) NOT NULL DEFAULT '',
            version VARCHAR(32) NOT NULL DEFAULT '',
            build INT UNSIGNED NOT NULL DEFAULT 0,
            channel VARCHAR(20) NOT NULL DEFAULT 'stable',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_machine (machine_id),
            INDEX idx_product (product),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    try {
        $machineId = $_GET['machine_id'] ?? $_GET['mid'] ?? ($_SERVER['HTTP_X_MACHINE_ID'] ?? '');
        if (!is_string($machineId)) $machineId = '';
        $machineId = trim($machineId);
        if ($machineId === '') $machineId = md5(($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . '|' . $product);

        // 只记录该机器首次安装或版本变更
        $existing = DB::one('SELECT id FROM ' . DB::t('install_logs') . ' WHERE machine_id = ? AND product = ? AND version = ? LIMIT 1', [$machineId, $product, $version]);
        if (!$existing) {
            DB::insert('install_logs', [
                'machine_id' => $machineId,
                'product'    => $product,
                'version'    => $version,
                'build'      => $build,
                'channel'    => $channel,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (Throwable $e) { /* 忽略日志写入失败 */ }
}

// ------------------------------------------------------------------
// 从数据库读取最新发布信息
// ------------------------------------------------------------------
$latest = null;

if ($useDb) {
    try {
        $latest = DB::one(
            'SELECT * FROM ' . DB::t('releases') . '
             WHERE channel = ? AND status = 1
             ORDER BY id DESC LIMIT 1',
            [$channel]
        );
    } catch (Throwable $e) {
        // 表不存在时回落到 releases 目录
    }
}

// 回退到文件系统
if ($latest === null) {
    $releasesDir = __DIR__ . '/../releases';
    if (is_dir($releasesDir)) {
        foreach (glob($releasesDir . '/*', GLOB_ONLYDIR) as $dir) {
            $manifestFile = $dir . '/manifest.json';
            if (!is_file($manifestFile)) continue;
            $manifest = json_decode(@file_get_contents($manifestFile), true);
            if (!is_array($manifest)) continue;
            if (($manifest['channel'] ?? 'stable') !== $channel) continue;
            if ($latest === null || version_compare($manifest['version'], $latest['version'] ?? '0.0.0', '>')) {
                $latest = $manifest;
            }
        }
    }
}

if ($latest === null) {
    echo json_encode([
        'success'        => true,
        'product'        => APP_PRODUCT,
        'latest_version' => APP_VERSION,
        'latest_build'   => APP_BUILD,
        'min_version'    => APP_VERSION,
        'channel'        => APP_CHANNEL,
        'published_at'   => date('Y-m-d H:i:s'),
        'download_url'   => '',
        'sha256'         => '',
        'signature'      => '',
        'release_notes'  => ['当前为初始版本'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 判断请求方是否低于最低支持版本
$isBelowMin = false;
if (!empty($latest['min_version']) && version_compare($version, $latest['min_version'], '<')) {
    $isBelowMin = true;
}

// release_notes 可能是 JSON 字符串或数组
$notes = $latest['release_notes'] ?? [];
if (is_string($notes)) {
    $decoded = json_decode($notes, true);
    $notes = is_array($decoded) ? $decoded : [];
}

// ------------------------------------------------------------------
// 授权门禁（v1.2.0 新增）
// ------------------------------------------------------------------
// vu_settings.license_gate = 1 时启用：请求必须携带有效的
// license_key + domain（安装时激活绑定的域名）才返回下载地址，
// 且下载地址改为 HMAC 限时签名链接（api/download.php）——
// 盗版源码可以拿走，但更新通道被锁死。
// gate = 0（默认）时行为与旧版完全一致，兼容存量部署。
$dlUrl    = (string) ($latest['download_url'] ?? '');
$licFlag  = false;
$licMsg   = '';
if ($useDb) {
    require_once __DIR__ . '/../lib/License.php';
    try {
        if (License::gateEnabled()) {
            $licFlag = true;
            $vr = License::verify(
                trim((string) ($_POST['license_key'] ?? $_GET['license_key'] ?? '')),
                trim((string) ($_POST['domain'] ?? $_GET['domain'] ?? ''))
            );
            if ($vr['ok']) {
                // 绝对 URL：客户端（system_update_do）强制校验 https:// 前缀，
                // 相对路径会被判"下载地址不合法"
                $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
                $dlUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME'] ?? '/api/version.php') . '/download.php?v=' . urlencode((string) $latest['version'])
                       . '&' . License::makeDownloadToken((string) $latest['version'], License::normalizeDomain(trim((string) ($_POST['domain'] ?? $_GET['domain'] ?? ''))));
            } else {
                $dlUrl  = '';
                $licMsg = $vr['msg'];
            }
        }
    } catch (Throwable $e) {
        // 授权体系自身异常（DB 故障/表损坏/门禁状态未知）→ fail-closed：
        // 门禁曾开启的情况下绝不允许静默退回公开下载地址，宁可中断更新链路
        $dlUrl  = '';
        $licFlag = true;
        $licMsg = '授权服务暂时不可用，请稍后重试（更新门禁开启中）';
    }
} elseif (is_file(__DIR__ . '/../storage/license_gate.on')) {
    // 数据库不可用 + 粘性标志显示门禁曾开启 → 同样 fail-closed，
    // 不下发公开 download_url（否则 DB 一挂门禁就形同虚设）
    $dlUrl   = '';
    $licFlag = true;
    $licMsg  = '授权服务暂时不可用，请稍后重试（更新门禁开启中）';
}

echo json_encode([
    'success'        => true,
    'product'        => APP_PRODUCT,
    'latest_version' => $latest['version'] ?? APP_VERSION,
    'latest_build'   => (int) ($latest['build'] ?? 0),
    'min_version'    => $latest['min_version'] ?? APP_VERSION,
    'channel'        => $latest['channel'] ?? 'stable',
    'published_at'   => $latest['published_at'] ?? date('Y-m-d H:i:s'),
    'download_url'   => $dlUrl,
    'sha256'         => $latest['sha256'] ?? '',
    'signature'      => $latest['signature'] ?? '',
    'release_notes'  => $notes,
    'force_update'   => $isBelowMin,
    'license_required' => $licFlag,
    'license_msg'      => $licMsg,
], JSON_UNESCAPED_UNICODE);

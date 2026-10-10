<?php
/**
 * 授权下载 API（v1.2.0）
 * ==================================================================
 * license_gate 开启时，version.php 下发的下载地址指向本接口：
 *   download.php?v=<版本号>&e=<过期时间戳>&s=<HMAC>&d=<域名hex>
 *
 * 校验链：签名有效且未过期（10 分钟时效）→ 定位 releases/{v}/update.zip
 * → 流式输出。静态 releases/ 目录仍保留旧包供存量部署直接下载；
 * 新门禁生效后建议管理员将 releases/ 改名为不可猜测目录或加 Web 层拒绝。
 */

require_once __DIR__ . '/../version.php';
require_once __DIR__ . '/../lib/DB.php';
require_once __DIR__ . '/../lib/License.php';

header('Content-Type: application/json; charset=utf-8');

// 连接数据库
$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => '系统未安装']);
    exit;
}
$config = require $configFile;
if (empty($config['db'])) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => '数据库未配置']);
    exit;
}
try {
    DB::init($config['db']);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => '数据库连接失败']);
    exit;
}

$version = trim((string) ($_GET['v'] ?? ''));
if ($version === '' || !preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $version)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '版本号不合法']);
    exit;
}

// 令牌校验（签名 + 时效 + 域名归属）
if (!License::checkDownloadToken($version, $_GET)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '下载授权无效或已过期，请到站点后台「系统更新」重新检查并携带有效授权']);
    exit;
}

// 定位更新包：优先 releases/{v}/update.zip，其次 storage/updates/{v}.zip
$candidates = [
    __DIR__ . '/../releases/' . $version . '/update.zip',
    __DIR__ . '/../storage/updates/' . $version . '.zip',
];
$pkg = null;
foreach ($candidates as $f) {
    $real = realpath($f);
    if ($real !== false && is_file($real)) { $pkg = $real; break; }
}
if ($pkg === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => '更新包不存在']);
    exit;
}

// 流式输出
$size = filesize($pkg);
header('Content-Type: application/zip');
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename="update_' . $version . '.zip"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$fp = fopen($pkg, 'rb');
while (!feof($fp)) {
    echo fread($fp, 256 * 1024);
    if (connection_aborted()) break;
    flush();
}
fclose($fp);

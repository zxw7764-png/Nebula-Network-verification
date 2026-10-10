<?php
/**
 * 健康检查 API
 * ==================================================================
 * 更新完成后自动调用，检查系统健康状态。
 */

require_once __DIR__ . '/../version.php';

header('Content-Type: application/json; charset=utf-8');

$versionOk = defined('APP_VERSION') && APP_VERSION !== '';

$filesOk = true;
$criticalFiles = ['version.php', 'lib/DB.php', 'lib/VersionManager.php'];
foreach ($criticalFiles as $f) {
    if (!is_file(__DIR__ . '/../' . $f)) { $filesOk = false; break; }
}

$dbOk = false;
$configFile = __DIR__ . '/../config/config.php';
if (is_file($configFile)) {
    $dbOk = true; // 配置存在即认为数据库可用（深度检查由 VersionManager::healthCheck 完成）
}

$ok = $versionOk && $filesOk;

echo json_encode([
    'success'  => $ok,
    'version'  => APP_VERSION,
    'build'    => APP_BUILD,
    'database' => $dbOk,
    'checks'   => [
        ['name' => '版本文件', 'level' => $versionOk ? 'ok' : 'error', 'text' => $versionOk ? '正常' : '异常'],
        ['name' => '核心文件', 'level' => $filesOk ? 'ok' : 'error', 'text' => $filesOk ? '完整' : '缺失'],
        ['name' => '数据库', 'level' => $dbOk ? 'ok' : 'error', 'text' => $dbOk ? '配置存在' : '配置缺失'],
    ],
], JSON_UNESCAPED_UNICODE);

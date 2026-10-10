<?php
/**
 * 官网门户实时统计 API
 * ==================================================================
 * GET api/stats.php
 *
 * 返回：{"success":true,"users":123,"cached":false}
 *   users  — 主项目安装量（install_logs 按 machine_id 去重，与后台
 *            仪表盘「独立安装」口径一致）
 *   cached — 本次结果是否来自缓存
 *
 * 说明：
 *   - 结果缓存 storage/cache/portal_stats.json，默认 60 秒，避免高频
 *     COUNT 压库；主库异常时回落最近一次缓存（最长 10 倍 TTL）。
 *   - 不暴露任何数据库结构与错误细节。
 */

require_once __DIR__ . '/../version.php';
require_once __DIR__ . '/../lib/DB.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

$out = ['success' => true, 'users' => null, 'cached' => false];

$configFile = __DIR__ . '/../config/config.php';
$hasDb = false;
if (is_file($configFile)) {
    try {
        $config = require $configFile;
        if (!empty($config['db'])) {
            DB::init($config['db']);
            $hasDb = true;
        }
    } catch (Throwable $e) {
        $hasDb = false;
    }
}

$cacheTtl  = 60;
$cacheDir  = __DIR__ . '/../storage/cache';
$cacheFile = $cacheDir . '/portal_stats.json';

$readCache = static function () use ($cacheFile): ?array {
    if (!is_file($cacheFile)) return null;
    $j = json_decode((string) @file_get_contents($cacheFile), true);
    return is_array($j) ? $j : null;
};

if ($hasDb) {
    $cache = $readCache();
    $isFresh = $cache !== null && (time() - (int) ($cache['t'] ?? 0)) < $cacheTtl;

    if ($isFresh) {
        $out['users']  = (int) ($cache['users'] ?? 0);
        $out['cached'] = true;
    } else {
        try {
            $users = (int) DB::value(
                'SELECT COUNT(DISTINCT machine_id) FROM ' . DB::t('install_logs')
            );
            $out['users'] = $users;
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            @file_put_contents(
                $cacheFile,
                json_encode(['t' => time(), 'users' => $users]),
                LOCK_EX
            );
        } catch (Throwable $e) {
            // 表不存在或库异常：回落旧缓存（最长 10 倍 TTL），否则保持 null
            if ($cache !== null && (time() - (int) ($cache['t'] ?? 0)) < $cacheTtl * 10) {
                $out['users']  = (int) ($cache['users'] ?? 0);
                $out['cached'] = true;
            }
        }
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

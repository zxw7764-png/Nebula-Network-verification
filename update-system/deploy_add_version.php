<?php
/**
 * 直接添加版本到数据库
 */
require_once __DIR__ . '/lib/bootstrap.php';

// 读取 manifest
$manifestPath = __DIR__ . '/releases/2.65.31/manifest.json';
if (!file_exists($manifestPath)) {
    echo "错误: manifest.json 不存在\n";
    exit(1);
}

$manifest = json_decode(file_get_contents($manifestPath), true);
if (!$manifest) {
    echo "错误: manifest.json 解析失败\n";
    exit(1);
}

$releaseNotes = $manifest['release_notes'] ?? [];

// 检查版本是否已存在
$exists = DB::one('SELECT id FROM ' . DB::t('releases') . ' WHERE version = ?', [$manifest['version']]);
if ($exists) {
    echo "错误: 版本 " . $manifest['version'] . " 已存在 (ID: " . $exists['id'] . ")\n";
    exit(1);
}

// 插入新版本
$id = DB::insert('releases', [
    'version' => $manifest['version'],
    'build' => (int) $manifest['build'],
    'min_version' => $manifest['min_version'],
    'schema_version' => (int) $manifest['schema_version'],
    'channel' => $manifest['channel'],
    'download_url' => $manifest['download_url'],
    'sha256' => $manifest['sha256'],
    'signature' => $manifest['signature'] ?? '',
    'release_notes' => json_encode($releaseNotes, JSON_UNESCAPED_UNICODE),
    'requirements' => json_encode($manifest['requirements'] ?? [], JSON_UNESCAPED_UNICODE),
    'files' => json_encode($manifest['files'] ?? [], JSON_UNESCAPED_UNICODE),
    'published_at' => date('Y-m-d H:i:s'),
    'status' => 1,
    'created_at' => time(),
]);

echo "✅ 版本 {$manifest['version']} 已成功添加 (ID: {$id})\n";
echo "下载地址: {$manifest['download_url']}\n";
echo "SHA-256: {$manifest['sha256']}\n";

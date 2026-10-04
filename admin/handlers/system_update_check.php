<?php
/**
 * admin action: system_update_check
 * ------------------------------------------------------------------
 * 对接 update-system 的 api/version.php 接口，
 * 检查当前 Nebula 验证系统是否有新版本可用。
 *
 * 请求方式：POST
 * 可选参数：force=1 时跳过缓存强制刷新
 */

$force = !empty($input['force']);

// 当前系统版本
$currentVersion = (string) (defined('NB_VERSION') ? NB_VERSION : Config::get('system_version', '1.0.0'));

// 版本更新服务地址（从配置读取，默认空）
$updateServer = Config::get('update_server', '');
if ($updateServer === '') {
    // 未配置更新服务器，静默返回（不阻断后台）
    Response::ok([
        'current_version' => $currentVersion,
        'latest'          => null,
        'configured'      => false,
    ]);
}

// 构造请求参数（与 update-system api/version.php 的参数一致）
$params = http_build_query([
    'product' => 'nebula-verification',
    'version' => $currentVersion,
    'build'   => 0,
    'channel' => 'stable',
]);

$url = rtrim($updateServer, '/') . '/api/version.php?' . $params;

// 带缓存（6 小时）
$cacheKey = 'system_update_check';
if (!$force) {
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        Response::ok([
            'current_version' => $currentVersion,
            'latest'          => $cached,
            'cached'          => true,
        ]);
    }
}

// 请求 update-system
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_USERAGENT      => 'Nebula/' . $currentVersion,
]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $httpCode >= 400) {
    Response::error(1001, '无法连接版本更新服务：' . ($err ?: 'HTTP ' . $httpCode));
}

$data = json_decode($body, true);
if (!is_array($data) || !($data['success'] ?? false)) {
    Response::error(1001, '版本更新服务返回数据格式错误');
}

// 缓存 6 小时
Cache::set($cacheKey, $data, 21600);

Response::ok([
    'current_version' => $currentVersion,
    'latest'          => $data,
    'cached'          => false,
]);

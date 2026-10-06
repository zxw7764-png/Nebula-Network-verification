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
    // 缺少更新服务器地址：明确报错，不再静默显示"已是最新版本"，
    // 便于部署者第一时间发现配置被删/被改，及时修复更新链路。
    Response::error(1001, '未配置更新服务器地址（config.php 中缺少 update_server），无法检查版本更新');
}

// 构造请求参数（与 update-system api/version.php 的参数一致）
// v2.66.0 起携带本站授权码 + 部署域名：更新服务器开启「更新门禁」时，
// 无有效授权将拿不到下载地址（license_required=true）。
$params = http_build_query([
    'product' => 'nebula-verification',
    'version' => $currentVersion,
    'build'   => 0,
    'channel' => 'stable',
    'license_key' => (string) Config::get('license_key', ''),
    'domain'      => strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')),
]);

$url = rtrim($updateServer, '/') . '/api/version.php?' . $params;

// 带缓存（6 小时）
$cacheKey = 'system_update_check_' . $currentVersion; // 按版本隔离：升级后旧缓存自动失效
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

// 授权门禁响应：服务器要求授权但本站未激活 / 授权无效 → 明确提示并透传原因
if (!empty($data['license_required']) && empty($data['download_url'])) {
    Response::error(1001, '获取更新需要有效授权：' . (string) ($data['license_msg'] ?? '未激活或授权无效')
        . '。请在下方授权激活卡片配置授权码，激活后重试。');
}

// 缓存 6 小时；但带授权门禁的响应内含「限时签名下载链接」（令牌默认 10 分钟有效），
// 若一并缓存 6 小时，用户稍后点更新会因令牌过期而下载失败 —— 这类响应不缓存。
if (empty($data['license_required'])) {
    Cache::set($cacheKey, $data, 21600);
}

Response::ok([
    'current_version' => $currentVersion,
    'latest'          => $data,
    'cached'          => false,
]);

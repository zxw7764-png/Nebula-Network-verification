<?php
/**
 * admin action: license_manage
 * ------------------------------------------------------------------
 * 后台授权激活码管理：
 *   status    — 查看当前授权状态（是否已配置、脱敏显示、绑定域名）
 *   activate  — 重新填写授权码：调 update-system 激活绑定当前域名，
 *               成功后写入 config/config.php 的 license_key
 *
 * 场景：安装时未激活 / 授权过期 / 授权码更换 —— 在「系统更新」页随时重新激活。
 */

$op = Util::str($input, 'op', 'status');

/** 当前部署站域名（归一化：去端口、去 www.、转小写，与授权服务器规则一致） */
$licDomain = static function (): string {
    $d = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $d = preg_replace('/:\d+$/', '', $d);
    $d = preg_replace('/^www\./', '', $d);
    return $d;
};

if ($op === 'status') {
    $key = (string) Config::get('license_key', '');
    Response::ok([
        'configured' => $key !== '',
        'masked'     => $key === '' ? '' : substr($key, 0, 6) . '****' . substr($key, -6),
        'domain'     => $licDomain(),
    ]);
}

if ($op === 'activate') {
    $key = strtolower(trim((string) Util::get($input, 'license_key', '')));
    if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
        Response::error(1001, '授权码格式不正确（应为 32 位十六进制）');
    }

    $server = rtrim((string) Config::get('update_server', ''), '/');
    if ($server === '') {
        Response::error(1001, '未配置更新服务器地址（update_server），无法激活');
    }
    $domain = $licDomain();
    if (!preg_match('/^[a-z0-9\-]+(\.[a-z0-9\-]+)+$/', $domain)) {
        Response::error(1001, '无法识别当前部署域名，请通过正式域名访问后台');
    }

    // 调用授权服务器激活
    $ch = curl_init($server . '/api/license.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'activate', 'license_key' => $key, 'domain' => $domain]),
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http >= 400) {
        Response::error(1001, '无法连接授权服务器：' . ($err ?: 'HTTP ' . $http));
    }
    $resp = json_decode((string) $body, true);
    if (!is_array($resp) || (int) ($resp['code'] ?? -1) !== 0) {
        Response::error(1001, '激活失败：' . (string) ($resp['msg'] ?? '授权服务器返回异常'));
    }

    // 写入 config.php（preg_replace_callback 纯字面量替换，与安装器同规）
    $configFile = NB_ROOT . '/config/config.php';
    if (!is_file($configFile) || !is_writable($configFile)) {
        Response::error(1001, 'config/config.php 不可写，请手动将授权码填入 license_key 配置');
    }
    $cfg = (string) file_get_contents($configFile);
    if (!preg_match("/'license_key'\s*=>\s*'[^']*'/", $cfg)) {
        // 老模板缺键：自动在 update_server 之后插入（免手动改配置）
        if (!preg_match("/'update_server'\s*=>\s*'[^']*'/", $cfg)) {
            Response::error(1001, 'config.php 缺少 update_server 配置，无法自动补授权键');
        }
        $cfg = preg_replace_callback(
            "/('update_server'\s*=>\s*'[^']*')/",
            static function ($m) {
                return $m[1] . ",\n\n    // 授权码（后台系统更新页激活后自动写入；空白=未激活）\n    'license_key'   => ''";
            },
            $cfg,
            1
        );
    }
    $cfg = preg_replace_callback(
        "/'license_key'\s*=>\s*'[^']*'/",
        static function ($m) use ($key) {
            $q = str_replace(['\\', "'"], ['\\\\', "\\'"], $key);
            return "'license_key' => '" . $q . "'";
        },
        $cfg
    );
    file_put_contents($configFile, $cfg);

    Audit::log($admin, 'license_activate', '系统授权',
        '授权激活成功（绑定 ' . $domain . '）', [], ['domain' => $domain]);

    Response::ok(['domain' => $domain], '激活成功，已绑定 ' . $domain);
}

Response::error(1001, '未知 op');

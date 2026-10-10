<?php
/**
 * 更新包上传 API（带认证）
 * ==================================================================
 * 供外部自动化（打包脚本 / CI / 运维）直接上传更新包并发布上架，
 * 与后台「版本管理」页面等效，但走独立认证通道。
 *
 * ✦ 认证方式：HTTP Basic Auth（管理员账号密码，验证 vu_admins 表）
 *   - 仅允许超级管理员（role = 1）
 *   - 复用 IP 级防爆破（vu_login_throttle）与账号级锁定（lock_until）
 *   - 失败不区分「账号不存在 / 密码错误」，统一返回 401
 *
 * ✦ 上传格式：multipart/form-data
 *   - update_file   必填，ZIP 更新包
 *   - version       版本号（留空则从包内 manifest.json 读取）
 *   - build / min_version / channel / schema_version / status
 *                  可选，显式传入优先于 manifest.json
 *   - signature     可选，Ed25519 签名（Base64）
 *   - release_notes 可选，更新日志（JSON 数组或每行一条）
 *   - download_url  可选，留空自动生成（基于部署路径）
 *
 * ✦ 智能集成 pack.py：
 *   pack.py 打出的 ZIP 自带 manifest.json，本接口会自动解析其中
 *   product/version/build/min_version/schema_version/channel/files/
 *   requirements/release_notes 作为默认值，参数可覆盖。
 *   product 与 APP_PRODUCT 不一致的更新包一律拒绝。
 *
 * 成功后将：
 *   1) 文件保存到 releases/{version}/update.zip
 *   2) 计算 SHA-256、生成下载地址
 *   3) 写入 vu_releases（默认 status=1 上架），可立即被客户端更新
 *
 * 示例：
 *   curl -u admin:密码 -F "update_file=@update_2.66.0.zip" \
 *        -F "release_notes=修复若干问题" \
 *        https://你的域名/update-system/api/upload.php
 */

require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** 统一 JSON 输出并终止 */
function uploadOut(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================================================================
// 1. 请求方法校验
// ==================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    uploadOut(['code' => 1001, 'msg' => '请使用 POST（multipart/form-data）请求'], 405);
}

// ==================================================================
// 2. Basic Auth 认证
// ==================================================================

/** 解析 Basic Auth 凭证（兼容 PHP_AUTH_* 与 Authorization 头） */
function uploadBasicAuth(): array
{
    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

    if ($user === '' || $pass === '') {
        $hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($hdr === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = (string) $v; break; }
            }
        }
        if (preg_match('/^Basic\s+(.+)$/i', $hdr, $m)) {
            $decoded = base64_decode(trim($m[1]), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }
    return [$user, $pass];
}

// -- IP 级防爆破（复用 vu_login_throttle 表） --
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

function uploadThrottleLeft(string $ip): int
{
    try {
        $until = (int) DB::value('SELECT lock_until FROM ' . DB::t('login_throttle') . ' WHERE ip = ?', [$ip]);
        return max(0, $until - time());
    } catch (Throwable $e) {
        return 0;
    }
}

function uploadThrottleFail(string $ip): void
{
    $now  = time();
    $win  = 600;  // 计数窗口：10 分钟
    $max  = 20;   // 窗口内最大失败次数
    $lock = 900;  // 锁定时长：15 分钟
    try {
        $row = DB::one('SELECT fail_cnt, last_try FROM ' . DB::t('login_throttle') . ' WHERE ip = ?', [$ip]);
        if ($row && (int) $row['last_try'] < $now - $win) {
            DB::exec('UPDATE ' . DB::t('login_throttle') . ' SET fail_cnt = 1, lock_until = 0, last_try = ? WHERE ip = ?', [$now, $ip]);
            return;
        }
        if ($row) {
            $cnt = (int) $row['fail_cnt'] + 1;
        } else {
            $cnt = 1;
            DB::exec('INSERT INTO ' . DB::t('login_throttle') . ' (ip, fail_cnt, lock_until, last_try) VALUES (?, 0, 0, ?)', [$ip, $now]);
        }
        if ($cnt >= $max) {
            DB::exec('UPDATE ' . DB::t('login_throttle') . ' SET fail_cnt = ?, lock_until = ?, last_try = ? WHERE ip = ?', [$cnt, $now + $lock, $now, $ip]);
        } else {
            DB::exec('UPDATE ' . DB::t('login_throttle') . ' SET fail_cnt = ?, last_try = ? WHERE ip = ?', [$cnt, $now, $ip]);
        }
    } catch (Throwable $e) { /* 防爆破表异常不阻断主流程 */ }
}

$ipLockLeft = uploadThrottleLeft($ip);
if ($ipLockLeft > 0) {
    uploadOut(['code' => 2004, 'msg' => '尝试过于频繁，请 ' . ceil($ipLockLeft / 60) . ' 分钟后重试'], 429);
}

[$username, $password] = uploadBasicAuth();
if ($username === '') {
    header('WWW-Authenticate: Basic realm="update-system-upload"');
    uploadOut(['code' => 2001, 'msg' => '需要登录：请提供管理员账号与密码（HTTP Basic Auth）'], 401);
}

// -- 账号验证 --
$admin = null;
try {
    $admin = DB::one('SELECT * FROM ' . DB::t('admins') . ' WHERE username = ?', [$username]);
} catch (Throwable $e) {
    uploadOut(['code' => 9999, 'msg' => '服务器内部错误'], 500);
}

if (!$admin || !password_verify($password, (string) $admin['password'])) {
    uploadThrottleFail($ip);
    // 账号级失败计数（与登录一致，达到阈值锁定账号）
    if ($admin) {
        try {
            DB::exec('UPDATE ' . DB::t('admins') . ' SET login_fail_cnt = login_fail_cnt + 1 WHERE id = ?', [$admin['id']]);
            $fail = (int) DB::value('SELECT login_fail_cnt FROM ' . DB::t('admins') . ' WHERE id = ?', [$admin['id']]);
            $threshold = 5;
            $lockSecs  = 900;
            if ($fail >= $threshold) {
                DB::exec('UPDATE ' . DB::t('admins') . ' SET lock_until = ? WHERE id = ?', [time() + $lockSecs, $admin['id']]);
            }
        } catch (Throwable $e) { /* 忽略 */ }
    }
    header('WWW-Authenticate: Basic realm="update-system-upload"');
    uploadOut(['code' => 2001, 'msg' => '账号或密码错误'], 401);
}

// -- 账号状态：锁定 / 禁用 / 非超管 --
if ((int) $admin['lock_until'] > time()) {
    $left = (int) $admin['lock_until'] - time();
    uploadOut(['code' => 2003, 'msg' => '账号已锁定，请 ' . ceil($left / 60) . ' 分钟后重试'], 403);
}
if ((int) $admin['status'] !== 1) {
    uploadOut(['code' => 2002, 'msg' => '账号已被禁用'], 403);
}
if ((int) $admin['role'] !== 1) {
    uploadOut(['code' => 2003, 'msg' => '无权限：仅超级管理员可上传更新包'], 403);
}

// -- 认证成功：清零失败计数与 IP 计数 --
try {
    DB::exec('UPDATE ' . DB::t('admins') . ' SET login_fail_cnt = 0, lock_until = 0 WHERE id = ?', [$admin['id']]);
    DB::exec('DELETE FROM ' . DB::t('login_throttle') . ' WHERE ip = ?', [$ip]);
} catch (Throwable $e) { /* 忽略 */ }

// ==================================================================
// 3. 更新包上传
// ==================================================================
if (empty($_FILES['update_file']) || ($_FILES['update_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    uploadOut(['code' => 1001, 'msg' => '请上传更新包（表单字段 update_file，仅接受 ZIP）'], 400);
}
if (!is_uploaded_file($_FILES['update_file']['tmp_name'])) {
    uploadOut(['code' => 1001, 'msg' => '更新包上传失败，请重试'], 400);
}
$fileName = strtolower((string) ($_FILES['update_file']['name'] ?? ''));
if (!str_ends_with($fileName, '.zip')) {
    uploadOut(['code' => 1001, 'msg' => '仅支持 ZIP 格式的更新包'], 400);
}
if ((int) $_FILES['update_file']['size'] <= 0) {
    uploadOut(['code' => 1001, 'msg' => '更新包为空文件'], 400);
}

// -- 先落临时文件，读取包内 manifest.json（若存在）再定版本 --
$tmpDir = APP_ROOT . '/storage/updates/packages';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0750, true);
$tmpPath = $tmpDir . '/upload_' . bin2hex(random_bytes(6)) . '.zip';
if (!move_uploaded_file($_FILES['update_file']['tmp_name'], $tmpPath)) {
    uploadOut(['code' => 1001, 'msg' => '更新包保存失败（storage/updates/packages 不可写）'], 500);
}

/** 读取 ZIP 内的 manifest.json（无则返回空数组） */
function uploadReadManifest(string $zipPath): array
{
    if (!extension_loaded('zip')) return [];
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return [];
    $idx = $zip->locateName('manifest.json');
    $json = $idx === false ? false : $zip->getFromIndex($idx);
    $zip->close();
    if ($json === false) return [];
    $m = json_decode((string) $json, true);
    return is_array($m) ? $m : [];
}

$manifest = uploadReadManifest($tmpPath);

// -- 产品标识校验（manifest 存在时必须匹配） --
if (!empty($manifest['product']) && $manifest['product'] !== APP_PRODUCT) {
    @unlink($tmpPath);
    uploadOut(['code' => 1001, 'msg' => '产品标识不匹配：包内 product=' . $manifest['product'] . '，期望 ' . APP_PRODUCT], 400);
}

// ==================================================================
// 4. 版本信息（显式参数优先于 manifest.json）
// ==================================================================
$version = trim((string) ($_POST['version'] ?? ($manifest['version'] ?? '')));
if (!preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $version)) {
    @unlink($tmpPath);
    uploadOut(['code' => 1001, 'msg' => '版本号缺失或不合法（需如 1.0.0 或 1.0.0.1；可放在表单 version 或包内 manifest.json）'], 400);
}

$build        = (int) ($_POST['build']         ?? ($manifest['build']         ?? 0));
$minVersion   = trim((string) ($_POST['min_version']     ?? ($manifest['min_version']     ?? '1.0.0')));
// min_version 缺省/固定 1.0.0 时自动抬升为当前大版本基线（如上传 2.66.4.11 → 2.66.0）
if (in_array($minVersion, ['1.0.0', '0.0.0', ''], true)) {
    $vm = explode('.', $version);
    if (count($vm) >= 2) $minVersion = $vm[0] . '.' . $vm[1] . '.0';
}
$schemaVer    = (int) ($_POST['schema_version'] ?? ($manifest['schema_version'] ?? 1));
$channel      = trim((string) ($_POST['channel'] ?? ($manifest['channel'] ?? 'stable')));
$signature    = trim((string) ($_POST['signature'] ?? ($manifest['signature'] ?? '')));
$downloadUrl  = trim((string) ($_POST['download_url'] ?? ''));

// 更新日志：POST 传入 > manifest，支持 JSON 数组 / 每行一条
$notes = $_POST['release_notes'] ?? null;
if ($notes === null && isset($manifest['release_notes'])) $notes = $manifest['release_notes'];
if (is_string($notes)) {
    $decoded = json_decode($notes, true);
    $notes = is_array($decoded)
        ? array_values(array_filter(array_map('strval', $decoded)))
        : array_values(array_filter(array_map('trim', explode("\n", $notes))));
}
$notes = is_array($notes) ? array_values(array_filter(array_map('strval', $notes))) : [];

// 环境要求 / 文件列表
$requirements = $_POST['requirements'] ?? ($manifest['requirements'] ?? []);
if (is_string($requirements)) { $d = json_decode($requirements, true); if (is_array($d)) $requirements = $d; }
if (!is_array($requirements)) $requirements = [];

$files = $_POST['files'] ?? ($manifest['files'] ?? []);
if (is_string($files)) { $d = json_decode($files, true); if (is_array($d)) $files = $d; }
if (!is_array($files)) $files = [];

// 状态：默认上架（status=1）
$status = isset($_POST['status']) ? (int) $_POST['status'] : 1;

// -- 版本唯一性 --
try {
    $exists = DB::one('SELECT id FROM ' . DB::t('releases') . ' WHERE version = ?', [$version]);
} catch (Throwable $e) {
    $exists = null;
}
if ($exists) {
    @unlink($tmpPath);
    uploadOut(['code' => 1001, 'msg' => '版本 ' . $version . ' 已存在（ID: ' . $exists['id'] . '）'], 400);
}

// ==================================================================
// 5. 落盘正式目录 + 计算 SHA-256 + 生成下载地址
// ==================================================================
$relDir = APP_ROOT . '/releases/' . $version;
if (!is_dir($relDir)) @mkdir($relDir, 0755, true);
$dest = $relDir . '/update.zip';

if (!@rename($tmpPath, $dest)) {
    // 跨盘等场景回退 copy；仍失败则清理
    if (!@copy($tmpPath, $dest)) {
        @unlink($tmpPath);
        uploadOut(['code' => 1001, 'msg' => '更新包保存失败（releases 目录不可写）'], 500);
    }
    @unlink($tmpPath);
}
@chmod($dest, 0644);

$sha256 = hash_file('sha256', $dest);

// 生成下载地址：自动基于部署路径推导（留空时）
if ($downloadUrl === '') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    // SCRIPT_NAME 形如 /update-system/api/upload.php → 部署根 /update-system
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/upload.php'));
    $base = rtrim(dirname($script), '/');
    $parts = array_values(array_filter(explode('/', $base), 'strlen'));
    $prefix = '';
    if (count($parts) >= 2) {
        $prefix = '/' . implode('/', array_slice($parts, 0, count($parts) - 1));
    }
    $downloadUrl = $scheme . '://' . $host . $prefix . '/releases/' . $version . '/update.zip';
}

// ==================================================================
// 6. 写入版本库（发布上架）
// ==================================================================
$id = 0;
try {
    $id = DB::insert('releases', [
        'version'        => $version,
        'build'          => $build,
        'min_version'    => $minVersion,
        'schema_version' => $schemaVer,
        'channel'        => $channel,
        'download_url'   => $downloadUrl,
        'sha256'         => $sha256,
        'signature'      => $signature,
        'release_notes'  => json_encode($notes, JSON_UNESCAPED_UNICODE),
        'requirements'   => json_encode($requirements, JSON_UNESCAPED_UNICODE),
        'files'          => json_encode($files, JSON_UNESCAPED_UNICODE),
        'published_at'   => date('Y-m-d H:i:s'),
        'status'         => $status,
        'created_at'     => time(),
    ]);
} catch (Throwable $e) {
    uploadOut(['code' => 9999, 'msg' => '写入版本库失败：' . $e->getMessage()], 500);
}

// 写入 update_history（与后台口径一致，便于审计）
try {
    VersionManager::ensureHistoryTable();
    VersionManager::recordUpdateHistory([
        'from_version'  => '',
        'to_version'    => $version,
        'status'        => 'published',
        'operator_id'   => (int) $admin['id'],
        'operator_name' => $username,
        'started_at'    => date('Y-m-d H:i:s'),
        'finished_at'   => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) { /* 历史记录失败不影响发布 */ }

// 大版本轮换规则（2026-10-10 起）：新大版本上架后，删除更新系统中
// 之前所有大版本的 release 记录与 releases/{v}/ 发行包。
// 另：min_version 为默认值 1.0.0 时自动抬升为当前大版本基线（major.minor.0），
// 不再出现「最低可用 1.0.0」这种失去保护意义的取值。
$pruned = [];
try {
    $pruned = VersionManager::prunePreviousMajors($version);
} catch (Throwable $e) { /* 轮换失败不影响本次发布 */ }

uploadOut([
    'code' => 0,
    'msg'  => '版本 ' . $version . ' 已发布',
    'data' => [
        'id'           => $id,
        'version'      => $version,
        'build'        => $build,
        'channel'      => $channel,
        'sha256'       => $sha256,
        'pruned_major_versions' => $pruned,
        'download_url' => $downloadUrl,
        'status'       => $status,
    ],
], 200);
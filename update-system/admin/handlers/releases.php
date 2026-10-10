<?php
/**
 * admin handler: releases
 * ==================================================================
 * 版本管理：发布 / 编辑 / 下架 / 删除版本
 *
 * 操作（op）：
 *   list    — 列出所有版本（分页）
 *   create  — 发布新版本
 *   update  — 编辑已有版本
 *   toggle  — 上架/下架切换
 *   delete  — 删除版本
 */

require_once __DIR__ . '/../../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// 入口令牌守卫（凭入口 Cookie 通行）
AdminAuth::entryGuard();

// ------------------------------------------------------------------
// 请求校验
// ------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    echo json_encode(['code' => 1001, 'msg' => '请使用 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = $_POST;

$op = $input['op'] ?? 'list';

// ------------------------------------------------------------------
// 登录校验
// ------------------------------------------------------------------
$admin = AdminAuth::check();
if (!$admin) {
    echo json_encode(['code' => 1002, 'msg' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// CSRF 校验（list 豁免）
// ------------------------------------------------------------------
if ($op !== 'list') {
    $csrf = $input['csrf'] ?? '';
    if (!is_string($csrf) || $csrf === '' || !AdminAuth::checkCsrf($csrf)) {
        echo json_encode(['code' => 1006, 'msg' => 'CSRF 校验失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 确保 releases 表存在
ensureReleasesTable();

try {

// ---- 列表 ----
if ($op === 'list') {
    $page = max(1, (int) ($input['page'] ?? 1));
    $size = min(100, max(1, (int) ($input['size'] ?? 20)));
    $offset = ($page - 1) * $size;

    // 筛选：通道 / 状态 / 版本号关键词
    $where = [];
    $params = [];
    $channel = trim((string) ($input['channel'] ?? ''));
    if (in_array($channel, ['stable', 'beta', 'dev'], true)) {
        $where[] = 'channel = ?';
        $params[] = $channel;
    }
    if (isset($input['status']) && $input['status'] !== '') {
        $where[] = 'status = ?';
        $params[] = (int) $input['status'] === 1 ? 1 : 0;
    }
    $keyword = trim((string) ($input['keyword'] ?? ''));
    if ($keyword !== '') {
        $where[] = 'version LIKE ?';
        $params[] = '%' . $keyword . '%';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int) DB::value('SELECT COUNT(*) FROM ' . DB::t('releases') . $whereSql, $params);
    $rows = DB::all('SELECT * FROM ' . DB::t('releases') . $whereSql . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset, $params);

    // 解码 JSON 字段
    foreach ($rows as &$r) {
        $r['release_notes_arr'] = decodeJsonField($r['release_notes'] ?? '');
        $r['requirements_arr']  = decodeJsonField($r['requirements'] ?? '');
        $r['files_arr']          = decodeJsonField($r['files'] ?? '');
    }
    unset($r);

    echo json_encode(['code' => 0, 'data' => ['total' => $total, 'list' => $rows]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 发布新版本 ----
if ($op === 'create') {
    // 若随表单上传了 ZIP，先落盘并自动生成下载地址 + SHA-256
    if (!empty($_FILES['update_file']['name'])) {
        $pkg = handlePackageUpload(trim((string) ($input['version'] ?? '')));
        $input['download_url'] = $pkg['download_url'];
        if (empty($input['sha256'])) $input['sha256'] = $pkg['sha256'];
    }
    $data = validateReleaseInput($input);
    // 检查版本号唯一
    $exists = DB::one('SELECT id FROM ' . DB::t('releases') . ' WHERE version = ?', [$data['version']]);
    if ($exists) {
        echo json_encode(['code' => 1001, 'msg' => '版本号 ' . $data['version'] . ' 已存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = DB::insert('releases', [
        'version'       => $data['version'],
        'build'         => $data['build'],
        'min_version'   => $data['min_version'],
        'schema_version'=> $data['schema_version'],
        'channel'       => $data['channel'],
        'download_url'  => $data['download_url'],
        'sha256'        => $data['sha256'],
        'signature'     => $data['signature'],
        'release_notes' => $data['release_notes'],
        'requirements'  => $data['requirements'],
        'files'         => $data['files'],
        'published_at'  => date('Y-m-d H:i:s'),
        'status'        => $data['status'],
        'created_at'    => time(),
    ]);

    // 大版本轮换规则：新大版本上架后删除之前所有大版本的记录与发行包
    try { VersionManager::prunePreviousMajors($data['version']); } catch (Throwable $e) { /* 不影响本次发布 */ }

    echo json_encode(['code' => 0, 'msg' => '版本 ' . $data['version'] . ' 已发布', 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 编辑版本 ----
if ($op === 'update') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['code' => 1001, 'msg' => '缺少版本 ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $existing = DB::one('SELECT * FROM ' . DB::t('releases') . ' WHERE id = ?', [$id]);
    if (!$existing) {
        echo json_encode(['code' => 1001, 'msg' => '版本不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 编辑时若重新上传 ZIP，同样落盘并刷新下载地址 + SHA-256
    if (!empty($_FILES['update_file']['name'])) {
        $pkg = handlePackageUpload(trim((string) ($input['version'] ?? $existing['version'])));
        $input['download_url'] = $pkg['download_url'];
        if (empty($input['sha256'])) $input['sha256'] = $pkg['sha256'];
    }

    $data = validateReleaseInput($input, true);
    // 版本号变更时检查唯一
    if ($data['version'] !== $existing['version']) {
        $dup = DB::one('SELECT id FROM ' . DB::t('releases') . ' WHERE version = ? AND id != ?', [$data['version'], $id]);
        if ($dup) {
            echo json_encode(['code' => 1001, 'msg' => '版本号 ' . $data['version'] . ' 已被占用'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    DB::update('releases', [
        'version'        => $data['version'],
        'build'          => $data['build'],
        'min_version'    => $data['min_version'],
        'schema_version' => $data['schema_version'],
        'channel'        => $data['channel'],
        'download_url'   => $data['download_url'],
        'sha256'         => $data['sha256'],
        'signature'      => $data['signature'],
        'release_notes'  => $data['release_notes'],
        'requirements'   => $data['requirements'],
        'files'          => $data['files'],
        'status'         => $data['status'],
    ], 'id = :id', ['id' => $id]);

    echo json_encode(['code' => 0, 'msg' => '版本已更新'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 上架/下架 ----
// ---- 单条读取（编辑弹窗跨页取数用）----
if ($op === 'get') {
    $id = (int) ($input['id'] ?? 0);
    $row = DB::one('SELECT * FROM ' . DB::t('releases') . ' WHERE id = ?', [$id]);
    if (!$row) {
        echo json_encode(['code' => 1003, 'msg' => '版本不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 0, 'data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量操作（上架 / 下架 / 删除）----
if ($op === 'batch') {
    $act = (string) ($input['action'] ?? '');
    $ids = $input['ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
        echo json_encode(['code' => 1001, 'msg' => '缺少 ids'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if ($ids === []) {
        echo json_encode(['code' => 1001, 'msg' => 'ids 不合法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (count($ids) > 200) {
        echo json_encode(['code' => 1001, 'msg' => '单批最多 200 条'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    if ($act === 'on') {
        DB::exec('UPDATE ' . DB::t('releases') . " SET status = 1 WHERE id IN ($marks)", $ids);
        echo json_encode(['code' => 0, 'msg' => '已批量上架 ' . count($ids) . ' 个版本'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($act === 'off') {
        DB::exec('UPDATE ' . DB::t('releases') . " SET status = 0 WHERE id IN ($marks)", $ids);
        echo json_encode(['code' => 0, 'msg' => '已批量下架 ' . count($ids) . ' 个版本'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($act === 'delete') {
        DB::exec('DELETE FROM ' . DB::t('releases') . " WHERE id IN ($marks)", $ids);
        echo json_encode(['code' => 0, 'msg' => '已批量删除 ' . count($ids) . ' 个版本'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 1001, 'msg' => '未知批量操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($op === 'toggle') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['code' => 1001, 'msg' => '缺少版本 ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $existing = DB::one('SELECT id, version, status FROM ' . DB::t('releases') . ' WHERE id = ?', [$id]);
    if (!$existing) {
        echo json_encode(['code' => 1001, 'msg' => '版本不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $newStatus = (int) $existing['status'] === 1 ? 0 : 1;
    DB::update('releases', ['status' => $newStatus], 'id = :id', ['id' => $id]);
    echo json_encode(['code' => 0, 'msg' => $newStatus === 1 ? '版本已上架' : '版本已下架'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 删除版本 ----
if ($op === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['code' => 1001, 'msg' => '缺少版本 ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $existing = DB::one('SELECT id, version FROM ' . DB::t('releases') . ' WHERE id = ?', [$id]);
    if (!$existing) {
        echo json_encode(['code' => 1001, 'msg' => '版本不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    DB::exec('DELETE FROM ' . DB::t('releases') . ' WHERE id = ?', [$id]);
    echo json_encode(['code' => 0, 'msg' => '版本 ' . $existing['version'] . ' 已删除'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['code' => 1001, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['code' => 9999, 'msg' => '服务器内部错误：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ------------------------------------------------------------------
// 辅助函数
// ------------------------------------------------------------------

/**
 * 处理更新包 ZIP 上传
 * - 保存到 update-system 目录下的 releases/{version}/update.zip
 * - 自动计算 SHA-256
 * - 生成可公网访问的下载地址（基于当前请求的 Host 与 update-system 部署路径）
 *
 * @throws RuntimeException 参数或上传失败时
 */
function handlePackageUpload(string $version): array
{
    if ($version === '' || !preg_match('/^\d+\.\d+\.\d+/', $version)) {
        throw new RuntimeException('请先填写合法的版本号，再上传更新包');
    }
    if (empty($_FILES['update_file']) || ($_FILES['update_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('更新包上传失败，请重试');
    }
    $name = strtolower((string) ($_FILES['update_file']['name'] ?? ''));
    if (!str_ends_with($name, '.zip')) {
        throw new RuntimeException('仅支持 ZIP 格式的更新包');
    }

    // 目标目录：update-system/releases/{version}/update.zip
    $releasesDir = rtrim(__DIR__, '/\\') . '/../../releases/' . $version;
    if (!is_dir($releasesDir)) {
        @mkdir($releasesDir, 0755, true);
    }
    $dest = $releasesDir . '/update.zip';
    if (!move_uploaded_file($_FILES['update_file']['tmp_name'], $dest)) {
        throw new RuntimeException('更新包保存失败（目录不可写）：' . $releasesDir);
    }
    @chmod($dest, 0644);

    $sha256 = hash_file('sha256', $dest);

    // 生成下载地址：https://{host}/update-system/releases/{version}/update.zip
    // 若后台部署在 Web 根，去掉 /update-system 前缀
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $base  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/update-system/admin/handlers/releases.php')), '/');
    // base 形如 /update-system/admin/handlers，往上退两级得部署根（/update-system 或 /）
    $parts = array_values(array_filter(explode('/', $base), 'strlen'));
    $deployPrefix = '';
    if (count($parts) >= 3) {
        $deployPrefix = '/' . implode('/', array_slice($parts, 0, count($parts) - 2));
    }
    $downloadUrl = $scheme . '://' . $host . $deployPrefix . '/releases/' . $version . '/update.zip';

    return [
        'download_url' => $downloadUrl,
        'sha256'       => $sha256,
    ];
}

function validateReleaseInput(array $input, bool $isEdit = false): array
{
    $version = trim((string) ($input['version'] ?? ''));
    if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
        throw new RuntimeException('版本号格式不合法（需如 1.0.0）');
    }

    $downloadUrl = trim((string) ($input['download_url'] ?? ''));
    if ($downloadUrl === '' && !$isEdit) {
        throw new RuntimeException('下载地址不能为空');
    }

    $notes = $input['release_notes'] ?? [];
    if (is_string($notes)) {
        $decoded = json_decode($notes, true);
        $notes = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode("\n", $notes)));
    }
    if (!is_array($notes)) $notes = [];
    $notes = array_values(array_filter(array_map('strval', $notes)));

    $requirements = $input['requirements'] ?? [];
    if (is_string($requirements)) {
        $requirements = json_decode($requirements, true) ?: [];
    }
    if (!is_array($requirements)) $requirements = [];

    $files = $input['files'] ?? [];
    if (is_string($files)) {
        $files = json_decode($files, true) ?: [];
    }
    if (!is_array($files)) $files = [];

    return [
        'version'        => $version,
        'build'          => (int) ($input['build'] ?? 0),
        'min_version'    => trim((string) ($input['min_version'] ?? '1.0.0')),
        'schema_version' => (int) ($input['schema_version'] ?? 1),
        'channel'        => trim((string) ($input['channel'] ?? 'stable')),
        'download_url'   => $downloadUrl,
        'sha256'         => trim((string) ($input['sha256'] ?? '')),
        'signature'      => trim((string) ($input['signature'] ?? '')),
        'release_notes'  => json_encode($notes, JSON_UNESCAPED_UNICODE),
        'requirements'   => json_encode($requirements, JSON_UNESCAPED_UNICODE),
        'files'          => json_encode($files, JSON_UNESCAPED_UNICODE),
        'status'         => (int) ($input['status'] ?? 1),
    ];
}

function decodeJsonField(string $json): array
{
    if ($json === '') return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function ensureReleasesTable(): void
{
    try {
        DB::one('SELECT 1 FROM ' . DB::t('releases') . ' LIMIT 1');
    } catch (Throwable $e) {
        DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('releases') . " (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            version       VARCHAR(32)  NOT NULL,
            build         INT UNSIGNED NOT NULL DEFAULT 0,
            min_version   VARCHAR(32)  NOT NULL DEFAULT '1.0.0',
            schema_version INT         NOT NULL DEFAULT 1,
            channel       VARCHAR(20)  NOT NULL DEFAULT 'stable',
            download_url  VARCHAR(500) NOT NULL DEFAULT '',
            sha256        CHAR(64)     NOT NULL DEFAULT '',
            signature     TEXT,
            release_notes TEXT,
            requirements  TEXT,
            files         TEXT,
            published_at  DATETIME     NOT NULL,
            status        TINYINT      NOT NULL DEFAULT 1,
            created_at    INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_version (version),
            INDEX idx_channel (channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

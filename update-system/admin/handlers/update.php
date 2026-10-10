<?php
/**
 * admin handler: update
 * ==================================================================
 * 更新执行 Handler
 * 操作：check / update / status / history / logout / backups / rollback
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

$op = $input['op'] ?? 'check';

// ------------------------------------------------------------------
// 登录校验
// ------------------------------------------------------------------
$admin = AdminAuth::check();
if (!$admin) {
    echo json_encode(['code' => 1002, 'msg' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// CSRF 校验（只读 check 豁免；含登出在内的其余操作一律要求 CSRF）
// ------------------------------------------------------------------
if ($op !== 'check') {
    $csrf = $input['csrf'] ?? '';
    if (!is_string($csrf) || $csrf === '' || !AdminAuth::checkCsrf($csrf)) {
        echo json_encode(['code' => 1006, 'msg' => 'CSRF 校验失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ------------------------------------------------------------------
// 操作分发
// ------------------------------------------------------------------
try {

// ---- 登出 ----
if ($op === 'logout') {
    AdminAuth::logout();
    echo json_encode(['code' => 0, 'msg' => '已退出'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 检查版本 ----
if ($op === 'check') {
    $result = VersionManager::checkLatestVersion(true);
    if (!$result['success']) {
        echo json_encode(['code' => 1001, 'msg' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $info = $result['data'];
    echo json_encode([
        'code' => 0, 'msg' => 'success',
        'data' => [
            'current'         => VersionManager::getCurrentVersion(),
            'latest'          => $info,
            'has_new_version' => VersionManager::hasNewVersion($info['latest_version'] ?? null),
            'force_update'    => VersionManager::isBelowMinVersion($info['min_version'] ?? null),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 执行更新 ----
if ($op === 'update') {
    $checkResult = VersionManager::checkLatestVersion(true);
    if (!$checkResult['success']) {
        echo json_encode(['code' => 1001, 'msg' => '无法获取版本信息：' . $checkResult['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $versionInfo = $checkResult['data'];
    if (!VersionManager::hasNewVersion($versionInfo['latest_version'] ?? '')) {
        echo json_encode(['code' => 1001, 'msg' => '当前已是最新版本'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($versionInfo['download_url'])) {
        echo json_encode(['code' => 1001, 'msg' => '版本服务器未提供下载地址'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $steps = [];
    $adminInfo = ['id' => $admin['id'], 'username' => $admin['username']];
    $result = VersionManager::doUpdate($versionInfo, $adminInfo, function ($step, $status) use (&$steps) {
        $steps[] = ['name' => $step, 'status' => $status];
    });

    echo json_encode([
        'code' => 0,
        'msg'  => $result['ok'] ? '更新成功' : '更新失败',
        'data' => [
            'ok'         => $result['ok'],
            'steps'      => $steps,
            'version'    => VersionManager::getCurrentVersion()['version'],
            'history_id' => $result['history_id'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 获取状态 ----
if ($op === 'status') {
    $checkResult = VersionManager::checkLatestVersion();
    echo json_encode([
        'code' => 0,
        'data' => [
            'current' => VersionManager::getCurrentVersion(),
            'latest'  => $checkResult['data'],
            'locked'  => VersionManager::isLocked(),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 更新历史 ----
if ($op === 'history') {
    $page = max(1, (int) ($input['page'] ?? 1));
    $size = min(50, max(1, (int) ($input['size'] ?? 20)));
    try {
        $history = VersionManager::getHistory($page, $size);
    } catch (Throwable $e) {
        $history = ['total' => 0, 'list' => []];
    }
    echo json_encode(['code' => 0, 'data' => $history], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 列出备份 ----
if ($op === 'backups') {
    $backups = VersionManager::listBackups();
    echo json_encode(['code' => 0, 'data' => $backups], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 手动回滚 ----
if ($op === 'rollback') {
    $backupName = $input['backup'] ?? '';
    if (empty($backupName)) {
        echo json_encode(['code' => 1001, 'msg' => '请指定备份'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $backupName = basename($backupName);
    if (!preg_match('/^\d{8}_\d{6}$/', $backupName)) {
        echo json_encode(['code' => 1001, 'msg' => '备份名格式不合法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = VersionManager::rollback($backupName);
    echo json_encode([
        'code' => $result['ok'] ? 0 : 1001,
        'msg'  => $result['ok'] ? '回滚成功' : '回滚失败：' . $result['error'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['code' => 1001, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['code' => 9999, 'msg' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
}

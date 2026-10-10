<?php
/**
 * admin handler: dashboard
 * ==================================================================
 * 数据概览：版本统计、安装数据、API 调用统计、版本分布。
 *
 * 操作（op）：
 *   overview   — 全局概览
 *   api_stats  — API 调用统计
 */

require_once __DIR__ . '/../../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// 入口令牌守卫（凭入口 Cookie 通行）
AdminAuth::entryGuard();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    echo json_encode(['code' => 1001, 'msg' => '请使用 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = $_POST;

$op = $input['op'] ?? 'overview';

// 登录校验
$admin = AdminAuth::check();
if (!$admin) {
    echo json_encode(['code' => 1002, 'msg' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 确保所有统计表存在
ensureStatsTables();
ensureReleasesTable();

try {

if ($op === 'overview') {
    // 版本统计（releases 表可能为空，不影响）
    $totalReleases = safeCount('SELECT COUNT(*) FROM ' . DB::t('releases'));
    $activeReleases = safeCount('SELECT COUNT(*) FROM ' . DB::t('releases') . ' WHERE status = 1');
    $latestRelease = null;
    try {
        $latestRelease = DB::one('SELECT version, build, channel, published_at FROM ' . DB::t('releases') . ' WHERE status = 1 ORDER BY id DESC LIMIT 1');
    } catch (Throwable $e) { /* 表不存在或空 */ }

    // 安装统计
    $totalInstalls = safeCount('SELECT COUNT(*) FROM ' . DB::t('install_logs'));
    $uniqueInstalls = safeCount('SELECT COUNT(DISTINCT machine_id) FROM ' . DB::t('install_logs'));

    // 今日安装
    $todayStart = date('Y-m-d 00:00:00');
    $todayInstalls = safeCount('SELECT COUNT(*) FROM ' . DB::t('install_logs') . ' WHERE created_at >= ?', [$todayStart]);

    // 近 7 天安装趋势
    $trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $dayStart = $day . ' 00:00:00';
        $dayEnd = $day . ' 23:59:59';
        $count = safeCount('SELECT COUNT(*) FROM ' . DB::t('install_logs') . ' WHERE created_at >= ? AND created_at <= ?', [$dayStart, $dayEnd]);
        $trend[] = ['date' => $day, 'count' => $count];
    }

    // 版本分布（按已安装版本统计）
    $versionDist = [];
    try {
        $versionDist = DB::all('SELECT version, COUNT(*) as cnt FROM ' . DB::t('install_logs') . ' GROUP BY version ORDER BY cnt DESC LIMIT 20');
    } catch (Throwable $e) { /* 空表或不存在 */ }

    // API 调用统计
    $totalApiCalls = safeCount('SELECT COUNT(*) FROM ' . DB::t('api_logs'));
    $todayApiCalls = safeCount('SELECT COUNT(*) FROM ' . DB::t('api_logs') . ' WHERE created_at >= ?', [$todayStart]);

    // 更新历史统计
    $updateHistory = ['total' => 0, 'success' => 0, 'failed' => 0];
    try {
        VersionManager::ensureHistoryTable();
        $updateHistory['total'] = safeCount('SELECT COUNT(*) FROM ' . DB::t('update_history'));
        $updateHistory['success'] = safeCount('SELECT COUNT(*) FROM ' . DB::t('update_history') . ' WHERE status = ?', ['success']);
        $updateHistory['failed'] = safeCount('SELECT COUNT(*) FROM ' . DB::t('update_history') . ' WHERE status = ?', ['failed']);
    } catch (Throwable $e) { /* 忽略 */ }

    // 当前系统信息
    $currentVersion = VersionManager::getCurrentVersion();
    $mysqlVer = '未知';
    try { $mysqlVer = (string) (DB::value('SELECT VERSION()') ?: '未知'); } catch (Throwable $e) {}

    echo json_encode([
        'code' => 0,
        'data' => [
            'releases' => [
                'total'   => $totalReleases,
                'active'  => $activeReleases,
                'latest'  => $latestRelease,
            ],
            'installs' => [
                'total'   => $totalInstalls,
                'unique'  => $uniqueInstalls,
                'today'   => $todayInstalls,
                'trend'   => $trend,
                'version_dist' => $versionDist,
            ],
            'api' => [
                'total_calls'  => $totalApiCalls,
                'today_calls'  => $todayApiCalls,
            ],
            'update_history' => $updateHistory,
            'system' => [
                'version' => $currentVersion['version'],
                'build'   => $currentVersion['build'],
                'product' => $currentVersion['product'],
                'php'     => PHP_VERSION,
                'mysql'   => $mysqlVer,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($op === 'api_stats') {
    $days = min(90, max(1, (int) ($input['days'] ?? 30)));
    $startDate = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

    $stats = [];
    try {
        $stats = DB::all('SELECT DATE(created_at) as d, COUNT(*) as cnt FROM ' . DB::t('api_logs') . ' WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d', [$startDate]);
    } catch (Throwable $e) { /* 空表 */ }

    echo json_encode(['code' => 0, 'data' => $stats], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['code' => 1001, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['code' => 9999, 'msg' => '服务器内部错误：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ------------------------------------------------------------------
// 辅助函数
// ------------------------------------------------------------------

/** 安全执行 COUNT 查询，表不存在或异常时返回 0 */
function safeCount(string $sql, array $params = []): int
{
    try {
        $v = DB::value($sql, $params);
        return $v !== null ? (int) $v : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function ensureStatsTables(): void
{
    // install_logs 表
    try {
        DB::one('SELECT 1 FROM ' . DB::t('install_logs') . ' LIMIT 1');
    } catch (Throwable $e) {
        DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('install_logs') . " (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            machine_id  VARCHAR(128) NOT NULL DEFAULT '',
            product     VARCHAR(64)  NOT NULL DEFAULT '',
            version     VARCHAR(32)  NOT NULL DEFAULT '',
            build       INT UNSIGNED NOT NULL DEFAULT 0,
            channel     VARCHAR(20)  NOT NULL DEFAULT 'stable',
            ip          VARCHAR(64)  NOT NULL DEFAULT '',
            created_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_machine (machine_id),
            INDEX idx_product (product),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // api_logs 表
    try {
        DB::one('SELECT 1 FROM ' . DB::t('api_logs') . ' LIMIT 1');
    } catch (Throwable $e) {
        DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('api_logs') . " (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action      VARCHAR(64)  NOT NULL DEFAULT '',
            product     VARCHAR(64)  NOT NULL DEFAULT '',
            version     VARCHAR(32)  NOT NULL DEFAULT '',
            ip          VARCHAR(64)  NOT NULL DEFAULT '',
            created_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
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

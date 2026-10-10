<?php
/**
 * admin handler: licenses（v1.3.0）
 * ==================================================================
 * 授权码管理 + 更新门禁开关
 *
 * 操作（op）：
 *   list    — 分页列出授权码 [page, size, channel, status, keyword]
 *   get     — 获取单条授权码详情（授权码已脱敏）{ id }
 *   reveal  — 显示单条完整授权码（需 CSRF）{ id }
 *   create  — 批量签发 { count, days, plan, note }
 *   toggle  — 启用/停用切换 { id }
 *   delete  — 删除 { id }
 *   rebind  — 换绑/解绑域名 { id, domain }
 *   gate    — 更新门禁开关 { on: 0|1 }
 *   batch   — 批量操作 { action: enable|disable|delete, ids: [] }
 *   trial_conf — 试用配置 { days, limit }
 */

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/License.php';

header('Content-Type: application/json; charset=utf-8');

AdminAuth::entryGuard();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    echo json_encode(['code' => 1001, 'msg' => '请使用 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = $_POST;

$op = (string) ($input['op'] ?? 'list');

$admin = AdminAuth::check();
if (!$admin) {
    echo json_encode(['code' => 1002, 'msg' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 授权码脱敏：列表中不回显完整授权码，避免批量明文泄露（日志/投屏/共享屏幕）。
// 需要完整值用于交付客户时，走单独的 reveal 操作（需 CSRF，单条返回）。
$maskKey = static function ($k): string {
    $k = (string) $k;
    return strlen($k) <= 10 ? $k : (substr($k, 0, 6) . str_repeat('*', strlen($k) - 10) . substr($k, -4));
};

// list / get 不需要 CSRF（纯读取）
if (!in_array($op, ['list', 'get'])) {
    $csrf = (string) ($input['csrf'] ?? '');
    if ($csrf === '' || !AdminAuth::checkCsrf($csrf)) {
        echo json_encode(['code' => 1006, 'msg' => 'CSRF 校验失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 表存在性兜底（老站未跑迁移时自动建表，幂等）
try {
    DB::one('SELECT 1 FROM ' . DB::t('licenses') . ' LIMIT 1');
} catch (Throwable $e) {
    DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('licenses') . " (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `license_key` CHAR(32) NOT NULL,
        `domain` VARCHAR(128) NOT NULL DEFAULT '',
        `status` TINYINT NOT NULL DEFAULT 1,
        `plan` VARCHAR(20) NOT NULL DEFAULT 'standard',
        `note` VARCHAR(255) DEFAULT NULL,
        `issued_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `activated_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_check_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_ip` VARCHAR(64) NOT NULL DEFAULT '',
        `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_key` (`license_key`),
        INDEX `idx_domain` (`domain`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权码'");
}

try {

// ---- 列表（支持筛选 + 分页）----
if ($op === 'list') {
    $page      = max(1, (int) ($input['page'] ?? 1));
    $size      = min(100, max(1, (int) ($input['size'] ?? 15)));
    $offset    = ($page - 1) * $size;

    // 筛选条件
    $channel   = trim((string) ($input['channel'] ?? ''));  // stable / beta / dev / ''=全部
    $status    = (int) ($input['status'] ?? -1);             // -1=全部, 0=停用, 1=启用
    $keyword   = trim((string) ($input['keyword'] ?? ''));   // 授权码 / 域名 / 备注 关键词

    $whereClauses = [];
    $whereParams  = [];

    if ($channel !== '') {
        $whereClauses[] = "plan = ?";
        $whereParams[]  = $channel;
    }
    if ($status >= 0) {
        $whereClauses[] = "status = ?";
        $whereParams[]  = $status;
    }
    if ($keyword !== '') {
        $like = '%' . $keyword . '%';
        $whereClauses[] = "(license_key LIKE ? OR domain LIKE ? OR note LIKE ?)";
        $whereParams[]  = $like;
        $whereParams[]  = $like;
        $whereParams[]  = $like;
    }
    $whereSql = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    $total = (int) DB::value('SELECT COUNT(*) FROM ' . DB::t('licenses') . $whereSql, $whereParams);
    $totalPages = max(1, (int) ceil($total / $size));

    // 分页取数
    $listSql = 'SELECT * FROM ' . DB::t('licenses') . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$size . ' OFFSET ' . (int)$offset;
    $rows = DB::all($listSql, $whereParams);
    foreach ($rows as &$r) {
        $r['license_key'] = $maskKey($r['license_key'] ?? ''); // 脱敏回显
    }
    unset($r);

    echo json_encode([
        'code' => 0,
        'data' => [
            'list'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'size'         => $size,
            'total_pages'  => $totalPages,
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 获取单条（编辑弹窗用，跨页也能取到）----
if ($op === 'get') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['code' => 1001, 'msg' => '缺少 id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $row = DB::one('SELECT * FROM ' . DB::t('licenses') . ' WHERE id = ?', [$id]);
    if (!$row) {
        echo json_encode(['code' => 1003, 'msg' => '授权码不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $row['license_key'] = $maskKey($row['license_key'] ?? ''); // 脱敏回显
    echo json_encode(['code' => 0, 'data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 显示单条完整授权码（交付客户时复制用，需 CSRF）----
if ($op === 'reveal') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['code' => 1001, 'msg' => '缺少 id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $k = DB::value('SELECT license_key FROM ' . DB::t('licenses') . ' WHERE id = ?', [$id]);
    if ($k === null) {
        echo json_encode(['code' => 1003, 'msg' => '授权码不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 0, 'data' => ['license_key' => (string) $k]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量签发 ----
if ($op === 'create') {
    $count = min(100, max(1, (int) ($input['count'] ?? 1)));
    $days  = min(3650, max(0, (int) ($input['days'] ?? 0)));
    $plan  = in_array(($input['plan'] ?? ''), ['standard', 'pro', 'trial'], true) ? (string) $input['plan'] : 'standard';
    $note  = mb_substr(trim((string) ($input['note'] ?? '')), 0, 255);
    $expires = $days > 0 ? time() + $days * 86400 : 0;

    $keys = [];
    for ($i = 0; $i < $count; $i++) {
        $key = License::generateKey();
        DB::insert('licenses', [
            'license_key' => $key,
            'status'      => 1,
            'plan'        => $plan,
            'note'        => $note !== '' ? $note : null,
            'issued_at'   => time(),
            'expires_at'  => $expires,
            'created_at'  => time(),
        ]);
        $keys[] = $key;
    }

    echo json_encode(['code' => 0, 'msg' => '已签发 ' . $count . ' 枚', 'data' => ['count' => $count, 'keys' => $keys]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 启用/停用 ----
if ($op === 'toggle') {
    $id = (int) ($input['id'] ?? 0);
    $row = DB::one('SELECT id, status FROM ' . DB::t('licenses') . ' WHERE id = ?', [$id]);
    if (!$row) {
        echo json_encode(['code' => 1003, 'msg' => '授权码不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    DB::update('licenses', ['status' => (int) $row['status'] === 1 ? 0 : 1], 'id = :id', ['id' => $id]);
    echo json_encode(['code' => 0, 'msg' => '已切换状态'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 删除 ----
if ($op === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    // 已激活（已绑定域名，即已投入使用）的授权码不做硬删除，保证审计留痕，
    // 需要停止使用时请用「停用」。
    $row = DB::one('SELECT domain FROM ' . DB::t('licenses') . ' WHERE id = ?', [$id]);
    if ($row && trim((string) $row['domain']) !== '') {
        echo json_encode(['code' => 1009, 'msg' => '该授权码已绑定域名并投入使用，为保证审计留痕不可删除，请改用「停用」'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    DB::exec('DELETE FROM ' . DB::t('licenses') . ' WHERE id = ?', [$id]);
    echo json_encode(['code' => 0, 'msg' => '已删除'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 换绑 / 解绑 ----
if ($op === 'rebind') {
    $id = (int) ($input['id'] ?? 0);
    $domain = License::normalizeDomain((string) ($input['domain'] ?? ''));
    $row = DB::one('SELECT id FROM ' . DB::t('licenses') . ' WHERE id = ?', [$id]);
    if (!$row) {
        echo json_encode(['code' => 1003, 'msg' => '授权码不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($domain !== '' && DB::one('SELECT id FROM ' . DB::t('licenses') . ' WHERE domain = ? AND id != ?', [$domain, $id])) {
        echo json_encode(['code' => 1004, 'msg' => '该域名已被其他授权码绑定'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    DB::update('licenses', [
        'domain'       => $domain,
        'activated_at' => $domain !== '' ? time() : 0,
    ], 'id = :id', ['id' => $id]);
    echo json_encode(['code' => 0, 'msg' => $domain !== '' ? '已换绑到 ' . $domain : '已解除绑定'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量操作（enable / disable / delete）----
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
    if ($act === 'enable') {
        DB::exec('UPDATE ' . DB::t('licenses') . " SET status = 1 WHERE id IN ($marks)", $ids);
        echo json_encode(['code' => 0, 'msg' => '已批量启用 ' . count($ids) . ' 枚'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($act === 'disable') {
        DB::exec('UPDATE ' . DB::t('licenses') . " SET status = 0 WHERE id IN ($marks)", $ids);
        echo json_encode(['code' => 0, 'msg' => '已批量停用 ' . count($ids) . ' 枚'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($act === 'delete') {
        // 已激活（已绑定域名）的授权码保留不删，保证审计留痕
        $locked  = (int) DB::value('SELECT COUNT(*) FROM ' . DB::t('licenses') . " WHERE id IN ($marks) AND domain <> ''", $ids);
        $deleted = DB::exec('DELETE FROM ' . DB::t('licenses') . " WHERE id IN ($marks) AND (domain = '' OR domain IS NULL)", $ids);
        $m = '已批量删除 ' . $deleted . ' 枚';
        if ($locked > 0) $m .= '；另有 ' . $locked . ' 枚已激活的授权码已保留（请改用停用）';
        echo json_encode(['code' => 0, 'msg' => $m], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 1001, 'msg' => '未知批量操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 试用授权配置（trial_days / trial_daily_limit / trial_pow_difficulty）----
if ($op === 'trial_conf') {
    $days  = min(3650, max(1, (int) ($input['days'] ?? 7)));
    $limit = min(100, max(0, (int) ($input['limit'] ?? 3)));
    $diff  = min(6, max(1, (int) ($input['difficulty'] ?? 4)));
    $t = DB::t('settings');
    DB::exec("INSERT INTO {$t} (skey, svalue, remark, updated_at) VALUES ('trial_days', ?, '试用授权有效期(天)', UNIX_TIMESTAMP())
              ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = UNIX_TIMESTAMP()", [(string) $days]);
    DB::exec("INSERT INTO {$t} (skey, svalue, remark, updated_at) VALUES ('trial_daily_limit', ?, '试用授权每人每日限领(0=关闭)', UNIX_TIMESTAMP())
              ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = UNIX_TIMESTAMP()", [(string) $limit]);
    DB::exec("INSERT INTO {$t} (skey, svalue, remark, updated_at) VALUES ('trial_pow_difficulty', ?, '试用领取人机验证难度(1-6)', UNIX_TIMESTAMP())
              ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = UNIX_TIMESTAMP()", [(string) $diff]);
    echo json_encode(['code' => 0, 'msg' => '试用配置已保存', 'data' => ['days' => $days, 'limit' => $limit, 'difficulty' => $diff]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 门禁开关 ----
if ($op === 'gate') {
    $on = !empty($input['on']) ? '1' : '0';
    $t = DB::t('settings');
    DB::exec("INSERT INTO {$t} (skey, svalue, remark, updated_at) VALUES ('license_gate', ?, '更新门禁', UNIX_TIMESTAMP())
              ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = UNIX_TIMESTAMP()", [$on]);
    echo json_encode(['code' => 0, 'msg' => $on === '1' ? '门禁已开启' : '门禁已关闭'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['code' => 1001, 'msg' => '未知 op'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // 非调试模式不回显异常细节，避免泄露 SQL / 表结构
    @error_log('[licenses] ' . $e->getMessage());
    $debug = (bool) (($GLOBALS['config'] ?? [])['debug'] ?? false);
    echo json_encode([
        'code' => 5000,
        'msg'  => '服务器错误' . ($debug ? '：' . $e->getMessage() : '，请稍后重试'),
    ], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * 清空日志类数据表
 *
 * 用法：
 *   php install/clear_logs.php                # 交互确认
 *   php install/clear_logs.php --yes          # 跳过确认
 *   php install/clear_logs.php --yes --include-state   # 连同会话/限流一起清
 *   php install/clear_logs.php --dry-run      # 只统计不删除
 *
 * 分组说明：
 *   logs   —— 真日志，清除无副作用
 *   state  —— 名为 log 实为运行状态，清除会导致强制下线 / 限流重置
 */
// 仅限命令行：防止被浏览器直接请求执行（见 _cli_guard.php 说明）
require_once __DIR__ . '/_cli_guard.php';

require_once __DIR__ . '/../lib/bootstrap.php';

$argv    = $argv ?? [];
$yes     = in_array('--yes', $argv, true);
$dryRun  = in_array('--dry-run', $argv, true);
$incState = in_array('--include-state', $argv, true);

/** 日志表（清除无副作用） */
$logTables = [
    'logs'         => '系统运行日志',
    'audit_logs'   => '管理端操作审计',
    'card_logs'    => '卡密使用记录',
    'api_stats'    => '接口调用统计',
];

/** 状态表（清除有副作用，需显式 --include-state） */
$stateTables = [
    'rate_limit'     => '限流计数（清除后限流重置）',
    'sessions'       => '用户会话（清除后用户强制下线）',
    'admin_sessions' => '管理会话（清除后管理员强制下线）',
];

$targets = $logTables + ($incState ? $stateTables : []);

echo str_repeat('=', 66) . PHP_EOL;
echo ' 清空日志数据' . ($incState ? '（含状态表）' : '') . ($dryRun ? '  [DRY-RUN]' : '') . PHP_EOL;
echo ' 数据库: ' . Config::get('db.name') . '   前缀: ' . Config::get('db.prefix') . PHP_EOL;
echo str_repeat('=', 66) . PHP_EOL . PHP_EOL;

// ---------- 1. 统计 ----------
$plan = [];
$grand = 0;
foreach ($targets as $key => $desc) {
    $tbl = Database::t($key);
    try {
        $r = Database::one("SELECT COUNT(*) AS c FROM {$tbl}");
        $n = (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        echo "  [跳过] {$tbl} —— 表不存在" . PHP_EOL;
        continue;
    }
    $plan[$key] = ['tbl' => $tbl, 'desc' => $desc, 'rows' => $n];
    $grand += $n;
    printf("  %-20s %-34s %6d 行\n", $tbl, $desc, $n);
}
echo PHP_EOL . "  合计 {$grand} 行" . PHP_EOL . PHP_EOL;

if ($grand === 0) {
    echo "  所有目标表已是空的，无需清理。" . PHP_EOL;
    exit(0);
}

if ($dryRun) {
    echo "  [DRY-RUN] 未执行任何删除。" . PHP_EOL;
    exit(0);
}

// ---------- 2. 确认 ----------
if (!$yes) {
    echo "  即将清空以上 " . count($plan) . " 张表，操作不可恢复！" . PHP_EOL;
    if ($incState) {
        echo "  ⚠ 包含状态表：所有用户与管理员将被强制下线。" . PHP_EOL;
    }
    echo '  输入 yes 继续: ';
    $in = trim((string) fgets(STDIN));
    if (strtolower($in) !== 'yes') {
        echo "  已取消。" . PHP_EOL;
        exit(1);
    }
}

// ---------- 3. 执行 ----------
echo PHP_EOL;
$ok = $fail = 0;
foreach ($plan as $key => $info) {
    try {
        // TRUNCATE 比 DELETE 快，且重置 AUTO_INCREMENT
        Database::exec("TRUNCATE TABLE {$info['tbl']}", []);
        printf("  [OK]   %-20s 已清空 %d 行\n", $info['tbl'], $info['rows']);
        $ok++;
    } catch (Throwable $e) {
        printf("  [FAIL] %-20s %s\n", $info['tbl'], $e->getMessage());
        $fail++;
    }
}

// ---------- 4. 复核 ----------
echo PHP_EOL . ' 复核：' . PHP_EOL;
$left = 0;
foreach ($plan as $info) {
    $r = Database::one("SELECT COUNT(*) AS c FROM {$info['tbl']}");
    $n = (int) ($r['c'] ?? 0);
    $left += $n;
    printf("  %-20s 剩余 %d 行%s\n", $info['tbl'], $n, $n === 0 ? '  ✓' : '');
}

// ---------- 5. 留痕（写入文件，因为审计表已被清空）----------
$logDir = NB_ROOT . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$line = sprintf(
    "[%s] clear_logs  ip=%s  tables=%d  rows=%d  ok=%d fail=%d include_state=%s\n",
    date('Y-m-d H:i:s'), Util::ip(), count($plan), $grand, $ok, $fail, $incState ? 'yes' : 'no'
);
@file_put_contents($logDir . '/maintenance.log', $line, FILE_APPEND);

echo PHP_EOL . str_repeat('=', 66) . PHP_EOL;
printf(" 完成：成功 %d 张表，失败 %d 张，共清除 %d 行（剩余 %d 行）\n", $ok, $fail, $grand, $left);
echo ' 留痕: logs/maintenance.log' . PHP_EOL;
echo str_repeat('=', 66) . PHP_EOL;

exit($fail > 0 ? 1 : 0);

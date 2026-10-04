<?php
/**
 * 统一数据库迁移执行器（schema version 体系）
 * ------------------------------------------------------------------
 * 2026-09-30 审计 P2 指出：零散的 migrate_xxx.php 会导致老客户
 * 「执行了 A、漏了 B」，数据库状态彼此不一致。
 *
 * 本执行器把迁移收敛为版本化登记表：
 *   - 当前数据库版本存于 settings 表 skey='schema_version'；
 *   - 全新安装（schema.sql 建库）写入 $BASELINE 版本，无需补跑迁移；
 *   - 升级时按登记表顺序补跑所有「大于当前版本」的迁移，每个迁移
 *     文件内部自保证幂等（存在列/表则跳过）；
 *   - 登记表中声明的文件缺失时立即终止 —— 宁可不动库，不能半升级。
 *
 * 用法（CLI）：
 *   php install/migrate.php status    查看当前版本与待执行迁移
 *   php install/migrate.php run       执行待迁移（幂等，可重复运行）
 *
 * 新增迁移的登记方式：在 MIGRATIONS 里追加一行
 *   '2.65.x' => ['migrate_yyy.php', 'migrate_zzz.php'],
 * 文件放在 install/ 目录，随更新包分发（空白包不含运行时迁移是刻意设计，
 * 但发布更新包时 deploy 工具必须带上本文件与对应迁移）。
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/lib/bootstrap.php';

/** 全新安装 schema.sql 已包含的基线版本（等于打此包时的 NB_VERSION） */
const BASELINE = '2.65.11';

/**
 * 版本 => 迁移文件列表（按版本升序执行）
 * 注意：老版本的迁移文件只服务「从更老版本升级」的客户，
 * 新装客户不会执行它们（schema.sql 已内置）。
 */
const MIGRATIONS = [
    '2.65.8'  => ['migrate_admin_totp.php', 'migrate_admin_session_bind.php'],
    '2.65.11' => ['migrate_key_rotation.php', 'migrate_tenant.php'],
    '2.65.12' => ['migrate_online_stats.php'],
    '2.65.13' => ['migrate_feature_key.php'],
];

function currentSchemaVersion(): string
{
    $v = Setting::get('schema_version', '');
    if ($v !== '') {
        return (string) $v;
    }
    // 老安装没有版本标记：settings 表存在但没记录 → 视为基线之前的未知状态，
    // 由管理员通过 --baseline=2.65.x 显式设定，这里返回 0 触发全量待跑清单
    return '0';
}

function cmpVer(string $a, string $b): int
{
    return version_compare($a, $b);
}

$cmd = $argv[1] ?? 'status';

if ($cmd === 'baseline') {
    // 首次接入版本体系时人工声明当前状态：--base 命令把 schema_version 设为指定版本
    $base = $argv[2] ?? '';
    if (!preg_match('/^\d+(\.\d+){0,3}$/', $base)) {
        exit("用法: php install/migrate.php baseline <版本号>\n");
    }
    Setting::set('schema_version', $base, '数据库结构版本');
    echo "schema_version 已设为 {$base}\n";
    exit(0);
}

$cur = currentSchemaVersion();
if ($cur === '0') {
    echo "!! 数据库尚无 schema_version 标记。\n";
    echo "   全新安装（由本包 schema.sql 建库）请执行: php install/migrate.php baseline " . BASELINE . "\n";
    echo "   老库升级请先确认当前代码版本后执行: php install/migrate.php baseline <老版本号>\n";
    exit(1);
}

// 组装待执行清单
$pending = [];
foreach (MIGRATIONS as $ver => $files) {
    if (cmpVer($ver, $cur) > 0) {
        foreach ($files as $f) {
            $pending[] = [$ver, $f];
        }
    }
}

if ($cmd === 'status') {
    echo "当前版本: {$cur}\n";
    echo "目标版本: " . (MIGRATIONS ? array_key_last(array_reverse(MIGRATIONS, true)) : $cur) . "\n";
    if (!$pending) {
        echo "无待执行迁移，数据库已是最新。\n";
        exit(0);
    }
    echo "待执行:\n";
    foreach ($pending as [$ver, $f]) {
        echo "  [{$ver}] {$f}\n";
    }
    exit(0);
}

if ($cmd !== 'run') {
    echo "用法: php install/migrate.php status|run|baseline <版本>\n";
    exit(1);
}

// run：先确认所有文件都在，缺任何一个都不动手
$missing = [];
foreach ($pending as [$ver, $f]) {
    if (!is_file(__DIR__ . '/' . $f)) {
        $missing[] = "[{$ver}] {$f}";
    }
}
if ($missing) {
    echo "!! 缺少迁移文件，已终止（数据库未做任何改动）：\n  " . implode("\n  ", $missing) . "\n";
    echo "   请先获取对应版本的完整更新包。\n";
    exit(1);
}

foreach ($pending as [$ver, $f]) {
    echo ">>> [{$ver}] 执行 {$f} ... ";
    ob_start();
    try {
        require __DIR__ . '/' . $f;
        ob_end_clean();
        Setting::set('schema_version', $ver, '数据库结构版本');
        echo "完成，版本推进到 {$ver}\n";
    } catch (Throwable $e) {
        ob_end_clean();
        echo "失败：" . $e->getMessage() . "\n";
        echo "   已终止。请排查后重跑（迁移均为幂等实现）。\n";
        exit(1);
    }
}
echo "全部迁移完成。\n";

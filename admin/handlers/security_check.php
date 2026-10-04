<?php
/**
 * Admin handler: security_check
 * 安全自检 —— 扫描管理面与数据面的已知风险项，给管理员一份可操作的清单。
 * ----------------------------------------------------------------------------
 * 检查项（level：ok / warn / danger）：
 *   1. 管理员 2FA 覆盖：存在未绑定 TOTP 的管理员 → danger（单点账号）
 *   2. 默认账号名：存在 username='admin' 的账号 → warn（撞库首选目标）
 *   3. 功能密钥覆盖：软件未配置 feature_key → warn（核心数据明文随包分发）
 *   4. 运行时防护覆盖：无任何策略（全局与软件级均为空）→ warn
 *      （此时全部软件回落内置默认策略：标准级全开 —— 想关闭须建全局等级 0 策略）
 *   5. 备份文件暴露面：data/backups 下存在 SQL 备份 → info（确认 web 不可达）
 *   6. 管理员密码策略档：信息项（当前新密码要求：≥10 位 + 大写或符号）
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_SECURITY);

$checks = [];
$now = time();

// ------------------------------------------------------------------
// 1. 管理员 2FA 覆盖
// ------------------------------------------------------------------
$table = Database::t('admins');
$total = (int) Database::value("SELECT COUNT(*) FROM {$table}");
$noTotp = (int) Database::value("SELECT COUNT(*) FROM {$table} WHERE (totp_enabled IS NULL OR totp_enabled = 0)");
$checks[] = [
    'id'     => 'admin_2fa',
    'level'  => $noTotp > 0 ? 'danger' : 'ok',
    'title'  => '管理员两步验证（2FA）',
    'detail' => $noTotp > 0
        ? "有 {$noTotp}/{$total} 个管理员账号未绑定动态验证码。未绑定的账号仅靠「密码+验证码图」，被撞库后即完全失守，请尽快在「个人中心」绑定。"
        : "全部 {$total} 个管理员账号均已绑定动态验证码。",
];

// ------------------------------------------------------------------
// 2. 默认账号名
// ------------------------------------------------------------------
$hasAdminName = (int) Database::value("SELECT COUNT(*) FROM {$table} WHERE username = 'admin'");
$checks[] = [
    'id'     => 'default_username',
    'level'  => $hasAdminName > 0 ? 'warn' : 'ok',
    'title'  => '默认管理员账号名',
    'detail' => $hasAdminName > 0
        ? '存在用户名为「admin」的账号 —— 这是自动化撞库的首要目标，建议在「管理员管理」里改名或改用不常见账号。'
        : '未发现使用默认账号名（admin）的账号。',
];

// ------------------------------------------------------------------
// 3. 功能密钥（数据层保护）覆盖
// ------------------------------------------------------------------
$swTable  = Database::t('softwares');
$swTotal  = (int) Database::value("SELECT COUNT(*) FROM {$swTable} WHERE status = 1");
$noFk     = (int) Database::value("SELECT COUNT(*) FROM {$swTable} WHERE status = 1 AND (feature_key IS NULL OR feature_key = '')");
$checks[] = [
    'id'     => 'feature_key',
    'level'  => $noFk > 0 ? 'warn' : 'ok',
    'title'  => '功能密钥（核心数据加密）',
    'detail' => $noFk > 0
        ? "有 {$noFk}/{$swTotal} 个软件未配置功能密钥 —— 核心数据仍以明文随程序分发，patch 登录判定即可白嫖。请在「软件管理」为本软件设置功能密钥并加密核心数据包。"
        : "全部 {$swTotal} 个软件均已启用功能密钥（核心数据加密分发）。",
];

// ------------------------------------------------------------------
// 4. 运行时防护策略覆盖
// ------------------------------------------------------------------
$rpTable    = Database::t('runtime_policies');
$policyCnt  = (int) Database::value("SELECT COUNT(*) FROM {$rpTable} WHERE enabled = 1 AND status = 1");
$checks[] = [
    'id'     => 'rt_policy_cover',
    'level'  => $policyCnt > 0 ? 'ok' : 'warn',
    'title'  => '运行时防护策略',
    'detail' => $policyCnt > 0
        ? "已配置 {$policyCnt} 条生效策略（软件专属 &gt; 全局；未覆盖的软件回落内置默认：标准级全开）。"
        : '尚未配置任何策略 —— 所有软件都在使用内置默认策略（标准级全开）。要关闭某软件防护，请建覆盖它的等级 0 策略；要全局关闭，建一条全局等级 0 策略即可。',
];

// ------------------------------------------------------------------
// 5. 备份文件暴露面（只提示，不做网络探测）
// ------------------------------------------------------------------
$bkDir = NB_ROOT . '/data/backups';
$bkCnt = 0;
if (is_dir($bkDir)) {
    foreach (glob($bkDir . '/*.sql*') as $f) {
        if (is_file($f)) $bkCnt++;
    }
}
$checks[] = [
    'id'     => 'backup_expose',
    'level'  => $bkCnt > 0 ? 'info' : 'ok',
    'title'  => '备份文件暴露面',
    'detail' => $bkCnt > 0
        ? "data/backups 下有 {$bkCnt} 个 SQL 备份文件。请确认 Web 服务器已拒绝该目录的 HTTP 访问（数据库备份含全部账号/密文哈希，泄露即灾难）。"
        : 'data/backups 下没有 SQL 备份文件。',
];

// ------------------------------------------------------------------
// 6. 管理员密码策略档（信息项）
// ------------------------------------------------------------------
$checks[] = [
    'id'     => 'admin_password_policy',
    'level'  => 'ok',
    'title'  => '管理员密码策略',
    'detail' => '新密码要求：≥10 位、同时含字母与数字、必须包含大写字母或符号、拒绝常见弱口令（已有密码不强制重设，改密时生效）。',
];

// 汇总
$summary = ['danger' => 0, 'warn' => 0, 'ok' => 0, 'info' => 0];
foreach ($checks as $c) {
    $summary[$c['level']] = ($summary[$c['level']] ?? 0) + 1;
}

Response::ok([
    'checks'  => $checks,
    'summary' => $summary,
    'checked_at' => $now,
]);

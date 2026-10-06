<?php
/**
 * admin action: user_batch_op
 * 用户批量操作：封禁/解封/加时长/加点数/踢下线/删除
 */

$op  = Util::str($input, 'op', '');
$ids = $input['ids'] ?? [];
if (!is_array($ids)) {
    $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

if (!$ids) {
    Response::error(1001, '请选择用户');
}
if (count($ids) > 500) {
    Response::error(1001, '单次最多操作 500 个用户');
}

$now   = time();
// 2026-10-06 审计：批量越权修复 —— 租户管理员不可批量操作其他软件下的用户；
// requireTouchAll 对有归属但越权的 ID 整单拒绝（查无记录的交业务侧 404/忽略）。
Tenant::requireTouchAll($admin, 'users', $ids);
$users = Database::all(
    'SELECT id, username, status, points, max_devices, vip_expire, nickname, email, remark, group_id'
    . ' FROM ' . Database::t('users') . ' WHERE id IN (' . implode(',', $ids) . ')'
);

if (!$users) {
    Response::error(1001, '未找到对应用户');
}
$realIds = array_column($users, 'id');
$in      = implode(',', array_map('intval', $realIds));

switch ($op) {
    // ---------------- 批量改状态 ----------------
    case 'status':
        $value = Util::int($input, 'value', 1);
        if (!in_array($value, [0, 1, 2], true)) {
            Response::error(1001, '状态值不合法');
        }
        if ($value === 0) {
            // 限时封禁：duration + unit（年/月/周/天/小时/分钟/秒），duration<=0 = 永久
            $duration = Util::int($input, 'duration', -1);
            if ($duration < 0) { $duration = Util::int($input, 'days', 0); }   // 兼容旧参数
            [$banExpire, $banDesc] = Util::durationExpire($duration, Util::str($input, 'unit', 'day'), $now);
        } else {
            $banExpire = 0;   // 解封/冻结时清空封禁到期
            $banDesc   = '';
        }
        Database::exec(
            'UPDATE ' . Database::t('users') . " SET status = ?, ban_expire = ?, updated_at = ? WHERE id IN ($in)",
            [$value, $banExpire, $now]
        );
        if ($value !== 1) {
            // 封禁/冻结时强制下线
            Database::exec(
                'UPDATE ' . Database::t('sessions') . " SET status = 3 WHERE user_id IN ($in)",
                []
            );
        }
        $label  = [0 => '封禁', 1 => '解封', 2 => '冻结'][$value];
        $detail = "批量{$label} " . count($realIds) . ' 个用户' . ($banDesc ?: '');
        Audit::log($admin, 'user_batch', "用户批量({$label})", $detail,
            [], [], ['ids' => $realIds, 'value' => $value, 'ban_expire' => $banExpire]);
        Response::ok(['count' => count($realIds)], "已{$label} " . count($realIds) . ' 个用户' . ($banDesc ?: ''));
        break;

    // ---------------- 批量加时长 ----------------
    case 'add_days':
        $days = Util::int($input, 'value', 0);
        if ($days === 0) {
            Response::error(1001, '天数不能为 0');
        }
        $delta = $days * 86400;
        // 永久卡（-1）保持不变；未激活的从当前时间起算
        Database::exec(
            'UPDATE ' . Database::t('users')
            . ' SET vip_expire = CASE'
            . '   WHEN vip_expire = -1 THEN -1'
            . '   WHEN vip_expire > ? THEN vip_expire + ?'
            . '   ELSE ? + ?'
            . ' END, updated_at = ?'
            . " WHERE id IN ($in)",
            [$now, $delta, $now, $delta, $now]
        );
        Audit::log($admin, 'user_batch', '用户批量(加时长)', "为 " . count($realIds) . " 个用户调整 {$days} 天",
            [], [], ['ids' => $realIds, 'days' => $days]);
        Response::ok(['count' => count($realIds)], '已为 ' . count($realIds) . " 个用户调整 {$days} 天");
        break;

    // ---------------- 批量加点数 ----------------
    case 'add_points':
        $pts = Util::int($input, 'value', 0);
        if ($pts === 0) {
            Response::error(1001, '点数不能为 0');
        }
        // 扣减时不允许为负
        Database::exec(
            'UPDATE ' . Database::t('users')
            . ' SET points = GREATEST(0, points + ?), updated_at = ?'
            . " WHERE id IN ($in)",
            [$pts, $now]
        );
        Audit::log($admin, 'user_batch', '用户批量(加点数)', "为 " . count($realIds) . " 个用户调整 {$pts} 点",
            [], [], ['ids' => $realIds, 'points' => $pts]);
        Response::ok(['count' => count($realIds)], '已为 ' . count($realIds) . " 个用户调整 {$pts} 点");
        break;

    // ---------------- 批量踢下线 ----------------
    case 'kick':
        Database::exec('UPDATE ' . Database::t('sessions') . " SET status = 3 WHERE user_id IN ($in)", []);
        Audit::log($admin, 'user_batch', '用户批量(下线)', '强制下线 ' . count($realIds) . ' 个用户',
            [], [], ['ids' => $realIds]);
        Response::ok(['count' => count($realIds)], '已强制下线 ' . count($realIds) . ' 个用户');
        break;

    // ---------------- 批量删除（仅超管 + 需密码确认） ----------------
    case 'delete':
        Deleter::confirmPassword($admin, $input, '批量删除用户');

        $r = Deleter::users($realIds);

        Audit::log($admin, 'user_batch', '用户批量(删除)',
            '批量删除 ' . $r['deleted'] . ' 个用户：' . implode(', ', array_slice($r['usernames'], 0, 20)),
            [], [], ['ids' => $realIds, 'usernames' => $r['usernames']]);

        Response::ok(['count' => $r['deleted']], '已删除 ' . $r['deleted'] . ' 个用户');
        break;

    default:
        Response::error(1001, '未知操作');
}

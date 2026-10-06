<?php
/**
 * admin action: device_ban
 * 拉黑机器码（可指定时长），同时解绑并踢下线
 */

$ids = $input['device_ids'] ?? [];
if (!is_array($ids)) {
    $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

if (!$ids) {
    Response::error(1001, '请选择设备');
}
if (count($ids) > 500) {
    Response::error(1001, '单次最多操作 500 台设备');
}

$reason = mb_substr(Util::str($input, 'reason', '管理员拉黑'), 0, 250);

// 时长：duration + unit（year/month/week/day/hour/minute/second）；兼容旧参数 days
$duration = Util::int($input, 'duration', -1);
if ($duration < 0) {
    $duration = Util::int($input, 'days', 0);
}
$unitMap = [
    'year'   => ['years',   '年'],
    'month'  => ['months',  '个月'],
    'week'   => ['weeks',   '周'],
    'day'    => ['days',    '天'],
    'hour'   => ['hours',   '小时'],
    'minute' => ['minutes', '分钟'],
    'second' => ['seconds', '秒'],
];
$unit = Util::str($input, 'unit', 'day');
if (!isset($unitMap[$unit])) {
    $unit = 'day';
}

$now = time();
if ($duration > 0) {
    $duration = min($duration, 100000);   // 防溢出/误操作
    [$str, $unitName] = $unitMap[$unit];
    $expire = strtotime("+{$duration} {$str}", $now);
    if ($expire === false || $expire <= $now) {
        $expire = $now;                    // 极端情况兜底为立即到期
    }
    $desc = "（{$duration} {$unitName}）";
} else {
    $expire = 0;
    $desc = '（永久）';
}

$in      = implode(',', $ids);
$devices = Database::all(
    'SELECT id, user_id, machine_id FROM ' . Database::t('devices') . " WHERE id IN ($in)"
);
if (!$devices) {
    Response::error(1001, '未找到对应设备');
}

// 2026-10-06 审计：跨租户批量拉黑修复 —— 任一设备不在本租户范围内即整单拒绝
//（device→user→software 授权链校验；此前可凭 device_id 拉黑任意软件/租户的机器码）
foreach ($devices as $dv) {
    Tenant::requireTouchDevice($admin, (int) $dv['id']);
}

$count = 0;
foreach ($devices as $dv) {
    // 写入黑名单（已存在则更新）
    Database::exec(
        'INSERT INTO ' . Database::t('device_bans')
        . ' (machine_id, reason, admin_id, expire_at, created_at) VALUES (?, ?, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE reason = VALUES(reason), expire_at = VALUES(expire_at),'
        . ' admin_id = VALUES(admin_id)',
        [$dv['machine_id'], $reason, (int) $admin['id'], $expire, $now]
    );
    // 解绑并踢下线
    Database::exec(
        'UPDATE ' . Database::t('devices') . ' SET status = 0, unbind_at = ?, unbind_reason = ? WHERE id = ?',
        [$now, '拉黑：' . $reason, (int) $dv['id']]
    );
    Database::exec(
        'UPDATE ' . Database::t('sessions') . ' SET status = 3 WHERE user_id = ? AND machine_id = ?',
        [(int) $dv['user_id'], $dv['machine_id']]
    );
    $count++;
}

$machineIds = array_column($devices, 'machine_id');
Audit::log($admin, 'device_ban', '设备批量(拉黑)',
    "拉黑 {$count} 台设备{$desc}，原因：{$reason}",
    [], [], ['machine_ids' => array_slice($machineIds, 0, 200), 'duration' => $duration, 'unit' => $unit]);

Response::ok(['count' => $count], "已拉黑 {$count} 台设备{$desc}");

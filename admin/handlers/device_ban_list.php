<?php
/**
 * admin action: device_ban_list
 * 设备黑名单列表
 */

$page    = max(1, Util::int($input, 'page', 1));
$size    = min(200, max(1, Util::int($input, 'size', 20)));
$keyword = Util::str($input, 'keyword', '');

$where  = ['1=1'];
$params = [];

// 2026-10-06 审计：黑名单按租户范围过滤 —— 仅展示本租户设备（machine_id → devices → users.software_id）的黑名单记录
$swIds = Tenant::softwareScope($admin);
if ($swIds !== null) {
    if (!$swIds) {
        $where[] = '1=0';
    } else {
        $ph = [];
        foreach (array_values($swIds) as $i => $id) {
            $ph[] = ':dSw' . $i;
            $params['dSw' . $i] = $id;
        }
        $where[] = 'EXISTS (SELECT 1 FROM ' . Database::t('devices') . ' d'
            . ' JOIN ' . Database::t('users') . ' u ON u.id = d.user_id'
            . ' WHERE d.machine_id = b.machine_id AND u.software_id IN (' . implode(',', $ph) . '))';
    }
}

if ($keyword !== '') {
    $where[] = '(b.machine_id LIKE :kw OR b.reason LIKE :kw2)';
    $params['kw']  = "%{$keyword}%";
    $params['kw2'] = "%{$keyword}%";
}

$baseSql = 'SELECT b.*, a.username AS admin_name FROM ' . Database::t('device_bans') . ' b
            LEFT JOIN ' . Database::t('admins') . ' a ON a.id = b.admin_id
            WHERE ' . implode(' AND ', $where);

[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`b`.`created_at` DESC');

$now  = time();
$list = array_map(function ($b) use ($now) {
    $expire = (int) $b['expire_at'];
    return [
        'id'         => (int) $b['id'],
        'machine_id' => $b['machine_id'],
        'reason'     => $b['reason'],
        'admin_name' => $b['admin_name'] ?? '-',
        'permanent'  => $expire === 0,
        'expire_at'  => $expire === 0 ? '' : Util::date($expire),
        'active'     => $expire === 0 || $expire > $now,
        'created_at' => Util::date((int) $b['created_at']),
    ];
}, $rows);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'pages' => (int) ceil($total / $size),
    'list'  => $list,
]);

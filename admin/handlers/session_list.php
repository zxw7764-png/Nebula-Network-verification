<?php
/**
 * admin action: session_list
 * 在线会话列表
 */

$timeout = Policy::heartbeatTimeout();
$onlyOnline = Util::get($input, 'online', '1') === '1';

$where  = ['1=1'];
$params = [];

// 2026-10-06 审计：会话列表按租户范围过滤（sessions 无 software_id，经 JOIN 的 u.software_id 推导）
Tenant::applyNamed($where, $params, 'u.software_id');

if ($onlyOnline) {
    $where[] = 's.status = 1 AND s.last_active > :la';
    $params['la'] = time() - $timeout;
}

$baseSql = 'SELECT s.*, u.username FROM ' . Database::t('sessions') . ' s
            LEFT JOIN ' . Database::t('users') . ' u ON u.id = s.user_id
            WHERE ' . implode(' AND ', $where);

$page = max(1, Util::int($input, 'page', 1));
$size = min(200, max(1, Util::int($input, 'size', 30)));

[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`s`.`last_active` DESC');

$list = array_map(function ($s) use ($timeout) {
    return [
        'id'          => (int) $s['id'],
        'user_id'     => (int) $s['user_id'],
        'username'    => $s['username'] ?: '(管理员)',
        'machine_id'  => $s['machine_id'],
        'ip'          => $s['ip'],
        'client_ver'  => $s['client_ver'],
        'status'      => (int) $s['status'],
        'online'      => (int) $s['status'] === 1 && (time() - (int) $s['last_active']) < $timeout,
        'login_at'    => Util::date((int) $s['login_at']),
        'last_active' => Util::date((int) $s['last_active']),
    ];
}, $rows);

Response::ok([
    'total'       => $total,
    'page'        => $page,
    'size'        => $size,
    'pages'       => (int) ceil($total / $size),
    'online_count'=> Session::onlineCount($timeout),
    'list'        => $list,
]);

<?php
/**
 * admin action: user_list
 * 用户列表（分页、搜索、筛选）
 */

$page    = max(1, Util::int($input, 'page', 1));
$size    = min(200, max(1, Util::int($input, 'size', 20)));
$keyword = Util::str($input, 'keyword', '');
$status  = Util::get($input, 'status', '');
$groupId = Util::int($input, 'group_id', 0);
$swId    = Util::int($input, 'sw', 0);   // 0=全部 / -1=通用（未绑定） / >0=指定软件
$sort    = Util::str($input, 'sort', 'id');
$order   = strtolower(Util::str($input, 'order', 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowSort = ['id', 'username', 'vip_expire', 'points', 'created_at', 'last_login_time'];
if (!in_array($sort, $allowSort, true)) {
    $sort = 'id';
}

$where  = ['1=1'];
$params = [];

// 多租户：租户管理员仅见归属软件的用户
Tenant::applyNamed($where, $params);

if ($keyword !== '') {
    // 关键词同时匹配激活卡密快照 —— 卡被删了也能凭卡密找回账号
    $where[] = '(username LIKE :kw OR email LIKE :kw2 OR nickname LIKE :kw3 OR card_code LIKE :kw4)';
    $params['kw']  = "%{$keyword}%";
    $params['kw2'] = "%{$keyword}%";
    $params['kw3'] = "%{$keyword}%";
    $params['kw4'] = "%{$keyword}%";
}
if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
if ($groupId > 0) {
    $where[] = 'group_id = :gid';
    $params['gid'] = $groupId;
}
if ($swId === -1) {
    $where[] = 'software_id = 0';
} elseif ($swId > 0) {
    $where[] = 'software_id = :sw';
    $params['sw'] = $swId;
}

// 软件名映射（软件表很小，一次全取）
$swName = [];
foreach (Software::options() as $s) {
    $swName[(int) $s['id']] = (string) $s['name'];
}

$baseSql = 'SELECT * FROM ' . Database::t('users') . ' WHERE ' . implode(' AND ', $where);

[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, "`$sort` $order");

$now = time();
$list = array_map(function ($u) use ($now, $swName) {
    $expire = (int) $u['vip_expire'];
    $uSwId  = (int) ($u['software_id'] ?? 0);
    $banExp = (int) ($u['ban_expire'] ?? 0);
    return [
        'id'             => (int) $u['id'],
        'username'       => $u['username'],
        'nickname'       => $u['nickname'],
        'email'          => $u['email'],
        'status'         => (int) $u['status'],
        'status_text'    => [0 => '封禁', 1 => '正常', 2 => '冻结'][(int) $u['status']] ?? '未知',
        'ban_expire'     => $banExp,
        'ban_text'       => ((int) $u['status'] === 0 && $banExp > 0) ? '至 ' . Util::date($banExp) : '',
        'group_id'       => (int) $u['group_id'],
        'software_id'    => $uSwId,
        'software_name'  => $uSwId > 0 ? ($swName[$uSwId] ?? ('软件#' . $uSwId)) : '',
        'vip_expire'     => $expire,
        // 会员文案：永久 / 有到期时间 / 点数·次数卡（有余额） / 未激活
        'vip_text'       => $expire === -1 ? '永久'
            : ($expire > 0 ? Util::date($expire)
            : ((int) $u['points'] > 0 ? '点数·次数卡' : '未激活')),
        'vip_valid'      => $expire === -1 || $expire > $now || (int) $u['points'] > 0,
        'points'         => (int) $u['points'],
        'max_devices'    => (int) $u['max_devices'],
        'device_count'   => Device::activeCount((int) $u['id']),
        'last_login_ip'  => $u['last_login_ip'],
        'last_login'     => Util::date((int) $u['last_login_time']),
        'created_at'     => Util::date((int) $u['created_at']),
        'remark'         => $u['remark'],
    ];
}, $rows);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'pages' => (int) ceil($total / $size),
    'list'  => $list,
    'softwares' => Software::options(),
    'groups' => Database::all('SELECT id, name FROM ' . Database::t('groups') . ' ORDER BY id ASC'),
]);

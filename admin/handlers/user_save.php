<?php
/**
 * admin action: user_save
 * 新增 / 编辑用户
 */

$op     = Util::str($input, 'op', 'save');
$userId = Util::int($input, 'user_id', 0);
$now    = time();

// 未指定 op 时，按是否带 user_id 自动判定新增/编辑
if ($op === 'save') {
    $op = $userId > 0 ? 'update' : 'create';
}

// ------------------------------------------------------------------
// 新增
// ------------------------------------------------------------------
if ($op === 'create') {
    $username = Util::str($input, 'username', '');
    $password = (string) Util::get($input, 'password', '');

    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]{3,32}$/u', $username)) {
        Response::error(1001, '用户名需 3-32 位字母、数字、下划线或中文');
    }
    if (($issue = Util::passwordIssue($password)) !== null) {
        Response::error(1001, $issue);
    }
    if (Database::value('SELECT id FROM ' . Database::t('users') . ' WHERE username = ?', [$username])) {
        Response::error(1001, '用户名已存在');
    }
    // 租户隔离：新建用户归属的软件必须在授权范围内
    Tenant::requireTouch($admin, max(0, Util::int($input, 'software_id', 0)));

    $uid = Database::insert('users', [
        'username'    => $username,
        'password'    => Util::hashPassword($password),
        'email'       => Util::str($input, 'email', '') ?: null,
        'nickname'    => Util::str($input, 'nickname', $username),
        'status'      => 1,
        'group_id'    => Util::int($input, 'group_id', 1),
        'software_id' => max(0, Util::int($input, 'software_id', 0)),
        'vip_expire'  => Util::int($input, 'vip_expire', 0),
        'points'      => Util::int($input, 'points', 0),
        'max_devices' => Util::int($input, 'max_devices', (int) Config::get('policy.default_max_devices', 1)),
        'register_ip' => 'admin:' . Util::ip(),
        'remark'      => Util::str($input, 'remark', '') ?: null,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);

    Audit::log($admin, 'user_create', "用户#{$uid} {$username}",
        "新增用户 {$username}",
        [],
        [
            'username'    => $username,
            'email'       => Util::str($input, 'email', ''),
            'nickname'    => Util::str($input, 'nickname', $username),
            'status'      => 1,
            'vip_expire'  => Util::int($input, 'vip_expire', 0),
            'points'      => Util::int($input, 'points', 0),
            'max_devices' => Util::int($input, 'max_devices', 1),
        ]);

    Response::ok(['user_id' => $uid], '用户创建成功');
}

// ------------------------------------------------------------------
// 编辑
// ------------------------------------------------------------------
if ($userId <= 0) {
    Response::error(1001, '缺少 user_id');
}

$user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
if (!$user) {
    Response::error(1001, '用户不存在');
}
// 租户隔离：只能编辑归属软件在自己范围内的用户
Tenant::touchRow($admin, 'users', $user);

$updates = ['updated_at' => $now];

if (array_key_exists('password', $input) && (string) $input['password'] !== '') {
    if (strlen((string) $input['password']) < 6) {
        Response::error(1001, '密码至少 6 位');
    }
    $updates['password'] = Util::hashPassword((string) $input['password']);
}
if (array_key_exists('email', $input))    $updates['email']    = Util::str($input, 'email', '') ?: null;
if (array_key_exists('nickname', $input)) $updates['nickname'] = Util::str($input, 'nickname', '') ?: null;
if (array_key_exists('status', $input)) {
    $newStatus = Util::int($input, 'status', 1);
    $updates['status'] = $newStatus;
    if ($newStatus === 0) {
        if (!array_key_exists('duration', $input) && !array_key_exists('unit', $input) && (int) $user['ban_expire'] > 0) {
            // 未携带时长参数且原本是限时封禁 → 保留原到期时间（编辑其他字段不受影响）
            $updates['ban_expire'] = (int) $user['ban_expire'];
            $banDesc = '';
        } else {
            // 限时封禁：duration + unit（年/月/周/天/小时/分钟/秒），duration<=0 = 永久
            $duration = Util::int($input, 'duration', -1);
            if ($duration < 0) { $duration = Util::int($input, 'days', 0); }   // 兼容旧参数
            [$updates['ban_expire'], $banDesc] = Util::durationExpire($duration, Util::str($input, 'unit', 'day'), $now);
        }
    } else {
        $updates['ban_expire'] = 0;   // 解封/冻结时清空封禁到期
        $banDesc = '';
    }
}
if (array_key_exists('group_id', $input)) $updates['group_id'] = Util::int($input, 'group_id', 1);
if (array_key_exists('software_id', $input)) {
    $updates['software_id'] = max(0, Util::int($input, 'software_id', 0));
    // 租户隔离：改归属也不允许把用户挪到范围外的软件
    Tenant::requireTouch($admin, (int) $updates['software_id']);
}
if (array_key_exists('vip_expire', $input)) $updates['vip_expire'] = Util::int($input, 'vip_expire', 0);
if (array_key_exists('points', $input))   $updates['points']   = Util::int($input, 'points', 0);
if (array_key_exists('max_devices', $input)) $updates['max_devices'] = Util::int($input, 'max_devices', 1);
if (array_key_exists('remark', $input))   $updates['remark']   = Util::str($input, 'remark', '') ?: null;

// 快捷操作
if (array_key_exists('add_days', $input)) {
    $days = Util::int($input, 'add_days', 0);
    $base = (int) $user['vip_expire'];
    if ($base === -1) {
        // 永久不动
    } else {
        $base = max($base, $now);
        $updates['vip_expire'] = $base + $days * 86400;
    }
}
if (array_key_exists('add_points', $input)) {
    $updates['points'] = (int) $user['points'] + Util::int($input, 'add_points', 0);
}

Database::update('users', $updates, 'id = :id', ['id' => $userId]);

// 封禁时踢下线
if (isset($updates['status']) && (int) $updates['status'] !== 1) {
    Session::kickUser($userId);
}

// 审计：对比变更前后
$oldSnap = [
    'username'    => $user['username'],
    'nickname'    => (string) ($user['nickname'] ?? ''),
    'email'       => (string) ($user['email'] ?? ''),
    'status'      => (int) $user['status'],
    'group_id'    => (int) $user['group_id'],
    'software_id' => (int) ($user['software_id'] ?? 0),
    'vip_expire'  => (int) $user['vip_expire'],
    'points'      => (int) $user['points'],
    'max_devices' => (int) $user['max_devices'],
    'remark'      => (string) ($user['remark'] ?? ''),
    'password'    => (string) $user['password'],
];
$newRow = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
$newSnap = [
    'username'    => $newRow['username'],
    'nickname'    => (string) ($newRow['nickname'] ?? ''),
    'email'       => (string) ($newRow['email'] ?? ''),
    'status'      => (int) $newRow['status'],
    'group_id'    => (int) $newRow['group_id'],
    'software_id' => (int) ($newRow['software_id'] ?? 0),
    'vip_expire'  => (int) $newRow['vip_expire'],
    'points'      => (int) $newRow['points'],
    'max_devices' => (int) $newRow['max_devices'],
    'remark'      => (string) ($newRow['remark'] ?? ''),
    'password'    => (string) $newRow['password'],
];

$detail = '编辑用户 ' . $user['username'];
if ((int) ($updates['status'] ?? 1) === 0 && !empty($banDesc)) {
    $detail .= ' ' . $banDesc;
}
Audit::log($admin, 'user_update', "用户#{$userId} {$user['username']}",
    $detail, $oldSnap, $newSnap);

Response::ok(['user_id' => $userId], '保存成功');

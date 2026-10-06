<?php
/**
 * admin action: user_detail
 * 用户详情：基础信息 + 设备 + 卡密使用记录 + 最近日志
 */

$userId = Util::int($input, 'user_id', 0);
if ($userId <= 0) {
    Response::error(1001, '缺少 user_id');
}

$user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
if (!$user) {
    Response::error(1001, '用户不存在');
}

// 2026-10-06 审计：跨租户 IDOR 修复 —— 租户管理员仅可见/可查自己软件下的用户（此接口曾可枚举任意 user_id 拉取完整取证数据）
Tenant::requireTouchUser($admin, $userId);

$group = Auth::group((int) $user['group_id']);
$vip   = Auth::checkVip($user);

// 设备
$devices = array_map(fn($d) => [
    'id'          => (int) $d['id'],
    'machine_id'  => $d['machine_id'],
    'device_name' => $d['device_name'],
    'os_info'     => $d['os_info'],
    'ip'          => $d['ip'],
    'status'      => (int) $d['status'],
    'bind_at'     => Util::date((int) $d['bind_at']),
    'last_seen'   => Util::date((int) $d['last_seen']),
    'online'      => (int) $d['status'] === 1
        && (time() - (int) $d['last_seen']) < Policy::heartbeatTimeout(),
], Device::listByUser($userId));

// 使用的卡密
$cards = Database::all(
    'SELECT code, type, duration, used_at, used_ip FROM ' . Database::t('cards') . '
     WHERE used_by = ? ORDER BY used_at DESC LIMIT 50',
    [$userId]
);

// 卡密可能已被管理端删除 —— 用用户行 card_code 快照 + 独立保留的 card_logs 兜底，
// 保证用户管理的卡密记录不随卡密中心删除而消失
$snapCode = trim((string) ($user['card_code'] ?? ''));
if ($snapCode !== '' && !in_array($snapCode, array_column($cards, 'code'), true)) {
    // 从独立日志里取该用户对此激活码的激活/登录痕迹
    $lg = Database::one(
        'SELECT action, ip, created_at FROM ' . Database::t('card_logs') . '
         WHERE user_id = ? AND code = ? ORDER BY id ASC LIMIT 1',
        [$userId, $snapCode]
    );
    $cards[] = [
        'code'           => $snapCode,
        'type'           => (int) ($user['card_type'] ?? 0) ?: null,
        'duration'       => 0,
        'used_at'        => (int) ($lg['created_at'] ?? 0),
        'used_ip'        => (string) ($lg['ip'] ?? ''),
        '_from_snapshot' => true, // 标记：卡已删除，记录来自快照
    ];
}

foreach ($cards as &$c) {
    $c['code_mask']  = Util::maskCard($c['code']);
    $c['type_text']  = !empty($c['_from_snapshot'])
        ? trim('已删卡·' . ((int) $c['type'] ? Card::typeName((int) $c['type']) : ''))
        : Card::typeName((int) $c['type']);
    $c['used_at_text'] = Util::date((int) $c['used_at']);
}
unset($c);

// 最近日志
$logs = Database::all(
    'SELECT action, result, message, ip, created_at FROM ' . Database::t('logs') . '
     WHERE user_id = ? ORDER BY id DESC LIMIT 30',
    [$userId]
);
foreach ($logs as &$l) {
    $l['time_text'] = Util::date((int) $l['created_at']);
}
unset($l);

// 在线会话
$sessions = Database::all(
    'SELECT ip, machine_id, client_ver, login_at, last_active FROM ' . Database::t('sessions') . '
     WHERE user_id = ? AND status = 1 ORDER BY last_active DESC',
    [$userId]
);
foreach ($sessions as &$s) {
    $s['login_at_text']    = Util::date((int) $s['login_at']);
    $s['last_active_text'] = Util::date((int) $s['last_active']);
    $s['online'] = (time() - (int) $s['last_active']) < Policy::heartbeatTimeout();
}
unset($s);

$uSwId = (int) ($user['software_id'] ?? 0);
$swName = '';
if ($uSwId > 0) {
    $sw = Software::find($uSwId);
    $swName = $sw ? (string) $sw['name'] : ('软件#' . $uSwId);
}

Response::ok([
    'user' => [
        'id'           => (int) $user['id'],
        'username'     => $user['username'],
        'nickname'     => $user['nickname'],
        'email'        => $user['email'],
        'status'       => (int) $user['status'],
        'group_id'     => (int) $user['group_id'],
        'group_name'   => $group['name'] ?? '-',
        'software_id'   => $uSwId,
        'software_name' => $swName,
        'vip_expire'   => (int) $user['vip_expire'],
        'vip_text'     => (int) $user['vip_expire'] === -1 ? '永久' : Util::date((int) $user['vip_expire']),
        'points'       => (int) $user['points'],
        'max_devices'  => (int) $user['max_devices'],
        'register_ip'  => $user['register_ip'],
        'last_login_ip'=> $user['last_login_ip'],
        'last_login'   => Util::date((int) $user['last_login_time']),
        'created_at'   => Util::date((int) $user['created_at']),
        'remark'       => $user['remark'],
        'vip'          => $vip,
    ],
    'devices'  => $devices,
    'cards'    => $cards,
    'logs'     => $logs,
    'sessions' => $sessions,
    'softwares' => Software::options(),
]);

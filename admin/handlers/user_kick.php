<?php
/**
 * admin action: user_kick
 * 强制下线 / 重置密码 / 清空设备
 */

$op     = Util::str($input, 'op', 'kick');
$userId = Util::int($input, 'user_id', 0);

if ($userId <= 0) {
    Response::error(1001, '缺少 user_id');
}

$user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
if (!$user) {
    Response::error(1001, '用户不存在');
}

// 2026-10-06 审计：跨租户操作修复 —— 租户管理员不可重置/踢除其他软件下的用户
Tenant::requireTouchUser($admin, $userId);

switch ($op) {
    case 'kick':
        $n = Session::kickUser($userId);
        Logger::log('admin_user_kick', 1, "强制下线 {$user['username']}，{$n} 个会话", ['username' => $admin['username']]);
        Response::ok(['count' => $n], "已强制下线 {$n} 个会话");
        break;

    case 'reset_password':
        $new = Util::str($input, 'password', '');
        if (strlen($new) < 6) {
            $new = Util::random(10);
        }
        Database::update('users', [
            'password'        => Util::hashPassword($new),
            'login_fail_cnt'  => 0,
            'lock_until'      => 0,
            'updated_at'      => time(),
        ], 'id = :id', ['id' => $userId]);
        Session::kickUser($userId);
        Logger::log('admin_user_reset_pwd', 1, "重置密码 {$user['username']}", ['username' => $admin['username']]);
        Response::ok(['password' => $new], '密码已重置，请通知用户');
        break;

    case 'unlock':
        Database::update('users', [
            'login_fail_cnt' => 0,
            'lock_until'     => 0,
            'updated_at'     => time(),
        ], 'id = :id', ['id' => $userId]);
        Logger::log('admin_user_unlock', 1, "解锁账号 {$user['username']}", ['username' => $admin['username']]);
        Response::ok(null, '账号已解锁');
        break;

    case 'clear_devices':
        $n = Device::unbindAll($userId, '管理员清空设备');
        Session::kickUser($userId);
        Logger::log('admin_user_clear_dev', 1, "清空设备 {$user['username']}，{$n} 台", ['username' => $admin['username']]);
        Response::ok(['count' => $n], "已清空 {$n} 台设备");
        break;

    default:
        Response::error(1001, '未知操作');
}

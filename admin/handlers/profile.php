<?php
/**
 * admin action: profile
 * 获取/修改当前管理员信息 + 登录历史
 */

$op = Util::str($input, 'op', 'get');

if ($op === 'get') {
    Response::ok(AdminAuth::publicInfo($admin));
}

if ($op === 'change_password') {
    $old = (string) Util::get($input, 'old_password', '');
    $new = (string) Util::get($input, 'new_password', '');

    // 强度校验（管理员严格档：≥10 位、字母+数字、需含大写或符号、拒绝弱口令）
    if (($pwIssue = Util::passwordIssue($new, true)) !== null) {
        Response::error(1001, $pwIssue);
    }

    $r = AdminAuth::changePassword((int) $admin['id'], $old, $new);
    Audit::log($admin, 'password_change', "管理员#{$admin['id']} {$admin['username']}",
        $r['ok'] ? '修改密码成功' : '修改密码失败：' . $r['msg']);
    if (!$r['ok']) {
        Response::error(1001, $r['msg']);
    }
    Response::ok(null, $r['msg']);
}

if ($op === 'update') {
    $nickname = mb_substr(Util::str($input, 'nickname', ''), 0, 64);
    $oldNick  = (string) ($admin['nickname'] ?? '');
    Database::update('admins', ['nickname' => $nickname], 'id = :id', ['id' => $admin['id']]);
    Audit::log($admin, 'profile_update', "管理员#{$admin['id']}",
        '修改昵称', ['nickname' => $oldNick], ['nickname' => $nickname]);
    Response::ok(null, '资料已更新');
}

if ($op === 'login_history') {
    $page = max(1, Util::int($input, 'page', 1));
    $size = min(50, max(1, Util::int($input, 'size', 10)));
    $adminId = (int) $admin['id'];

    $total = (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('logs')
        . ' WHERE admin_id = ? AND action LIKE ?',
        [$adminId, 'admin_login%']
    );

    $offset = ($page - 1) * $size;
    $rows = Database::all(
        'SELECT action, message, result, ip, created_at'
        . ' FROM ' . Database::t('logs')
        . ' WHERE admin_id = ? AND action LIKE ?'
        . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset,
        [$adminId, 'admin_login%']
    );
    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'time_text' => Util::date((int) $r['created_at']),
            'ip'        => $r['ip'] ?? '',
            'result'    => (int) $r['result'],
            'message'   => $r['message'] ?? '',
        ];
    }
    Response::ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
}

// ------------------------------------------------------------------
// 二次验证（TOTP）—— 绑定状态查询 / 生成密钥 / 启用 / 关闭
// 权限点：profile 已登记为 null（任何已登录管理员都能改自己的账号安全）
// ------------------------------------------------------------------
if ($op === 'totp_status') {
    Response::ok(AdminAuth::totpStatus((int) $admin['id']));
}

if ($op === 'totp_init') {
    $r = AdminAuth::totpInit((int) $admin['id']);
    if (!$r['ok']) {
        Response::error(1001, $r['msg']);
    }
    Audit::log($admin, 'totp_init', "管理员#{$admin['id']} {$admin['username']}", '生成二次验证密钥（待绑定）');
    Response::ok($r, $r['msg']);
}

if ($op === 'totp_enable') {
    $code = (string) Util::get($input, 'code', '');
    $r = AdminAuth::totpEnable((int) $admin['id'], $code);
    Audit::log($admin, 'totp_enable', "管理员#{$admin['id']} {$admin['username']}",
        $r['ok'] ? '开启二次验证' : '开启二次验证失败：' . $r['msg']);
    if (!$r['ok']) {
        Response::error(1001, $r['msg']);
    }
    Response::ok($r, $r['msg']);
}

if ($op === 'totp_disable') {
    $pass = (string) Util::get($input, 'password', '');
    $r = AdminAuth::totpDisable((int) $admin['id'], $pass);
    Audit::log($admin, 'totp_disable', "管理员#{$admin['id']} {$admin['username']}",
        $r['ok'] ? '关闭二次验证' : '关闭二次验证失败：' . $r['msg']);
    if (!$r['ok']) {
        Response::error(1001, $r['msg']);
    }
    Response::ok(null, $r['msg']);
}

Response::error(1001, '未知操作');

<?php
/**
 * admin action: admin_save
 * 管理员账号管理（仅超级管理员：admin.manage 为超管专属硬权限，
 * 即使写入自定义权限清单也不生效，见 AdminPermission::allows）
 *
 * 参数：
 *   op          save（默认，新增/编辑）| toggle | reset_password | reset_totp | delete
 *   id          账号 ID，save 时 0=新增
 *   username    登录名（3-32 位字母数字下划线，新增必填）
 *   password    密码（新增必填；编辑传空=不改）
 *   nickname    显示名
 *   role        2 操作员 | 3 只读（界面不可创建/修改超级管理员）
 *   status      1 启用 0 停用
 *   permissions 自定义权限点数组（JSON），null=清除自定义（回退角色默认矩阵）
 *   new_password reset_password 用
 *
 * 防自锁：
 *   · 不能停用 / 删除自己；
 *   · 不能停用 / 删除 / 降权任何超级管理员账号（超管账号只能由本人或 DB 维护）。
 */

$table  = Database::t('admins');
$sessT  = Database::t('admin_sessions');
$op     = Util::str($input, 'op', 'save');
$selfId = (int) ($admin['id'] ?? 0);

/** 取目标账号；超管账号除昵称外一律不可被他人操作 */
function nb_admin_target(string $table, int $id): array
{
    $row = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$row) {
        Response::error(1004, '管理员账号不存在');
    }
    return $row;
}

/** 会话白名单校验：仅保留权限点目录中的合法项 */
function nb_clean_perms($raw): ?string
{
    if ($raw === null) {
        return null;                       // 清除自定义 → 回退角色默认矩阵
    }
    $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($arr)) {
        Response::error(1001, 'permissions 必须是权限点数组');
    }
    $allowed = AdminPermission::catalogPermissions();   // 不含 admin.manage（超管专属）
    $clean = array_values(array_intersect($arr, $allowed));
    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------
// 停用 / 启用
// ---------------------------------------------------------------
if ($op === 'toggle') {
    $id = Util::int($input, 'id', 0);
    $t  = nb_admin_target($table, $id);
    if ((int) $t['role'] === 1) {
        Response::error(1004, '超级管理员账号不可停用');
    }
    if ($id === $selfId) {
        Response::error(1004, '不能停用当前登录的账号');
    }
    $new = (int) $t['status'] === 1 ? 0 : 1;
    Database::exec("UPDATE {$table} SET status = ? WHERE id = ?", [$new, $id]);
    if ($new === 0) {
        Database::exec("UPDATE {$sessT} SET status = 0 WHERE admin_id = ?", [$id]);   // 踢下线
    }
    Audit::log($admin, 'admin_toggle', "管理员#{$id} {$t['username']}", $new ? '启用管理员' : '停用管理员',
        ['status' => (int) $t['status']], ['status' => $new]);
    Response::ok(['id' => $id, 'status' => $new], $new === 1 ? '账号已启用' : '账号已停用');
}

// ---------------------------------------------------------------
// 重置密码（超管找回/交接场景）
// ---------------------------------------------------------------
if ($op === 'reset_password') {
    $id  = Util::int($input, 'id', 0);
    $t   = nb_admin_target($table, $id);
    if ((int) $t['role'] === 1) {
        Response::error(1004, '超级管理员密码请使用「修改密码」自助更换');
    }
    $newPass = (string) Util::get($input, 'new_password', '');
    if (($issue = Util::passwordIssue($newPass, true)) !== null) {
        Response::error(1001, $issue);
    }
    Database::exec("UPDATE {$table} SET password = ? WHERE id = ?",
        [Util::hashPassword($newPass), $id]);
    Database::exec("UPDATE {$sessT} SET status = 0 WHERE admin_id = ?", [$id]);       // 改密踢全部会话
    Audit::log($admin, 'admin_reset_password', "管理员#{$id} {$t['username']}", '重置管理员密码',
        [], []);
    Response::ok(['id' => $id], '密码已重置，该账号需重新登录');
}

// ---------------------------------------------------------------
// 重置 2FA（管理员丢失验证器时的恢复手段；清空后账号可在个人中心重新绑定）
// ---------------------------------------------------------------
if ($op === 'reset_totp') {
    $id = Util::int($input, 'id', 0);
    $t  = nb_admin_target($table, $id);
    try {
        Database::exec(
            "UPDATE {$table} SET totp_enabled = 0, totp_secret = NULL, totp_recovery = NULL WHERE id = ?",
            [$id]
        );
    } catch (Throwable $e) {
        Response::error(9999, '重置失败，请确认已执行 install/migrate_admin_totp.php');
    }
    Audit::log($admin, 'admin_reset_totp', "管理员#{$id} {$t['username']}", '重置管理员二次验证',
        ['totp' => (int) ($t['totp_enabled'] ?? 0) === 1 ? 'enabled' : 'none'], ['totp' => 'cleared']);
    Response::ok(['id' => $id], '二次验证已重置，该账号可重新绑定');
}

// ---------------------------------------------------------------
// 删除
// ---------------------------------------------------------------
if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    $t  = nb_admin_target($table, $id);
    if ((int) $t['role'] === 1) {
        Response::error(1004, '超级管理员账号不可删除');
    }
    if ($id === $selfId) {
        Response::error(1004, '不能删除当前登录的账号');
    }
    Database::exec("DELETE FROM {$table} WHERE id = ?", [$id]);
    Database::exec("DELETE FROM {$sessT} WHERE admin_id = ?", [$id]);
    Audit::log($admin, 'admin_delete', "管理员#{$id} {$t['username']}", '删除管理员账号',
        ['username' => $t['username'], 'role' => (int) $t['role']], []);
    Response::ok(['id' => $id], '账号已删除');
}

// ---------------------------------------------------------------
// 新增 / 编辑
// ---------------------------------------------------------------
$id       = Util::int($input, 'id', 0);
$username = trim(Util::str($input, 'username', ''));
$nickname = mb_substr(trim(Util::str($input, 'nickname', '')), 0, 64);
$role     = Util::int($input, 'role', 2);
$status   = Util::int($input, 'status', 1) === 1 ? 1 : 0;
$password = (string) Util::get($input, 'password', '');

// 界面永远不可创建 / 设置超级管理员（防多超管互锁，超管只在 DB 层维护）
if ($role !== 2 && $role !== 3) {
    Response::error(1001, '角色只能选择操作员或只读');
}
// permissions：key 存在即提交了自定义清单；显式 null 字段表示清除
$permsRaw = array_key_exists('permissions', $input) ? Util::get($input, 'permissions', null) : false;
$permsCol = $permsRaw === false ? false : nb_clean_perms($permsRaw);   // false=本次不动 | string|null=写入值

if ($id > 0) {
    // ── 编辑 ──
    $t = nb_admin_target($table, $id);

    if ((int) $t['role'] === 1) {
        // 超管账号：仅允许改自己的显示名（其他维度本人自助或 DB 维护）
        if ($id !== $selfId) {
            Response::error(1004, '超级管理员账号仅可由本人修改资料');
        }
        Database::exec("UPDATE {$table} SET nickname = ? WHERE id = ?", [$nickname, $id]);
        Audit::log($admin, 'admin_save', "管理员#{$id} {$t['username']}", '修改昵称',
            ['nickname' => (string) $t['nickname']], ['nickname' => $nickname]);
        Response::ok(['id' => $id], '已保存');
    }

    if ($password !== '' && ($issue = Util::passwordIssue($password, true)) !== null) {
        Response::error(1001, $issue);
    }

    $data = [
        'nickname' => $nickname,
        'role'     => $role,
        'status'   => $status,
    ];
    if ($permsCol !== false) {
        $data['permissions'] = $permsCol;          // string（JSON）或 null（清除）
    }
    Database::update('admins', $data, 'id = :id', ['id' => $id]);

    // 权限实时生效（AdminAuth::check 每次请求重读账号行），无需踢会话；
    // 停用则主动踢下线，改密必须踢下线（旧会话作废）
    if ($status === 0) {
        Database::exec("UPDATE {$sessT} SET status = 0 WHERE admin_id = ?", [$id]);
    }

    Audit::log($admin, 'admin_save', "管理员#{$id} {$t['username']}", '编辑管理员',
        Util::pick($t, ['nickname', 'role', 'status', 'permissions']),
        Util::pick($data, ['nickname', 'role', 'status', 'permissions']));

    // 改密一并踢下线
    if ($password !== '') {
        Database::exec("UPDATE {$table} SET password = ? WHERE id = ?",
            [Util::hashPassword($password), $id]);
        Database::exec("UPDATE {$sessT} SET status = 0 WHERE admin_id = ?", [$id]);
        Audit::log($admin, 'admin_reset_password', "管理员#{$id} {$t['username']}", '重置管理员密码（编辑页）',
            [], []);
    }

    Response::ok(['id' => $id], '管理员已更新');
}

// ── 新增 ──
if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
    Response::error(1001, '登录名需为 3-32 位字母、数字或下划线');
}
if (Database::value("SELECT id FROM {$table} WHERE username = ?", [$username])) {
    Response::error(1001, '登录名已存在');
}
if (($issue = Util::passwordIssue($password)) !== null) {
    Response::error(1001, $issue);
}

$data = [
    'username'    => $username,
    'password'    => Util::hashPassword($password),
    'nickname'    => $nickname !== '' ? $nickname : $username,
    'role'        => $role,
    'permissions' => $permsCol === false ? null : $permsCol,
    'status'      => $status,
    'created_at'  => time(),
];
$newId = Database::insert('admins', $data);

Audit::log($admin, 'admin_save', "管理员#{$newId} {$username}", '新增管理员账号',
    [], ['username' => $username, 'role' => $role, 'status' => $status]);

Response::ok(['id' => $newId], '管理员已创建');

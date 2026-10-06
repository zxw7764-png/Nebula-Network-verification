<?php
/**
 * admin action: agent_save
 * 代理商新增 / 编辑 / 删除 / 启停 / 按类型调整额度 / 重置密码
 * ------------------------------------------------------------------
 * 额度与单价按卡类型配置（nb_agent_types）：
 *   · 编辑表单提交 types = {"1":{"enabled":1,"quota_total":100,"price_yuan":"1.00"}, ...}
 *   · 充值 op=grant 提交 types = {"1":10,"4":-2}（该类型额度增量） + add_balance_yuan
 * 金额字段一律以「元」从前端提交，服务端转成分存储（避免浮点误差累积）。
 * 参数: op = create|update|delete|toggle|grant|reset_password（默认 create/update 由 id 判定）
 */

$op = Util::str($input, 'op', '');

/** 元 -> 分（四舍五入，负数保留符号） */
$yuan2fen = static function ($v): int {
    $n = (float) $v;
    return (int) round($n * 100);
};

/** 读取「按卡类型」的绝对配置（编辑表单） */
$readTypes = static function () use ($input): array {
    $types = Util::get($input, 'types', []);
    return is_array($types) ? $types : [];
};

/** 读取「按卡类型」的增量（充值表单）：{"1":10,"4":-2} 或 {"1":{"add":10}} */
$readTypeAdds = static function () use ($input): array {
    $raw = Util::get($input, 'types', []);
    if (!is_array($raw)) {
        return [];
    }
    $adds = [];
    foreach ($raw as $t => $v) {
        $add = is_array($v) ? (int) ($v['add'] ?? $v['add_quota'] ?? 0) : (int) $v;
        if ($add !== 0) {
            $adds[(string) $t] = $add;
        }
    }
    return $adds;
};

// ------------------------------------------------------------------
// 删除（有卡密记录时禁止删除，避免卡密归属变成野指针）
// ------------------------------------------------------------------
if ($op === 'delete') {
    $id    = Util::int($input, 'id', 0);
    $agent = Agent::find($id);
    if (!$agent) {
        Response::error(1001, '代理商不存在');
    }
    // 2026-10-06 审计：租户管理员仅可删除自己软件下的代理
    Tenant::touchRow($admin, 'agents', $agent, 'software_id');

    $cardCount = (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('cards') . ' WHERE agent_id = ?',
        [$id]
    );
    if ($cardCount > 0) {
        Response::error(1001, '该代理商名下已有 ' . $cardCount . ' 张卡密，无法删除；请改为「禁用」以保留归属记录');
    }

    // 连带清理该代理独占的数据行，避免留下孤儿记录
    // （卡密不允许删，已在上面拦截；此处清理会话 / 类型配置 / 操作日志）
    Database::exec('DELETE FROM ' . Database::t('agent_sessions') . ' WHERE agent_id = ?', [$id]);
    Database::exec('DELETE FROM ' . Database::t('agent_types') . ' WHERE agent_id = ?', [$id]);
    Database::exec('DELETE FROM ' . Database::t('agent_logs') . ' WHERE agent_id = ?', [$id]);
    Database::exec('DELETE FROM ' . Database::t('agents') . ' WHERE id = ?', [$id]);

    Audit::log($admin, 'agent_delete', '代理商#' . $id . ' ' . $agent['username'], '删除代理商');
    Response::ok(['id' => $id], '已删除');
}

// ------------------------------------------------------------------
// 启停
// ------------------------------------------------------------------
if ($op === 'toggle') {
    $id    = Util::int($input, 'id', 0);
    $agent = Agent::find($id);
    if (!$agent) {
        Response::error(1001, '代理商不存在');
    }
    // 2026-10-06 审计：租户管理员仅可启停自己软件下的代理
    Tenant::touchRow($admin, 'agents', $agent, 'software_id');
    $new = (int) $agent['status'] === Agent::STATUS_ON ? Agent::STATUS_OFF : Agent::STATUS_ON;

    Database::update('agents', ['status' => $new, 'updated_at' => time()], 'id = :id', ['id' => $id]);
    if ($new === Agent::STATUS_OFF) {
        // 禁用即踢下线，避免已登录会话继续发货
        Database::exec('UPDATE ' . Database::t('agent_sessions') . ' SET status = 0 WHERE agent_id = ?', [$id]);
    }

    Audit::log($admin, 'agent_update', '代理商#' . $id . ' ' . $agent['username'],
        '状态：' . Agent::statusName((int) $agent['status']) . ' → ' . Agent::statusName($new),
        ['status' => (int) $agent['status']], ['status' => $new]);

    Response::ok(['id' => $id, 'status' => $new], $new === Agent::STATUS_ON ? '已启用' : '已禁用');
}

// ------------------------------------------------------------------
// 额度 / 余额调整（按卡类型增量）
// ------------------------------------------------------------------
if ($op === 'grant') {
    $id      = Util::int($input, 'id', 0);
    $addYuan = (float) Util::get($input, 'add_balance_yuan', 0);
    $remark  = mb_substr(Util::str($input, 'remark', ''), 0, 100);
    $adds    = $readTypeAdds();

    $agent = Agent::find($id);
    if (!$agent) {
        Response::error(1001, '代理商不存在');
    }
    // 2026-10-06 审计：额度/余额调整涉及资金与授权资产，租户管理员仅可操作自己软件下的代理
    Tenant::touchRow($admin, 'agents', $agent, 'software_id');
    if (!$adds && $addYuan === 0.0) {
        Response::error(1001, '请填写要调整的张数或金额');
    }

    $r = Agent::grant($id, $adds, $yuan2fen($addYuan), (string) $admin['username'], $remark);
    if (!$r['ok']) {
        Response::error(1001, $r['msg']);
    }

    $fresh = Agent::find($id);
    Audit::log($admin, 'agent_grant', '代理商#' . $id . ' ' . $agent['username'],
        '调整额度：' . ($adds ? json_encode($adds, JSON_UNESCAPED_UNICODE) : '')
        . '，余额 ' . ($addYuan > 0 ? '+' : '') . number_format($addYuan, 2, '.', '') . ' 元',
        [], [], ['types' => $adds, 'add_balance_yuan' => $addYuan, 'remark' => $remark]);

    Response::ok(['agent' => Agent::publicInfo($fresh)], '调整成功');
}

// ------------------------------------------------------------------
// 重置密码
// ------------------------------------------------------------------
if ($op === 'reset_password') {
    $id   = Util::int($input, 'id', 0);
    $pass = (string) Util::get($input, 'password', '');
    if (strlen($pass) < 8) {
        Response::error(1001, '新密码至少 8 位');
    }
    $agent = Agent::find($id);
    if (!$agent) {
        Response::error(1001, '代理商不存在');
    }
    // 2026-10-06 审计：租户管理员仅可重置自己软件下代理的密码
    Tenant::touchRow($admin, 'agents', $agent, 'software_id');

    Database::update('agents', [
        'password'   => Util::hashPassword($pass),
        'updated_at' => time(),
    ], 'id = :id', ['id' => $id]);
    // 改密后该代理所有会话失效
    Database::exec('UPDATE ' . Database::t('agent_sessions') . ' SET status = 0 WHERE agent_id = ?', [$id]);

    Agent::log($id, 'password', '管理员重置登录密码');
    Audit::log($admin, 'agent_update', '代理商#' . $id . ' ' . $agent['username'], '重置登录密码');

    Response::ok(['id' => $id], '密码已重置');
}

// ------------------------------------------------------------------
// 新增 / 编辑
// ------------------------------------------------------------------
$id       = Util::int($input, 'id', 0);
$username = preg_replace('/[^A-Za-z0-9_-]/', '', Util::str($input, 'username', ''));
$nickname = mb_substr(Util::str($input, 'nickname', ''), 0, 64);
$contact  = mb_substr(Util::str($input, 'contact', ''), 0, 120);
$mode     = Util::int($input, 'charge_mode', Agent::MODE_QUOTA);
$maxDev   = max(1, min(99, Util::int($input, 'max_devices', 1)));
$groupId  = Util::int($input, 'group_id', 0);
$canVoid  = Util::int($input, 'can_void', 1) === 1 ? 1 : 0;
$status   = Util::int($input, 'status', Agent::STATUS_ON) === Agent::STATUS_ON ? Agent::STATUS_ON : Agent::STATUS_OFF;
$remark   = mb_substr(Util::str($input, 'remark', ''), 0, 250);
$types    = $readTypes();
if (strlen($username) < 3 || strlen($username) > 32) {
    Response::error(1001, '代理账号需 3-32 位，仅限字母、数字、下划线、短横线');
}
if (!array_key_exists((string) $mode, Agent::allModes())) {
    Response::error(1001, '控量模式不正确');
}
if ($groupId > 0 && !Database::value('SELECT id FROM ' . Database::t('groups') . ' WHERE id = ?', [$groupId])) {
    Response::error(1001, '所选用户组不存在');
}

$old = $id > 0 ? Agent::find($id) : null;
if ($id > 0 && !$old) {
    Response::error(1001, '代理商不存在');
}
// 2026-10-06 审计：租户管理员仅可编辑自己软件下的代理
if ($old) {
    Tenant::touchRow($admin, 'agents', $old, 'software_id');
}

// 账号唯一性
$dup = Database::one(
    'SELECT id FROM ' . Database::t('agents') . ' WHERE username = ? AND id <> ?',
    [$username, $id]
);
if ($dup) {
    Response::error(1001, '代理账号已存在');
}

$fields = [
    'username'    => $username,
    'nickname'    => $nickname ?: null,
    'contact'     => $contact ?: null,
    'charge_mode' => $mode,
    'balance'     => max(0, $yuan2fen((float) Util::get($input, 'balance_yuan', 0))),
    'max_devices' => $maxDev,
    'group_id'    => $groupId,
    'can_void'    => $canVoid,
    'status'      => $status,
    'remark'      => $remark ?: null,
    'updated_at'  => time(),
];

// 代理生成卡密的固定前缀（空 = 不限制，代理可自填）。
// 防御：旧版前端（浏览器缓存）不会提交该字段，此时保持原值不动，避免误清空。
if (array_key_exists('card_prefix', $input)) {
    $cp = AgentCode::normalizeCardPrefix(Util::str($input, 'card_prefix', ''));
    $fields['card_prefix'] = $cp !== '' ? $cp : null;
}

// ------------------------------------------------------------------
// 编辑
// ------------------------------------------------------------------
if ($id > 0) {
    Database::update('agents', $fields, 'id = :id', ['id' => $id]);
    // 按卡类型的额度/单价（绝对配置）；未提交则保持原状
    if ($types) {
        Agent::saveTypes($id, $types);
    } else {
        Agent::ensureRows($id);
    }

    $fresh = Agent::find($id);
    Audit::log($admin, 'agent_update', '代理商#' . $id . ' ' . $username, '编辑代理商资料', $old, $fresh);

    Response::ok(['id' => $id, 'agent' => Agent::publicInfo($fresh)], '保存成功');
}

// ------------------------------------------------------------------
// 新增：必须给初始密码
// ------------------------------------------------------------------
$pass = (string) Util::get($input, 'password', '');
if (strlen($pass) < 8) {
    Response::error(1001, '初始密码至少 8 位');
}

// 软件归属（仅新增时可指定；创建后不可更换，避免名下卡密/额度跨软件错位）
$swId = Util::int($input, 'software_id', 0);
if ($swId > 0) {
    $sw = Database::one(
        'SELECT id FROM ' . Database::t('softwares') . ' WHERE id = ? AND status = 1',
        [$swId]
    );
    if (!$sw) {
        Response::error(1001, '所选软件不存在或已停用');
    }
    // 2026-10-06 审计：租户管理员仅可为自己的软件创建代理
    Tenant::requireTouch($admin, $swId);
    $fields['software_id'] = $swId;
} elseif (Tenant::isTenant($admin)) {
    // 未指定时默认软件 1：租户管理员不盲降落到平台默认软件
    Response::error(4031, '租户管理员创建代理商必须指定归属软件');
}

$fields['password']   = Util::hashPassword($pass);
$fields['created_at'] = time();

$newId = Database::insert('agents', $fields);

// 新建时若未提交按类型的配置，则先把 4 种类型都建出来（默认不开放），由管理员随后配置
if ($types) {
    Agent::saveTypes($newId, $types);
} else {
    Agent::ensureRows($newId);
}
Agent::log($newId, 'grant', '账号创建（后台创建），发货规格由管理员配置');

Audit::log($admin, 'agent_create', '代理商#' . $newId . ' ' . $username, '新增代理商',
    [], $fields, ['charge_mode' => $mode, 'types' => $types]);

Response::ok([
    'id'    => $newId,
    'agent' => Agent::publicInfo(Agent::find($newId)),
], '代理商已创建');

<?php
/**
 * Admin handler: rt_session_unblock
 * 解除运行时会话阻断（§7 §55 审计）
 * 将 BLOCKED/RISK 状态恢复为 CLEAN，需审计记录
 */

AdminPermission::require($admin, AdminPermission::RT_SESSION_BLOCK);

// 注意：参数名绝不能用 token —— SessionCookie::fromRequest() 的取令牌优先级
// 是 X-Token 头 > body.token > Cookie，body 里叫 token 的业务参数会被入口
// 当成管理员令牌去验证，必然 1003 并清掉管理端 Cookie（管理员被登出）。
$token = (string) ($requestData['session_token'] ?? '');
if ($token === '') {
    Response::error(1001, '缺少会话令牌');
}

// 获取当前状态用于审计
$session = Database::one(
    'SELECT id, token, user_id, rt_status, rt_risk_score, rt_risk_level FROM ' . Database::t('sessions') . ' WHERE token = ?',
    [$token]
);
if (!$session) {
    // 注意：业务错误不能用 1002/1003 —— 前端把这两个码当作「登录过期」会强制登出
    Response::error(1001, '会话不存在或已销毁');
}

// §7: 误报可由后台恢复为 CLEAN
$ok = Database::update('sessions', [
    'rt_status'       => 'CLEAN',
    'rt_risk_score'   => 0,
    'rt_risk_level'   => 'LOW',
    // §21: sticky 事件不可自动降低到 CLEAN，但管理员手动解除是审计操作，允许
], 'token = :tok', ['tok' => $token]);

// 同时恢复 3.1 会话层（MySQL 不支持 IN (SELECT ... LIMIT n)，LIMIT 必须去掉；
// 按 session_id 关联通常只有一条 hsessions 记录，全量更新无副作用。
// 注意 Database::update 内部全用命名参数，这里必须用 :name 而非 ? 占位）
Database::update('hsessions', [
    'rt_status'       => 'CLEAN',
    'rt_risk_score'   => 0,
    'rt_risk_level'   => 'LOW',
], 'sid IN (SELECT hsid FROM ' . Database::t('security_events') . ' WHERE session_id = :sessid)',
   ['sessid' => $token]);

// §55: 审计日志
Audit::log($admin, 'rt_session_unblock',
    "会话#{$session['id']}",
    "解除运行时阻断: {$session['rt_status']} → CLEAN (用户#{$session['user_id']})",
    ['rt_status' => $session['rt_status'], 'rt_risk_score' => $session['rt_risk_score']],
    ['rt_status' => 'CLEAN', 'rt_risk_score' => 0],
    ['token' => substr($token, 0, 16) . '...', 'user_id' => $session['user_id']]
);

Response::ok(['ok' => $ok > 0]);

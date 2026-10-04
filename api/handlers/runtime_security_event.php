<?php
/**
 * action: runtime_security_event
 * ------------------------------------------------------------------
 * 客户端 RuntimeGuard 上报安全事件（§15 §16 §73 §74 §75）
 *
 * 请求：需 3.1 会话信封 + 有效 token（业务会话）
 * 逻辑：
 *   1. 验证业务会话（token + machine_id）
 *   2. 读取事件数据（已由 Crypto::parseRequest 解密）
 *   3. 调用 RuntimeEventService::handle 处理
 *   4. 返回 accepted + runtime 状态
 *
 * §73: 处理流程 = decrypt → validate → authenticate → validate seq
 *                → validate event → dedup → risk → persist → action → response
 * §74: 返回 accepted + runtime { status, risk_level, risk_score, action }
 * §96: Critical 事件同步 Block Session
 */

$token     = Util::str($requestData, 'token', '');
$machineId = Util::str($requestData, 'machine_id', '');

// 会话验证
$v = Session::validate($token, $machineId);
if (!$v['ok']) {
    Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
}

$session = $v['session'];

// §68: 检查 Session Runtime 状态 — BLOCKED 直接拒绝
$rtStatus = (string) ($session['rt_status'] ?? 'UNKNOWN');
if ($rtStatus === 'BLOCKED') {
    Response::send(7001, 'Runtime security verification failed.', [
        'runtime' => [
            'status'      => 'BLOCKED',
            'risk_level'  => $session['rt_risk_level'] ?? 'CRITICAL',
            'risk_score'  => (int) ($session['rt_risk_score'] ?? 100),
            'action'      => 'REVOKE_SESSION',
        ],
        'need_relogin' => true,
    ]);
}

// 提取事件数据
$eventData = $requestData['event'] ?? [];
if (!is_array($eventData) || empty($eventData)) {
    // 也允许直接在顶层传事件字段
    $eventData = $requestData;
}

// 获取 3.1 会话信息
$hsid = Handshake::active() ? Handshake::sessionId() : '';
$seq  = (int) ($parsed['seq'] ?? 0);

// 补充会话关联信息到事件上下文
$session['device_id'] = 0;
if ($machineId !== '') {
    $devRow = Database::one(
        'SELECT id FROM ' . Database::t('devices') . ' WHERE user_id = ? AND machine_id = ? AND status = 1 LIMIT 1',
        [(int) $session['user_id'], $machineId]
    );
    if ($devRow) {
        $session['device_id'] = (int) $devRow['id'];
    }
}

// 处理事件
$result = RuntimeEventService::handle($eventData, $session, $hsid, $seq);

if (!$result['accepted']) {
    $code = $result['error']['code'] ?? 'RUNTIME_EVENT_REJECTED';
    $msg  = $result['error']['msg'] ?? '事件被拒绝';
    // §80: 错误码映射
    $errCodeMap = [
        'RUNTIME_EVENT_REJECTED'      => 7003,
        'RUNTIME_EVENT_RATE_LIMITED'  => 7004,
    ];
    Response::send(
        $errCodeMap[$code] ?? 7003,
        $msg,
        ['runtime' => $result['runtime']]
    );
}

// §74: 返回 accepted + runtime 状态
Response::ok([
    'accepted' => true,
    'deduped'  => $result['deduped'] ?? false,
    'runtime'  => $result['runtime'],
], 'ok');

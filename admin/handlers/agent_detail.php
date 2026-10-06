<?php
/**
 * admin action: agent_detail
 * 代理商详情：档案 + 发货统计 + 最近卡密 + 最近批次 + 操作日志
 * 参数: id
 */

$id = Util::int($input, 'id', 0);
$agent = Agent::find($id);
if (!$agent) {
    Response::error(1001, '代理商不存在');
}

// 2026-10-06 审计：跨租户读取修复 —— 租户管理员仅可查看自己软件下的代理及其卡密/批次资产
Tenant::touchRow($admin, 'agents', $agent, 'software_id');

// 最近 30 张卡密
$cards = [];
foreach (Database::all(
    'SELECT id, code, type, duration, max_devices, group_id, status, used_by, used_at, expire_at, created_at
     FROM ' . Database::t('cards') . ' WHERE agent_id = ? ORDER BY id DESC LIMIT 30',
    [$id]
) as $c) {
    $cards[] = [
        'id'            => (int) $c['id'],
        'code'          => $c['code'],
        'type'          => (int) $c['type'],
        'type_text'     => Card::typeName((int) $c['type']),
        'duration_text' => (int) $c['type'] === 1 ? Util::duration((int) $c['duration']) : (string) $c['duration'],
        'max_devices'   => (int) $c['max_devices'],
        'status'        => (int) $c['status'],
        'status_text'   => Card::statusName((int) $c['status']),
        'used_by'       => (int) $c['used_by'],
        'used_at_text'  => (int) $c['used_at'] > 0 ? Util::date((int) $c['used_at']) : '',
        'expire_text'   => (int) $c['expire_at'] > 0 ? Util::date((int) $c['expire_at']) : '永久',
        'created_at_text' => Util::date((int) $c['created_at']),
    ];
}

// 最近 10 个批次
$batches = [];
foreach (Database::all(
    'SELECT * FROM ' . Database::t('card_batches') . ' WHERE agent_id = ? ORDER BY id DESC LIMIT 10',
    [$id]
) as $b) {
    $batches[] = [
        'id'         => (int) $b['id'],
        'name'       => (string) $b['name'],
        'prefix'     => (string) ($b['prefix'] ?? ''),
        'count'      => (int) $b['count'],
        'used_count' => (int) $b['used_count'],
        'created_at_text' => Util::date((int) $b['created_at']),
    ];
}

// 最近 20 条操作日志
$logs = [];
foreach (Agent::recentLogs($id, 20) as $l) {
    $logs[] = [
        'action'      => $l['action'],
        'action_text' => Agent::actionName((string) $l['action']),
        'detail'      => (string) ($l['detail'] ?? ''),
        'amount'      => (int) $l['amount'],
        'ip'          => (string) ($l['ip'] ?? ''),
        'time_text'   => Util::date((int) $l['created_at']),
    ];
}

Response::ok([
    'agent'   => Agent::publicInfo($agent),
    'stats'   => Agent::stats($id),
    'cards'   => $cards,
    'batches' => $batches,
    'logs'    => $logs,
]);

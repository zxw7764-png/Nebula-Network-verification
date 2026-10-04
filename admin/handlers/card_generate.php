<?php
/**
 * admin action: card_generate
 * 批量生成卡密
 */

$r = Card::generate($input, (int) $admin['id']);

if (!$r['ok']) {
    Audit::log($admin, 'card_generate', '卡密生成', '生成失败：' . $r['msg'],
        [], [], Util::pick($input, ['count', 'type', 'duration', 'max_devices', 'group_id', 'prefix', 'format']));
    Response::error($r['code'], $r['msg']);
}

Audit::log($admin, 'card_generate', '批次#' . ($r['data']['batch_id'] ?? 0),
    '生成 ' . ($r['data']['count'] ?? 0) . ' 张卡密',
    [], [], [
        'count'       => $r['data']['count'] ?? 0,
        'batch_id'    => $r['data']['batch_id'] ?? 0,
        'type'        => Util::int($input, 'type', 1),
        'duration'    => Util::int($input, 'duration', 0),
        'max_devices' => Util::int($input, 'max_devices', 1),
        'group_id'    => Util::int($input, 'group_id', 0),
        'prefix'      => Util::str($input, 'prefix', ''),
        'format'      => Util::str($input, 'format', 'XXXX-XXXX-XXXX-XXXX'),
    ]);

// 不返回全部卡密（可能上万条），只返回前 50 条预览
$preview = array_slice($r['data']['codes'], 0, 50);
$data = $r['data'];
$data['codes'] = $preview;
$data['preview_count'] = count($preview);

Response::ok($data, $r['msg']);

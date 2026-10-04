<?php
/**
 * Admin handler: rt_device_unblock
 * 解除运行时设备风险（§42 §43 §44 §55 审计）
 * 将设备 Runtime 风险清零，允许后续正常使用
 */

AdminPermission::require($admin, AdminPermission::RT_DEVICE_BLOCK);

$deviceId = (int) ($requestData['device_id'] ?? 0);
if ($deviceId <= 0) {
    Response::error(1001, '缺少设备ID');
}

$device = Database::one(
    'SELECT id, user_id, machine_id, device_name, rt_risk_score, rt_risk_level, rt_flags FROM ' . Database::t('devices') . ' WHERE id = ?',
    [$deviceId]
);
if (!$device) {
    // 1002/1003 是前端登出码，业务错误一律用 1001
    Response::error(1001, '设备不存在');
}

$ok = Database::update('devices', [
    'rt_risk_score' => 0,
    'rt_risk_level' => 'LOW',
    'rt_flags'      => 0,
], 'id = :id', ['id' => $deviceId]);

Audit::log($admin, 'rt_device_unblock',
    "设备#{$deviceId}",
    "解除设备运行时风险: {$device['device_name']} (分数: {$device['rt_risk_score']} → 0)",
    ['rt_risk_score' => $device['rt_risk_score'], 'rt_risk_level' => $device['rt_risk_level']],
    ['rt_risk_score' => 0, 'rt_risk_level' => 'LOW'],
    ['device_id' => $deviceId, 'user_id' => $device['user_id']]
);

Response::ok(['ok' => $ok > 0]);

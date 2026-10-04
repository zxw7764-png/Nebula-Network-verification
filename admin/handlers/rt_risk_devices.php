<?php
/**
 * Admin handler: rt_risk_devices
 * 风险设备列表（§41 §42 §43 §44）
 * 按设备统计 RuntimeGuard 违规事件，发现反复出现安全问题的设备
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_VIEW);

$page = max(1, (int) ($requestData['page'] ?? 1));
$size = min(100, max(1, (int) ($requestData['size'] ?? 20)));
$softwareId = (int) ($requestData['software_id'] ?? 0);

// §41: 按 device_id 统计安全事件
$base = 'SELECT
    d.id as device_id,
    d.user_id,
    d.machine_id,
    d.device_name,
    d.rt_risk_score,
    d.rt_risk_level,
    d.rt_flags,
    d.status as device_status,
    d.ip as last_ip,
    d.last_seen,
    u.username,
    COUNT(se.id) as total_events,
    SUM(CASE WHEN se.risk_level = "CRITICAL" THEN 1 ELSE 0 END) as critical_events,
    SUM(CASE WHEN se.risk_level = "HIGH" THEN 1 ELSE 0 END) as high_events,
    MAX(se.created_at) as last_event_at
FROM ' . Database::t('devices') . ' d
LEFT JOIN ' . Database::t('users') . ' u ON u.id = d.user_id
LEFT JOIN ' . Database::t('security_events') . ' se ON se.device_id = d.id';

$where = 'WHERE d.rt_risk_score > 0 OR se.id IS NOT NULL';
$params = [];

if ($softwareId > 0) {
    $where .= ' AND (d.user_id IN (SELECT id FROM ' . Database::t('users') . ' WHERE software_id = ?))';
    $params[] = $softwareId;
}

$base .= ' ' . $where . ' GROUP BY d.id';

[$total, $rows] = Database::paginate($base, $params, $page, $size, 'd.rt_risk_score DESC, last_event_at DESC');
// 上面的 ORDER BY 字段别名（如 last_event_at）由 GROUP BY 产生，需在分页查询里可用

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'list'  => $rows,
]);

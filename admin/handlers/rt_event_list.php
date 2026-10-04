<?php
/**
 * Admin handler: rt_event_list
 * 安全事件列表（§39）
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_EVENTS);

$page = max(1, (int) ($requestData['page'] ?? 1));
$size = min(100, max(1, (int) ($requestData['size'] ?? 20)));

$filters = [
    'event_type'  => $requestData['event_type'] ?? '',
    'risk_level'  => $requestData['risk_level'] ?? '',
    'software_id' => (int) ($requestData['software_id'] ?? 0),
    'handled'     => $requestData['handled'] ?? '',
];

[$total, $rows] = RuntimeEventService::listEvents($page, $size, $filters);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'list'  => $rows,
]);

<?php
/**
 * Admin handler: rt_event_detail
 * 安全事件详情（§40）
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_EVENTS);

$id = (int) ($requestData['id'] ?? 0);
if ($id <= 0) {
    Response::error(1001, '缺少事件ID');
}

$event = RuntimeEventService::getEvent($id);
if (!$event) {
    // 1002/1003 是前端登出码，业务错误一律用 1001
    Response::error(1001, '事件不存在');
}

Response::ok([
    'event' => $event,
]);

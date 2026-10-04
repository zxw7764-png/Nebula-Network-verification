<?php
/**
 * Admin handler: rt_event_handle
 * 标记安全事件处理状态（§104 §105: 误报不删除原事件，保留审计数据）
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_EVENTS);

$id     = (int) ($requestData['id'] ?? 0);
$status = (int) ($requestData['status'] ?? -1);
$note   = (string) ($requestData['note'] ?? '');

if ($id <= 0) {
    Response::error(1001, '缺少事件ID');
}

// status: 0=未处理 1=已处理 2=误报
if (!in_array($status, [0, 1, 2], true)) {
    Response::error(1001, '无效的处理状态');
}

$ok = RuntimeEventService::markHandled($id, $status, $note);
if (!$ok) {
    Response::error(9999, '操作失败');
}

// §55: 审计日志
$event = RuntimeEventService::getEvent($id);
Audit::log($admin, 'rt_event_handle',
    "安全事件#{$id}",
    $event ? "{$event['event_type']} → " . ($status === 2 ? '误报' : '已处理') : '',
    [], [],
    ['event_id' => $id, 'status' => $status, 'note' => $note]
);

Response::ok(['ok' => true]);

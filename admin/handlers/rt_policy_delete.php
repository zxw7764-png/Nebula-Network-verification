<?php
/**
 * Admin handler: rt_policy_delete
 * 删除运行时策略（§55 审计）
 */

AdminPermission::require($admin, AdminPermission::RT_POLICY_WRITE);

$id = (int) ($requestData['id'] ?? 0);
if ($id <= 0) {
    Response::error(1001, '缺少策略ID');
}

$old = RuntimePolicy::get($id);
if (!$old) {
    // 1002/1003 是前端登出码，业务错误一律用 1001
    Response::error(1001, '策略不存在');
}

$ok = RuntimePolicy::delete($id);

Audit::log($admin, 'rt_policy_delete',
    "运行时策略#{$id}",
    "删除策略: {$old['policy_name']}",
    $old, [], ['policy_id' => $id]
);

Response::ok(['ok' => $ok]);

<?php
/**
 * Admin handler: rt_policy_list
 * 运行时策略列表
 */

AdminPermission::require($admin, AdminPermission::RT_POLICY_READ);

$page = max(1, (int) ($requestData['page'] ?? 1));
$size = min(100, max(1, (int) ($requestData['size'] ?? 20)));

[$total, $rows] = RuntimePolicy::listAll($page, $size);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'list'  => $rows,
]);

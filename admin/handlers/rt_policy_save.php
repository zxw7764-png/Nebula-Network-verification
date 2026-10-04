<?php
/**
 * Admin handler: rt_policy_save
 * 创建/更新运行时策略（§36 §55 §82 §83 §84）
 * §36: 普通代理不能任意关闭检测项，权限受 RBAC 控制
 * §82: Policy 修改属于高风险操作，要求 RBAC + CSRF + 管理员 Session + Audit
 * §83: 支持 Draft → Published 发布流程
 * §84: 支持版本回滚（通过 version 递增 + 历史记录）
 */

AdminPermission::require($admin, AdminPermission::RT_POLICY_WRITE);

$data = $requestData;

// 参数校验
$level = (int) ($data['protection_level'] ?? 2);
if ($level < 0 || $level > 3) {
    Response::error(1001, '防护等级必须在 0-3 之间');
}

$watchdogMs = (int) ($data['watchdog_interval_ms'] ?? 3000);
if ($watchdogMs < 500 || $watchdogMs > 60000) {
    Response::error(1001, '看门狗间隔必须在 500-60000ms 之间');
}

// 动作校验
foreach (['medium_action', 'high_action', 'critical_action'] as $field) {
    $val = strtoupper((string) ($data[$field] ?? ''));
    if (!in_array($val, ['REPORT', 'TERMINATE', 'REVOKE_SESSION'], true)) {
        Response::error(1001, "{$field} 无效值");
    }
    $data[$field] = $val;
}

$oldPolicy = null;
$id = (int) ($data['id'] ?? 0);
if ($id > 0) {
    $oldPolicy = RuntimePolicy::get($id);
    if (!$oldPolicy) {
        // 1002/1003 是前端登出码，业务错误一律用 1001
        Response::error(1001, '策略不存在');
    }
}

$newId = RuntimePolicy::save($data);

// §55: 审计日志
Audit::log($admin, 'rt_policy_save',
    "运行时策略#{$newId}",
    $oldPolicy ? "更新策略: {$oldPolicy['policy_name']}" : '新建策略',
    $oldPolicy ? array_intersect_key($oldPolicy, $data) : [],
    array_intersect_key($data, $oldPolicy ?? []),
    ['policy_id' => $newId]
);

Response::ok([
    'id'      => $newId,
    'ok'      => true,
]);

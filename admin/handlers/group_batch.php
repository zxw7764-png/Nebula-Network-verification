<?php
/**
 * admin action: group_batch
 * 用户组批量操作（op=delete）
 * 校验规则与 group_save::delete 一致：默认组不可删、组内有用户的组跳过
 */

$op  = Util::str($input, 'op', '');

// 2026-10-06 审计：用户组为全局对象（nb_groups 无软件归属），租户管理员不可操作
if (Tenant::isTenant($admin)) {
    Response::error(4031, '用户组为全局配置，仅平台管理员可管理');
}
$ids = array_values(array_unique(array_filter(
    array_map('intval', (array) ($input['ids'] ?? [])),
    fn ($v) => $v > 0
)));

if ($op !== 'delete' || $ids === []) {
    Response::error(1001, '缺少操作类型或未选择用户组');
}

$done    = 0;
$skipped = [];
foreach ($ids as $id) {
    if ($id <= 1) {
        $skipped[] = "组#{$id}（默认组不可删除）";
        continue;
    }
    $used = (int) Database::value('SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE group_id = ?', [$id]);
    if ($used > 0) {
        $skipped[] = "组#{$id}（组内 {$used} 个用户）";
        continue;
    }
    Database::exec('DELETE FROM ' . Database::t('groups') . ' WHERE id = ?', [$id]);
    Audit::log($admin, 'group_batch', "用户组#{$id}", '批量删除用户组');
    $done++;
}

$msg = "已删除 {$done} 个用户组";
if ($skipped !== []) {
    $msg .= '，跳过 ' . count($skipped) . ' 个：' . implode('、', $skipped);
}
Response::ok(['deleted' => $done, 'skipped' => $skipped], $msg);

<?php
/**
 * admin action: plan_save
 * 新增 / 编辑 / 删除 / 启用停用价格套餐（仅官网展示字段）
 *
 * 发卡侧配置（上架 / 售价 / 挂卡规格 / 商品陈列）已拆至
 * shop_goods_save（商品与交易 → 商品管理），此处不再受理。
 *
 * 参数：
 *   op        save（默认）| delete | toggle
 *   id        套餐 ID，0=新增
 *   name      套餐名
 *   price     价格文案（字符串，支持"面议"这类非数字）
 *   unit      价格单位（默认"元"）
 *   duration  时长文案
 *   desc      套餐说明，一行一条
 *   badge     角标文案（如"热销"）
 *   highlight 1=高亮展示
 *   sort      排序（越大越靠前）
 *   status    1启用 0停用
 */

$op    = Util::str($input, 'op', 'save');
$table = Database::t('plans');

if ($op === 'delete') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '套餐不存在');
    }
    // 2026-10-06 审计：套餐归属校验（挂卡归属 + 官网展示归属均须在租户范围内）
    Tenant::touchRow($admin, 'plans', $old, 'software_id');
    if ((int) ($old['web_software_id'] ?? 0) > 0) {
        Tenant::requireTouch($admin, (int) $old['web_software_id']);
    }

    // 发卡商品已拆独立表 nb_shop_plans，这里只删官网侧（软删 web_deleted=1），
    // 与发卡商店完全互不影响
    try {
        Database::exec("UPDATE {$table} SET web_deleted = 1, status = 0 WHERE id = ?", [$id]);
    } catch (Throwable $e) {
        // 未跑迁移（无 web_deleted 列）的老库退回物理删除
        Database::exec("DELETE FROM {$table} WHERE id = ?", [$id]);
    }
    Audit::log($admin, 'plan_delete', "套餐#{$id} {$old['name']}", '删除价格套餐（发卡商品不受影响）',
        ['name' => $old['name'], 'price' => $old['price']], ['web_deleted' => 1]);

    Response::ok(['id' => $id], '套餐已从官网删除（发卡商品不受影响）');
}

if ($op === 'toggle') {
    $id = Util::int($input, 'id', 0);
    if ($id <= 0) {
        Response::error(1001, '缺少 id');
    }
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '套餐不存在');
    }
    // 2026-10-06 审计：套餐归属校验（挂卡归属 + 官网展示归属均须在租户范围内）
    Tenant::touchRow($admin, 'plans', $old, 'software_id');
    if ((int) ($old['web_software_id'] ?? 0) > 0) {
        Tenant::requireTouch($admin, (int) $old['web_software_id']);
    }

    $new = (int) $old['status'] === 1 ? 0 : 1;
    Database::exec("UPDATE {$table} SET status = ? WHERE id = ?", [$new, $id]);

    Audit::log($admin, 'plan_toggle', "套餐#{$id} {$old['name']}", '切换套餐状态',
        ['status' => (int) $old['status']], ['status' => $new]);

    Response::ok(['id' => $id, 'status' => $new], $new === 1 ? '套餐已启用' : '套餐已停用');
}

// ---------------------------------------------------------------
// 保存
// ---------------------------------------------------------------
$id        = Util::int($input, 'id', 0);
$name      = mb_substr(trim(Util::str($input, 'name', '')), 0, 60);
$price     = mb_substr(trim(Util::str($input, 'price', '')), 0, 30);
$unit      = mb_substr(trim(Util::str($input, 'unit', '元')) ?: '元', 0, 14);
$duration  = mb_substr(trim(Util::str($input, 'duration', '')), 0, 60);
$desc      = (string) Util::get($input, 'desc', '');
$badge     = mb_substr(trim(Util::str($input, 'badge', '')), 0, 30);
$highlight = Util::int($input, 'highlight', 0) === 1 ? 1 : 0;
$sort      = Util::int($input, 'sort', 0);
$status    = Util::int($input, 'status', 1) === 1 ? 1 : 0;
// 官网展示归属软件：0=全部软件通用；>0 时必须是存在的软件
$webSoftwareId = Util::int($input, 'web_software_id', 0);
if ($webSoftwareId < 0) {
    $webSoftwareId = 0;
}
if ($webSoftwareId > 0 && !Software::find($webSoftwareId)) {
    Response::error(1001, '所属软件不存在');
}
// 2026-10-06 审计：租户管理员仅可为自己的软件创建官网套餐；全域通用（0）仅平台管理员可建
if ($webSoftwareId > 0) {
    Tenant::requireTouch($admin, $webSoftwareId);
} elseif (Tenant::isTenant($admin)) {
    Response::error(4031, '租户管理员创建官网套餐必须指定归属软件');
}

if ($name === '') {
    Response::error(1001, '套餐名不能为空');
}

$data = [
    'name'             => $name,
    'price'            => $price,
    'unit'             => $unit,
    'duration'         => $duration,
    'desc'             => $desc,
    'badge'            => $badge,
    'highlight'        => $highlight,
    'sort'             => $sort,
    'status'           => $status,
];
// 老库未跑迁移（无 web_software_id 列）时不写该字段，避免 SQL 未知列报错
if ((bool) Database::one("SHOW COLUMNS FROM {$table} LIKE 'web_software_id'")) {
    $data['web_software_id'] = $webSoftwareId;
}

if ($id > 0) {
    $old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
    if (!$old) {
        Response::error(1004, '套餐不存在');
    }
    // 2026-10-06 审计：套餐归属校验（挂卡归属 + 官网展示归属均须在租户范围内）
    Tenant::touchRow($admin, 'plans', $old, 'software_id');
    if ((int) ($old['web_software_id'] ?? 0) > 0) {
        Tenant::requireTouch($admin, (int) $old['web_software_id']);
    }

    Database::update('plans', $data, 'id = :id', ['id' => $id]);

    Audit::log($admin, 'plan_save', "套餐#{$id} {$name}", '编辑套餐',
        Util::pick($old, ['name', 'price', 'unit', 'duration', 'desc', 'badge', 'highlight', 'sort', 'status']),
        $data);

    Response::ok(['id' => $id], '套餐已更新');
}

$data['created_at'] = time();
$newId = Database::insert('plans', $data);

Audit::log($admin, 'plan_save', "套餐#{$newId} {$name}", '新增套餐', [], $data);

Response::ok(['id' => $newId], '套餐已添加');

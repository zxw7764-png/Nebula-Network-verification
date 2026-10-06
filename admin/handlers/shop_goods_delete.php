<?php
/**
 * admin action: shop_goods_delete
 * 删除发卡商品（发卡商品独立表 nb_shop_plans，物理删除；支持批量 ids）
 * ------------------------------------------------------------------
 * 连带处理该商品的外部卡密池：未售卡密直接删除，已售卡密脱批（batch_id=0，
 * 保留内容供订单回查）；订单自身存有商品快照，不受影响。
 * 官网价格套餐（nb_plans）完全不受影响。
 *
 * 参数：
 *   id    单个商品
 *   ids   批量商品（数组或逗号分隔）
 *
 * 权限：settings.business（仅超管），与 shop_goods_save 同档。
 */

$rawIds = $input['ids'] ?? $input['id'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = explode(',', (string) $rawIds);
}
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn ($v) => $v > 0)));
if (!$ids) {
    Response::error(1001, '缺少 id');
}
if (count($ids) > 500) {
    Response::error(1001, '单次最多删除 500 个商品');
}

$in   = implode(',', $ids);
// 2026-10-06 审计：跨租户删除修复 —— 任一商品不在本租户范围内即整单拒绝（此前可删任意软件商品及其卡密池）
Tenant::requireTouchAll($admin, 'shop_plans', $ids);
$rows = Database::all('SELECT id, name, shop_price FROM ' . Database::t('shop_plans') . " WHERE id IN ({$in})");
if (!$rows) {
    Response::error(1004, '商品不存在');
}

$cardsTable = Database::t('shop_cards');

$unsold = 0;
$sold   = 0;
foreach ($rows as $old) {
    $pid = (int) $old['id'];

    // 卡密池清理：未售删除 / 已售脱批
    $u = (int) Database::one(
        "SELECT COUNT(*) FROM {$cardsTable} WHERE plan_id = ? AND status = 0",
        [$pid]
    )['COUNT(*)'];
    $s = (int) Database::one(
        "SELECT COUNT(*) FROM {$cardsTable} WHERE plan_id = ? AND status = 1",
        [$pid]
    )['COUNT(*)'];
    Database::exec("DELETE FROM {$cardsTable} WHERE plan_id = ? AND status = 0", [$pid]);
    if ($s > 0) {
        Database::exec("UPDATE {$cardsTable} SET batch_id = 0 WHERE plan_id = ? AND status = 1", [$pid]);
    }
    $unsold += $u;
    $sold   += $s;

    Database::exec('DELETE FROM ' . Database::t('shop_plans') . ' WHERE id = ?', [$pid]);
    Audit::log($admin, 'shop_goods_delete', "发卡商品#{$pid} {$old['name']}", '删除发卡商品',
        ['name' => $old['name'], 'shop_price' => $old['shop_price']],
        ['unsold_removed' => $u, 'sold_detached' => $s]);
}

$msg = count($rows) === 1
    ? '发卡商品已删除'
    : "已删除 " . count($rows) . ' 个发卡商品';
if ($unsold > 0 || $sold > 0) {
    $msg .= "（连带清理未售卡密 {$unsold} 条，已售 {$sold} 条脱批保留）";
}
Response::ok(['deleted' => count($rows)], $msg);

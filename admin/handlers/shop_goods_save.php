<?php
/**
 * admin action: shop_goods_save
 * 保存发卡商品配置（商品与交易 → 商品管理）
 * ------------------------------------------------------------------
 * 发卡商品独立表 nb_shop_plans（2.32.23 起与官网价格套餐 nb_plans 完全分离），
 * 编辑 / 新增 / 删除均只影响发卡商店，与官网价格套餐互不牵动。
 *
 * 新增（id=0）：需填商品名，创建一条仅发卡侧生效的记录——
 *   status=0（不出现在官网首页价格套餐区，需要时在价格套餐页开启）、
 *   price 文案默认同发卡售价、unit=元；此后与普通套餐一样可双端管理。
 *
 * 权限：settings.business（仅超管），与发卡网配置同档——
 * 挂卡规格配错会导致发错卡，与 shop_setting 同级管控。
 *
 * card_source 卡密来源：0=本系统卡（按挂卡规格取卡，默认）
 * 1=外部卡密（非本验证系统的商品，从 shop_cards_import 导入的卡密池发货，
 * 无需挂卡规格，库存=池中未售条数）。
 */

$id = Util::int($input, 'id', 0);

$table = Database::t('shop_plans');

/** 发卡挂卡规格：type => 说明 */
$SHOP_TYPES = [
    Card::TYPE_DURATION => '时长卡',
    Card::TYPE_POINTS   => '点数卡',
    Card::TYPE_TIMES    => '次数卡',
    Card::TYPE_FOREVER  => '永久卡',
];

$shopStatus     = Util::int($input, 'shop_status', 0) === 1 ? 1 : 0;
$shopPriceRaw   = trim(Util::str($input, 'shop_price', '0'));
$shopOrigRaw    = trim(Util::str($input, 'shop_orig_price', '0')); // 划线原价（仅展示用，0=无折扣）
$cardType       = Util::int($input, 'card_type', Card::TYPE_DURATION);
$cardDuration   = max(0, Util::int($input, 'card_duration', 0));
$cardMaxDevices = Util::int($input, 'card_max_devices', 1);
$cardGroupId    = max(0, Util::int($input, 'card_group_id', 0));
$cardSource     = Util::int($input, 'card_source', 0) === 1 ? 1 : 0;

// 多规格挂卡类型：cards 数组优先（前端多行规格），缺省把单字段当作一张卡（兼容老调用）
// 逐条严格校验：任何一条不合法都直接报错（指出第几条），绝不静默丢弃——避免"加多条却只剩一条"
$rawCards = Util::get($input, 'cards', null);
$cards = [];
$cardsProvided = is_array($rawCards);   // 前端是否显式传了 cards 数组（区分「新前端传空」与「老前端没传」）
if ($cardsProvided) {
    foreach ($rawCards as $idx => $c) {
        $no = $idx + 1;
        if (!is_array($c)) {
            Response::error(1001, "第 {$no} 个挂卡类型格式不正确");
        }
        $ct = Util::int($c, 'card_type', 0);
        if (!isset($SHOP_TYPES[$ct])) {
            Response::error(1001, "第 {$no} 个挂卡类型不合法（请选择时长卡/点数卡/次数卡/永久卡）");
        }
        $cp = trim(Util::str($c, 'price', ''));
        if ($cp === '' || !is_numeric($cp) || (float) $cp < 0) {
            Response::error(1001, "第 {$no} 个挂卡类型（{$SHOP_TYPES[$ct]}）的售价需为不小于 0 的数字，填 0 表示用商品默认售价");
        }
        $cmd  = max(1, min(255, Util::int($c, 'card_max_devices', 1)));
        $cgid = max(0, Util::int($c, 'card_group_id', 0));
        $cd   = max(0, Util::int($c, 'card_duration', 0));
        if ($ct !== Card::TYPE_FOREVER && $cd <= 0) {
            Response::error(1001, "第 {$no} 个挂卡类型（{$SHOP_TYPES[$ct]}）必须填写卡面数值（时长卡填时长，点数卡填点数，次数卡填次数）");
        }
        $cards[] = [
            'card_type'        => $ct,
            'card_duration'    => $cd,
            'price'            => number_format((float) $cp, 2, '.', ''),
            'card_max_devices' => $cmd,
            'card_group_id'    => $cgid,
        ];
    }
    if (!$cards) {
        Response::error(1001, '请至少配置一个挂卡类型');
    }
}
if (!$cardsProvided && isset($SHOP_TYPES[$cardType])) {
    // 回退：老前端未传 cards 字段时，用单字段作为唯一一张卡（向后兼容）
    $cards = [[
        'card_type'        => $cardType,
        'card_duration'    => $cardDuration,
        'price'            => number_format((float) $shopPriceRaw, 2, '.', ''),
        'card_max_devices' => $cardMaxDevices,
        'card_group_id'    => $cardGroupId,
    ]];
}
// 用首张规格覆盖主表字段（兼容旧读取逻辑 / 列表快速展示）
if ($cards) {
    $cardType       = $cards[0]['card_type'];
    $cardDuration   = $cards[0]['card_duration'];
    $cardMaxDevices = $cards[0]['card_max_devices'];
    $cardGroupId    = $cards[0]['card_group_id'];
}

$shopCategory   = mb_substr(trim(Util::str($input, 'shop_category', '')), 0, 60);
$shopName       = mb_substr(trim(Util::str($input, 'shop_name', '')), 0, 120);
$shopIcon       = trim(Util::str($input, 'shop_icon', ''));
$shopIntro      = mb_substr(trim(Util::str($input, 'shop_intro', '')), 0, 200);
$shopDetail     = mb_substr(trim(Util::str($input, 'shop_detail', '')), 0, 5000);
// 推荐高亮（发卡商店商品卡描边）与角标文案（如「热销」）：落发卡独立列，
// 与官网价格套餐的 highlight/badge 完全区分；未传时不改动原值
$highlightRaw   = Util::get($input, 'highlight', null);
$badgeRaw       = Util::get($input, 'badge', null);

if (!isset($SHOP_TYPES[$cardType])) {
    Response::error(1001, '挂卡类型不合法');
}
if ($shopStatus === 1 && empty($cards)) {
    Response::error(1001, '请至少配置一个挂卡类型（挂卡规格）');
}
if ($shopIcon !== '' && !preg_match('#^(https?://|/)#i', $shopIcon)) {
    Response::error(1001, '商品图标需为 http(s) 地址或上传后的站内路径');
}

// 上架校验（口径与原 plan_save 发卡段一致）
if ($shopStatus === 1) {
    // 售价：非负数金额（元），0=免费商品（下单即完成支付并自动发卡），
    // 与展示文案 price（可“面议”）相互独立
    if ($shopPriceRaw === '' || !is_numeric($shopPriceRaw) || (float) $shopPriceRaw < 0) {
        Response::error(1001, '发卡售价需为不小于 0 的数字（0=免费商品）');
    }
    // 划线原价（可选）：不小于 0 的数字，0 / 留空 = 不展示折扣
    if ($shopOrigRaw !== '' && (!is_numeric($shopOrigRaw) || (float) $shopOrigRaw < 0)) {
        Response::error(1001, '划线原价需为不小于 0 的数字（0 / 留空 = 不展示折扣）');
    }
    // 卡面数值校验（多规格时 cardDuration 已被首张规格覆盖，此校验兼容旧单字段调用）
    if ($cardType !== Card::TYPE_FOREVER && $cardDuration <= 0) {
        Response::error(1001, $SHOP_TYPES[$cardType] . '必须填写卡面数值（时长秒数 / 点数 / 次数）');
    }
    if ($cardMaxDevices < 1 || $cardMaxDevices > 255) {
        Response::error(1001, '设备上限需在 1-255 之间');
    }
}

$softwareId = Util::int($input, 'software_id', 0);
if ($softwareId > 0 && !Software::find($softwareId)) {
    Response::error(1001, '所选软件不存在');
}
// 2026-10-06 审计：租户管理员仅可为自己的软件配置发卡商品（此前可挂到任意软件）
if ($softwareId > 0) {
    Tenant::requireTouch($admin, $softwareId);
}

// 显示归属软件（按软件过滤商品用）：0=全部软件通用，N=仅该软件官网/商店显示。
// 与挂卡 software_id 完全独立；列未就绪（未跑迁移）时忽略该字段，不影响保存。
$shopSwId = Util::int($input, 'shop_software_id', 0);
$swColReady = Shop::hasShopSwCol();
if ($swColReady && $shopSwId > 0 && !Software::find($shopSwId)) {
    Response::error(1001, '显示归属软件不存在');
}
// 2026-10-06 审计：显示归属软件同样须在租户范围内
if ($swColReady && $shopSwId > 0) {
    Tenant::requireTouch($admin, $shopSwId);
}

$data = [
    'card_source'      => $cardSource,
    'shop_status'      => $shopStatus,
    'shop_price'       => number_format((float) $shopPriceRaw, 2, '.', ''),
    'shop_orig_price'  => number_format((float) ($shopOrigRaw !== '' ? $shopOrigRaw : 0), 2, '.', ''),
    'card_type'        => $cardType,
    'card_duration'    => $cardDuration,
    'card_max_devices' => $cardMaxDevices,
    'card_group_id'    => $cardGroupId,
    'shop_category'    => $shopCategory,
    'shop_name'        => $shopName,
    'shop_icon'        => $shopIcon,
    'shop_intro'       => $shopIntro,
    'shop_detail'      => $shopDetail,
];
if ($softwareId > 0) {
    $data['software_id'] = $softwareId;
}
if ($swColReady) {
    $data['shop_software_id'] = $shopSwId > 0 ? $shopSwId : 0;
}
if ($highlightRaw !== null) {
    $data['shop_highlight'] = (int) $highlightRaw === 1 ? 1 : 0;
}
if ($badgeRaw !== null) {
    $data['shop_badge'] = mb_substr(trim((string) $badgeRaw), 0, 30);
}

// 查单自定义提示（商品级单条文案）：买家查到该商品已支付订单时展示
if (Shop::hasShopNoticeCol()) {
    $data['shop_notice'] = mb_substr(trim(Util::str($input, 'shop_notice', '')), 0, 500);
}

// ---------------- 新增（id=0）：创建仅发卡侧生效的商品 ----------------
if ($id === 0) {
    $name = mb_substr(trim(Util::str($input, 'name', '')), 0, 60);
    if ($name === '') {
        Response::error(1001, '商品名不能为空');
    }

    $data['name']       = $name;
    $data['shop_name']  = $shopName !== '' ? $shopName : $name;
    $data['desc']       = '';                  // 卖点（TEXT 列无默认值，显式给空串）
    $data['duration']   = '';
    $data['sort']       = 0;
    $data['status']     = 0;
    $data['created_at'] = time();

    $newId = Database::insert('shop_plans', $data);
    Shop::syncPlanCards($newId, $cards);
    Audit::log($admin, 'shop_goods_save', "发卡商品#{$newId} {$name}", '新增发卡商品', [], $data);

    Response::ok(['id' => $newId], '发卡商品已添加');
}

// ---------------- 编辑（id>0） ----------------
$old = Database::one("SELECT * FROM {$table} WHERE id = ?", [$id]);
if (!$old) {
    Response::error(1004, '套餐不存在');
}
// 2026-10-06 审计：编辑发卡商品须校验归属软件在租户范围内（此前可改任意商品）
Tenant::touchRow($admin, 'shop_plans', $old, 'software_id');
if ((int) ($old['shop_software_id'] ?? 0) > 0) {
    Tenant::requireTouch($admin, (int) $old['shop_software_id']);
}

Database::update('shop_plans', $data, 'id = :id', ['id' => $id]);
Shop::syncPlanCards($id, $cards);

$pickKeys = ['card_source', 'shop_status', 'shop_price', 'shop_orig_price', 'card_type', 'card_duration',
    'card_max_devices', 'card_group_id', 'shop_category', 'shop_name', 'shop_icon', 'shop_intro', 'shop_detail'];
if ($swColReady) {
    $pickKeys[] = 'shop_software_id';
}
if (isset($data['shop_highlight'])) {
    $pickKeys[] = 'shop_highlight';
}
if (isset($data['shop_badge'])) {
    $pickKeys[] = 'shop_badge';
}
Audit::log($admin, 'shop_goods_save', "发卡商品#{$id} {$old['name']}", '配置发卡商品',
    Util::pick($old, $pickKeys), $data);

Response::ok(['id' => $id], '发卡商品配置已保存');

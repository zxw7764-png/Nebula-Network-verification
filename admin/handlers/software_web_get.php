<?php
/**
 * admin action: software_web_get
 * 读取某软件的官网文案覆盖（软件分站内容独立设置）
 * 返回覆盖 JSON 的原始字段；空 = 未覆盖，官网回落全局配置
 */

$id = Util::int($input, 'id', 0);
$sw = $id > 0 ? Software::find($id) : null;
if (!$sw) {
    Response::error(1001, '软件不存在');
}

// 2026-10-06 审计：跨租户读取修复 —— 租户管理员仅可读自己软件的官网覆盖配置
Tenant::requireTouch($admin, $id);

$raw = trim((string) Setting::get('web_sw_' . $id, ''));
$ov  = [];
if ($raw !== '') {
    $data = json_decode($raw, true);
    if (is_array($data)) {
        $ov = $data;
    }
}

Response::ok([
    'id'        => $id,
    'name'      => (string) $sw['name'],
    'overrides' => $ov,
]);

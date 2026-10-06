<?php
/**
 * admin action: software_web_save
 * 保存某软件的官网文案覆盖（软件分站内容独立设置）
 * 字段值为空字符串 = 清除覆盖（该处回落全局配置）；全部为空时删除覆盖 JSON
 */

$id = Util::int($input, 'id', 0);
$sw = $id > 0 ? Software::find($id) : null;
if (!$sw) {
    Response::error(1001, '软件不存在');
}

// 2026-10-06 审计：跨租户篡改修复 —— 租户管理员仅可修改自己软件的官网配置（此前可改任意软件分站）
Tenant::requireTouch($admin, $id);

// 可覆盖的官网文案字段白名单（与 web/inc/portal.php 的 webSetting() 取值键一致）
$keys = [
    'site_name', 'site_sub',
    'web_logo', 'web_favicon',
    'web_hero_title', 'web_hero_em', 'web_hero_lead2', 'web_hero_stats',
    'web_features_title', 'web_features_sub', 'web_features',
    'web_flow_title', 'web_flow_sub', 'web_flow',
    'web_faq_title', 'web_faq_sub', 'web_faq',
    'web_nav_links', 'web_foot_slogan', 'web_foot_links', 'web_copyright',
    'web_message_board', 'contact', 'web_shop_url',
    'web_theme', 'web_bg_url', 'web_ui_template',
];

// 合并语义：先读旧覆盖；input 未出现的字段保留旧值，出现的空值 = 清除该覆盖
// （便于只改单一字段的入口——如模板管理只回传 web_ui_template——不清掉其它覆盖）
$old = [];
$rawOld = trim((string) Setting::get('web_sw_' . $id, ''));
if ($rawOld !== '' && is_array($decodedOld = json_decode($rawOld, true))) {
    $old = $decodedOld;
}
$ov = $old;
foreach ($keys as $k) {
    if (!array_key_exists($k, $input)) {
        continue; // 未提交的字段保留旧覆盖
    }
    $v = is_string($input[$k]) ? trim($input[$k]) : '';
    if ($v === '') {
        unset($ov[$k]); // 提交空值 = 清除覆盖（回落全局）
        continue;
    }
    // 行式字段限制行数，防恶意超长输入
    $maxLines = in_array($k, ['web_features', 'web_flow', 'web_faq', 'web_nav_links', 'web_foot_links', 'web_hero_stats'], true) ? 30 : 1;
    $lines = preg_split('/\r\n|\r|\n/', $v);
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
    }
    $ov[$k] = implode("\n", array_map('trim', $lines));
}

// Logo / Favicon 只放行 http/https 直链（挡 javascript: 等伪协议被前台渲染）
foreach (['web_logo', 'web_favicon'] as $urlKey) {
    if (isset($ov[$urlKey]) && !preg_match('#^https?://#i', $ov[$urlKey])) {
        Response::error(1001, 'Logo / Favicon 地址必须以 http:// 或 https:// 开头');
    }
}

// 界面模板值校验（走 UiTemplate 接口，与前台回落口径一致）
if (isset($ov['web_ui_template']) && !UiTemplate::valid('web', (string) $ov['web_ui_template'])) {
    Response::error(1001, '界面模板非法');
}

// 主题色格式校验（#RRGGBB）；背景图放行 http(s) 外链与 / 开头站内路径（上传产物）
// 格式错时前台会静默回落默认，这里直接拦下提示
if (isset($ov['web_theme']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $ov['web_theme'])) {
    Response::error(1001, '主题色需填 #RRGGBB 六位十六进制色值（如 #5b9dff）');
}
if (isset($ov['web_bg_url']) && !preg_match('#^(https?://|/)#i', $ov['web_bg_url'])) {
    Response::error(1001, '背景图地址需 http(s) 链接，或使用「上传背景图」按钮');
}

if ($ov) {
    Setting::set('web_sw_' . $id, json_encode($ov, JSON_UNESCAPED_UNICODE), '软件分站官网内容覆盖');
} else {
    Setting::set('web_sw_' . $id, '', '软件分站官网内容覆盖（清空）');
}

Logger::log('admin_software_web_save', 1, '软件#' . $id . ' 官网内容已更新（' . count($ov) . ' 项覆盖）');

Response::ok(['id' => $id, 'overrides' => $ov], '官网内容已保存');

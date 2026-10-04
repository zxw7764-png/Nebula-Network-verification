<?php
/**
 * admin action: setting_save
 * 保存系统设置
 *
 * ------------------------------------------------------------------
 * 权限分档（P1-10）
 * ------------------------------------------------------------------
 * 修复前：整个 setting_save 只需「能进后台」即可调用，白名单里同时包含
 * 站点名称这类无害项，和 cache_redis_password 这类
 * 密钥与基础设施配置。一个操作员账号可以借此改掉后台入口密钥、把缓存
 * 指向自己的 Redis —— 属于权限提升。
 *
 * 现在按危险程度分四档，逐档校验（矩阵见 lib/AdminPermission.php）：
 *   settings.site      站点展示信息          —— 操作员可改
 *   settings.business  业务规则（注册/赠送等） —— 仅超管
 *   settings.security  能削弱防线的开关       —— 仅超管
 *   settings.infra     密钥 / 缓存基础设施    —— 仅超管
 *
 * 策略：本次提交里只要含有任何一档超出当前管理员权限的键，就整单拒绝
 * （而不是静默丢弃），避免出现「提示保存成功但实际只改了一半」的误解。
 */

$items = Util::get($input, 'settings', []);

if (!is_array($items) || !$items) {
    Response::error(1001, '没有需要保存的设置');
}

// ------------------------------------------------------------------
// 设置项 -> 分档 映射
// 新增设置项时必须在此登记，否则会被下方「未登记」分支拒绝。
// ------------------------------------------------------------------
$tiers = [
    // 站点展示：改错只影响外观
    AdminPermission::SETTINGS_SITE => [
        'site_name', 'site_sub', 'contact', 'web_message_board',
        'maintain_msg', 'register_closed_msg',
        // 官网首页文案（编辑入口在「内容运营 → 官网内容」，本 handler 仍负责存储）
        'web_hero_title', 'web_hero_em', 'web_hero_lead2', 'web_hero_stats',
        'web_features_title', 'web_features_sub', 'web_features',
        'web_flow_title', 'web_flow_sub', 'web_flow',
        'web_faq_title', 'web_faq_sub', 'web_faq',
        'web_nav_links', 'web_foot_slogan', 'web_foot_links', 'web_copyright',
        'web_theme',
        // 官网背景：图直链 + 底色（编辑入口在「内容运营 → 官网内容」）
        'web_bg_url',
        // 官网界面模板（farm/mario/ink/space，空=默认深空）
        'web_ui_template',
        // 官网小游戏开关（模板自带右下角小游戏 + 跨用户排行榜）
        'web_games_enabled',
        // 小游戏参数（后台「内容运营 → 小游戏与排行榜」配置，经 __NB_GAMES__.cfg 下发给模板游戏）
        'game_duration', 'game_top_n', 'game_rate_limit', 'game_durations',
        // 总站白页开关：开启后多软件未选择软件时官网首页只显示「选择软件」占位页
        'web_total_blank',
    ],

    // 业务规则：影响发放与额度，但不直接削弱安全防线
    // （agent_entry_key 属于代理商业务入口的开关，归业务档；与 infra 档同为仅超管）
    // shop_*：发卡网全套配置已迁至 shop_setting_save（仍归业务档，仅超管），本处不受理。
    AdminPermission::SETTINGS_BUSINESS => [
        'register_enable', 'default_max_devices',
        'register_gift_days', 'register_gift_points',
        'agent_enable', 'agent_register_enable', 'agent_unit_price',
        'agent_entry_key',
        // 点数/次数卡扣点策略
        'points_deduct_mode', 'points_deduct_minutes',
        // shop_* 已迁至独立 action shop_setting_save（运营分区 → 发卡网配置页），
        // 本 handler 不再受理，避免双入口
        'maintain_mode',
    ],

    // 安全策略：能削弱防线的开关
    // （min_client_version 已按软件迁至「软件管理」，此处不再受理）
    AdminPermission::SETTINGS_SECURITY => [
        'login_methods', 'single_login', 'geo_block',
        'login_reclaim_enable',
        'heartbeat_interval', 'heartbeat_timeout', 'session_ttl',
        'grace_enable', 'grace_seconds', 'grace_max_seconds',
        'unbind_per_day', 'rate_limit_per_min',
        'web_reg_max_hour', 'web_act_cooldown_min', 'web_act_max_min',
        // 人机风控总开关：关闭后四端接口不再做前端信号评分（排查误拦用）
        'guard_enabled',
        'ip_blacklist',
    ],

    // 基础设施与密钥：缓存后端（含 Redis 口令）与系统更新服务地址
    AdminPermission::SETTINGS_INFRA => [
        'cache_driver', 'cache_redis_host', 'cache_redis_port',
        'cache_redis_password', 'cache_redis_database',
        // 系统更新服务地址（对接 update-system）
        'update_server',
    ],
];

// 兼容别名：旧前端把副标题提交为 web_hero_lead，统一落到 web_hero_lead2
$aliases = ['web_hero_lead' => 'web_hero_lead2'];

// 反查表：key => 档位权限点
$keyTier = [];
foreach ($tiers as $perm => $keys) {
    foreach ($keys as $k) {
        $keyTier[$k] = $perm;
    }
}
foreach ($aliases as $from => $to) {
    $keyTier[$from] = $keyTier[$to];
}

// ------------------------------------------------------------------
// 1) 归一化 + 分档鉴权
// ------------------------------------------------------------------
$normalized = [];   // key => value（已过滤非法字符与未登记项）
$needed     = [];   // 本次提交实际需要的档位 => 涉及的键
$unknown    = [];

foreach ($items as $k => $v) {
    $k = preg_replace('/[^a-z_]/', '', (string) $k);
    if ($k === '') {
        continue;
    }
    if (!isset($keyTier[$k])) {
        $unknown[] = $k;
        continue;
    }
    if (isset($aliases[$k])) {
        $k = $aliases[$k];
    }
    $normalized[$k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    $needed[$keyTier[$k]][] = $k;
}

if ($unknown) {
    // 未登记的项不静默忽略：说明前端发了后端不认的键，需要显式暴露
    Logger::log('admin_setting', 0, '尝试保存未登记的设置项: ' . implode(', ', $unknown), [
        'admin_id' => (int) $admin['id'],
    ]);
    Response::error(1001, '存在未支持或已废弃的设置项：' . implode(', ', array_slice($unknown, 0, 5)));
}

if (!$normalized) {
    Response::error(1001, '没有可保存的设置项');
}

// 官网主题色：#RRGGBB 大写归一化，非法值回空（官网回退默认紫）
if (isset($normalized['web_theme'])) {
    $t = strtolower(trim($normalized['web_theme']));
    $normalized['web_theme'] = preg_match('/^#[0-9a-f]{6}$/', $t) ? $t : '';
}

// 分游戏时长表：JSON {模板id:秒}，逐项校验（id 字符集同模板 id、秒数 10~300），非法项丢弃
if (isset($normalized['game_durations'])) {
    $durMap = json_decode((string) $normalized['game_durations'], true);
    $durClean = [];
    if (is_array($durMap)) {
        foreach ($durMap as $dk => $dv) {
            $dk = (string) $dk; $dv = (int) $dv;
            if (preg_match('/^[a-z0-9_]{1,32}$/', $dk) && $dv >= 10 && $dv <= 300) {
                $durClean[$dk] = $dv;
            }
        }
    }
    $normalized['game_durations'] = $durClean ? json_encode($durClean, JSON_UNESCAPED_UNICODE) : '';
}

// 官网背景图：http/https 外链 或 / 开头站内路径（上传产物，挡 javascript: 等伪协议）
if (isset($normalized['web_bg_url'])) {
    $u = trim($normalized['web_bg_url']);
    $normalized['web_bg_url'] = preg_match('#^(https?://|/)#i', $u) ? $u : '';
}

// IP 黑名单：逐行校验，仅保留合法 IP / CIDR 段，非法行丢弃（合法行 >= 0 即整单可用）
$errors = [];
if (isset($normalized['ip_blacklist'])) {
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $normalized['ip_blacklist']) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $ipPart = explode('/', $line, 2)[0];
        if (@inet_pton($ipPart) === false) {
            $errors[] = "IP 黑名单存在非法行：{$line}（需为 IP 或 IP/掩码位，如 1.2.3.4 或 1.2.3.0/24）";
            continue;
        }
        if (strpos($line, '/') !== false) {
            $bits = (int) explode('/', $line, 2)[1];
            $max  = (strpos($ipPart, ':') !== false) ? 128 : 32;
            if ($bits < 0 || $bits > $max) {
                $errors[] = "IP 黑名单掩码位非法：{$line}（0-{$max}）";
                continue;
            }
        }
        $lines[] = $line;
    }
    if ($errors) {
        Response::error(1001, implode('；', $errors));
    }
    $normalized['ip_blacklist'] = implode("\n", $lines);
}

// 逐档校验：任一档无权限即整单拒绝
foreach ($needed as $perm => $keys) {
    AdminPermission::require($admin, $perm);
}

// ------------------------------------------------------------------
// 2) 写入
// ------------------------------------------------------------------
$oldValues = [];
$newValues = [];
$saved     = [];

foreach ($normalized as $k => $val) {
    $oldValues[$k] = (string) Setting::get($k, '');
    Setting::set($k, $val);
    $newValues[$k] = $val;
    $saved[] = $k;
}

if (!$saved) {
    Response::error(1001, '没有可保存的设置项');
}

// ------------------------------------------------------------------
// 人机风控开关的连带处理
// ------------------------------------------------------------------
// 本次提交里带了 guard_enabled，就顺带清掉「当前管理员 IP」的风控计数与
// 封禁标记：关掉开关立刻生效，不必等封禁窗口自己过期；重新打开则从零
// 计数，不会一上来就继承历史命中次数。
// ------------------------------------------------------------------
if (array_key_exists('guard_enabled', $normalized)) {
    Guard::clearBan();
}

// 审计里标记涉及的最高敏感档，便于事后追责
$tierLabels = [
    AdminPermission::SETTINGS_SITE     => '站点',
    AdminPermission::SETTINGS_BUSINESS => '业务',
    AdminPermission::SETTINGS_SECURITY => '安全',
    AdminPermission::SETTINGS_INFRA    => '基础设施',
];
$touchedTiers = array_map(fn($p) => $tierLabels[$p] ?? $p, array_keys($needed));

Audit::log($admin, 'setting_save', '系统设置',
    '修改设置项（' . implode('/', $touchedTiers) . '）：' . implode(', ', $saved),
    $oldValues, $newValues);

Response::ok(['saved' => $saved], '设置已保存');

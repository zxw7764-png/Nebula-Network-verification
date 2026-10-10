<?php
/**
 * action: login
 * ------------------------------------------------------------------
 * 登录方式由后台「系统设置 → 登录方式」**单选**决定，客户端按 init 下发的
 * 方式直接提交对应字段（不做「一次塞全部字段、服务端猜」的兼容）：
 *
 *   password        username + password
 *   username_code   username + code
 *   code            code
 *
 * 公共参数: machine_id(必填), device_name, os_info, client_ver
 */

$sw     = Software::current();
$method = LoginMethod::currentFor($sw);

$machineId  = Util::str($requestData, 'machine_id', '');
$deviceName = Util::str($requestData, 'device_name', '');
$osInfo     = Util::str($requestData, 'os_info', '');
$curIp      = Util::ip();

if ($machineId === '') {
    Response::error(4003, '缺少机器码 machine_id');
}

// 版本强制更新拦截：低于「最低可用版本」，或低于最新已发布版本且该版本勾选了强制更新
// （与 version 接口同一套规则；client_ver 缺失的旧客户端不拦，避免误伤）
$clientVer = Util::str($requestData, 'client_ver', '');
if ($clientVer !== '') {
    $swForVer = Software::current();
    if ($swForVer) {
        $vInfo = Software::versionInfo($swForVer, 'stable');
        $minVer = Software::minVersion($swForVer);
        $needUpdate = Util::versionCompare($clientVer, $vInfo['version']) < 0;
        $forceUpdate = Util::versionCompare($clientVer, $minVer) < 0
            || ($needUpdate && !empty($vInfo['force_update']));
        if ($forceUpdate) {
            Logger::log('login', 0, "版本过低被拦截: {$clientVer}", ['app_key' => $swForVer['app_key'] ?? '']);
            Response::send(6001, '当前版本过低，请升级客户端后再登录', [
                'latest'       => $vInfo['version'],
                'download_url' => $vInfo['download_url'],
                'need_relogin' => true,
            ]);
        }
    }
}

// §89 §90: 客户端能力检查（前移）—— 必须在创建会话 / 设备校验与绑定之前执行，
// 否则能力不符的登录虽被拒绝，仍会留下会话记录、把设备 IP 刷写甚至占用绑定名额
if (!RuntimeGuard::checkRuntimeGuardRequired($sw, $requestData)) {
    Logger::log('login', 0, '客户端不支持 RuntimeGuard，被强制策略拒绝', [
        'app_key'    => $sw['app_key'] ?? '',
        'machine_id' => $machineId,
    ]);
    Response::send(7002, '当前软件要求运行时安全防护，请更新客户端版本', [
        'need_relogin'           => true,
        'runtime_guard_required' => true,
    ]);
}

// 登录爆破防护
$attemptLimit = (int) Config::get('policy.login_attempt_per_min', 10);
if (!RateLimit::hit('login:' . Util::ip(), $attemptLimit, 60)) {
    Logger::log('login', 0, '登录尝试过于频繁', ['method' => $method]);
    Response::error(5001, '登录尝试过于频繁，请稍后再试');
}

// ------------------------------------------------------------------
// 激活码登录方式（username_code / code）：卡密就是凭证，防撞库与枚举
// 与 activate 接口同一套阈值，避免「换个接口继续试」的绕过。
// ------------------------------------------------------------------
$cardCode   = Util::str($requestData, 'code', '');
$loginMissKey = null;
$missLimit  = (int) Config::get('policy.card_miss_limit', 30);
$missWindow = (int) Config::get('policy.card_miss_window', 600);
if (LoginMethod::isCard($method) && $cardCode !== '') {
    $loginMissKey = RateLimit::missKey('login');
    if ($missLimit > 0 && $missWindow > 0 && RateLimit::count($loginMissKey, $missWindow) >= $missLimit) {
        Logger::log('login', 0, '卡密枚举尝试过多，来源已临时封禁', ['method' => $method]);
        Response::error(5001, '尝试次数过多，请稍后再试');
    }

    $cardLimit  = (int) Config::get('policy.card_try_limit', 8);
    $cardWindow = (int) Config::get('policy.card_try_window', 600);
    if ($cardLimit > 0 && !RateLimit::byCard($cardCode, 'login', $cardLimit, $cardWindow)) {
        Logger::log('login', 0, '同一卡密登录尝试过于频繁', ['method' => $method]);
        Response::error(5001, '该激活码尝试次数过多，请稍后再试');
    }
}

$r = Auth::loginBy($requestData, $method);

// 卡密不存在 → 计入枚举检测
if (!$r['ok'] && (int) $r['code'] === 3001 && $loginMissKey !== null && $missLimit > 0 && $missWindow > 0) {
    RateLimit::incr($loginMissKey, $missWindow);
}

if (!$r['ok']) {
    Logger::log('login', 0, $r['msg'], [
        'method' => $method,
        'raw'    => [
            'username' => Util::str($requestData, 'username', ''),
            'code'     => Util::maskCard(Util::str($requestData, 'code', '')),
        ],
    ]);
    Response::error($r['code'], $r['msg']);
}

$user     = $r['user'];
$username = (string) $user['username'];

// 会员有效性
$vip = Auth::checkVip($user);
if (!$vip['valid']) {
    Logger::log('login', 0, $vip['msg'], ['user_id' => $user['id'], 'username' => $username, 'method' => $method]);
    Response::send($vip['code'], $vip['msg'], [
        'need_activate' => true,
        'user'          => Auth::publicInfo($user),
    ]);
}

// 点数/次数卡扣点（模式可配：per_login 每次登录 / daily 每天首次 / online 心跳按在线时长）
// 时长卡/永久卡 points=0，天然不受影响。
if ($vip['points'] > 0 && Points::chargeOnLogin($user)) {
    $vip['points'] = (int) $vip['points'] - 1;
    Logger::log('login', 1, '点数扣减 1（剩余 ' . $vip['points'] . '）', [
        'user_id'  => (int) $user['id'],
        'username' => $username,
        'method'   => $method,
    ]);
}

// 设备校验（自动绑定）
// device_fp 为可选的硬件组件指纹：上报则做加权校验与模拟器/虚拟机识别，
// 不上报则行为与旧版完全一致（对接方可以按自己的节奏升级）。
$maxDevices = Auth::maxDevices($user);
$dev = Device::check((int) $user['id'], $machineId, [
    'ip'          => Util::ip(),
    'device_name' => $deviceName ?: '未知设备',
    'os_info'     => $osInfo,
    'device_fp'   => $requestData['device_fp'] ?? [],
], $maxDevices);

// 异地登录拦截（后台「系统设置 → 安全设置」可开关）
// ------------------------------------------------------------------
// 判据：已绑定设备本次登录 IP 与其上次记录 IP 不同即拦截；
// 首次绑定（新机器/重新激活）不做判定，否则用户第一次在网吧登录就会被挡。
// 注意：Device::check 内部已把设备 IP 刷成当前 IP，必须用返回的
// $dev['device']（校验前快照）里的旧 IP 比较，遍历设备表会永远放行。
if ($dev['ok'] && !$dev['auto_bound'] && Policy::geoBlock()) {
    $prevIp = trim((string) ($dev['device']['ip'] ?? ''));
    if ($prevIp !== '' && $prevIp !== $curIp) {
        // 把设备 IP 回滚为上次记录，否则重试一次新 IP 就会被放行
        Device::touch((int) $dev['device']['id'], $prevIp);
        Logger::log('login', 0, '异地登录已拦截', [
            'user_id'    => $user['id'],
            'username'   => $username,
            'machine_id' => $machineId,
            'raw'        => ['ip' => $curIp, 'known' => $prevIp],
        ]);
        Response::send(4006, '检测到异地登录，已拒绝（如需换网络请先解绑设备）', [
            'need_relogin' => true,
            'geo_block'    => true,
            'current_ip'   => Util::maskIp($curIp),
            'known_ips'    => [Util::maskIp($prevIp)],
        ]);
    }
}

if (!$dev['ok']) {
    Logger::log('login', 0, $dev['msg'], [
        'user_id'    => $user['id'],
        'username'   => $username,
        'machine_id' => $machineId,
        'method'     => $method,
    ]);
    Response::send($dev['code'], $dev['msg'], [
        'max_devices'  => $maxDevices,
        'bound_count'  => Device::activeCount((int) $user['id']),
        'devices'      => Device::listByUser((int) $user['id']),
    ]);
}

// 同账号单点登录（后台「系统设置 → 安全设置」可开关）
// ------------------------------------------------------------------
// 开启时：本次登录成功前，先把该账号的旧会话全部作废，
// 使「后登录踢掉先登录」，同一时刻只有一个端在线。
// 关闭时（默认）：允许多端同时在线，保持旧行为不变。
// 注意必须在 Session::create() **之前**执行，否则会把刚建的会话一起踢掉。
$singleLogin = Policy::singleLogin();
$kicked      = 0;
if ($singleLogin) {
    $kicked = Session::kickUser((int) $user['id']);
}

// 创建会话
$ttl   = (int) Config::get('policy.session_ttl', 3600);
$token = Session::create((int) $user['id'], [
    'machine_id' => $machineId,
    'ip'         => Util::ip(),
    'client_ver' => $clientVer,
], $ttl);

Logger::log('login', 1, '登录成功', [
    'user_id'    => $user['id'],
    'username'   => $username,
    'machine_id' => $machineId,
    'method'     => $method,
    'created'    => $r['created'],
    'kicked'     => $kicked,
]);

// 离线宽限票据：绑定本次 token 与 machine_id，附带公钥（客户端只需在登录/init
// 时取一次公钥并内置或缓存）。服务端抖动导致心跳失败时，客户端本地验签后
// 可在票据到期前继续离线运行，避免全体掉线。
// 返回 null 表示服务端未开启该能力，客户端按原逻辑处理即可。
$grace = Grace::issue($user, $token, $machineId, true);

// §32 §33 §86 §87 §88 §89 §90: Login 响应下发 Runtime Policy + 能力协商
// （强制 RuntimeGuard 检查已前移到会话创建之前；此处仅做能力解析用于策略下发）
$rtCapabilities = $requestData['capabilities'] ?? [];
$rtGuardSupported = is_array($rtCapabilities) && !empty($rtCapabilities['runtime_guard']);

$rtPolicyData = null;
if ($rtGuardSupported) {
    // 客户端声称支持 RuntimeGuard，下发策略
    $rtPolicyData = RuntimePolicy::forClient((int) ($sw['id'] ?? 0));
}

// 初始化会话 Runtime 状态为 CLEAN
Database::update('sessions', [
    'rt_status'       => 'CLEAN',
    'rt_risk_score'   => 0,
    'rt_risk_level'   => 'LOW',
    'rt_flags'        => 0,
    'rt_policy_version' => (int) ($rtPolicyData['policy_version'] ?? 0),
], 'token = :tok', ['tok' => $token]);

Response::ok([
    'token'          => $token,
    'expire_at'      => time() + $ttl,
    'ttl'            => $ttl,
    'login_method'   => $method,
    // 功能密钥：仅 login 成功后下发（后台「软件管理」配置，空=未启用）。
    // 接入方用它解密随程序分发的核心数据包 —— 登录失败 / 被踢 / 过期后数据保持密文。
    // ★ 刻意不在 init 下发：init 是免验证接口，功能密钥必须以登录成功为前提。
    'feature_key'    => (string) ($sw['feature_key'] ?? ''),
    'account_created'=> (bool) $r['created'],
    'user'           => Auth::publicInfo($user),
    'vip'            => $vip,
    'device'         => [
        'machine_id'  => $machineId,
        'auto_bound'  => $dev['auto_bound'],
        'max_devices' => $maxDevices,
        'bound_count' => Device::activeCount((int) $user['id']),
        // 命中的设备指纹风险标记（空数组表示正常）；仅供客户端提示，拦截已在服务端完成
        'risk'        => $dev['risk'] ?? [],
    ],
    'grace'          => $grace,
    // §32 §33: Runtime Policy（仅客户端声明支持时下发）
    'runtime_policy' => $rtPolicyData,
], '登录成功');

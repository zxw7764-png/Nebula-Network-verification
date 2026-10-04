<?php
/**
 * admin action: setting_get
 * 获取系统设置（含配置文件中不可写项）
 */

Response::ok([
    'settings' => Setting::all(),
    // 策略项「当前生效值 + 来源」：后台改的策略项（心跳/限流/单点登录等）
    // 由 Policy 统一按「数据库 → 配置文件」取值，这里把结果暴露给前端，
    // 便于管理员确认改动到底有没有落到运行时（避免再次出现"改了没效果"）。
    'effective' => Policy::debugTable(),
    // 登录方式可选项（唯一来源，前端下拉据此渲染，避免两处硬编码漂移）
    'login_methods_options' => LoginMethod::all(),
    'login_method'          => LoginMethod::current(),
    // 代理商控量模式可选项（同上，唯一来源）
    'agent_modes_options'   => Agent::allModes(),
    'agent_enabled'         => Agent::enabled(),
    // 缓存层运行时状态：bootstrap 阶段已把后台配置合并进 Cache，
    // 这里展示的是「合并后真实生效」的驱动与连接参数（不含密码明文）
    'cache_info'            => Cache::info(),
    // 人机风控总开关的「当前生效值」：数据库中没保存过时回退出厂默认（开启），
    // 前端据此回显，避免首次进设置页把开关误显示成「关闭」
    'guard'                 => [
        'enabled' => Guard::enabled(),
    ],
    // 缓存出厂值（config/cache 段，剔除密码）：表单里「后台没保存过」的字段用它回显
    'cache_config'          => [
        'driver' => Config::get('cache.driver', 'auto'),
        'redis'  => [
            'host'     => Config::get('cache.redis.host', '127.0.0.1'),
            'port'     => (int) Config::get('cache.redis.port', 6379),
            'database' => (int) Config::get('cache.redis.database', 0),
        ],
    ],
    'config'   => [
        'version' => Config::get('version'),
        'policy'  => Config::get('policy'),
        'crypto'  => [
            'enforce'     => Config::get('security.enforce_crypto'),
            'algo'        => 'ECDH P-256 + AES-256-GCM',
            'sign'        => 'ES256',
            'time_window' => Config::get('security.time_window'),
        ],
        // 注意：只暴露「展示用」的安全项，绝不返回 entry_key / default_pass 等敏感值
        'admin'   => [
            'path'                 => Config::get('admin.path', 'admin'),
            'session_ttl'          => (int) Config::get('admin.session_ttl', 7200),
            'entry_key_enable'     => Config::get('admin.entry_key', '') !== '',
            'ip_whitelist_enable'  => (bool) Config::get('admin.ip_whitelist_enable', false),
            'ip_whitelist'         => (array) Config::get('admin.ip_whitelist', []),
            'require_password_confirm' => (bool) Config::get('admin.require_password_confirm', true),
            'login_fail_threshold' => (int) Config::get('admin.login_fail_threshold', 5),
            'login_lock_seconds'   => (int) Config::get('admin.login_lock_seconds', 900),
        ],
        // 离线宽限（运行参数只读展示；分软件开关在「软件管理 → 编辑 → 策略覆盖」）
        // 注意：seconds / max_seconds 走 Setting::intWithConfig，与 Policy 层取值一致，
        //       避免「后台改了数据库值但下方只显示 config.php 默认值」的错位
        'grace'   => [
            'enable'      => (bool) Config::get('grace.enable', true),
            'seconds'     => (int) Setting::intWithConfig('grace_seconds', 'grace.seconds'),
            'max_seconds' => (int) Setting::intWithConfig('grace_max_seconds', 'grace.max_seconds'),
            'clock_skew'  => (int) Config::get('grace.clock_skew', 120),
            // ES256 公钥 PEM（公开信息，可展示）：客户端登录/心跳时由服务端自动下发，
            // SDK 无需手动配置；密钥由服务端自动生成落盘 config/grace_keys.php，后台只读展示，改密钥走「轮换密钥」按钮
            'algo'        => class_exists('Grace') ? Grace::algorithm() : '',
            'kid'         => class_exists('Grace') ? Grace::keyId() : '',
            'public_key'  => class_exists('Grace') ? (string) (Grace::publicKey() ?? '') : '',
        ],
        // 响应签名（3.1 协议）：公钥 PEM 由 lib/RespSign 管理，落盘 config/resp_sign_keys.php；
        // 首次请求自动生成，删除文件下次请求重新生成一套新密钥。
        // 与离线宽限密钥独立管理（轮换不影响离线票据）；后台只读展示，改密钥走「轮换密钥」按钮
        'resp_sign' => [
            'algo'       => class_exists('RespSign') ? RespSign::algorithm() : '',
            'kid'        => class_exists('RespSign') ? RespSign::keyId() : '',
            'public_key' => class_exists('RespSign') ? (string) (RespSign::publicKey() ?? '') : '',
        ],
    ],
]);

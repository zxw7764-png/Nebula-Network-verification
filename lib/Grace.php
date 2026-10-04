<?php
/**
 * 离线宽限期（离线授权票据）
 * ------------------------------------------------------------------
 * 要解决的问题：
 *   服务端抖动 / 网络闪断 / 临时 502 时，客户端心跳失败就会把用户踢下线。
 *   全员同时掉线会引发大量投诉，而实际上服务端可能只是重启了几十秒。
 *
 * 做法：
 *   服务端在 login / heartbeat 时下发一张「离线宽限票据」——
 *   用服务端【私钥】签名的紧凑凭证，客户端用内置【公钥】本地验签后，
 *   在票据到期前可以继续离线运行（客户端展示"离线模式，剩余 N 分钟"）。
 *
 * 为什么用非对称签名而不是 HMAC：
 *   HMAC 需要把密钥放进客户端，一旦被逆向出来，任何人都能无限伪造票据；
 *   公钥验签则私钥永不出服务端，伪造不了。这里用 ECDSA P-256（ES256），
 *   签名只有 ~70 字节，票据整体约 200~300 字节，放 JSON 里毫无压力。
 *
 * 票据里有什么（都对客户端可见，但不是敏感信息）：
 *   u 用户ID · m 机器码摘要 · k 会话令牌摘要 · e 会员到期 · i 签发时间
 *   g 宽限截止时间 · d 宽限时长 · n 随机数 · v 票据版本
 *
 * 边界与取舍（务必知悉）：
 *   · 离线期间服务端无法撤销票据 —— 所以宽限时长要短（默认 1 小时），
 *     且到期时间会被【账号有效期】钳制，永久卡也不会拿到无限期宽限。
 *   · 票据只在账号状态正常时就才签发；封号/踢下线的用户最多还能离线
 *     运行到票据到期为止，这是离线方案的固有代价。
 *   · 客户端本身运行在用户机器上，任何离线校验都可能被改客户端绕过。
 *     本机制的目标是「防止服务端抖动导致误伤」，不是「防破解」。
 *     真正的防盗版拦截仍应放在联网校验路径上。
 *
 * 客户端校验步骤（见 docs/OFFLINE_GRACE.md）：
 *   1) 用内置公钥验证签名；
 *   2) 比对 m 字段与本地机器码摘要、k 字段与本地会话摘要；
 *   3) 检查 time() < g（允许一定时钟偏差）；
 *   4) 全部通过才允许离线运行，否则按原逻辑退出。
 */
class Grace
{
    private static ?array $keys = null;
    private static bool   $keysTried = false;

    /** 票据前缀（版本 1），便于以后平滑升级格式 */
    private const PREFIX = 'G1';

    // ==================================================================
    // 开关 / 运行时信息
    // ==================================================================

    public static function enabled(): bool
    {
        if (!Setting::boolWithConfig('grace_enable', 'grace.enable')) {
            return false;
        }
        if ((int) Setting::intWithConfig('grace_seconds', 'grace.seconds') <= 0) {
            return false;
        }
        // 分软件策略覆盖：当前软件显式关闭（policy_json.grace_enable = 0）时不签发
        if (class_exists('Software')
            && Policy::swPolicyVal(Software::current(), 'grace_enable') === 0) {
            return false;
        }
        return self::ensureKeys();
    }

    /** 下发给客户端的公开信息（init 接口用） */
    public static function info(): array
    {
        $keys = self::ensureKeys() ? self::$keys : null;
        return [
            'enable'     => self::enabled(),
            'seconds'    => (int) Setting::intWithConfig('grace_seconds', 'grace.seconds'),
            'max_seconds'=> (int) Setting::intWithConfig('grace_max_seconds', 'grace.max_seconds'),
            'algorithm'  => $keys['algo'] ?? '',
            'kid'        => $keys['kid'] ?? '',
            'public_key' => $keys['public'] ?? '',
            'ticket_prefix' => self::PREFIX,
            'usage'      => '心跳失败时，本地用 public_key 验签后可在 until 前离线运行',
        ];
    }

    public static function publicKey(): ?string
    {
        return self::ensureKeys() ? (self::$keys['public'] ?? null) : null;
    }

    /** 算法标识（ES256 / RS256），密钥不可用时返回空串 */
    public static function algorithm(): string
    {
        return self::ensureKeys() ? (string) (self::$keys['algo'] ?? '') : '';
    }

    /** 密钥版本 kid（客户端可据此判断服务端是否换过密钥） */
    public static function keyId(): string
    {
        return self::ensureKeys() ? (string) (self::$keys['kid'] ?? '') : '';
    }

    /**
     * 用本类管理的私钥对任意报文签名（供响应防伪造签名使用）
     * 返回 DER 签名（ES256）/ PKCS#1 签名（RS256），密钥不可用返回 null。
     */
    public static function signMessage(string $body): ?string
    {
        if (!self::ensureKeys()) return null;
        return self::sign($body);
    }

    // ==================================================================
    // 签发
    // ==================================================================

    /**
     * 签发离线宽限票据
     *
     * @param array       $user         用户行（需要 id / vip_expire）
     * @param string|null $sessionToken 当前会话令牌，用于把票据绑到这次登录
     * @param string|null $machineId    机器码，用于把票据绑到这台机器
     * @param bool        $withKey      是否附带公钥（init/login 带，heartbeat 不带）
     * @return array|null 未启用 / 不满足条件时返回 null（客户端按普通模式处理）
     */
    public static function issue(array $user, ?string $sessionToken = null,
                                 ?string $machineId = null, bool $withKey = false): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        $now     = time();
        // 优先从当前软件的策略读取，否则走全局 Setting
        if (class_exists('Policy') && class_exists('Software')) {
            $seconds = Policy::graceSeconds(Software::current());
            $max     = Policy::graceMaxSeconds(Software::current());
        } else {
            $seconds = (int) Setting::intWithConfig('grace_seconds', 'grace.seconds');
            $max     = (int) Setting::intWithConfig('grace_max_seconds', 'grace.max_seconds');
        }
        if ($max > 0) {
            $seconds = min($seconds, $max);
        }
        if ($seconds <= 0) {
            return null;
        }

        $vip = (int) ($user['vip_expire'] ?? 0);
        // 未激活账号不签发（0 = 未激活；-1 = 永久，不钳制）
        if ($vip === 0) {
            return null;
        }

        // §30: 高危 Runtime 事件发生后，不允许通过旧 Grace Ticket 无限恢复 Session
        // 检查设备 Runtime 风险状态 — CRITICAL 设备拒绝签发 Grace Ticket
        if ($machineId !== null && $machineId !== '' && class_exists('Database')) {
            $devRt = Database::one(
                'SELECT rt_risk_level, rt_status FROM ' . Database::t('devices') . '
                 WHERE user_id = ? AND machine_id = ? AND status = 1 LIMIT 1',
                [(int) ($user['id'] ?? 0), $machineId]
            );
            if ($devRt) {
                $devRtLevel = strtoupper((string) ($devRt['rt_risk_level'] ?? 'LOW'));
                $devRtStatus = strtoupper((string) ($devRt['rt_status'] ?? 'UNKNOWN'));
                if ($devRtLevel === 'CRITICAL' || $devRtStatus === 'BLOCKED') {
                    // 设备处于 CRITICAL / BLOCKED 状态，拒绝恢复 Grace Session
                    return null;
                }
            }
        }

        $until = $now + $seconds;
        if ($vip > 0 && Config::get('grace.clamp_to_vip', true)) {
            // 离线宽限绝不能超过账号本身的到期时间
            $until = min($until, $vip);
        }
        if ($until <= $now) {
            return null;
        }

        $payload = [
            'v' => 1,
            'u' => (int) ($user['id'] ?? 0),
            'm' => self::digest($machineId),
            'k' => self::digest($sessionToken),
            'e' => $vip,
            'i' => $now,
            'g' => $until,
            'd' => $until - $now,
            'n' => bin2hex(random_bytes(6)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }
        $body = self::PREFIX . '.' . self::b64u($json);
        $sig  = self::sign($body);
        if ($sig === null) {
            return null;
        }

        $out = [
            'ticket'      => $body . '.' . self::b64u($sig),
            'until'       => $until,
            'seconds'     => $until - $now,
            'issued_at'   => $now,
            'server_time' => $now,
            'algorithm'   => self::$keys['algo'] ?? '',
            'kid'         => self::$keys['kid'] ?? '',
            'machine_bind'=> $payload['m'],
        ];
        if ($withKey) {
            $out['public_key'] = self::$keys['public'] ?? '';
        }
        return $out;
    }

    /**
     * 生成「与客户端上报值对应」的摘要。
     * 客户端校验时用同样的算法对本地值取摘要比对即可，原始值不上票据。
     */
    public static function digest(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return substr(hash('sha256', $value), 0, 16);
    }

    // ==================================================================
    // 验签（服务端自检 / 命令行工具 / 客户端参考实现复用同一份逻辑）
    // ==================================================================

    /**
     * @param string      $ticket    完整票据
     * @param string|null $publicKey 公钥 PEM，默认用本地公钥
     * @return array{ok:bool,msg:string,payload:?array}
     */
    public static function verify(string $ticket, ?string $publicKey = null): array
    {
        $fail = function (string $m): array { return ['ok' => false, 'msg' => $m, 'payload' => null]; };

        $parts = explode('.', $ticket);
        if (count($parts) !== 3) {
            return $fail('票据格式错误');
        }
        if ($parts[0] !== self::PREFIX) {
            return $fail('票据版本不支持：' . $parts[0]);
        }

        $pub = $publicKey ?: self::publicKey();
        if (!$pub) {
            return $fail('服务端公钥不可用');
        }

        $sig = self::b64uDecode($parts[2]);
        if ($sig === null) {
            return $fail('签名解码失败');
        }
        $body = $parts[0] . '.' . $parts[1];

        $v = @openssl_verify($body, $sig, $pub, OPENSSL_ALGO_SHA256);
        if ($v !== 1) {
            return $fail('签名校验失败（票据可能被篡改）');
        }

        $json = self::b64uDecode($parts[1]);
        if ($json === null) {
            return $fail('载荷解码失败');
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['g'], $payload['u'])) {
            return $fail('载荷格式错误');
        }

        return ['ok' => true, 'msg' => 'ok', 'payload' => $payload];
    }

    /**
     * 客户端视角的完整校验（验签 + 绑定校验 + 有效期），供调试工具调用。
     *
     * @param string      $ticket
     * @param string|null $machineId 本机机器码
     * @param string|null $token     本地保存的会话令牌
     * @param int|null    $now       校验时刻（默认当前时间），便于测试时钟偏差
     */
    public static function checkOffline(string $ticket, ?string $machineId = null,
                                        ?string $token = null, ?int $now = null): array
    {
        $r = self::verify($ticket);
        if (!$r['ok']) {
            return $r;
        }
        $p   = $r['payload'];
        $now = $now ?? time();

        if (!empty($p['m']) && self::digest($machineId) !== $p['m']) {
            return ['ok' => false, 'msg' => '票据与当前机器不匹配', 'payload' => $p];
        }
        if (!empty($p['k']) && self::digest($token) !== $p['k']) {
            return ['ok' => false, 'msg' => '票据与当前会话不匹配', 'payload' => $p];
        }
        $skew = max(0, (int) Config::get('grace.clock_skew', 120));
        if ($now > (int) $p['g'] + $skew) {
            return ['ok' => false, 'msg' => '离线宽限已到期', 'payload' => $p];
        }
        return ['ok' => true, 'msg' => 'ok', 'payload' => $p,
                'remain' => max(0, (int) $p['g'] - $now)];
    }

    // ==================================================================
    // 密钥管理
    // ==================================================================

    private static function keyFile(): string
    {
        $f = (string) Config::get('grace.key_file', '');
        if ($f === '') {
            $f = (defined('NB_ROOT') ? NB_ROOT : dirname(__DIR__)) . '/config/grace_keys.php';
        }
        return $f;
    }

    /** 确保密钥存在（首次调用时自动生成） */
    public static function ensureKeys(): bool
    {
        if (self::$keysTried) {
            return self::$keys !== null;
        }
        self::$keysTried = true;

        $file = self::keyFile();
        if (is_file($file)) {
            $data = @include $file;
            if (is_array($data) && !empty($data['private']) && !empty($data['public'])) {
                self::$keys = $data;
                return true;
            }
        }
        return self::generateKeys($file);
    }

    /** 重新生成一整套密钥（旧票据立即全部失效，慎用） */
    public static function rotateKeys(): array
    {
        $file = self::keyFile();
        if (is_file($file)) {
            @rename($file, $file . '.bak.' . date('YmdHis'));
        }
        self::$keys      = null;
        self::$keysTried = false;
        $ok = self::generateKeys($file);
        return ['ok' => $ok, 'kid' => self::$keys['kid'] ?? '', 'algo' => self::$keys['algo'] ?? ''];
    }

    private static function generateKeys(string $file): bool
    {
        if (!extension_loaded('openssl')) {
            self::warn('未安装 openssl 扩展，离线宽限期已自动关闭');
            return false;
        }
        $cnf = self::opensslConfig();

        // 优先 EC P-256（签名小、速度快）；某些环境不支持 EC 时回落 RSA-2048
        $candidates = [
            'ES256' => ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC],
            'RS256' => ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
        ];

        foreach ($candidates as $algo => $base) {
            $cfg = $base;
            if ($cnf !== null) {
                $cfg['config'] = $cnf;
            }
            $key = @openssl_pkey_new($cfg);
            if (!$key) {
                self::drainOpensslErrors();
                continue;
            }
            $pem = '';
            $exportOpt = ['encrypt_key' => false];
            if ($cnf !== null) {
                $exportOpt['config'] = $cnf;
            }
            if (!@openssl_pkey_export($key, $pem, null, $exportOpt) || $pem === '') {
                self::drainOpensslErrors();
                continue;
            }
            $detail = @openssl_pkey_get_details($key);
            if (!$detail || empty($detail['key'])) {
                self::drainOpensslErrors();
                continue;
            }

            $data = [
                'algo'       => $algo,
                'kid'        => bin2hex(random_bytes(4)),
                'private'    => $pem,
                'public'     => $detail['key'],
                'created_at' => time(),
            ];

            $php = "<?php\n"
                . "/**\n"
                . " * 离线宽限期签名密钥（自动生成，请勿手工修改）\n"
                . " * 删除本文件后下次请求会自动重新生成一套新密钥，\n"
                . " * 副作用是此前下发给客户端的离线票据全部失效（客户端会走联网校验）。\n"
                . " */\n"
                . "return " . var_export($data, true) . ";\n";

            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (@file_put_contents($file, $php, LOCK_EX) === false) {
                self::warn('密钥文件写入失败：' . $file . '（离线宽限期已关闭）');
                return false;
            }
            @chmod($file, 0600);
            self::$keys = $data;
            return true;
        }

        self::drainOpensslErrors();
        self::warn('无法生成签名密钥（EC/RSA 均失败），离线宽限期已关闭');
        return false;
    }

    /**
     * 定位 openssl.cnf。
     * phpStudy 等集成环境的 PHP 默认配置指向 C:\Program Files\Common Files\SSL\openssl.cnf，
     * 该文件往往不存在，会导致 openssl_pkey_new() 直接失败 —— 必须显式指定。
     */
    private static function opensslConfig(): ?string
    {
        $candidates = [
            (string) Config::get('grace.openssl_config', ''),
            (string) (getenv('OPENSSL_CONF') ?: ''),
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/extras/openssl/openssl.cnf',
            'C:/phpstudy_pro/Extensions/php/php8.0.2nts/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
        ];
        foreach ($candidates as $p) {
            // @：路径在 open_basedir 之外时 is_file() 会告警，
            // 而引导层错误处理器会把它升级为异常 —— 探测失败本应静默跳过。
            if ($p !== '' && @is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    private static function sign(string $body): ?string
    {
        $priv = self::$keys['private'] ?? '';
        if ($priv === '') {
            return null;
        }
        $key = @openssl_pkey_get_private($priv);
        if (!$key) {
            self::drainOpensslErrors();
            return null;
        }
        $sig = '';
        if (!@openssl_sign($body, $sig, $key, OPENSSL_ALGO_SHA256) || $sig === '') {
            self::drainOpensslErrors();
            return null;
        }
        return $sig;
    }

    private static function warn(string $msg): void
    {
        try {
            Logger::log('grace', 0, $msg);
        } catch (Throwable $e) {
            // 引导阶段 Logger 可能尚未初始化，忽略
        }
    }

    /** openssl 的错误队列必须显式读空，否则会污染后续调用的错误信息 */
    private static function drainOpensslErrors(): void
    {
        while (@openssl_error_string() !== false) {
        }
    }

    // ------------------------------------------------------------------
    // base64url
    // ------------------------------------------------------------------
    private static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): ?string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $v = base64_decode($s, true);
        return $v === false ? null : $v;
    }
}

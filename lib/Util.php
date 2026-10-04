<?php
/**
 * 通用工具
 */
class Util
{
    /**
     * 客户端真实 IP
     * ------------------------------------------------------------------
     * X-Forwarded-For / X-Real-IP / CF-Connecting-IP 都是【客户端可以自己伪造】的请求头。
     * 直接信任它们，等于把「限流维度、风控黑名单、审计日志来源、设备记录 IP」
     * 全部交给攻击者控制：伪造一个头就能绕过所有按 IP 的限流与封禁。
     *
     * 正确做法是「受信代理」模型：
     *   - 只有当直连来源 REMOTE_ADDR 命中 security.trusted_proxies 时，
     *     才认为请求经过了我们的反向代理/负载均衡，才去读 XFF 这类头；
     *   - 否则一律以 REMOTE_ADDR 为准。
     *
     * trusted_proxies 支持单 IP 与 CIDR（IPv4 / IPv6），例如：
     *   ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '::1']
     * 留空数组 = 没有任何反向代理，永不信任转发头（裸机部署的安全默认值）。
     */
    public static function ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!self::isTrustedProxy($remote)) {
            // 直连来源不可信（或未配置受信代理）：转发头一律忽略
            return $remote;
        }

        // 取自左向右第一个合法 IP（最接近真实客户端的一跳）
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
            if (empty($_SERVER[$k])) {
                continue;
            }
            foreach (explode(',', (string) $_SERVER[$k]) as $candidate) {
                $ip = trim($candidate);
                // 去掉 IPv6 映射前缀，如 ::ffff:203.0.113.5
                if (stripos($ip, '::ffff:') === 0) {
                    $ip = substr($ip, 7);
                }
                // 去掉可能的端口号（部分代理会带 :port）
                if (substr_count($ip, ':') === 1 && strpos($ip, '[') === false) {
                    $ip = explode(':', $ip)[0];
                }
                // 只接受公网/常规地址，且不能再是受信代理自身（避免取到内网跳板）
                if (filter_var($ip, FILTER_VALIDATE_IP)
                    && !self::isTrustedProxy($ip)) {
                    return $ip;
                }
            }
        }

        // 转发头全部缺失或不合法：回落到直连地址
        return $remote;
    }

    /**
     * 判断某个 IP 是否为受信代理来源
     * 支持精确匹配与 CIDR 网段（IPv4 / IPv6）
     */
    public static function isTrustedProxy(string $ip): bool
    {
        $list = (array) Config::get('security.trusted_proxies', []);
        if (!$list) {
            return false;
        }
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($list as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            // 网段写法 a.b.c.d/n  或  IPv6/n
            if (strpos($item, '/') !== false) {
                [$net, $bits] = explode('/', $item, 2);
                $bits = (int) $bits;
                $netBin = @inet_pton(trim($net));
                if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
                    continue;
                }
                $maxBits = strlen($netBin) * 8;
                if ($bits < 0 || $bits > $maxBits) {
                    continue;
                }
                if (self::cidrMatch($ipBin, $netBin, $bits)) {
                    return true;
                }
                continue;
            }

            // 单 IP 精确匹配
            $oneBin = @inet_pton($item);
            if ($oneBin !== false && hash_equals($oneBin, $ipBin)) {
                return true;
            }
        }
        return false;
    }

    /** 按位比较前缀是否落在网段内 */
    private static function cidrMatch(string $ipBin, string $netBin, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        $remBits   = $bits % 8;

        if ($fullBytes > 0
            && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remBits) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    /** 请求 UA */
    public static function ua(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    }

    /** 读取 JSON 请求体 */
    public static function input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return $_POST ?: [];
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        // 兼容 form 提交
        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    /** 取参数并做类型转换 */
    public static function get(array $arr, string $key, $default = null)
    {
        return array_key_exists($key, $arr) ? $arr[$key] : $default;
    }

    public static function int(array $arr, string $key, int $default = 0): int
    {
        // 非标量（数组/对象/null）一律回落默认值，防类型混淆触发未处理异常
        return isset($arr[$key]) && is_scalar($arr[$key]) ? (int) $arr[$key] : $default;
    }

    public static function str(array $arr, string $key, string $default = ''): string
    {
        return isset($arr[$key]) && is_scalar($arr[$key]) ? trim((string) $arr[$key]) : $default;
    }

    /**
     * 自然时间时长 → [到期时间戳, 审计描述]
     * 支持 unit：year/month/week/day/hour/minute/second（不合法回落 day）。
     * duration <= 0 → [0, '（永久）']；上限 100000 防溢出。
     */
    public static function durationExpire(int $duration, string $unit, ?int $now = null): array
    {
        $now = $now ?? time();
        $unitMap = ['year'   => ['years', '年'],   'month'  => ['months', '个月'],
                    'week'   => ['weeks', '周'],   'day'    => ['days', '天'],
                    'hour'   => ['hours', '小时'], 'minute' => ['minutes', '分钟'],
                    'second' => ['seconds', '秒']];
        if (!isset($unitMap[$unit])) {
            $unit = 'day';
        }
        if ($duration > 0) {
            $duration = min($duration, 100000);
            [$str, $name] = $unitMap[$unit];
            $expire = strtotime("+{$duration} {$str}", $now);
            if ($expire === false || $expire <= $now) {
                $expire = $now;
            }
            return [$expire, "（{$duration} {$name}）"];
        }
        return [0, '（永久）'];
    }

    /** 生成随机字符串 */
    public static function random(int $len = 16, string $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
    {
        $out = '';
        $max = strlen($charset) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $charset[random_int(0, $max)];
        }
        return $out;
    }

    /** 生成令牌 */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * 获取/生成当前会话的 CSRF 令牌（存 session）
     * 用于管理端写操作校验，防止跨站请求伪造。
     */
    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            // 后台会话 cookie 加固
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
            @session_start();
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = self::token(16);
        }
        return $_SESSION['_csrf'];
    }

    /** 校验 CSRF 令牌 */
    public static function csrfCheck(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $expect = $_SESSION['_csrf'] ?? '';
        return $expect !== '' && is_string($token) && hash_equals($expect, $token);
    }

    /** 密码哈希 */
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * 密码校验
     *
     * @param bool|null &$needsRehash 出参：校验通过但存储的哈希已过弱
     *                 （历史 md5 或 bcrypt 成本因子过低）时为 true，
     *                 调用方应把明文重新 hashPassword 后回写数据库，
     *                 实现「登录一次即自动升级」，老账号无需强制改密。
     */
    public static function verifyPassword(string $plain, string $hash, &$needsRehash = null): bool
    {
        if ($hash === '') {
            return false;
        }
        // 兼容历史 md5：老库不能直接把用户挡在门外，但 md5 无盐、可离线爆破，
        // 所以放行的同时标记 needsRehash，由登录入口立刻升级成 bcrypt。
        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            if (hash_equals($hash, md5($plain))) {
                $needsRehash = true;
                return true;
            }
            return false;
        }
        if (password_verify($plain, $hash)) {
            // 成本因子被调低过、或算法参数变更时，同样提示升级
            $needsRehash = password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 10]);
            return true;
        }
        return false;
    }

    /**
     * 密码强度检查 —— 设置 / 修改新密码时统一调用
     * ------------------------------------------------------------------
     * 返回 null 表示通过，否则返回可直接展示给用户的中文原因。
     *
     * 规则：长度 8-64；必须同时含字母与数字；拒绝常见弱口令与全同字符。
     * $adminLevel=true（管理员档）：长度 10-64，且必须额外包含大写字母或符号
     * —— 管理员账号是整个系统的单点，强度要求高于普通用户。
     *
     * 注意：只在「设置新密码」的链路上校验，登录链路不受影响 ——
     * 老账号哪怕密码很弱也照常登录，只是改密时必须换成符合强度的。
     */
    public static function passwordIssue(string $plain, bool $adminLevel = false): ?string
    {
        $len = strlen($plain);
        $minLen = $adminLevel ? 10 : 8;
        if ($len < $minLen) {
            return $adminLevel ? '管理员密码至少 10 位' : '密码至少 8 位';
        }
        if ($len > 64) {
            return '密码最长 64 位';
        }
        if (!preg_match('/[A-Za-z]/', $plain) || !preg_match('/[0-9]/', $plain)) {
            return '密码需同时包含字母和数字';
        }
        if ($adminLevel && !preg_match('/[A-Z]/', $plain) && !preg_match('/[^A-Za-z0-9]/', $plain)) {
            return '管理员密码需额外包含大写字母或符号';
        }
        $weak = [
            'admin888', 'admin123', 'administrator', 'password', 'passw0rd',
            '12345678', '123456789', '1234567890', '87654321', '11111111',
            'qwerty123', 'qwertyuiop', 'abcd1234', 'a1234567', 'abc12345',
            '1qaz2wsx', 'iloveyou', 'woaini1314', 'aa123456', 'qq123456',
            'zxcvbnm1', 'asdfghj1', 'aa111111', '123456a', 'a123456',
        ];
        if (in_array(strtolower($plain), $weak, true)) {
            return '密码过于简单，请更换';
        }
        // 全同字符（aaaaaaaa / 11111111）或全重复的简单模式
        if (preg_match('/^(.)\1+$/s', $plain)) {
            return '密码过于简单，请更换';
        }
        return null;
    }

    /** 格式化时间戳 */
    public static function date(int $ts): string
    {
        return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '-';
    }

    /** 秒 -> 可读时长 */
    public static function duration(int $sec): string
    {
        if ($sec <= 0) {
            return '永久';
        }
        $d = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);
        $m = intdiv($sec % 3600, 60);
        $parts = [];
        if ($d) $parts[] = "{$d}天";
        if ($h) $parts[] = "{$h}小时";
        if ($m && !$d) $parts[] = "{$m}分";
        return $parts ? implode('', $parts) : '不足1分';
    }

    /** 版本号比较：$a > $b 返回 1 */
    public static function versionCompare(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $a)));
        $pb = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $b)));
        $len = max(count($pa), count($pb));
        for ($i = 0; $i < $len; $i++) {
            $va = $pa[$i] ?? 0;
            $vb = $pb[$i] ?? 0;
            if ($va > $vb) return 1;
            if ($va < $vb) return -1;
        }
        return 0;
    }

    /** 脱敏卡密，如 AB12-****-CD34 */
    public static function maskCard(string $code): string
    {
        $len = strlen($code);
        if ($len <= 8) {
            return substr($code, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($code, -2);
        }
        return substr($code, 0, 4) . str_repeat('*', $len - 8) . substr($code, -4);
    }

    /**
     * IP 打码（返回给客户端/日志展示用，避免完整 IP 外泄）
     *   IPv4  192.168.1.100 → 192.168.1.*
     *   IPv6  2001:db8::1   → 2001:db8::*
     */
    public static function maskIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        if (strpos($ip, ':') !== false) {
            $pos = strrpos($ip, ':');
            return substr($ip, 0, $pos) . ':*';
        }
        $pos = strrpos($ip, '.');
        return $pos === false ? '*' : substr($ip, 0, $pos) . '.*';
    }

    /** 生成卡密，格式 XXXX-XXXX-XXXX-XXXX */
    public static function cardCode(string $prefix = '', int $segments = 4, int $segLen = 4): string
    {
        $parts = [];
        if ($prefix !== '') {
            $parts[] = strtoupper($prefix);
        }
        for ($i = 0; $i < $segments; $i++) {
            $parts[] = self::random($segLen);
        }
        return implode('-', $parts);
    }

    /** 数组白名单过滤 */
    public static function pick(array $src, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $src)) {
                $out[$k] = $src[$k];
            }
        }
        return $out;
    }
}

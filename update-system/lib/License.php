<?php
/**
 * 授权码体系 - 核心逻辑
 * ==================================================================
 * 源码分发产品的授权锚点：
 *   1. 签发授权码（后台批量生成）
 *   2. 激活 = 授权码绑定部署站域名（一码一域）
 *   3. 验签 = 激活态校验 + 有效期校验（安装时 & 更新下发时调用）
 *   4. 下载令牌 = HMAC 限时签名链接（盗版更新锁死的咬合点）
 *
 * 设计要点：
 *   - 域名归一化：取 host、去端口、去 www. 前缀、转小写 —— 用户填
 *     https://WWW.Example.com/ 与 example.com 视为同一站点
 *   - 一码一域：已绑定的码不能换绑（换绑走后台管理员操作，防一码多用）
 *   - 下载令牌密钥：vu_settings.license_secret，首次使用时自动生成
 */

class License
{
    /** 下载令牌有效期（秒） */
    const TOKEN_TTL = 600;

    /** 每 IP 激活/验签轻限流：窗口内最大次数 */
    const THROTTLE_MAX = 30;
    const THROTTLE_WINDOW = 60;

    // ------------------------------------------------------------------
    // 域名归一化
    // ------------------------------------------------------------------
    public static function normalizeDomain(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        // 允许传入完整 URL，取 host 部分
        if (strpos($raw, '://') !== false) {
            $host = (string) parse_url($raw, PHP_URL_HOST);
        } else {
            // 只接受标准主机名字符；含 @ / 空格 / 下划线等一律不当作 URL（避免 user@host 之类歧义归一化）
            $host = preg_match('#^[A-Za-z0-9.\-]+(:\d+)?(/|$)#', $raw) ? (string) parse_url('http://' . $raw, PHP_URL_HOST) : $raw;
        }
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^www\./', '', $host);
        // 只允许合法 host 字符
        if (!preg_match('/^[a-z0-9\-]+(\.[a-z0-9\-]+)+$/', $host)) return '';
        return $host;
    }

    /** 当前请求的归一化域名 */
    public static function currentDomain(): string
    {
        return self::normalizeDomain($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    }

    // ------------------------------------------------------------------
    // 签发
    // ------------------------------------------------------------------
    public static function generateKey(): string
    {
        try { $raw = random_bytes(16); } catch (\Exception $e) { $raw = hash('sha256', uniqid('', true), true); }
        return bin2hex($raw); // CHAR(32)
    }

    // ------------------------------------------------------------------
    // 激活：绑定域名（一码一域，绑定后不可自助换绑）
    // ------------------------------------------------------------------
    public static function activate(string $key, string $domainRaw): array
    {
        $key = strtolower(trim($key));
        $domain = self::normalizeDomain($domainRaw);
        if ($key === '' || $domain === '') {
            return ['ok' => false, 'msg' => '授权码或域名不合法'];
        }
        $lic = DB::one('SELECT * FROM ' . DB::t('licenses') . ' WHERE license_key = ?', [$key]);
        if (!$lic) {
            return ['ok' => false, 'msg' => '授权码不存在'];
        }
        if ((int) $lic['status'] !== 1) {
            return ['ok' => false, 'msg' => '授权码已被停用'];
        }
        if ($lic['expires_at'] > 0 && (int) $lic['expires_at'] < time()) {
            return ['ok' => false, 'msg' => '授权码已过期'];
        }
        $bound = (string) $lic['domain'];
        if ($bound !== '' && $bound !== $domain) {
            // 不回显已绑定域名，避免未认证调用者凭授权码反查绑定域名
            return ['ok' => false, 'msg' => '授权码已绑定其他域名，如需换绑请联系管理员'];
        }
        if ($bound === '') {
            // 一码一域：绑定与判定合并为一条条件 UPDATE，以影响行数决定成败。
            // 旧实现「先 SELECT 判空、再无条件 UPDATE」在并发下可被两个不同域名
            // 同时绑定 / 相互覆盖（TOCTOU），这里只有真正把空 domain 改掉的那一个成功。
            $n = DB::exec(
                'UPDATE ' . DB::t('licenses') . "
                 SET domain = ?, activated_at = ?
                 WHERE id = ? AND (domain = '' OR domain IS NULL)",
                [$domain, time(), (int) $lic['id']]
            );
            if ($n !== 1) {
                // 未抢到绑定：回读当前域名，判断是否恰好是自己（同域并发，视为幂等成功）
                $cur = (string) DB::value(
                    'SELECT domain FROM ' . DB::t('licenses') . ' WHERE id = ?',
                    [(int) $lic['id']]
                );
                if ($cur !== $domain) {
                    return ['ok' => false, 'msg' => '授权码已绑定其他域名，如需换绑请联系管理员'];
                }
            }
        }
        self::touch((int) $lic['id']);
        return ['ok' => true, 'msg' => '激活成功', 'domain' => $domain, 'plan' => (string) $lic['plan']];
    }

    // ------------------------------------------------------------------
    // 验签：安装器 & 更新下发共用
    // ------------------------------------------------------------------
    public static function verify(string $key, string $domainRaw): array
    {
        $key = strtolower(trim($key));
        $domain = self::normalizeDomain($domainRaw);
        if ($key === '' || $domain === '') {
            return ['ok' => false, 'msg' => '授权码或域名不合法'];
        }
        $lic = DB::one('SELECT * FROM ' . DB::t('licenses') . ' WHERE license_key = ?', [$key]);
        if (!$lic) return ['ok' => false, 'msg' => '授权码不存在'];
        if ((int) $lic['status'] !== 1) return ['ok' => false, 'msg' => '授权码已被停用'];
        if ((string) $lic['domain'] !== $domain) return ['ok' => false, 'msg' => '授权码与当前域名不匹配'];
        if ($lic['expires_at'] > 0 && (int) $lic['expires_at'] < time()) return ['ok' => false, 'msg' => '授权已过期'];
        self::touch((int) $lic['id']);
        return ['ok' => true, 'msg' => '验签通过', 'plan' => (string) $lic['plan'], 'expires_at' => (int) $lic['expires_at']];
    }

    private static function touch(int $id): void
    {
        try {
            DB::exec('UPDATE ' . DB::t('licenses') . ' SET last_check_at = ?, last_ip = ? WHERE id = ?',
                [time(), substr(self::clientIp(), 0, 64), $id]);
        } catch (\Throwable $e) { /* 验签主流程不受日志失败影响 */ }
    }

    // ------------------------------------------------------------------
    // 轻限流（文件计数，避免接口被刷爆数据库）
    // ------------------------------------------------------------------
    public static function throttle(): bool
    {
        return self::hitCounter(
            sys_get_temp_dir() . '/vu_lic_' . md5(self::clientIp()) . '.cnt',
            self::THROTTLE_WINDOW,
            self::THROTTLE_MAX
        );
    }

    /**
     * 原子文件计数：窗口内自增并判断是否超限。
     * ------------------------------------------------------------------
     * 用 flock 把「读-改-写」整体串行化（旧实现只在写时加 LOCK_EX，
     * 读取与写入之间可被并发穿插，导致少计、限流被绕过）。
     * 计数失败（无法加锁等）时返回 true 放行，不因限流组件异常阻断业务。
     */
    public static function hitCounter(string $file, int $window, int $limit): bool
    {
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return true;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return true;
            }
            $now  = time();
            $base = $now;
            $cnt  = 0;
            $raw  = stream_get_contents($fp);
            if ($raw !== false && $raw !== '') {
                $d    = explode('|', $raw);
                $base = (int) ($d[0] ?? $now);
                $cnt  = (int) ($d[1] ?? 0);
                if ($now - $base > $window) { $base = $now; $cnt = 0; }
            }
            $cnt++;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $base . '|' . $cnt);
            fflush($fp);
            flock($fp, LOCK_UN);
            return $cnt <= $limit;
        } finally {
            fclose($fp);
        }
    }

    /**
     * 读取「窗口内已计数」值（不递增），供展示/预判使用。
     */
    public static function counterValue(string $file, int $window): int
    {
        if (!is_file($file)) {
            return 0;
        }
        $raw = (string) @file_get_contents($file);
        if ($raw === '') {
            return 0;
        }
        $d = explode('|', $raw);
        $base = (int) ($d[0] ?? 0);
        $cnt  = (int) ($d[1] ?? 0);
        return (time() - $base > $window) ? 0 : $cnt;
    }

    // ------------------------------------------------------------------
    // 下载令牌：HMAC(secret, version|e|domain) 限时签名
    // ------------------------------------------------------------------
    public static function makeDownloadToken(string $version, string $domain): string
    {
        $e = time() + self::TOKEN_TTL;
        $sig = hash_hmac('sha256', self::tokenPayload($version, $e, $domain), self::secret());
        return 'e=' . $e . '&s=' . $sig . '&d=' . bin2hex($domain);
    }

    /**
     * 下载令牌载荷：把请求方 IP 一并纳入签名。
     * 令牌本是「拿到 URL 即可下载」的 bearer link；绑定请求方 IP 后，
     * 即使链接泄漏（日志/Referer/截图）也无法从其它来源复用。
     * version.php 与 download.php 均由部署站的服务器出网 curl 调用，
     * 同一台机器 IP 一致，故绑定 IP 不影响正常更新链路。
     */
    private static function tokenPayload(string $version, int $e, string $domain): string
    {
        return $version . '|' . $e . '|' . $domain . '|' . self::clientIp();
    }

    public static function checkDownloadToken(string $version, array $params): bool
    {
        $e   = (int) ($params['e'] ?? 0);
        $sig = (string) ($params['s'] ?? '');
        $d   = (string) ($params['d'] ?? '');
        $domain = self::normalizeDomain(@pack('H*', preg_replace('/[^0-9a-f]/i', '', $d)) ?: '');
        if ($domain === '' || $e < time() || $e > time() + self::TOKEN_TTL + 60) return false;
        return hash_equals(hash_hmac('sha256', self::tokenPayload($version, $e, $domain), self::secret()), $sig);
    }

    /** 令牌签名密钥（首次自动生成并存 vu_settings） */
    public static function secret(): string
    {
        $t = DB::t('settings');
        $row = DB::one("SELECT svalue FROM {$t} WHERE skey = 'license_secret'");
        if ($row && (string) $row['svalue'] !== '') return (string) $row['svalue'];
        $sec = bin2hex(random_bytes(32));
        try {
            DB::exec("INSERT INTO {$t} (skey, svalue, remark, updated_at) VALUES ('license_secret', ?, '下载令牌签名密钥', UNIX_TIMESTAMP())
                      ON DUPLICATE KEY UPDATE updated_at = updated_at", [$sec]);
        } catch (\Throwable $e) { /* 并发时以先写入者为准 */ }
        // 必须回读库中真实值：并发首用时未写入的一方若直接返回自己生成的 $sec，
        // 会用「库中不存在的密钥」签发令牌，导致下载验签间歇性失败。
        try {
            $row = DB::one("SELECT svalue FROM {$t} WHERE skey = 'license_secret'");
            if ($row && (string) $row['svalue'] !== '') return (string) $row['svalue'];
        } catch (\Throwable $e) { /* 回读失败则退回本次生成值 */ }
        return $sec;
    }

    // ------------------------------------------------------------------
    // 客户端真实 IP（可信代理感知）
    // ------------------------------------------------------------------
    /**
     * 取调用方真实 IP。
     * REMOTE_ADDR 在 CDN / 反向代理（Cloudflare、Nginx 反代等）后是代理 IP，
     * 直接用它做「按 IP 限领」会导致全站共用一个计数桶：正常用户被误伤，
     * 攻击者又不受限。这里仅在「直连方属于可信代理」时才信任转发头，
     * 可信代理列表由 config/config.php 的 license_trusted_proxies 配置（支持 CIDR）。
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        // 可信来源：显式配置的代理网段，或直连方本身就是私网/回环地址
        // （同机 Nginx 反代时 REMOTE_ADDR 为 127.0.0.1，公网攻击者无法伪造）。
        $trusted = (array) (($GLOBALS['config'] ?? [])['license_trusted_proxies'] ?? []);
        $isLocalPeer = filter_var($remote, FILTER_VALIDATE_IP) !== false
            && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if (!$isLocalPeer && !self::ipInList($remote, $trusted)) {
            return $remote; // 直连公网即真实来源，不信任任何转发头
        }
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
            if (empty($_SERVER[$k])) continue;
            foreach (explode(',', (string) $_SERVER[$k]) as $seg) {
                $ip = trim($seg);
                if (stripos($ip, '::ffff:') === 0) $ip = substr($ip, 7);
                if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) continue;
                if (self::ipInList($ip, $trusted)) continue; // 跳过链上的中间代理
                return $ip;
            }
        }
        return $remote;
    }

    /** IP 是否命中列表（精确或 CIDR） */
    private static function ipInList(string $ip, array $list): bool
    {
        foreach ($list as $item) {
            $item = trim((string) $item);
            if ($item === '') continue;
            if (strpos($item, '/') === false) {
                if ($item === $ip) return true;
                continue;
            }
            [$net, $bits] = explode('/', $item, 2);
            $bits = (int) $bits;
            $ipBin  = @inet_pton($ip);
            $netBin = @inet_pton($net);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) continue;
            $whole = intdiv($bits, 8);
            $rem   = $bits % 8;
            if ($whole > 0 && substr($ipBin, 0, $whole) !== substr($netBin, 0, $whole)) continue;
            if ($rem > 0) {
                $mask = 0xff << (8 - $rem) & 0xff;
                if ((ord($ipBin[$whole]) & $mask) !== (ord($netBin[$whole]) & $mask)) continue;
            }
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // 设置读取
    // ------------------------------------------------------------------
    public static function settingInt(string $key, int $default): int
    {
        try {
            $row = DB::one('SELECT svalue FROM ' . DB::t('settings') . ' WHERE skey = ?', [$key]);
            if (!$row) return $default;
            return (int) $row['svalue'];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** 该域名是否已存在授权（用于试用「一域一次」判定） */
    public static function domainTaken(string $domain): bool
    {
        if ($domain === '') return false;
        try {
            $row = DB::one('SELECT id FROM ' . DB::t('licenses') . ' WHERE domain = ? LIMIT 1', [$domain]);
            return $row !== null;
        } catch (\Throwable $e) {
            return true; // 查询异常时从严拒绝，避免绕过
        }
    }

    // ------------------------------------------------------------------
    // 人机验证：无状态签名挑战 + 工作量证明（hashcash 风格）
    // ------------------------------------------------------------------
    /**
     * 下发一道挑战。前端需找到一个整数 x，使 sha256(nonce|x) 的前
     * difficulty 个十六进制字符为 0；挑战本身用服务端密钥签名，前端无法伪造。
     * 属主流「无感验证」做法（ALTCHA / hashcash），无需第三方验证码服务。
     */
    public static function issueChallenge(): array
    {
        $difficulty = self::settingInt('trial_pow_difficulty', 4);
        if ($difficulty < 1) $difficulty = 1;
        if ($difficulty > 6) $difficulty = 6;
        $nonce = bin2hex(random_bytes(12));
        $ts    = time();
        $sig   = hash_hmac('sha256', $nonce . '|' . $ts . '|' . $difficulty, self::secret());
        return ['nonce' => $nonce, 'ts' => $ts, 'difficulty' => $difficulty, 'sig' => $sig];
    }

    /** 校验挑战签名、时效与工作量证明结果 */
    public static function verifyChallenge($nonce, $ts, $difficulty, $x, $sig): bool
    {
        $nonce = (string) $nonce; $sig = (string) $sig; $x = (string) $x;
        $ts = (int) $ts; $difficulty = (int) $difficulty;
        if ($nonce === '' || strlen($nonce) !== 24 || $sig === '' || $x === '' || !ctype_digit($x) || strlen($x) > 12) {
            return false;
        }
        if ($difficulty < 1 || $difficulty > 6) return false;
        if ($ts < time() - 300 || $ts > time() + 60) return false; // 5 分钟内有效
        if (!hash_equals(hash_hmac('sha256', $nonce . '|' . $ts . '|' . $difficulty, self::secret()), $sig)) {
            return false;
        }
        $h = hash('sha256', $nonce . '|' . $x);
        return strpos($h, str_repeat('0', $difficulty)) === 0;
    }

    // ------------------------------------------------------------------
    // 门禁开关（vu_settings.license_gate）
    // ------------------------------------------------------------------
    /**
     * 门禁粘性标志：一旦观测到门禁开启即落盘 storage/license_gate.on。
     * 作用：数据库/授权表故障导致门禁状态无法读取时，version.php 据此
     * fail-closed（拒绝下发下载地址），而不是静默退回公开下载 —— 否则
     * "开启更新授权门禁" 在存储故障期间形同虚设。
     */
    public static function gateFlagPath(): string
    {
        return dirname(__DIR__) . '/storage/license_gate.on';
    }

    public static function gateEnabled(): bool
    {
        $flag = self::gateFlagPath();
        try {
            $row = DB::one("SELECT svalue FROM " . DB::t('settings') . " WHERE skey = 'license_gate'");
            $on = $row !== null && trim((string) $row['svalue']) === '1';
            if ($on) {
                @file_put_contents($flag, '1');
            } else {
                @unlink($flag);
            }
            return $on;
        } catch (\Throwable $e) {
            // 门禁状态未知：若粘性标志显示曾开启，抛出让调用方 fail-closed；
            // 确认从未开启过才按关闭处理（兼容存量部署）
            if (is_file($flag)) {
                throw new \RuntimeException('license gate state unknown (sticky flag on)');
            }
            return false;
        }
    }
}

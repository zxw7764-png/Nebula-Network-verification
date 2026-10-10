<?php
/**
 * 管理后台认证
 * ==================================================================
 * 独立系统自带的后台认证，不依赖外部项目。
 * 负责：入口令牌、登录、会话管理、CSRF、防爆破、权限控制。
 *
 * 安全模型（纵深防御）：
 *   1. 入口令牌（admin_entry_key）—— 后台所有页面与 handler 必须先通过
 *      entryGuard()：首次凭 ?entry=<key> / X-Entry-Key 头换取 HttpOnly
 *      Cookie，之后凭 Cookie 通行；无令牌一律返回 404（伪装不存在）。
 *   2. 账号级防爆破 —— 连续失败 N 次锁定账号（vu_admins.lock_until）。
 *   3. IP 级防爆破 —— 同一 IP 独立于账号计数，超限锁 IP（vu_login_throttle）。
 *   4. 会话绑定 —— 会话令牌绑定客户端 UA 哈希，Cookie 被盗后换环境无效。
 *   5. CSRF —— 写操作强制携带会话级 CSRF 令牌，登录表单单独防 CSRF。
 */

class AdminAuth
{
    /** 会话 Cookie 名 */
    const SESSION_COOKIE = 'vu_admin_sid';

    /** 入口令牌 Cookie 名 */
    const ENTRY_COOKIE = 'vu_entry';

    /** 会话有效期（秒） */
    const SESSION_TTL = 7200;

    /** 入口 Cookie 有效期（秒，30 天，换 key 即全体失效） */
    const ENTRY_TTL = 2592000;

    /** IP 防爆破：窗口内最大失败次数 */
    const IP_FAIL_THRESHOLD = 20;

    /** IP 防爆破：计数窗口（秒） */
    const IP_FAIL_WINDOW = 600;

    /** IP 防爆破：锁定时长（秒） */
    const IP_LOCK_SECONDS = 900;

    /** @var array|null 配置缓存 */
    private static $cfgCache = null;

    // ==================================================================
    // 入口令牌
    // ==================================================================

    /**
     * 后台入口守卫：所有后台页面与 handler 的第一道关卡。
     * - 未配置 admin_entry_key 时放行（如全新未安装环境）
     * - 凭 ?entry= / X-Entry-Key / POST entry 换取入口 Cookie
     * - 均不通过 → 404（伪装页面不存在，不暴露后台位置）
     */
    public static function entryGuard(): void
    {
        $key = self::entryKey();
        if ($key === '') return;

        // 已持有合法入口 Cookie
        $cookie = $_COOKIE[self::ENTRY_COOKIE] ?? '';
        if (is_string($cookie) && $cookie !== ''
            && hash_equals(self::entryCookieValue($key), $cookie)) {
            return;
        }

        // 本次请求携带令牌：URL ?entry= > Header X-Entry-Key > POST/JSON entry
        $given = '';
        if (isset($_GET['entry']) && is_string($_GET['entry'])) {
            $given = $_GET['entry'];
        } elseif (isset($_SERVER['HTTP_X_ENTRY_KEY']) && is_string($_SERVER['HTTP_X_ENTRY_KEY'])) {
            $given = $_SERVER['HTTP_X_ENTRY_KEY'];
        } else {
            $input = self::input();
            if (isset($input['entry']) && is_string($input['entry'])) {
                $given = $input['entry'];
            }
        }

        if ($given !== '' && hash_equals($key, $given)) {
            // 令牌正确 → 下发入口 Cookie，后续访问无需再带 key
            setcookie(self::ENTRY_COOKIE, self::entryCookieValue($key), [
                'expires'  => time() + self::ENTRY_TTL,
                'path'     => '/',
                'secure'   => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            return;
        }

        self::deny404();
    }

    /** 入口 Cookie 期望值（由 key 派生，泄露 Cookie 无法反推 key，换 key 即失效） */
    private static function entryCookieValue(string $key): string
    {
        return hash_hmac('sha256', $key, 'nebula-update-system|entry-guard');
    }

    /** 读取 config 中的入口令牌 */
    public static function entryKey(): string
    {
        $cfg = self::cfg();
        return is_string($cfg['admin_entry_key'] ?? null) ? trim($cfg['admin_entry_key']) : '';
    }

    /** 伪装 404 响应 */
    private static function deny404(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">'
           . '<html><head><title>404 Not Found</title></head>'
           . '<body><h1>Not Found</h1><p>The requested URL was not found on this server.</p>'
           . '<hr><address>' . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Server') . '</address>'
           . '</body></html>';
        exit;
    }

    // ==================================================================
    // 登录 / 会话
    // ==================================================================

    /**
     * 管理员登录
     *
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function login(string $username, string $password): array
    {
        $cfg      = self::cfg();
        $thSnap   = $cfg['admin'] ?? [];
        $threshold = max(3, (int) ($thSnap['login_fail_threshold'] ?? 5));
        $lockSecs  = max(60, (int) ($thSnap['login_lock_seconds'] ?? 900));

        $ip = self::ip();

        // ---- IP 级防爆破：锁定中直接拒绝（不泄露账号是否存在） ----
        $ipLockLeft = self::throttleCheck($ip);
        if ($ipLockLeft > 0) {
            return ['ok' => false, 'code' => 2004,
                    'msg' => '尝试过于频繁，请 ' . ceil($ipLockLeft / 60) . ' 分钟后重试', 'data' => null];
        }

        $admin = DB::one(
            'SELECT * FROM ' . DB::t('admins') . ' WHERE username = ?',
            [$username]
        );

        if (!$admin) {
            self::throttleFail($ip);
            return ['ok' => false, 'code' => 2001, 'msg' => '账号或密码错误', 'data' => null];
        }

        if ((int) $admin['lock_until'] > time()) {
            $left = (int) $admin['lock_until'] - time();
            return ['ok' => false, 'code' => 2003, 'msg' => '账号已锁定，请 ' . ceil($left / 60) . ' 分钟后重试', 'data' => null];
        }

        if ((int) $admin['status'] !== 1) {
            return ['ok' => false, 'code' => 2002, 'msg' => '账号已被禁用', 'data' => null];
        }

        if (!password_verify($password, (string) $admin['password'])) {
            self::throttleFail($ip);

            // 账号失败计数原子自增
            DB::exec(
                'UPDATE ' . DB::t('admins') . ' SET login_fail_cnt = login_fail_cnt + 1 WHERE id = ?',
                [$admin['id']]
            );
            $fail = (int) DB::value(
                'SELECT login_fail_cnt FROM ' . DB::t('admins') . ' WHERE id = ?',
                [$admin['id']]
            );
            if ($fail >= $threshold) {
                DB::exec(
                    'UPDATE ' . DB::t('admins') . ' SET lock_until = ? WHERE id = ?',
                    [time() + $lockSecs, $admin['id']]
                );
            }
            return ['ok' => false, 'code' => 2001, 'msg' => '账号或密码错误', 'data' => null];
        }

        // 登录成功
        self::ensureUaColumn();

        $token   = bin2hex(random_bytes(32));
        $csrf    = bin2hex(random_bytes(16));
        $expires = time() + self::sessionTtl();

        // 删除旧会话，创建新会话（绑定 UA 哈希与 IP）
        DB::exec('DELETE FROM ' . DB::t('admin_sessions') . ' WHERE admin_id = ?', [$admin['id']]);
        DB::insert('admin_sessions', [
            'admin_id'  => (int) $admin['id'],
            'token'     => $token,
            'csrf'      => $csrf,
            'ip'        => $ip,
            'ua_hash'   => self::uaHash(),
            'expires'   => $expires,
            'created'   => time(),
        ]);

        // 重置失败计数，清除该 IP 的爆破计数
        DB::exec(
            'UPDATE ' . DB::t('admins') . ' SET login_fail_cnt = 0, lock_until = 0, last_login_ip = ?, last_login_at = ? WHERE id = ?',
            [$ip, time(), $admin['id']]
        );
        self::throttleClear($ip);

        // 设置 Cookie
        self::setCookie($token);

        return [
            'ok'   => true,
            'code' => 0,
            'msg'  => '登录成功',
            'data' => [
                'token' => $token,
                'csrf'  => $csrf,
                'admin' => [
                    'id'       => (int) $admin['id'],
                    'username' => $admin['username'],
                    'nickname' => $admin['nickname'] ?? $admin['username'],
                    'role'     => (int) $admin['role'],
                ],
            ],
        ];
    }

    /**
     * 校验当前请求是否已登录
     * 会话令牌同时校验 UA 绑定：环境变化（UA 不同）视为无效会话。
     *
     * @return array|null 管理员信息数组或 null（未登录）
     */
    public static function check(): ?array
    {
        $token = self::getToken();
        if ($token === '') return null;

        self::ensureUaColumn();

        $session = DB::one(
            'SELECT s.*, a.username, a.nickname, a.role, a.status
             FROM ' . DB::t('admin_sessions') . ' s
             JOIN ' . DB::t('admins') . ' a ON a.id = s.admin_id
             WHERE s.token = ? AND s.expires > ?',
            [$token, time()]
        );

        if (!$session) return null;
        if ((int) $session['status'] !== 1) return null;

        // UA 绑定校验：客户端环境变了 → 会话作废
        if ((string) ($session['ua_hash'] ?? '') !== self::uaHash()) {
            DB::exec('DELETE FROM ' . DB::t('admin_sessions') . ' WHERE token = ?', [$token]);
            return null;
        }

        return [
            'id'       => (int) $session['admin_id'],
            'username' => $session['username'],
            'nickname' => $session['nickname'] ?? $session['username'],
            'role'     => (int) $session['role'],
            'token'    => $token,
            'csrf'     => $session['csrf'],
        ];
    }

    /**
     * 登出
     */
    public static function logout(): void
    {
        $token = self::getToken();
        if ($token !== '') {
            DB::exec('DELETE FROM ' . DB::t('admin_sessions') . ' WHERE token = ?', [$token]);
        }
        self::clearCookie();
    }

    /**
     * CSRF 校验
     */
    public static function checkCsrf(string $csrf): bool
    {
        $admin = self::check();
        if (!$admin) return false;
        return hash_equals($admin['csrf'], $csrf);
    }

    /**
     * 验证密码
     */
    public static function verifyPassword(int $adminId, string $password): bool
    {
        $hash = DB::value('SELECT password FROM ' . DB::t('admins') . ' WHERE id = ?', [$adminId]);
        return $hash !== null && password_verify($password, (string) $hash);
    }

    // ==================================================================
    // IP 防爆破（vu_login_throttle）
    // ==================================================================

    /** 检查 IP 是否被锁，返回剩余锁定秒数（0 = 未锁） */
    private static function throttleCheck(string $ip): int
    {
        self::ensureThrottleTable();
        try {
            $lockUntil = (int) DB::value(
                'SELECT lock_until FROM ' . DB::t('login_throttle') . ' WHERE ip = ?',
                [$ip]
            );
        } catch (Throwable $e) {
            return 0;
        }
        return max(0, $lockUntil - time());
    }

    /** 记录一次失败：窗口内累计，超限锁 IP */
    private static function throttleFail(string $ip): void
    {
        self::ensureThrottleTable();
        $now = time();
        try {
            $row = DB::one('SELECT fail_cnt, last_try FROM ' . DB::t('login_throttle') . ' WHERE ip = ?', [$ip]);
            if ($row && (int) $row['last_try'] < $now - self::IP_FAIL_WINDOW) {
                // 窗口过期，重新计数
                DB::exec(
                    'UPDATE ' . DB::t('login_throttle') . ' SET fail_cnt = 1, lock_until = 0, last_try = ? WHERE ip = ?',
                    [$now, $ip]
                );
                return;
            }
            if ($row) {
                $cnt = (int) $row['fail_cnt'] + 1;
            } else {
                $cnt = 1;
                DB::exec('INSERT INTO ' . DB::t('login_throttle') . ' (ip, fail_cnt, lock_until, last_try) VALUES (?, 0, 0, ?)', [$ip, $now]);
            }
            if ($cnt >= self::IP_FAIL_THRESHOLD) {
                DB::exec(
                    'UPDATE ' . DB::t('login_throttle') . ' SET fail_cnt = ?, lock_until = ?, last_try = ? WHERE ip = ?',
                    [$cnt, $now + self::IP_LOCK_SECONDS, $now, $ip]
                );
            } else {
                DB::exec(
                    'UPDATE ' . DB::t('login_throttle') . ' SET fail_cnt = ?, last_try = ? WHERE ip = ?',
                    [$cnt, $now, $ip]
                );
            }
        } catch (Throwable $e) {
            // 防爆破表异常不阻断登录主流程
        }
    }

    /** 登录成功后清除该 IP 计数 */
    private static function throttleClear(string $ip): void
    {
        try {
            DB::exec('DELETE FROM ' . DB::t('login_throttle') . ' WHERE ip = ?', [$ip]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private static function ensureThrottleTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('login_throttle') . " (
                ip         VARCHAR(64)  NOT NULL,
                fail_cnt   INT UNSIGNED NOT NULL DEFAULT 0,
                lock_until INT UNSIGNED NOT NULL DEFAULT 0,
                last_try   INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (ip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            // ignore
        }
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /** 幂等升级：admin_sessions 补 ua_hash 列（旧库自动迁移） */
    private static function ensureUaColumn(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::exec('ALTER TABLE ' . DB::t('admin_sessions') . ' ADD COLUMN ua_hash CHAR(64) NOT NULL DEFAULT \'\' AFTER ip');
        } catch (Throwable $e) {
            // 列已存在等情况，忽略
        }
    }

    /** 客户端 UA 哈希（会话绑定用） */
    private static function uaHash(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    private static function sessionTtl(): int
    {
        $cfg = self::cfg();
        return max(600, (int) ($cfg['admin']['session_ttl'] ?? self::SESSION_TTL));
    }

    /** 读取应用配置（带缓存） */
    private static function cfg(): array
    {
        if (self::$cfgCache !== null) return self::$cfgCache;
        $file = APP_ROOT . '/config/config.php';
        $cfg = is_file($file) ? (require $file) : [];
        self::$cfgCache = is_array($cfg) ? $cfg : [];
        return self::$cfgCache;
    }

    private static function getToken(): string
    {
        // 优先从 Cookie 读取
        $cookie = $_COOKIE[self::SESSION_COOKIE] ?? '';
        if (is_string($cookie) && $cookie !== '') return $cookie;

        // 回退到 Header / Body
        $token = $_SERVER['HTTP_X_TOKEN'] ?? '';
        if (is_string($token) && $token !== '') return $token;

        $input = self::input();
        $token = $input['token'] ?? '';
        return is_string($token) ? $token : '';
    }

    private static function setCookie(string $token): void
    {
        setcookie(self::SESSION_COOKIE, $token, [
            'expires'  => time() + self::sessionTtl(),
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function clearCookie(): void
    {
        setcookie(self::SESSION_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function input(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        $cached = is_array($json) ? $json : $_POST;
        return $cached;
    }
}

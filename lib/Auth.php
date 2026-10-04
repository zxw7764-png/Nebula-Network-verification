<?php
/**
 * 账号认证业务
 */
class Auth
{
    /**
     * 注册
     * @param ?array $sw 上下文软件（客户端 API / 官网当前软件）；
     *                    分软件登录方式为「激活码直登」时同样关闭注册
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function register(array $in, string $ip, ?array $sw = null): array
    {
        // 注册开关分软件：该软件单独关闭时（policy_json.register_enable = 0）拒绝注册
        if (!Policy::registerEnableFor($sw)) {
            return ['ok' => false, 'code' => 1004, 'msg' => '当前未开放注册', 'data' => null];
        }

        // 「激活码直登」方式下没有账号体系：注册出来的账号没有可用的登录密码，
        // 只会产生一堆用不了的僵尸账号，直接关掉（分软件设置优先于全局）。
        if (LoginMethod::currentFor($sw) === LoginMethod::CODE) {
            return ['ok' => false, 'code' => 1004, 'msg' => '当前仅支持激活码登录，无需注册', 'data' => null];
        }

        $username = trim((string) Util::get($in, 'username', ''));
        $password = (string) Util::get($in, 'password', '');
        $email    = trim((string) Util::get($in, 'email', ''));

        // 参数校验
        if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]{3,32}$/u', $username)) {
            return ['ok' => false, 'code' => 1001, 'msg' => '用户名需 3-32 位字母、数字、下划线或中文', 'data' => null];
        }
        if (($pwIssue = Util::passwordIssue($password)) !== null) {
            return ['ok' => false, 'code' => 1001, 'msg' => $pwIssue, 'data' => null];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'code' => 1001, 'msg' => '邮箱格式不正确', 'data' => null];
        }

        // 重名检查
        if (Database::value('SELECT id FROM ' . Database::t('users') . ' WHERE username = ?', [$username])) {
            return ['ok' => false, 'code' => 1001, 'msg' => '用户名已存在', 'data' => null];
        }

        $now = time();
        $uid = Database::insert('users', [
            'username'       => $username,
            'password'       => Util::hashPassword($password),
            'email'          => $email ?: null,
            'nickname'       => $username,
            'status'         => 1,
            'group_id'       => 1,
            'software_id'    => Software::currentId(),
            'vip_expire'     => 0,
            'points'         => 0,
            'max_devices'    => Policy::defaultMaxDevices($sw),
            'register_ip'    => $ip,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return [
            'ok'   => true,
            'code' => 0,
            'msg'  => '注册成功',
            'data' => ['user_id' => $uid, 'username' => $username],
        ];
    }

    /**
     * 登录
     * @return array{ok:bool, code:int, msg:string, user:?array}
     */
    public static function login(string $username, string $password): array
    {
        $user = Database::one(
            'SELECT * FROM ' . Database::t('users') . ' WHERE username = ?',
            [$username]
        );

        if (!$user) {
            return ['ok' => false, 'code' => 2001, 'msg' => '用户名或密码错误', 'user' => null];
        }

        // 锁定检查
        if ((int) $user['lock_until'] > time()) {
            $left = (int) $user['lock_until'] - time();
            return ['ok' => false, 'code' => 2003, 'msg' => '账号已锁定，请 ' . ceil($left / 60) . ' 分钟后再试', 'user' => $user];
        }

        // 多软件隔离：客户端 API 上下文中，账号绑定了其他软件则拒绝登录
        if (Software::currentId() > 0 && (int) ($user['software_id'] ?? 0) > 0
            && (int) $user['software_id'] !== Software::currentId()) {
            return ['ok' => false, 'code' => 2005, 'msg' => '账号不属于当前软件', 'user' => null];
        }

        // 密码校验
        $nbRehash = false;
        if (!Util::verifyPassword($password, (string) $user['password'], $nbRehash)) {
            self::onLoginFail((int) $user['id'], (int) $user['login_fail_cnt']);
            return ['ok' => false, 'code' => 2001, 'msg' => '用户名或密码错误', 'user' => $user];
        }
        if ($nbRehash) {
            // 库里存的是历史弱哈希（无盐 md5 等）—— 既然这次明文已验证通过，
            // 顺手升级为 bcrypt，老账号不必强制改密也能逐步洗掉弱哈希。
            try {
                Database::update('users', ['password' => Util::hashPassword($password)],
                    'id = :id', ['id' => (int) $user['id']]);
            } catch (Throwable $e) {
                // 升级失败不影响本次登录
            }
        }

        // 状态检查
        if ((int) $user['status'] === 0) {
            return ['ok' => false, 'code' => 2002, 'msg' => '账号已被封禁', 'user' => $user];
        }
        if ((int) $user['status'] === 2) {
            return ['ok' => false, 'code' => 2002, 'msg' => '账号已被冻结', 'user' => $user];
        }

        // 登录成功，重置失败计数
        Database::update('users', [
            'login_fail_cnt'  => 0,
            'lock_until'      => 0,
            'last_login_ip'   => Util::ip(),
            'last_login_time' => time(),
            'updated_at'      => time(),
        ], 'id = :id', ['id' => $user['id']]);

        return ['ok' => true, 'code' => 0, 'msg' => '登录成功', 'user' => $user];
    }

    /**
     * 按后台配置的登录方式统一分发
     * ------------------------------------------------------------------
     * 客户端不会「一次塞全部字段让服务端猜」：这里只按当前配置的方式
     * 取对应字段，字段不全直接报错，报错文案会写明该方式需要的字段。
     *
     * @return array{ok:bool, code:int, msg:string, user:?array, created:bool}
     */
    public static function loginBy(array $in, ?string $method = null): array
    {
        $method = $method ?? LoginMethod::current();

        switch ($method) {
            case LoginMethod::PASSWORD:
                $username = trim((string) Util::get($in, 'username', ''));
                $password = (string) Util::get($in, 'password', '');
                if ($username === '' || $password === '') {
                    return self::fail(1001, '请输入用户名和密码');
                }
                $r = self::login($username, $password);
                $r['created'] = false;
                return $r;

            case LoginMethod::USERNAME_CODE:
                $username = trim((string) Util::get($in, 'username', ''));
                $code     = strtoupper(trim((string) Util::get($in, 'code', '')));
                if ($username === '' || $code === '') {
                    return self::fail(1001, '请输入用户名和激活码');
                }
                return self::loginByCard($code, $username);

            case LoginMethod::CODE:
                $code = strtoupper(trim((string) Util::get($in, 'code', '')));
                if ($code === '') {
                    return self::fail(1001, '请输入激活码');
                }
                return self::loginByCard($code, '');

            default:
                return self::fail(1001, '登录方式配置错误，请联系管理员');
        }
    }

    /**
     * 卡密登录
     * ------------------------------------------------------------------
     * 卡密已绑定账号 → 直接登录该账号（$username 非空时必须与其一致）
     * 卡密未绑定     → 建号并激活后登录
     *                  $username 为空（纯卡密方式）自动生成用户名；
     *                  $username 非空（用户名+激活码方式）使用它，
     *                  但若该用户名已被占用则拒绝 —— 否则任何持卡人都能
     *                  借未绑定的卡密登录进别人的账号（账号接管）。
     *
     * @return array{ok:bool, code:int, msg:string, user:?array, created:bool}
     */
    private static function loginByCard(string $code, string $username): array
    {
        $card = self::cardByCode($code);
        if (!$card) {
            // 卡密已被管理端删除 → 回退到用户行的卡密快照（card_code），
            // 让已激活过该卡的用户仍能凭原激活码登录（找回账号）。
            // 快照由 Card::activate 在激活时写入，删卡不影响。
            $snapUser = Database::one(
                'SELECT * FROM ' . Database::t('users') . ' WHERE card_code = ? ORDER BY id ASC LIMIT 1',
                [$code]
            );
            if (!$snapUser) {
                return self::fail(3001, '激活码不存在');
            }
            // 多软件隔离：快照账号只在本软件可登录
            $curSw = Software::currentId();
            if ($curSw > 0 && (int) ($snapUser['software_id'] ?? 0) > 0
                && (int) $snapUser['software_id'] !== $curSw) {
                return self::fail(3008, '激活码不属于当前软件');
            }
            // 用户名+激活码方式：用户名对不上按绑定冲突处理（防借码接管）
            if ($username !== '' && strcasecmp($username, (string) $snapUser['username']) !== 0) {
                return self::fail(3006, '该激活码已绑定其他账号');
            }
            $err = self::accountStatusError($snapUser);
            if ($err) {
                return $err;
            }
            return self::ok(self::touchLogin($snapUser), false);
        }

        // 多软件隔离：激活码必须属于当前软件
        $cardSw  = (int) ($card['software_id'] ?? 0);
        $curSw   = Software::currentId();
        if ($curSw > 0 && $cardSw > 0 && $cardSw !== $curSw) {
            return self::fail(3008, '激活码不属于当前软件');
        }

        $err = self::cardUsableError($card);
        if ($err) {
            return $err;
        }

        $boundUid = (int) $card['used_by'];
        $isUsed   = (int) $card['status'] === Card::STATUS_USED;

        // ---------- 已绑定：直接登录该账号 ----------
        if ($isUsed && $boundUid > 0) {
            $user = self::userById($boundUid);
            if ($user) {
                if ($username !== '' && strcasecmp($username, (string) $user['username']) !== 0) {
                    return self::fail(3006, '该激活码已绑定其他账号');
                }
                // 账号与激活码软件不一致 → 拒绝（1 软件的账号不能登录 2 软件）
                if ((int) ($user['software_id'] ?? 0) > 0 && $cardSw > 0
                    && (int) $user['software_id'] !== $cardSw) {
                    return self::fail(3008, '激活码不属于当前软件');
                }
                $err = self::accountStatusError($user);
                if ($err) {
                    return $err;
                }
                return self::ok(self::touchLogin($user), false);
            }
            // 绑定账号已被删除 → 视为未绑定，落到下面的建号流程
        }

        // ---------- 未绑定 ----------
        if ($username !== '') {
            // 未绑定的卡不允许挂到已存在的账号上（防接管）
            if (self::userByUsername($username)) {
                return self::fail(3007, '该用户名已被注册，请更换用户名');
            }
        } else {
            $username = self::suggestUsername($code);
        }

        if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]{3,32}$/u', $username)) {
            return self::fail(1001, '用户名需 3-32 位字母、数字、下划线或中文');
        }
        if (self::userByUsername($username)) {
            return self::fail(3007, '该用户名已被注册，请更换用户名');
        }

        // 先建号，再用 Card::activate 复用「时长/点数/永久/用户组/设备上限」全套逻辑。
        // 注意：Card::activate 内部自带事务，这里不能再套一层（PDO 不支持嵌套事务），
        // 因此改为「建号 → 激活 → 失败则补偿删号」。
        $now = time();
        try {
            $uid = Database::insert('users', [
                'username'    => $username,
                'password'    => Util::hashPassword(Util::token(24)), // 随机密码，卡密即凭证
                'email'       => null,
                'nickname'    => $username,
                'status'      => 1,
                'group_id'    => 1,
                'software_id' => $cardSw > 0 ? $cardSw : Software::currentId(),
                'vip_expire'  => 0,
                'points'      => 0,
                'max_devices' => (int) Config::get('policy.default_max_devices', 1),
                'register_ip' => Util::ip(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        } catch (Throwable $e) {
            // 不回显数据库异常细节（含 SQLSTATE / 表名 / 字段名），只进服务端日志
            @error_log('[nebula] auto create user failed: ' . $e->getMessage());
            return self::fail(9999, Config::get('debug')
                ? '账号创建失败：' . $e->getMessage()
                : '账号创建失败，请稍后再试');
        }

        $newUser = self::userById($uid);
        $act = Card::activate(['code' => $card['code']], $newUser);

        if (!$act['ok']) {
            // 补偿：激活失败（如并发下卡密刚被他人用掉）→ 回滚建号
            try {
                Database::exec('DELETE FROM ' . Database::t('users') . ' WHERE id = ?', [$uid]);
            } catch (Throwable $e) {
                // ignore
            }
            return self::fail($act['code'], $act['msg']);
        }

        $fresh = self::userById($uid);
        $err = self::accountStatusError($fresh);
        if ($err) {
            return $err;
        }

        return self::ok(self::touchLogin($fresh), true);
    }

    /** 卡密可用性校验，返回错误数组或 null */
    private static function cardUsableError(array $card): ?array
    {
        if ((int) $card['status'] === Card::STATUS_VOID) {
            return self::fail(3003, '激活码已作废');
        }
        if ((int) $card['expire_at'] > 0 && (int) $card['expire_at'] < time()) {
            return self::fail(3004, '激活码已过期');
        }
        return null;
    }

    /** 账号状态校验（封禁/冻结/锁定），返回错误数组或 null */
    private static function accountStatusError(array $user): ?array
    {
        if ((int) $user['lock_until'] > time()) {
            $left = (int) $user['lock_until'] - time();
            return self::fail(2003, '账号已锁定，请 ' . ceil($left / 60) . ' 分钟后再试');
        }
        if ((int) $user['status'] === 0) {
            if (self::autoUnbanIfExpired($user)) {
                return null;   // 限时封禁已到期，自动解封后放行
            }
            return self::fail(2002, self::bannedText($user));
        }
        if ((int) $user['status'] === 2) {
            return self::fail(2002, '账号已被冻结');
        }
        return null;
    }

    /** 限时封禁到期自动解封：status=0 且 ban_expire 已过 → 恢复正常并返回 true */
    public static function autoUnbanIfExpired(array $user): bool
    {
        if ((int) $user['status'] !== 0) {
            return false;
        }
        $expire = (int) ($user['ban_expire'] ?? 0);
        if ($expire <= 0 || $expire > time()) {
            return false;
        }
        Database::update('users', [
            'status'     => 1,
            'ban_expire' => 0,
            'updated_at' => time(),
        ], 'id = :id AND status = 0', ['id' => (int) $user['id']]);
        return true;
    }

    /** 封禁提示文案（限时封禁附带到期时间） */
    public static function bannedText(array $user): string
    {
        $expire = (int) ($user['ban_expire'] ?? 0);
        return $expire > 0
            ? '账号已被封禁，至 ' . date('Y-m-d H:i:s', $expire)
            : '账号已被封禁';
    }

    /** 登录成功后的统一收尾：清失败计数、记录登录时间/IP，返回最新行 */
    private static function touchLogin(array $user): array
    {
        Database::update('users', [
            'login_fail_cnt'  => 0,
            'lock_until'      => 0,
            'last_login_ip'   => Util::ip(),
            'last_login_time' => time(),
            'updated_at'      => time(),
        ], 'id = :id', ['id' => (int) $user['id']]);

        return self::userById((int) $user['id']) ?? $user;
    }

    /**
     * 为激活码直登生成随机用户名。
     * 刻意不使用卡密原文 —— 避免把激活码明文暴露在用户名/排行/评论等
     * 展示位（卡密就是登录凭证，泄露即被盗号）。格式：U + 7 位随机大写字母数字。
     */
    private static function suggestUsername(string $code): string
    {
        $base = 'U' . Util::random(7);
        if (strlen($base) < 6) {
            $base = 'U' . Util::random(8);
        }

        $name = $base;
        for ($i = 1; $i <= 999; $i++) {
            if (!self::userByUsername($name)) {
                return $name;
            }
            $name = 'U' . Util::random(7) . ($i > 1 ? $i : '');
        }
        return 'U' . Util::random(10);
    }

    private static function cardByCode(string $code): ?array
    {
        return Database::one(
            'SELECT * FROM ' . Database::t('cards') . ' WHERE code = ?',
            [$code]
        );
    }

    private static function userById(int $id): ?array
    {
        return Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$id]);
    }

    private static function userByUsername(string $username): ?array
    {
        return Database::one(
            'SELECT * FROM ' . Database::t('users') . ' WHERE username = ?',
            [$username]
        );
    }

    private static function fail(int $code, string $msg): array
    {
        return ['ok' => false, 'code' => $code, 'msg' => $msg, 'user' => null, 'created' => false];
    }

    private static function ok(array $user, bool $created): array
    {
        return ['ok' => true, 'code' => 0, 'msg' => '登录成功', 'user' => $user, 'created' => $created];
    }

    private static function onLoginFail(int $userId, int $currentFail): void
    {
        $fail     = $currentFail + 1;
        $threshold = (int) Config::get('policy.login_fail_threshold', 5);
        $lockSec  = (int) Config::get('policy.login_lock_seconds', 900);

        $data = ['login_fail_cnt' => $fail];
        if ($fail >= $threshold) {
            $data['lock_until'] = time() + $lockSec;
            $data['login_fail_cnt'] = 0;
        }
        Database::update('users', $data, 'id = :id', ['id' => $userId]);
    }

    /**
     * 会员有效性检查
     * @return array{valid:bool, code:int, msg:string, expire_at:int, points:int}
     */
    public static function checkVip(array $user): array
    {
        $expire = (int) $user['vip_expire'];
        $points = (int) $user['points'];

        // 未激活：看是否有试用
        if ($expire === 0 && $points <= 0) {
            $trial = (int) Config::get('policy.trial_seconds', 0);
            if ($trial > 0) {
                return ['valid' => true, 'code' => 0, 'msg' => '试用中', 'expire_at' => 0, 'points' => 0];
            }
            // 曾有卡密快照 = 点数/次数已用完；否则是真未激活
            $usedUp = trim((string) ($user['card_code'] ?? '')) !== '';
            return ['valid' => false, 'code' => 2004, 'msg' => $usedUp ? '次数/点数已用完，请重新充值' : '账号未激活，请使用激活码', 'expire_at' => 0, 'points' => 0];
        }

        // 永久卡（expire = -1 或极大值）
        if ($expire === -1) {
            return ['valid' => true, 'code' => 0, 'msg' => '永久会员', 'expire_at' => -1, 'points' => $points];
        }

        // 点数卡：有余额即有效
        if ($points > 0) {
            return ['valid' => true, 'code' => 0, 'msg' => '点数账户', 'expire_at' => $expire, 'points' => $points];
        }

        // 时长卡：检查是否过期
        if ($expire > 0 && $expire < time()) {
            return ['valid' => false, 'code' => 2004, 'msg' => '账号已过期', 'expire_at' => $expire, 'points' => 0];
        }

        return ['valid' => true, 'code' => 0, 'msg' => 'ok', 'expire_at' => $expire, 'points' => $points];
    }

    /** 获取用户组信息 */
    public static function group(int $groupId): ?array
    {
        return Database::one('SELECT * FROM ' . Database::t('groups') . ' WHERE id = ?', [$groupId]);
    }

    /**
     * 有效最大设备数 = max(用户自身设置, 所在用户组设置)
     * ------------------------------------------------------------------
     * 为什么是「取较大值」而不是「用户优先」：
     *   nb_users.max_devices 是 NOT NULL DEFAULT 1，注册即被写成 1（卡密激活时还会提升），
     *   永远不可能为 0。旧实现写成「$own > 0 就用 $own，否则回落到用户组」，
     *   导致用户组分支成了永远走不到的死代码 —— 后台把「高级用户组」设成 3 台也毫无效果。
     * 现在组作为「保底额度」生效：组只能把额度往上提，不会把已有用户（或卡密提额）降下来。
     */
    public static function maxDevices(array $user): int
    {
        $own = (int) $user['max_devices'];

        $groupId = (int) ($user['group_id'] ?? 0);
        $group   = $groupId > 0 ? self::group($groupId) : null;
        $fromGroup = $group ? (int) $group['max_devices'] : 0;

        $effective = max($own, $fromGroup);

        return $effective > 0
            ? $effective
            : (int) Config::get('policy.default_max_devices', 1);
    }

    /** 输出给客户端的用户信息（脱敏） */
    public static function publicInfo(array $user): array
    {
        return [
            'user_id'     => (int) $user['id'],
            'username'    => $user['username'],
            'nickname'    => $user['nickname'] ?: $user['username'],
            'vip_expire'  => (int) $user['vip_expire'],
            'vip_text'    => (int) $user['vip_expire'] === -1 ? '永久' : Util::date((int) $user['vip_expire']),
            'points'      => (int) $user['points'],
            'max_devices' => self::maxDevices($user),
            'status'      => (int) $user['status'],
            'group_id'    => (int) $user['group_id'],
        ];
    }
}

<?php
/**
 * Nebula 3.1 会话握手（ECDH P-256 + AES-256-GCM）
 * ----------------------------------------------------------------------------
 * 目标：客户端不再持有任何跨会话有效的对称机密。
 *   · 客户端每次进程启动生成临时 EC 密钥对，handshake 明文上报公钥；
 *   · 服务端同样生成临时密钥对，openssl_pkey_derive 算共享密钥，
 *     HKDF 派生 sk_enc / sk_mac，落库 nb_hsessions（会话级、过期即废）；
 *   · 之后业务请求改走 3.1 信封：
 *       请求  { sid, seq, t, data, mac, app_key }
 *         data = base64( iv[12] + AES-256-GCM(业务JSON) + tag[16] )
 *         iv   = iv_prefix(4) || seq 大端8字节   —— seq 严格递增，同方向内 IV 永不重复
 *         mac  = hex(HMAC-SHA256(sk_mac, sid|seq|t|sha256(data)))
 *       响应  { data(GCM), sid, sig(ES256), sig_kid, sig_algo, code }
 *         sig  = 服务端长期 ES256 私钥对 data|sid 签名（防伪造服务器，复用 RespSign）
 *
 * 方向密钥分离（2026-10-03 审计修复）：
 *   请求加密用 sk_enc；响应加密用 sk_enc_rsp = HKDF-SHA256(sk_enc, info="nebula31-enc-rsp")。
 *   同一 seq 下请求/响应的 (key, iv) 组合不再相同，消除 GCM nonce 跨方向重用。
 *
 * 协议安全性质：
 *   · 堆扫描只能拿到当次会话临时密钥（会话结束/过期即废）；
 *   · 握手响应带服务端长期私钥签名（客户端 pin 公钥验签），MITM 无法替换 eph_pub_S；
 *   · seq 单调递增 + 服务端原子 UPDATE 防重放；
 *   · GCM 认证加密（ AEAD），请求响应均无填充预言机问题。
 *
 * 3.0 静态密钥体系已于 2026-10-02 全面下线：请求带 sid 字段即走本类（3.1）。
 */

class Handshake
{
    /** 当前请求的 3.1 会话（openRequest 成功后设置，buildResponse 使用） */
    private static ?array $current = null;

    /** 会话空闲过期时间（秒）：6 小时内没有任何请求即作废 */
    const SESSION_TTL = 21600;

    /** 握手时间戳容忍窗口（秒）：客户端已用 ts_s 校准过时钟，60s 足够 */
    const TIME_WINDOW = 60;

    /** 频控：同 IP 每分钟最多握手次数 */
    const RATE_PER_MIN = 10;

    public static function active(): bool
    {
        return self::$current !== null;
    }

    /** 当前会话 id（响应体回带用） */
    public static function sessionId(): string
    {
        return self::$current['sid'] ?? '';
    }

    // ------------------------------------------------------------------
    // openssl EC 环境兼容（PHP 8.0 / phpStudy 无 openssl.cnf 时必须显式指定）
    // ------------------------------------------------------------------
    private static function opensslConfig(): ?string
    {
        // 注意：is_file 可能触发 open_basedir 限制（serv00 等受限主机把 warning 转
        // 异常会导致握手 9999），因此全部用 @ 抑制，找不到就当没有配置文件。
        foreach ([
            (getenv('OPENSSL_CONF') ?: ''),
            PHP_BINARY !== '' ? dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf' : '',
        ] as $c) {
            if ($c !== '' && @is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    private static function ecConfig(): array
    {
        $cfg = [
            'ec' => [
                'curve_name'       => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
            ],
        ];
        if (($cnf = self::opensslConfig()) !== null) {
            $cfg['config'] = $cnf;
        }
        return $cfg;
    }

    /** 65 字节非压缩点 → SPKI DER（P-256 固定 26 字节算法头 + 点） */
    private static function pointToSpki(string $point): string
    {
        return hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    }

    /** 65 字节非压缩点 → PEM 公钥（openssl_pkey_derive 需要） */
    private static function pointToPem(string $point): ?string
    {
        $der = self::pointToSpki($point);
        if ($der === false || strlen($der) !== 91) {
            return null;
        }
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    // ------------------------------------------------------------------
    // 纯 PHP P-256 ECDH 回退（BCMath；仅在 openssl EC 不可用时使用）
    // ------------------------------------------------------------------

    const P256_P = '115792089210356248762697446949407573530086143415290314195533631308867097853951';
    const P256_N = '115792089210356248762697446949407573529996955224135760342422259061068512044369';
    const P256_GX = '48439561293906451759052585252797914202762949526041747995844080717082404635286';
    const P256_GY = '36134250956749795798585127919587881956611106672985015071877198253568414405109';

    /** mod P（含负数处理） */
    private static function modP(string $x): string
    {
        $r = bcmod($x, self::P256_P);
        return bccomp($r, '0') < 0 ? bcadd($r, self::P256_P) : $r;
    }

    /** 模逆（费马小定理：a^(p-2) mod p） */
    private static function invP(string $a): string
    {
        return bcpowmod(self::modP($a), bcsub(self::P256_P, '2'), self::P256_P);
    }

    /** Jacobian 坐标点加（X1,Y1,Z1 + X2,Y2,Z2）；无穷远点 = Z1==0 */
    private static function jAdd(array $p1, array $p2): array
    {
        if ((int) $p1['z'] === 0) return $p2;
        if ((int) $p2['z'] === 0) return $p1;
        $z1z1 = self::modP(bcmul($p1['z'], $p1['z']));
        $z2z2 = self::modP(bcmul($p2['z'], $p2['z']));
        $u1 = self::modP(bcmul($p1['x'], $z2z2));
        $u2 = self::modP(bcmul($p2['x'], $z1z1));
        $s1 = self::modP(bcmul($p1['y'], bcmul($z2z2, $p2['z'])));
        $s2 = self::modP(bcmul($p2['y'], bcmul($z1z1, $p1['z'])));
        if (bccomp($u1, $u2) === 0) {
            return bccomp($s1, $s2) === 0 ? self::jDouble($p1) : ['x' => '0', 'y' => '1', 'z' => '0'];
        }
        $h  = self::modP(bcsub($u2, $u1));
        $r  = self::modP(bcsub($s2, $s1));
        $hh = self::modP(bcmul($h, $h));
        $h3 = self::modP(bcmul($hh, $h));
        $u1h2 = self::modP(bcmul($u1, $hh));
        $x3 = self::modP(bcsub(bcsub(bcmul($r, $r), $h3), bcmul('2', $u1h2)));
        $y3 = self::modP(bcsub(bcmul($r, bcsub($u1h2, $x3)), bcmul($s1, $h3)));
        $z3 = self::modP(bcmul(bcmul($p1['z'], $p2['z']), $h));
        return ['x' => $x3, 'y' => $y3, 'z' => $z3];
    }

    /** Jacobian 倍点（a = -3 专用公式） */
    private static function jDouble(array $p): array
    {
        if ((int) $p['z'] === 0 || bccomp($p['y'], '0') === 0) {
            return ['x' => '0', 'y' => '1', 'z' => '0'];
        }
        $yy = self::modP(bcmul($p['y'], $p['y']));
        $s  = self::modP(bcmul('4', bcmul($p['x'], $yy)));
        $zz = self::modP(bcmul($p['z'], $p['z']));
        $m  = self::modP(bcsub(bcmul('3', bcmul($p['x'], $p['x'])), bcmul('3', bcmul($zz, $zz))));
        $x3 = self::modP(bcsub(bcmul($m, $m), bcmul('2', $s)));
        $y3 = self::modP(bcsub(bcmul($m, bcsub($s, $x3)), bcmul('8', bcmul($yy, $yy))));
        $z3 = self::modP(bcmul('2', bcmul($p['y'], $p['z'])));
        return ['x' => $x3, 'y' => $y3, 'z' => $z3];
    }

    /**
     * 标量乘（Jacobian double-and-add，k 为十进制字符串）。
     * 返回仿射坐标（内部只做一次模逆）；无穷远点返回 null。
     */
    private static function scalarMul(string $k, array $p): ?array
    {
        $r = ['x' => '0', 'y' => '1', 'z' => '0'];   // 无穷远
        $add = ['x' => $p['x'], 'y' => $p['y'], 'z' => '1'];
        while (bccomp($k, '0') > 0) {
            if (bccomp(bcmod($k, '2'), '1') === 0) {
                $r = self::jAdd($r, $add);
            }
            $add = self::jDouble($add);
            $k = bcdiv($k, '2', 0);
        }
        if ((int) $r['z'] === 0) return null;
        $zinv = self::invP($r['z']);
        $zinv2 = self::modP(bcmul($zinv, $zinv));
        return ['x' => self::modP(bcmul($r['x'], $zinv2)),
                'y' => self::modP(bcmul($r['y'], bcmul($zinv2, $zinv)))];
    }

    /** 32 字节大端二进制 → 十进制 BC 字符串 */
    private static function binToBc(string $bin): string
    {
        $hex = bin2hex($bin);
        $dec = '0';
        for ($i = 0, $n = strlen($hex); $i < $n; ++$i) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($hex[$i]));
        }
        return $dec;
    }

    /** 十进制 BC 字符串 → 定长 32 字节大端二进制 */
    private static function bcToBin32(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0') > 0) {
            $r   = (int) bcmod($dec, '16');
            $hex = dechex($r) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        // 补齐到偶数位（避免 dechex 单字符导致 hex2bin 报错），再左填充到 64 位 hex
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        return str_pad(hex2bin($hex) ?: '', 32, "\0", STR_PAD_LEFT);
    }

    /**
     * 纯 PHP ECDH：privateKey = 随机 d，publicPoint = d·G，
     * shared = (d·peerPoint).x。同时把服务端公钥点写入 $serverPointOut。
     * 返回 32 字节共享密钥；客户端公钥不在曲线上返回 false。
     */
    private static function purePhpEcdh(string $clientPoint65, ?string &$serverPointOut)
    {
        if (strlen($clientPoint65) !== 65 || $clientPoint65[0] !== "\x04") {
            return false;
        }
        // 客户端公钥点必须严格在曲线上（y² = x³ - 3x + b），否则可被无效曲线攻击
        $x = self::binToBc(substr($clientPoint65, 1, 32));
        $y = self::binToBc(substr($clientPoint65, 33, 32));
        $b = '41058363725152142129326129780047268409114441015993725554835256314039467401291';
        $lhs = self::modP(bcmul($y, $y));
        $rhs = self::modP(bcadd(bcadd(bcmul($x, bcmul($x, $x)), bcmul('-3', $x)), $b));   // x³ - 3x + b
        if (bccomp($lhs, $rhs) !== 0) {
            return false;
        }

        // 随机私钥 d ∈ [1, n-1]
        $d = '';
        do {
            $d = self::binToBc(random_bytes(32));
        } while (bccomp($d, '1') < 0 || bccomp($d, self::P256_N) >= 0);

        // 服务端公钥 = d·G
        $g = ['x' => self::P256_GX, 'y' => self::P256_GY];
        $sp = self::scalarMul($d, $g);
        if ($sp === null) return false;
        $serverPointOut = "\x04" . self::bcToBin32($sp['x']) . self::bcToBin32($sp['y']);

        // 共享密钥 = (d·Peer).x
        $shared = self::scalarMul($d, ['x' => $x, 'y' => $y]);
        return $shared === null ? false : self::bcToBin32($shared['x']);
    }

    // ------------------------------------------------------------------
    // 握手：创建会话
    // ------------------------------------------------------------------

    /**
     * 处理 action=handshake（明文 JSON）。
     * 返回给 handler 的响应数据（明文输出，Response::setEncrypt(false)）。
     * 失败直接 Response::error 退出。
     */
    public static function create(array $input): array
    {
        $ephPub = (string) ($input['eph_pub'] ?? '');
        $nc     = (string) ($input['nc'] ?? '');
        $ts     = (int) ($input['ts'] ?? 0);
        $mhash  = preg_replace('/[^a-f0-9]/', '', (string) ($input['mhash'] ?? ''));

        // 频控：握手是最容易被滥用的端点（无认证），单独限一道
        if (!RateLimit::byIp('hs31', self::RATE_PER_MIN)) {
            Response::error(5001, '握手过于频繁，请稍后再试');
        }

        // 时间戳窗口
        if ($ts <= 0 || abs(time() - $ts) > self::TIME_WINDOW * 5) {
            Response::error(5003, '握手时间戳超出允许范围');
        }

        // 客户端临时公钥：65 字节非压缩点（0x04 || X || Y）
        $point = base64_decode($ephPub, true);
        if ($point === false || strlen($point) !== 65 || $point[0] !== "\x04") {
            Response::error(1001, 'eph_pub 无效（需要 65 字节非压缩 P-256 点）');
        }
        $ncRaw = base64_decode($nc, true);
        if ($ncRaw === false || strlen($ncRaw) !== 16) {
            Response::error(1001, 'nc 无效（需要 16 字节随机数）');
        }

        // 服务端临时密钥对 + ECDH 共享密钥
        $serverPoint = '';
        $shared      = false;
        $serverPriv  = openssl_pkey_new(self::ecConfig());
        if ($serverPriv !== false) {
            $detail = openssl_pkey_get_details($serverPriv);
            if (isset($detail['ec']['x'], $detail['ec']['y'])) {
                $serverPoint = "\x04" . $detail['ec']['x'] . $detail['ec']['y'];
                // ECDH 共享密钥（X 坐标 32 字节，大端）
                $peerPem = self::pointToPem($point);
                if ($peerPem !== null) {
                    $peer   = openssl_pkey_get_public($peerPem);
                    $shared = $peer === false ? false : @openssl_pkey_derive($serverPriv, $peer, 32);
                }
            }
        }
        if ($shared === false || strlen($shared) !== 32) {
            // 回退：纯 PHP P-256 标量乘（phpStudy PHP 8.0.2 生成的 EC 密钥内部缺私钥组件，
            // openssl_pkey_derive 必然失败 —— 已知构建问题，Linux/生产环境走不到这里）
            $shared = self::purePhpEcdh($point, $serverPoint);
            if ($shared === false) {
                Logger::log('handshake', 0, 'ECDH derive failed: ' . openssl_error_string());
                Response::error(1001, 'ECDH 协商失败（公钥不在曲线上？）');
            }
        }

        // HKDF 派生会话密钥（域分离）
        $ns     = random_bytes(16);
        $salt   = $ncRaw . $ns;
        $skEnc  = hash_hkdf('sha256', $shared, 32, 'nebula31-enc', $salt);
        $skMac  = hash_hkdf('sha256', $shared, 32, 'nebula31-mac', $salt);

        // 会话落库；顺带清理过期会话
        $now      = time();
        $swId     = Software::currentId();
        $sid      = bin2hex(random_bytes(16));
        // GCM IV 前 4 字节由双方从握手材料独立派生（sha256(nc‖ns)[0..4)），
        // 客户端无需服务端下发，IV 后 8 字节 = seq 大端 —— 全局唯一性由 seq 单调保证
        $ivPrefix = substr(hash('sha256', $ncRaw . $ns, true), 0, 4);
        try {
            Database::exec(
                'DELETE FROM ' . Database::t('hsessions') . ' WHERE expire_at > 0 AND expire_at < ?',
                [$now]
            );
            // 同一机器哈希只保留最新一把（防堆积）
            if ($mhash !== '') {
                Database::exec(
                    'DELETE FROM ' . Database::t('hsessions') . ' WHERE mhash = ? AND software_id = ?',
                    [$mhash, $swId]
                );
            }
            Database::exec(
                'INSERT INTO ' . Database::t('hsessions') . '
                 (sid, software_id, mhash, sk_enc, sk_mac, iv_prefix, seq, created_at, expire_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
                [$sid, $swId, $mhash, $skEnc, $skMac, $ivPrefix, $now, $now + self::SESSION_TTL]
            );
        } catch (Throwable $e) {
            Logger::log('handshake', 0, 'session store failed: ' . $e->getMessage());
            Response::error(9999, '会话创建失败');
        }

        // 服务端 ES256 签名（防伪造服务器：MITM 无法替换 eph_pub_S）
        // 签名对象 = sid|eph_pub_S|ns|nc|ts_s —— 绑定双方 nonce，响应不可重放/不可篡改
        $tsS    = time();
        $sEphB  = base64_encode($serverPoint);
        $signed = $sid . '|' . $sEphB . '|' . base64_encode($ns) . '|' . $nc . '|' . $tsS;
        $sig    = RespSign::signMessage($signed);
        if ($sig === null || $sig === '') {
            Response::error(9999, '握手签名失败');
        }

        return [
            'proto'    => 31,
            'sid'      => $sid,
            'eph_pub'  => $sEphB,
            'ns'       => base64_encode($ns),
            'ts_s'     => $tsS,
            'sign'     => base64_encode($sig),
            'sig_kid'  => RespSign::keyId(),
            'sig_algo' => RespSign::algorithm(),
        ];
    }

    // ------------------------------------------------------------------
    // 3.1 业务请求解析
    // ------------------------------------------------------------------

    /**
     * 解析 { sid, seq, t, data, mac } 信封（由 Crypto::parseRequest 委托）。
     * 返回与 Crypto::parseRequest 相同结构的数据。
     * @throws CryptoException
     */
    public static function openRequest(array $input): array
    {
        $sid  = preg_replace('/[^a-f0-9]/', '', (string) ($input['sid'] ?? ''));
        $seq  = (int) ($input['seq'] ?? 0);
        $t    = (int) ($input['t'] ?? 0);
        $data = (string) ($input['data'] ?? '');
        $mac  = (string) ($input['mac'] ?? '');

        if ($sid === '' || strlen($sid) !== 32 || $seq <= 0 || $data === '') {
            throw new CryptoException('missing_field', '3.1 信封缺少必要字段 sid/seq/data');
        }

        // 会话必须属于当前软件（跨软件会话复用直接拒绝）
        $row = Database::one(
            'SELECT sk_enc, sk_mac, iv_prefix, seq, expire_at FROM ' . Database::t('hsessions') . '
             WHERE sid = ? AND software_id = ? AND expire_at > ?',
            [$sid, Software::currentId(), time()]
        );
        if (!$row) {
            throw new CryptoException('bad_sign', '3.1 会话无效或已过期，请重新握手');
        }

        // 时间窗口（客户端已按 ts_s 校准，60 秒足够）
        if (abs(time() - $t) > self::TIME_WINDOW) {
            throw new CryptoException('time_expired', '请求时间戳超出允许范围');
        }

        // HMAC 校验（GCM 本身已认证，这里再绑 sid/seq/ts，防止字段被换序重放）
        $expectMac = hash_hmac('sha256', $sid . '|' . $seq . '|' . $t . '|' . hash('sha256', $data), $row['sk_mac']);
        if (!hash_equals($expectMac, $mac)) {
            throw new CryptoException('bad_sign', '请求 MAC 校验失败');
        }

        // seq 单调递增（原子 UPDATE，唯一写入口，天然防重放）；顺带滑动续期空闲过期
        $updated = Database::exec(
            'UPDATE ' . Database::t('hsessions') . ' SET seq = ?, expire_at = ? WHERE sid = ? AND seq < ?',
            [$seq, time() + self::SESSION_TTL, $sid, $seq]
        );
        if ($updated === false || $updated === 0) {
            throw new CryptoException('replay', '请求序号重复或乱序');
        }

        // GCM 解密：iv = iv_prefix(4) || seq 大端(8) = 12 字节
        $raw = base64_decode($data, true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            throw new CryptoException('decrypt_fail', '数据解密失败');
        }
        $iv  = substr($row['iv_prefix'], 0, 4) . pack('J', $seq);
        $tag = substr($raw, -16);
        $ct  = substr($raw, 12, -16);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $row['sk_enc'], OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new CryptoException('decrypt_fail', '数据解密失败（GCM 认证未通过）');
        }

        $dataArr = json_decode($plain, true);
        if (!is_array($dataArr)) {
            throw new CryptoException('bad_json', '数据格式错误');
        }

        // 登记当前会话供响应加密使用（响应方向独立密钥，见类注释「方向密钥分离」）
        self::$current = [
            'sid'        => $sid,
            'sk_enc'     => (string) $row['sk_enc'],
            'sk_enc_rsp' => hash_hkdf('sha256', (string) $row['sk_enc'], 32, 'nebula31-enc-rsp'),
            'iv_prefix'  => (string) $row['iv_prefix'],
            'seq'        => $seq,
        ];

        return ['data' => $dataArr, 'raw' => $input, 'plain' => false, 'session31' => true, 'kid' => null, 'session_mid' => ''];
    }

    /**
     * 3.1 加密响应体（由 Crypto::buildResponse 在会话活跃时委托）。
     * 附带服务端 ES256 签名（签名对象 data|sid），客户端 pin 公钥验签。
     */
    public static function buildResponse(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $seq  = self::$current['seq'];
        $iv   = substr(self::$current['iv_prefix'], 0, 4) . pack('J', $seq);

        $tag  = '';
        $ct   = openssl_encrypt($json, 'aes-256-gcm', self::$current['sk_enc_rsp'], OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            // 理论上不可能（密钥/IV 都来自合法会话）；兜底返回明文结构让客户端报协议错误
            return ['proto' => 31, 'err' => 'gcm_encrypt_failed'];
        }
        $data = base64_encode($iv . $ct . $tag);

        $resp = [
            'proto' => 31,
            'sid'   => self::$current['sid'],
            'data'  => $data,
        ];

        // 长期私钥签名（防伪造服务器），签名对象 data|sid
        if (class_exists('RespSign') && method_exists('RespSign', 'signMessage')) {
            $sig = RespSign::signMessage($data . '|' . self::$current['sid']);
            if ($sig !== null && $sig !== '') {
                $resp['sig']      = base64_encode($sig);
                $resp['sig_kid']  = RespSign::keyId();
                $resp['sig_algo'] = RespSign::algorithm();
            }
        }
        return $resp;
    }

    /** 清空当前会话（单请求生命周期结束） */
    public static function reset(): void
    {
        self::$current = null;
    }
}

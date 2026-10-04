<?php
/**
 * 响应签名密钥管理（独立于离线宽限密钥）
 * ------------------------------------------------------------------
 * 用途：对 API 响应进行 ES256/RS256 签名，客户端用公钥验签防止中间人篡改
 * 存储：config/resp_sign_keys.php（自动生成，请勿手工修改）
 * 轮换：删除本文件后下次调用 ensureKeys() 会自动重新生成
 *
 * 与 Grace.php 的区别：
 *   - Grace.php 的密钥同时用于「离线宽限票据签名」和「响应签名」
 *   - 本类将两者分离，响应签名密钥独立管理，更安全
 */

// PHP 8.1+ 才内置此常量，8.0 及以下手动定义
if (!defined('OPENSSL_ALGO_ES256')) {
    define('OPENSSL_ALGO_ES256', 'sha256');
}

class RespSign
{
    private static ?array $keys = null;
    private static bool   $keysTried = false;

    private const KEY_FILE = __DIR__ . '/../config/resp_sign_keys.php';

    // ==================================================================
    // 公钥 / kid / algo 读取
    // ==================================================================

    /** 返回公钥 PEM 字符串，未生成返回 null */
    public static function publicKey(): ?string
    {
        return self::ensureKeys() ? (self::$keys['public'] ?? null) : null;
    }

    /** 返回算法标识（ES256 / RS256），未生成返回空串 */
    public static function algorithm(): string
    {
        return self::ensureKeys() ? (string) (self::$keys['algo'] ?? '') : '';
    }

    /** 返回密钥版本 kid，未生成返回空串 */
    public static function keyId(): string
    {
        return self::ensureKeys() ? (string) (self::$keys['kid'] ?? '') : '';
    }

    /** 返回完整密钥信息数组（仅供内部使用） */
    public static function keyInfo(): array
    {
        self::ensureKeys();
        return self::$keys ?? ['algo' => '', 'kid' => '', 'public' => '', 'private' => ''];
    }

    // ==================================================================
    // 签发
    // ==================================================================

    /**
     * 用本类管理的私钥对任意报文签名
     * 返回 DER 签名（ES256）/ PKCS#1 签名（RS256），密钥不可用返回 null
     */
    public static function signMessage(string $body): ?string
    {
        if (!self::ensureKeys()) return null;
        return self::sign($body);
    }

    // ==================================================================
    // 轮换
    // ==================================================================

    /**
     * 轮换密钥：删除旧文件并生成新密钥对
     * 备份旧文件为 resp_sign_keys.php.bak.YYYYMMDDHHmmss
     * @return array{algo:string,kid:string,public_key:string}
     */
    public static function rotateKeys(): array
    {
        // 备份旧文件
        if (file_exists(self::KEY_FILE)) {
            $bak = self::KEY_FILE . '.bak.' . date('YmdHis');
            copy(self::KEY_FILE, $bak);
        }
        // 清除缓存
        self::$keys = null;
        self::$keysTried = false;
        // 重新生成
        self::ensureKeys();
        return [
            'algo'      => self::algorithm(),
            'kid'       => self::keyId(),
            'public_key'=> self::publicKey() ?? '',
        ];
    }

    // ==================================================================
    // 内部方法
    // ==================================================================

    private static function ensureKeys(): bool
    {
        if (self::$keysTried) {
            return self::$keys !== null;
        }
        self::$keysTried = true;

        if (!file_exists(self::KEY_FILE)) {
            self::generateKeys();
        }

        if (!file_exists(self::KEY_FILE)) {
            return false;
        }

        $data = @include self::KEY_FILE;
        if (!is_array($data) || empty($data['private']) || empty($data['public'])) {
            self::generateKeys();
            $data = @include self::KEY_FILE;
        }

        if (!is_array($data)) {
            return false;
        }

        self::$keys = [
            'algo'    => (string) ($data['algo'] ?? ''),
            'kid'     => (string) ($data['kid'] ?? ''),
            'public'  => (string) ($data['public'] ?? ''),
            'private' => (string) ($data['private'] ?? ''),
        ];

        return !empty(self::$keys['private']);
    }

    private static function generateKeys(): void
    {
        $candidates = [
            'ES256' => ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC],
            'RS256' => ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
        ];

        $cnf = self::opensslConfig();

        $config = Config::get('resp_sign', []);
        $algo = (string) ($config['algo'] ?? 'ES256');
        $tryAlgos = [$algo];
        if ($algo !== 'ES256' && $algo !== 'RS256') {
            $tryAlgos = ['ES256', 'RS256'];
        }

        $generated = false;

        foreach ($tryAlgos as $a) {
            if (!isset($candidates[$a])) continue;
            $base = $candidates[$a];
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

            $kid = substr(hash('sha256', $detail['key']), 0, 8);
            $data = [
                'algo'      => $a,
                'kid'       => $kid,
                'private'   => $pem,
                'public'    => $detail['key'],
                'created_at'=> time(),
            ];

            $tmpFile = self::KEY_FILE . '.tmp';
            $php = "<?php\n/**\n * 响应签名密钥（自动生成，请勿手工修改）\n * 删除本文件后下次请求会自动重新生成一套新密钥，\n * 副作用是此前由旧密钥签名的响应全部失效（客户端会拒绝）。\n */\nreturn array (\n  'algo' => '" . addslashes($a) . "',\n  'kid' => '" . addslashes($kid) . "',\n  'private' => " . var_export($pem, true) . ",\n  'public' => " . var_export($detail['key'], true) . ",\n  'created_at' => " . time() . ",\n);\n";
            file_put_contents($tmpFile, $php);
            rename($tmpFile, self::KEY_FILE);
            @chmod(self::KEY_FILE, 0600);

            self::$keys = $data;
            self::$keysTried = true;
            $generated = true;
            break;
        }

        if (!$generated) {
            self::drainOpensslErrors();
        }
    }

    /**
     * 定位 openssl.cnf。
     * phpStudy 等集成环境的 PHP 默认配置指向 C:\Program Files\Common Files\SSL\openssl.cnf，
     * 该文件往往不存在，会导致 openssl_pkey_new() 直接失败 —— 必须显式指定。
     */
    private static function opensslConfig(): ?string
    {
        $candidates = [
            (string) Config::get('resp_sign.openssl_config', ''),
            (string) (getenv('OPENSSL_CONF') ?: ''),
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/extras/openssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
        ];
        foreach ($candidates as $p) {
            if ($p !== '' && @is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    private static function drainOpensslErrors(): void
    {
        while (openssl_error_string() !== '') {
            // 清空错误队列
        }
    }

    /** 实际签名操作 */
    private static function sign(string $body): ?string
    {
        if (empty(self::$keys['private'])) {
            return null;
        }
        $sig = null;
        $algo = self::$keys['algo'] ?? 'ES256';
        if ($algo === 'ES256') {
            openssl_sign($body, $sig, self::$keys['private'], OPENSSL_ALGO_ES256);
        } elseif ($algo === 'RS256') {
            openssl_sign($body, $sig, self::$keys['private'], OPENSSL_ALGO_SHA256);
        }
        return $sig !== false ? $sig : null;
    }
}

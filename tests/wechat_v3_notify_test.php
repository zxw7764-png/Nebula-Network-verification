<?php
/**
 * 微信 V3 回调重写验证（wechatVerifyNotify）
 * 用 stream wrapper 模拟 php://input，自签证书模拟平台证书，进程内多用例。
 * 运行：php tests/wechat_v3_notify_test.php  （期望 13 PASS / 0 FAIL）
 * 平台测试证书由 openssl 在运行时生成到 tests/_certs/（仅测试用，非敏感；分发包不含预置私钥）。
 */
error_reporting(E_ALL & ~E_DEPRECATED);

// NB_ROOT 按本文件位置推导（分发包/开发树均可运行，不写死开发机路径）
define('NB_ROOT', dirname(__DIR__));
$certDir = __DIR__ . '/_certs';
if (!is_dir($certDir)) { @mkdir($certDir, 0777, true); }

// openssl.cnf 探测（Windows 下 RSA 生成必需；优先取 PHP 安装目录 extras/ssl）
foreach ([
    (getenv('OPENSSL_CONF') ?: ''),
    dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
] as $cnf) {
    if ($cnf !== '' && is_file($cnf)) { putenv("OPENSSL_CONF={$cnf}"); break; }
}

// stub Setting：certDir 指向测试证书目录（Pay.php 内部调 Setting::get）
class Setting {
    public static function get($k, $d = null) {
        global $certDir;
        if ($k === 'wechat_cert_dir') { return $certDir; }
        return $d;
    }
}

require NB_ROOT . '/lib/Pay.php';

// ---- php://input 模拟 ----
class MockPhpStream {
    public $context;
    private static $data = '';
    private $pos = 0;
    public static function setData($d) { self::$data = (string) $d; }
    public function stream_open($path, $mode, $options, &$opened) { $this->pos = 0; return true; }
    public function stream_read($c) { $r = substr(self::$data, $this->pos, $c); $this->pos += strlen($r); return $r; }
    public function stream_eof() { return $this->pos >= strlen(self::$data); }
    public function stream_stat() { return []; }
    public function stream_seek($o, $w) { $this->pos = $o; return true; }
    public function stream_tell() { return $this->pos; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'MockPhpStream');

// ---- 「平台证书」：运行时自签生成到 _certs/（分发包不携带预置私钥）----
$cnfFile = null;
foreach ([
    (getenv('OPENSSL_CONF') ?: ''),
    dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
    '/etc/ssl/openssl.cnf',
    '/usr/local/etc/openssl/openssl.cnf',
] as $c) { if ($c !== '' && is_file($c)) { $cnfFile = $c; break; } }
$genArgs = ['digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
if ($cnfFile) { $genArgs['config'] = $cnfFile; }
$certPem = $pkeyPem = '';
if (is_file($certDir . '/platform.pem') && is_file($certDir . '/platform.key')) {
    // 已存在（上次运行生成）直接复用
    $certPem = (string) file_get_contents($certDir . '/platform.pem');
    $pkeyPem = (string) file_get_contents($certDir . '/platform.key');
} else {
    $pkey = @openssl_pkey_new($genArgs);
    $csr  = $pkey ? @openssl_csr_new(['commonName' => 'nebula-test-platform'], $pkey, $genArgs) : false;
    $cert = ($pkey && $csr) ? @openssl_csr_sign($csr, null, $pkey, 3650, $genArgs, (int) (microtime(true) % 100000)) : false;
    if ($pkey && $cert) {
        @openssl_x509_export($cert, $certPem);
        @openssl_pkey_export($pkey, $pkeyPem, null, $cnfFile ? ['config' => $cnfFile] : []);
        @file_put_contents($certDir . '/platform.pem', $certPem);
        @file_put_contents($certDir . '/platform.key', $pkeyPem);
    }
}
$serial = ($certPem !== '') ? strtoupper((string) openssl_x509_parse($certPem)['serialNumber']) : '';
if ($certPem === '' || $pkeyPem === '' || $serial === '') {
    fwrite(STDERR, "FAIL: 测试证书生成失败（检查 openssl 扩展与 openssl.cnf 配置）\n");
    exit(1);
}

$m = new ReflectionMethod('Pay', 'wechatVerifyNotify');
$m->setAccessible(true);

$pass = 0; $fail = 0;
function check(string $name, bool $cond): void {
    global $pass, $fail;
    echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
    $cond ? $pass++ : $fail++;
}

/** 构造一条完整通知（加密+验签），写入 php://input 模拟层与 $_SERVER 头 */
function buildNotify(array $txn, string $apiKey, array $o = []): void {
    global $pkeyPem, $serial;
    $ts  = (string) ($o['ts'] ?? time());
    $aad = (string) ($o['aad'] ?? 'transaction');
    $nonce = $o['nonce'] ?? 'abc123def456'; // 12 字节
    $plain = json_encode($txn, JSON_UNESCAPED_UNICODE);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $apiKey, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
    if ($ct === false) { throw new RuntimeException('encrypt fail: ' . openssl_error_string()); }
    $res = [
        'original_type' => 'transaction',
        'algorithm'     => $o['algorithm'] ?? 'AEAD_AES_256_GCM',
        'ciphertext'    => base64_encode($ct . $tag),
        'nonce'         => $nonce,
    ];
    if ($aad !== '' || !isset($o['omit_aad'])) { $res['associated_data'] = $aad; }
    if (!empty($o['tamper_ct'])) {
        $bin = base64_decode($res['ciphertext']);
        $bin[0] = $bin[0] === 'A' ? 'B' : 'A';
        $res['ciphertext'] = base64_encode($bin);
    }
    $env = ['id' => 'evt_' . uniqid(), 'event_type' => 'TRANSACTION.SUCCESS'];
    if (!empty($o['drop_resource'])) { $env = ['id' => 'evt_x']; } else { $env['resource'] = $res; }
    $raw = json_encode($env);
    MockPhpStream::setData($raw);
    $nHdr = 'hdr' . bin2hex(random_bytes(8));
    $sigB64 = 'broken';
    if (empty($o['bad_sig'])) {
        openssl_sign("{$ts}\n{$nHdr}\n{$raw}\n", $sig, $pkeyPem, OPENSSL_ALGO_SHA256);
        $sigB64 = base64_encode($sig);
    } else {
        $sigB64 = base64_encode('not-a-signature');
    }
    $_SERVER['HTTP_WECHATPAY_TIMESTAMP'] = $ts;
    $_SERVER['HTTP_WECHATPAY_NONCE'] = $nHdr;
    $_SERVER['HTTP_WECHATPAY_SIGNATURE'] = $sigB64;
    $_SERVER['HTTP_WECHATPAY_SERIAL'] = $serial;
}

$KEY  = str_repeat('a', 32);
$BASE = ['appid' => 'wx123', 'mchid' => '1900000001', 'out_trade_no' => 'NB20260929001',
         'transaction_id' => '420000123420260929001', 'trade_state' => 'SUCCESS',
         'amount' => ['total' => 100, 'payer_total' => 100, 'currency' => 'CNY']];
$CFG  = ['appid' => 'wx123', 'mchid' => '1900000001', 'apiKey' => $KEY];

// 1. 正常通知
buildNotify($BASE, $KEY);
$r = $m->invoke(null, [], $CFG);
if ($r !== [true, 'NB20260929001', 100, '420000123420260929001']) {
    echo "[debug] 用例1实际返回: ";
    var_export($r);
    echo "\n[debug] openssl: " . openssl_error_string() . "\n";
}
check('正常通知：验签+解密+字段提取', $r === [true, 'NB20260929001', 100, '420000123420260929001']);

// 2. 错误签名
buildNotify($BASE, $KEY, ['bad_sig' => true]);
check('错误签名拒绝', $m->invoke(null, [], $CFG)[0] === false);

// 3. 篡改密文（合法签名但密文坏 → 解密失败）
buildNotify($BASE, $KEY, ['tamper_ct' => true]);
check('篡改密文拒绝', $m->invoke(null, [], $CFG)[0] === false);

// 4. 错误 APIv3 密钥（长度正确内容错）
buildNotify($BASE, $KEY);
check('错误 APIv3 密钥拒绝', $m->invoke(null, [], array_merge($CFG, ['apiKey' => str_repeat('b', 32)]))[0] === false);

// 5. APIv3 密钥长度非 32
buildNotify($BASE, $KEY);
check('APIv3 密钥长度≠32 拒绝', $m->invoke(null, [], array_merge($CFG, ['apiKey' => 'shortkey']))[0] === false);

// 6. trade_state 非 SUCCESS
$t = $BASE; $t['trade_state'] = 'REFUND';
buildNotify($t, $KEY);
check('非 SUCCESS 状态拒绝', $m->invoke(null, [], $CFG)[0] === false);

// 7. 时间戳偏差 >5 分钟（重放）
buildNotify($BASE, $KEY, ['ts' => (string) (time() - 600)]);
check('时间戳超 5 分钟拒绝(重放防护)', $m->invoke(null, [], $CFG)[0] === false);

// 8. 缺 resource
buildNotify($BASE, $KEY, ['drop_resource' => true]);
check('缺 resource 拒绝', $m->invoke(null, [], $CFG)[0] === false);

// 9. algorithm 非 AEAD_AES_256_GCM
buildNotify($BASE, $KEY, ['algorithm' => 'AES_256_GCM']);
check('非官方算法名拒绝', $m->invoke(null, [], $CFG)[0] === false);

// 10. appid 不匹配
$t = $BASE; $t['appid'] = 'wx999';
buildNotify($t, $KEY);
check('appid 不匹配拒绝', $m->invoke(null, [], $CFG)[0] === false);

// 11. associated_data 为空（官方允许 aad 为空）
buildNotify($BASE, $KEY, ['aad' => '', 'omit_aad' => true]);
check('AAD 为空仍可解密', $m->invoke(null, [], $CFG)[0] === true);

// 12. 金额为 0/负数
$t = $BASE; $t['amount']['total'] = 0;
buildNotify($t, $KEY);
check('金额≤0 拒绝', $m->invoke(null, [], $CFG)[0] === false);

// 13. 缺 out_trade_no
$t = $BASE; unset($t['out_trade_no']);
buildNotify($t, $KEY);
check('缺订单号拒绝', $m->invoke(null, [], $CFG)[0] === false);

echo "\n结果: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

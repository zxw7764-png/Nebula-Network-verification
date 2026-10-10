<?php
/**
 * 授权 API（供 Nebula 安装器 / 后台授权校验调用）
 * ==================================================================
 * POST application/json，两个 action：
 *
 *   activate  { license_key, domain }  激活：绑定域名（一码一域）
 *   verify    { license_key, domain }  验签：检查绑定/状态/有效期
 *
 * 响应：{ code: 0, msg: "...", data: {...} }
 * 限流：每 IP 每分钟 30 次（文件计数）。
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/License.php';

header('Content-Type: application/json; charset=utf-8');

function licOut(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// GET 请求：返回试用配置（供门户弹窗动态显示天数/每日限领）
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $trialDays  = 7; $trialLimit = 3;
    try {
        $cfgRows = DB::all("SELECT skey, svalue FROM " . DB::t('settings') . " WHERE skey IN ('trial_days','trial_daily_limit')");
        foreach ($cfgRows as $cr) {
            if ($cr['skey'] === 'trial_days')  $trialDays  = max(1, (int) $cr['svalue']);
            if ($cr['skey'] === 'trial_daily_limit') $trialLimit = max(0, (int) $cr['svalue']);
        }
    } catch (Throwable $e) { /* 用默认值 */ }
    licOut(['code' => 0, 'data' => ['trial_days' => $trialDays, 'trial_daily_limit' => $trialLimit]]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    licOut(['code' => 1001, 'msg' => '请使用 POST 请求'], 405);
}

if (!License::throttle()) {
    licOut(['code' => 1002, 'msg' => '请求过于频繁，请稍后再试'], 429);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

$action = (string) ($input['action'] ?? '');
$key    = (string) ($input['license_key'] ?? '');
$domain = (string) ($input['domain'] ?? '');

// 防呆：安装器可能不传 domain，默认取当前请求 host（即授权服务器自身域名的场景不适用，
// 因此 domain 为必填 —— 安装器必须显式传部署站域名）
if ($action === 'activate') {
    $r = License::activate($key, $domain);
    licOut(['code' => $r['ok'] ? 0 : 1003, 'msg' => $r['msg']] + ($r['ok'] ? ['data' => ['domain' => $r['domain'], 'plan' => $r['plan']]] : []));
}

if ($action === 'verify') {
    $r = License::verify($key, $domain);
    licOut(['code' => $r['ok'] ? 0 : 1004, 'msg' => $r['msg']] + ($r['ok'] ? ['data' => ['plan' => $r['plan'], 'expires_at' => $r['expires_at']]] : []));
}

// 人机验证：下发挑战（签名 nonce + 难度），前端解出工作量证明后才能领试用码
if ($action === 'challenge') {
    licOut(['code' => 0, 'data' => License::issueChallenge()]);
}

if ($action === 'trial') {
    // 门户自助试用授权码：天数/每日限领/验证难度由后台「授权管理 → 试用配置」控制（默认 7 天 / 3 枚 / 难度 4）
    $trialDays  = License::settingInt('trial_days', 7);
    $trialLimit = License::settingInt('trial_daily_limit', 3);
    if ($trialDays < 1) { $trialDays = 7; }
    if ($trialLimit < 0) { $trialLimit = 0; }

    if ($trialLimit <= 0) {
        licOut(['code' => 1005, 'msg' => '试用授权码发放已关闭，请联系管理员获取正式授权码'], 403);
    }

    // 1) 域名必填 + 归一化，用于「一域一次」判定与即时绑定
    $reqDomain = License::normalizeDomain((string) ($input['domain'] ?? ''));
    if ($reqDomain === '') {
        licOut(['code' => 1006, 'msg' => '请填写你的部署域名（如 demo.example.com）后再领取'], 400);
    }

    // 2) 人机验证：校验签名挑战 + 工作量证明（无状态，无需第三方验证码）
    $ok = License::verifyChallenge(
        $input['nonce'] ?? '', $input['ts'] ?? 0, $input['difficulty'] ?? 0,
        $input['x'] ?? '', $input['sig'] ?? ''
    );
    if (!$ok) {
        licOut(['code' => 1007, 'msg' => '人机验证未通过，请刷新后重试'], 400);
    }

    // 3) 每 IP 每日限领（真实 IP，可信代理感知；原子计数）
    $ip = License::clientIp();
    $file = sys_get_temp_dir() . '/vu_trial_' . md5($ip) . '.cnt';
    if (!License::hitCounter($file, 86400, $trialLimit)) {
        licOut(['code' => 1005, 'msg' => '试用授权码每日限领 ' . $trialLimit . ' 枚，请明天再试'], 429);
    }

    // 4) 一域一次：该域名已存在任何授权则拒绝（防止换 IP / 换机器反复领取）
    if (License::domainTaken($reqDomain)) {
        licOut(['code' => 1008, 'msg' => '该域名已领取过授权，如需更换域名请联系管理员'], 409);
    }

    $now = time();
    $key = License::generateKey();
    $expires = $now + $trialDays * 86400;
    try {
        DB::insert('licenses', [
            'license_key'  => $key,
            'status'       => 1,
            'plan'         => 'trial',
            'domain'       => $reqDomain,   // 领取时即绑定，杜绝「先囤码后挑域名」
            'note'         => '门户自助体验（' . substr($ip, 0, 64) . '）',
            'issued_at'    => $now,
            'activated_at' => $now,
            'expires_at'   => $expires,
            'created_at'   => $now,
        ]);
    } catch (\Throwable $e) {
        // 极端并发下同域名重复插入：唯一/竞态时统一给出「已领取」提示
        if (License::domainTaken($reqDomain)) {
            licOut(['code' => 1008, 'msg' => '该域名已领取过授权，如需更换域名请联系管理员'], 409);
        }
        throw $e;
    }
    licOut(['code' => 0, 'msg' => '试用授权码已签发（' . $trialDays . ' 天有效，已绑定 ' . $reqDomain . '）', 'data' => [
        'license_key' => $key,
        'expires_at'  => $expires,
        'plan'        => 'trial',
        'domain'      => $reqDomain,
        'trial_days'  => $trialDays,
    ]]);
}

licOut(['code' => 1001, 'msg' => '未知 action（支持 activate / verify / trial / challenge）'], 400);

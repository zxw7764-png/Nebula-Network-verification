<?php
/**
 * admin action: login
 */

$username = Util::str($input, 'username', '');
$password = (string) Util::get($input, 'password', '');

if ($username === '' || $password === '') {
    Response::error(1001, '请输入账号和密码');
}

// 图形验证码：答案在拉图时写入 session，一次性消费
if (!Captcha::verify((string) Util::get($input, 'captcha', ''))) {
    Response::error(1002, '验证码错误或已过期');
}

// 爆破防护
if (!RateLimit::hit('admin_login:' . Util::ip(), 10, 300)) {
    Response::error(5001, '尝试过于频繁，请 5 分钟后再试');
}

// ------------------------------------------------------------------
// 失败锁定（IP 维度）
// 账号维度的锁定已在 AdminAuth::login() 内实现（login_fail_cnt +
// lock_until，默认连错 5 次锁 15 分钟）。这里补的是 IP 维度：
// 攻击者换账号名继续撞库时，账号维度不生效，但同一 IP 会持续累加，
// 达到阈值后整个 IP 在本窗口内被拒。
// ------------------------------------------------------------------
$lockWindow = 900;   // 15 分钟
$lockMax    = 15;    // 窗口内允许的失败次数（比账号阈值宽，避免 NAT 出口误伤）
$failIp     = 'adminfail:ip:' . Util::ip();

if (RateLimit::count($failIp, $lockWindow) >= $lockMax) {
    Logger::log('admin_login', 0, '该 IP 已临时锁定（失败次数超限）', ['username' => $username]);
    Response::error(5001, '账号或密码错误次数过多，请 ' . (int) ($lockWindow / 60) . ' 分钟后再试');
}

// 二次验证动态码（绑定 TOTP 的账号才需要；未绑定时该字段被忽略）
$totp = preg_replace('/\s+/', '', (string) Util::get($input, 'totp', ''));

$r = AdminAuth::login($username, $password, $totp);

// code=2006 只是"请补一个动态验证码"，不是一次失败的登录尝试：
// 不写失败日志、也不计入 IP 爆破计数，否则正常登录会平白多一条失败记录。
$isNeedTotp = (int) $r['code'] === 2006;

if (!$isNeedTotp) {
    Logger::log('admin_login', $r['ok'] ? 1 : 0, $r['msg'], ['username' => $username]);
}

if (!$r['ok']) {
    if (!$isNeedTotp) {
        RateLimit::incr($failIp, $lockWindow);
    }
    Response::error($r['code'], $r['msg']);
}

// 登录成功：清掉该 IP 的失败计数，避免正常用户被同出口的历史失败拖累
RateLimit::reset($failIp, $lockWindow);

// ------------------------------------------------------------------
// 下发会话 Cookie（P1-09）
// 令牌写入 HttpOnly Cookie，JS 读不到 → XSS 无法窃取。
// 响应体里仍然返回 token，供「Cookie 被禁用」或旧客户端回退使用
// （前端会优先用 Cookie，只有拿不到时才退回 X-Token 头）。
// 会话密钥（session_key）始终只走响应体 + JS 内存，不进 Cookie。
// ------------------------------------------------------------------
SessionCookie::issue($r['data']['token'], (int) $r['data']['expire_at'] - time());

Response::ok($r['data'], $r['msg']);

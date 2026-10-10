<?php
/**
 * 后台登录页
 * ==================================================================
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 入口令牌守卫：无令牌一律 404（不暴露后台位置）
AdminAuth::entryGuard();

// 已登录则跳转
$admin = AdminAuth::check();
if ($admin) {
    header('Location: dashboard.php');
    exit;
}

// ------------------------------------------------------------------
// 登录表单 CSRF：nonce Cookie（10 分钟）+ HMAC 表单令牌
// ------------------------------------------------------------------
$loginNonce = $_COOKIE['vu_login_nonce'] ?? '';
if (!is_string($loginNonce) || !preg_match('/^[0-9a-f]{32}$/', $loginNonce)) {
    $loginNonce = bin2hex(random_bytes(16));
    setcookie('vu_login_nonce', $loginNonce, [
        'expires'  => time() + 600,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
$formToken = hash_hmac('sha256', $loginNonce, AdminAuth::entryKey() ?: 'nb-portal-fallback');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $lt       = (string) ($_POST['lt'] ?? '');

    if (!hash_equals($formToken, $lt)) {
        $err = '页面已过期，请刷新后重试';
    } elseif ($username === '' || $password === '') {
        $err = '请输入账号和密码';
    } else {
        $result = AdminAuth::login($username, $password);
        if ($result['ok']) {
            // 用完即弃登录 nonce
            setcookie('vu_login_nonce', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            header('Location: dashboard.php');
            exit;
        }
        $err = $result['msg'];
    }
}

$appName = APP_NAME;
$ver = APP_VERSION;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 - <?= htmlspecialchars($appName) ?></title>
<link rel="stylesheet" href="assets/admin.css?v=<?= htmlspecialchars($ver) ?>">
<style>
body { display: flex; align-items: center; justify-content: center; padding: 20px; }
.login-card {
    background: var(--card-bg); border: 1px solid var(--border-2);
    border-radius: var(--r-xl); width: 100%; max-width: 400px;
    box-shadow: var(--shadow-lg); overflow: hidden;
    animation: slideUp .35s ease;
}
.login-head {
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    color: #fff; padding: 28px 32px; position: relative; overflow: hidden;
}
.login-head::after {
    content: ''; position: absolute; top: -50%; right: -20%;
    width: 200px; height: 200px; border-radius: 50%;
    background: rgba(255,255,255,.08);
}
.login-head .logo {
    width: 44px; height: 44px; border-radius: 12px;
    background: rgba(255,255,255,.15); backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 14px; position: relative; z-index: 1;
}
.login-head .logo svg { width: 24px; height: 24px; color: #fff; }
.login-head h1 { font-size: 20px; font-weight: 700; position: relative; z-index: 1; }
.login-head p { font-size: 13px; color: rgba(255,255,255,.7); margin-top: 6px; position: relative; z-index: 1; }
.login-body { padding: 28px 32px; }
.field { margin-bottom: 18px; }
.field label { display: block; font-size: 13px; font-weight: 600; color: var(--text-sub); margin-bottom: 8px; }
.field input {
    width: 100%; padding: 11px 14px;
    border: 1px solid var(--border-2); border-radius: var(--r-md);
    font-size: 14px; outline: none; transition: all .2s;
    background: var(--input-bg); color: var(--text);
}
.field input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
.field input::placeholder { color: var(--text-faint); }
.login-btn {
    width: 100%; padding: 12px;
    background: linear-gradient(135deg, var(--accent), #6a4df0);
    color: #fff; border: none; border-radius: var(--r-md);
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: .2s; box-shadow: 0 6px 20px var(--accent-glow);
}
.login-btn:hover { filter: brightness(1.12); transform: translateY(-1px); }
.login-foot { text-align: center; margin-top: 20px; font-size: 12px; color: var(--text-faint); }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-head">
        <div class="logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polyline>
            </svg>
        </div>
        <h1><?= htmlspecialchars($appName) ?></h1>
        <p>管理后台登录</p>
    </div>
    <div class="login-body">
        <?php if ($err): ?>
            <div class="alert alert-danger" style="margin-bottom:18px"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="lt" value="<?= htmlspecialchars($formToken) ?>">
            <div class="field">
                <label>账号</label>
                <input name="username" placeholder="请输入管理员账号" required autofocus>
            </div>
            <div class="field">
                <label>密码</label>
                <input name="password" type="password" placeholder="请输入密码" required>
            </div>
            <button class="login-btn" type="submit">登 录</button>
        </form>
        <div class="login-foot">v<?= htmlspecialchars($ver) ?></div>
    </div>
</div>
</body>
</html>

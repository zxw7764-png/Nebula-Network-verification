<?php
/**
 * 在线版本更新系统 - 安装向导
 * ==================================================================
 * 浏览器访问: http://你的域名/update-system/install/install.php
 * 安装完成后请删除 install 目录。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

session_start();

$root        = dirname(__DIR__);
$configFile  = $root . '/config/config.php';
$schemaFile  = __DIR__ . '/schema.sql';
$lockFile    = __DIR__ . '/install.lock';
$installed   = is_file($lockFile);

// ------------------------------------------------------------------
// 入口令牌守卫（已安装状态生效）
// 与后台共用同一把 admin_entry_key：防止安装向导被探测/重装利用
// ------------------------------------------------------------------
$cfgNow = is_file($configFile) ? @include $configFile : null;
$entryKey = (is_array($cfgNow) && isset($cfgNow['admin_entry_key'])) ? (string) $cfgNow['admin_entry_key'] : '';
if ($entryKey !== '') {
    $entryOk = false;
    $given = (string) ($_GET['entry'] ?? '');
    if ($given === '' && isset($_SERVER['HTTP_X_ENTRY_KEY'])) {
        $given = (string) $_SERVER['HTTP_X_ENTRY_KEY'];
    }
    if ($given !== '' && hash_equals($entryKey, $given)) {
        $entryOk = true;
        setcookie('vu_entry', hash_hmac('sha256', $entryKey, 'nebula-update-system|entry-guard'), [
            'expires' => time() + 2592000, 'path' => '/',
            'secure'  => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    } else {
        $c = $_COOKIE['vu_entry'] ?? '';
        if (is_string($c) && $c !== '' && hash_equals(hash_hmac('sha256', $entryKey, 'nebula-update-system|entry-guard'), $c)) {
            $entryOk = true;
        }
    }
    if (!$entryOk) {
        http_response_code(404);
        exit('<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>');
    }
}

$step = $_GET['step'] ?? '1';
$err  = '';
$msg  = '';

// 已安装保护
if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit('系统已安装，如需重装请先删除 install/install.lock');
}

// ------------------------------------------------------------------
// 处理提交
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- 数据库检测 + 配置填写 ----
    if ($action === 'check_db') {
        $host = trim($_POST['db_host'] ?? '127.0.0.1');
        $port = (int) ($_POST['db_port'] ?? 3306);
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = (string) ($_POST['db_pass'] ?? '');

        // 输入校验
        if (!preg_match('/^[A-Za-z0-9.\-:]{1,120}$/', $host)) {
            $err = '数据库地址格式错误'; $step = '1';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            $err = '数据库名格式错误：仅限字母/数字/下划线'; $step = '1';
        } elseif (!preg_match('/^[\x21-\x7E]{1,64}$/', $user)) {
            $err = '数据库用户名格式错误'; $step = '1';
        } elseif (strlen($pass) > 64) {
            $err = '数据库密码过长'; $step = '1';
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', trim($_POST['admin_user'] ?? ''))) {
            $err = '管理员账号格式错误：3-32 位，仅字母/数字/下划线'; $step = '1';
        } elseif (strlen((string) ($_POST['admin_pass'] ?? '')) < 8) {
            $err = '管理员密码至少 8 位'; $step = '1';
        } elseif (strtolower((string) $_POST['admin_pass']) === 'admin888') {
            $err = '管理员密码不能使用默认值 admin888'; $step = '1';
        }

        if ($err === '') try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $pdo->exec("USE `{$name}`");

            $_SESSION['db'] = compact('host', 'port', 'name', 'user', 'pass');
            $_SESSION['admin'] = [
                'user' => trim($_POST['admin_user']),
                'pass' => (string) $_POST['admin_pass'],
            ];
            $_SESSION['site_name'] = trim($_POST['site_name'] ?? '在线版本更新系统');
            $_SESSION['db_prefix'] = trim($_POST['db_prefix'] ?? 'vu_');

            header('Location: install.php?step=2');
            exit;
        } catch (Throwable $e) {
            @error_log('[version-update install] db connect failed: ' . $e->getMessage());
            $err = '数据库连接失败，请检查地址/端口/库名/账号/密码';
            $step = '1';
        }
    }

    // ---- 执行安装 ----
    if ($action === 'do_install') {
        $db = $_SESSION['db'] ?? null;
        if (!$db) {
            $err = '会话已失效，请重新开始'; $step = '1';
        } else try {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $prefix = $_SESSION['db_prefix'] ?? 'vu_';

            // 执行建表 SQL（替换前缀）
            $sql = file_get_contents($schemaFile);
            // 重新安装：清空当前库中该前缀的所有旧表，避免残留数据/唯一键冲突
            $oldTables = $pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES " .
                "WHERE TABLE_SCHEMA = " . $pdo->quote($db['name']) .
                " AND TABLE_NAME LIKE " . $pdo->quote($prefix . '%')
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($oldTables)) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($oldTables as $t) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $sql = preg_replace('/^--.*$/m', '', $sql);
            // 替换表前缀
            $sql = str_replace('`vu_', '`' . $prefix, $sql);
            // 逐条执行
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                if ($stmt !== '') $pdo->exec($stmt . ';');
            }

            // 创建管理员
            $adminUser = $_SESSION['admin']['user'];
            $adminPass = $_SESSION['admin']['pass'];
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 10]);

            $pdo->prepare("INSERT INTO `{$prefix}admins` (username, password, nickname, role, status, created_at) VALUES (?,?,?,1,1,?)")
                ->execute([$adminUser, $hash, $adminUser, time()]);

            // 站点名
            $siteName = $_SESSION['site_name'];
            $pdo->prepare("UPDATE `{$prefix}settings` SET svalue = ? WHERE skey = 'site_name'")
                ->execute([$siteName]);

            // 生成配置文件
            if (!is_dir(dirname($configFile))) {
                @mkdir(dirname($configFile), 0750, true);
            }

            // 转义函数（防止注入）
            $nbQ = function ($v): string {
                return str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v);
            };

            // 生成后台入口令牌（访问后台必须携带，泄露可随时更换）
            $entryKeyNew = bin2hex(random_bytes(16));

            $configContent = "<?php\n/**\n * 版本更新系统 - 自动生成的配置\n * ⚠ 禁止提交到 Git\n */\n\nreturn [\n\n";
            $configContent .= "    'db' => [\n";
            $configContent .= "        'host'    => '" . $nbQ($db['host']) . "',\n";
            $configContent .= "        'port'    => " . (int) $db['port'] . ",\n";
            $configContent .= "        'name'    => '" . $nbQ($db['name']) . "',\n";
            $configContent .= "        'user'    => '" . $nbQ($db['user']) . "',\n";
            $configContent .= "        'pass'    => '" . $nbQ($db['pass']) . "',\n";
            $configContent .= "        'charset' => 'utf8mb4',\n";
            $configContent .= "        'prefix'  => '" . $nbQ($prefix) . "',\n";
            $configContent .= "    ],\n\n";
            $configContent .= "    'admin' => [\n";
            $configContent .= "        'session_ttl' => 7200,\n";
            $configContent .= "        'login_fail_threshold' => 5,\n";
            $configContent .= "        'login_lock_seconds' => 900,\n";
            $configContent .= "    ],\n\n";
            $configContent .= "    'admin_entry_key' => '" . $entryKeyNew . "',\n\n";
            $configContent .= "    'update_server' => '',\n\n";
            $configContent .= "    'public_key_file' => 'storage/updates/update-public-key.pem',\n\n";
            $configContent .= "    'debug' => false,\n\n";
            $configContent .= "];\n";

            if (@file_put_contents($configFile, $configContent) === false) {
                throw new RuntimeException('config/config.php 写入失败，请检查目录权限');
            }

            // 创建安装锁
            file_put_contents($lockFile, date('Y-m-d H:i:s'));

            // 创建存储目录
            $storageDirs = [
                $root . '/storage/updates/backups',
                $root . '/storage/updates/packages',
                $root . '/storage/updates/logs',
            ];
            foreach ($storageDirs as $d) {
                if (!is_dir($d)) @mkdir($d, 0750, true);
            }

            $_SESSION['install_result'] = [
                'admin'     => $adminUser,
                'pass'      => $adminPass,
                'entry_key' => $entryKeyNew,
            ];

            header('Location: install.php?step=3');
            exit;
        } catch (Throwable $e) {
            $err = '安装失败: ' . $e->getMessage();
            $step = '2';
        }
    }
}

if ($installed && $step !== '3') {
    $step = 'done';
}

$result = $_SESSION['install_result'] ?? null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>在线版本更新系统 - 安装向导</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif;
    background:
        radial-gradient(1200px 600px at 20% -10%, rgba(124, 92, 255, .35), transparent 60%),
        radial-gradient(900px 500px at 90% 110%, rgba(56, 189, 248, .18), transparent 55%),
        #0b0e17;
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 20px; color: #e5e7eb;
}
.card {
    background: #12162a; border: 1px solid rgba(255,255,255,.08); border-radius: 18px; width: 100%; max-width: 620px;
    box-shadow: 0 30px 80px rgba(0,0,0,.55); overflow: hidden;
    animation: nbPop .45s cubic-bezier(.22,1,.36,1);
}
@keyframes nbPop { from { opacity: 0; transform: translateY(14px) scale(.985); } to { opacity: 1; transform: none; } }
.head {
    background: linear-gradient(135deg, #7c5cff, #5b9dff); color: #fff; padding: 26px 32px;
}
.head h1 { font-size: 21px; font-weight: 700; letter-spacing: .5px; }
.head p { font-size: 13px; color: rgba(255,255,255,.78); margin-top: 5px; }
.body { padding: 32px; }
.steps { display: flex; gap: 8px; margin-bottom: 28px; }
.steps div { flex: 1; height: 4px; background: rgba(255,255,255,.1); border-radius: 2px; transition: .3s; }
.steps div.on { background: linear-gradient(90deg, #7c5cff, #5b9dff); }
.field { margin-bottom: 18px; }
.field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #cbd5e1; }
.field input {
    width: 100%; padding: 11px 14px; border: 1px solid rgba(255,255,255,.14);
    border-radius: 10px; font-size: 14px; transition: .2s; outline: none;
    background: rgba(255,255,255,.05); color: #e5e7eb;
}
.field input::placeholder { color: #64748b; }
.field input:focus { border-color: #7c5cff; box-shadow: 0 0 0 3px rgba(124,92,255,.25); background: rgba(255,255,255,.07); }
.row { display: flex; gap: 12px; }
.row .field { flex: 1; }
.btn {
    width: 100%; padding: 13px; background: linear-gradient(135deg, #7c5cff, #6a4df0); color: #fff;
    border: none; border-radius: 10px; font-size: 15px; font-weight: 600;
    cursor: pointer; transition: .2s; margin-top: 8px;
    box-shadow: 0 8px 24px rgba(124,92,255,.35);
}
.btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
.alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; }
.alert.err { background: rgba(239,68,68,.12); color: #fca5a5; border: 1px solid rgba(239,68,68,.35); }
.alert.ok  { background: rgba(34,197,94,.12); color: #86efac; border: 1px solid rgba(34,197,94,.35); }
.alert.warn{ background: rgba(245,158,11,.1); color: #fcd34d; border: 1px solid rgba(245,158,11,.3); }
.tip { font-size: 12px; color: #94a3b8; margin-top: 5px; }
code { background: rgba(124,92,255,.12); padding: 2px 7px; border-radius: 5px; font-size: 12px; color: #c4b5fd; word-break: break-all; border: 1px solid rgba(124,92,255,.2); }
h3 { font-size: 15px; margin-bottom: 12px; color: #f1f5f9; }
ul.done-list { list-style: none; }
ul.done-list li { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.06); font-size: 13px; display: flex; justify-content: space-between; gap: 12px; }
ul.done-list li span:last-child { font-weight: 600; color: #c4b5fd; text-align: right; word-break: break-all; }
.link-cards { display: grid; gap: 10px; margin: 12px 0 4px; }
.link-card {
    display: flex; align-items: center; gap: 12px; padding: 13px 16px;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px; text-decoration: none; transition: .18s;
}
.link-card:hover { border-color: rgba(124,92,255,.55); background: rgba(124,92,255,.08); transform: translateY(-1px); }
.link-card .ic { width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #7c5cff, #5b9dff); font-size: 18px; color: #fff; }
.link-card .tx { flex: 1; min-width: 0; }
.link-card .t { font-size: 13.5px; font-weight: 600; color: #f1f5f9; }
.link-card .d { font-size: 12px; color: #94a3b8; margin-top: 2px; word-break: break-all; }
.link-card .go { color: #7c5cff; font-size: 16px; flex-shrink: 0; }
</style>
</head>
<body>
<div class="card">
    <div class="head">
        <h1>在线版本更新系统</h1>
        <p>安装向导 · 版本检测 · 一键更新 · 数字签名 · 自动回滚</p>
    </div>
    <div class="body">
        <div class="steps">
            <div class="<?= in_array($step, ['1','2','3','done']) ? 'on' : '' ?>"></div>
            <div class="<?= in_array($step, ['2','3','done']) ? 'on' : '' ?>"></div>
            <div class="<?= in_array($step, ['3','done']) ? 'on' : '' ?>"></div>
        </div>

        <?php if ($err): ?>
            <div class="alert err"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <?php if ($step === '1'): ?>
            <h3>环境检测</h3>
            <?php
            $checks = [
                'PHP 版本 >= 8.0'    => version_compare(PHP_VERSION, '8.0.0', '>='),
                'PDO MySQL 扩展'     => extension_loaded('pdo_mysql'),
                'OpenSSL 扩展'       => extension_loaded('openssl'),
                'cURL 扩展'          => extension_loaded('curl'),
                'ZIP 扩展'           => extension_loaded('zip'),
                'JSON 扩展'          => extension_loaded('json'),
                'config 目录可写'    => is_writable(dirname($configFile)) || @mkdir(dirname($configFile), 0750, true),
                'storage 目录可写'   => is_writable($root . '/storage') || @mkdir($root . '/storage', 0750, true),
            ];
            $allOk = !in_array(false, $checks, true);
            ?>
            <ul class="done-list">
                <?php foreach ($checks as $k => $v): ?>
                    <li>
                        <span><?= $k ?></span>
                        <span style="color:<?= $v ? '#38a169' : '#e53e3e' ?>"><?= $v ? '✓ 通过' : '✗ 失败' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$allOk): ?>
                <div class="alert err" style="margin-top:16px">请先解决上述环境问题再继续安装。</div>
            <?php else: ?>
                <form method="post" style="margin-top:20px">
                    <input type="hidden" name="action" value="check_db">
                    <h3>数据库配置</h3>
                    <div class="row">
                        <div class="field">
                            <label>数据库地址</label>
                            <input name="db_host" value="127.0.0.1" required>
                        </div>
                        <div class="field" style="max-width:110px">
                            <label>端口</label>
                            <input name="db_port" value="3306" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>数据库名</label>
                        <input name="db_name" value="version_update" required>
                        <div class="tip">若不存在会自动创建</div>
                    </div>
                    <div class="row">
                        <div class="field">
                            <label>数据库用户名</label>
                            <input name="db_user" value="root" required>
                        </div>
                        <div class="field">
                            <label>数据库密码</label>
                            <input name="db_pass" type="password">
                        </div>
                    </div>
                    <div class="field">
                        <label>表前缀</label>
                        <input name="db_prefix" value="vu_" required>
                        <div class="tip">同一库部署多套系统时可修改前缀</div>
                    </div>
                    <h3 style="margin-top:24px">管理员与站点</h3>
                    <div class="field">
                        <label>站点名称</label>
                        <input name="site_name" value="在线版本更新系统">
                    </div>
                    <div class="row">
                        <div class="field">
                            <label>管理员账号</label>
                            <input name="admin_user" value="admin" required>
                        </div>
                        <div class="field">
                            <label>管理员密码</label>
                            <input name="admin_pass" type="password" minlength="8" required placeholder="至少 8 位">
                        </div>
                    </div>
                    <div class="alert warn" style="margin-top:16px">
                        安全要求：管理员密码必须修改（不能使用 admin888），至少 8 位。
                    </div>
                    <button class="btn" type="submit">下一步：开始安装</button>
                </form>
            <?php endif; ?>

        <?php elseif ($step === '2'): ?>
            <div class="alert warn">
                即将创建数据库表并写入配置文件。此操作会重置 <code>config/config.php</code>。
            </div>
            <ul class="done-list">
                <li><span>数据库</span><span><?= htmlspecialchars($_SESSION['db']['name'] ?? '') ?></span></li>
                <li><span>地址</span><span><?= htmlspecialchars(($_SESSION['db']['host'] ?? '') . ':' . ($_SESSION['db']['port'] ?? '')) ?></span></li>
                <li><span>表前缀</span><span><?= htmlspecialchars($_SESSION['db_prefix'] ?? 'vu_') ?></span></li>
                <li><span>管理员</span><span><?= htmlspecialchars($_SESSION['admin']['user'] ?? '') ?></span></li>
                <li><span>站点名称</span><span><?= htmlspecialchars($_SESSION['site_name'] ?? '') ?></span></li>
            </ul>
            <form method="post" style="margin-top:20px">
                <input type="hidden" name="action" value="do_install">
                <button class="btn" type="submit">确认安装</button>
            </form>

        <?php elseif ($step === '3' && $result): ?>
            <?php
            $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
            $entryKeyShown = (string) ($result['entry_key'] ?? '');
            $loginUrl = htmlspecialchars($base . '/admin/login.php?entry=' . urlencode($entryKeyShown));
            $updateUrl = htmlspecialchars($base . '/admin/update.php?entry=' . urlencode($entryKeyShown));
            $versionApi = htmlspecialchars($base . '/api/version.php');
            ?>
            <div class="alert ok">🎉 安装完成！以下信息仅显示一次，请立即保存。</div>

            <h3 style="margin-top:16px">管理员账号</h3>
            <ul class="done-list">
                <li><span>账号</span><span><?= htmlspecialchars($result['admin']) ?></span></li>
                <li><span>密码</span><span><?= htmlspecialchars($result['pass']) ?></span></li>
                <li>
                    <span>后台入口令牌</span>
                    <span><code><?= htmlspecialchars($entryKeyShown) ?></code></span>
                </li>
            </ul>
            <div class="tip" style="margin-top:6px">
                后台入口必须携带入口令牌访问（<code>/admin/login.php?entry=令牌</code>），
                首次通过后 30 天内免带。令牌保存在 <code>config/config.php</code> 的
                <code>admin_entry_key</code>，泄露可随时更换（更换后所有入口 Cookie 立即失效）。
            </div>

            <h3 style="margin-top:20px">快捷入口</h3>
            <div class="link-cards">
                <a class="link-card" href="<?= $loginUrl ?>">
                    <div class="ic">🔑</div>
                    <div class="tx">
                        <div class="t">后台登录</div>
                        <div class="d"><?= $loginUrl ?></div>
                    </div>
                    <div class="go">→</div>
                </a>
                <a class="link-card" href="<?= $updateUrl ?>">
                    <div class="ic">🔄</div>
                    <div class="tx">
                        <div class="t">版本更新页面</div>
                        <div class="d"><?= $updateUrl ?></div>
                    </div>
                    <div class="go">→</div>
                </a>
                <a class="link-card" href="<?= $versionApi ?>">
                    <div class="ic">📡</div>
                    <div class="tx">
                        <div class="t">版本检查 API</div>
                        <div class="d"><?= $versionApi ?></div>
                    </div>
                    <div class="go">→</div>
                </a>
            </div>

            <div class="alert err" style="margin-top:20px">
                ⚠ 请立即删除 <code>install</code> 目录，否则存在被重装风险！<br>
                <span style="font-size:12px;color:rgba(239,68,68,.7)">命令：rm -rf update-system/install/</span>
            </div>

        <?php else: ?>
            <div class="alert warn">
                系统已安装。如需重新安装，请删除 <code>install/install.lock</code> 文件。
            </div>
            <div class="alert err">⚠ 请务必删除 <code>install</code> 目录。</div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

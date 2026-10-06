<?php
/**
 * 一键安装脚本
 * 浏览器访问: http://你的域名/install/install.php
 * 安装完成后请立即删除 install 目录
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

session_start();

$root        = dirname(__DIR__);
$configFile  = $root . '/config/config.php';
$schemaFile  = __DIR__ . '/schema.sql';
$installed   = is_file($root . '/install/install.lock');

$step = $_GET['step'] ?? '1';
$msg  = '';
$err  = '';

// 安全守卫：已安装的系统直接拒绝一切提交（防止重装覆盖 config.php / 重建管理员）
if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'activate_license') {
    http_response_code(403);
    exit('系统已安装，如需重装请先删除 install/install.lock');
}

// ------------------------------------------------------------------
// 授权激活（v2.66.0 新增，安装成功页调用）
// ------------------------------------------------------------------
// 仅允许 install.lock 存在（安装已完成）后调用：
// 输入授权码 → 调官方 update-system 的 api/license.php 激活并绑定当前域名
// → 成功后写入 config/config.php 的 license_key。
// 失败不阻断使用（宽限模式），后台「系统更新」会持续提示未激活。
if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_license') {
    header('Content-Type: application/json; charset=utf-8');

    $out = static function (bool $ok, string $msg): void {
        echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };

    $key = strtolower(trim((string) ($_POST['license_key'] ?? '')));
    if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
        $out(false, '授权码格式不正确（应为 32 位十六进制）');
    }

    // 从 config.php 读取官方更新服务器地址
    $cfgSrc = (string) @file_get_contents($configFile);
    if (!preg_match("/'update_server'\s*=>\s*'([^']+)'/", $cfgSrc, $mServer) || trim($mServer[1]) === '') {
        $out(false, 'config.php 中缺少 update_server 配置，无法激活');
    }
    $server = rtrim($mServer[1], '/');

    // 当前部署站域名（归一化：去端口、去 www.、转小写，与服务端规则一致）
    $domain = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $domain = preg_replace('/:\d+$/', '', $domain);
    $domain = preg_replace('/^www\./', '', $domain);
    if (!preg_match('/^[a-z0-9\-]+(\.[a-z0-9\-]+)+$/', $domain)) {
        $out(false, '无法识别当前部署域名，请通过正式域名访问安装页后重试');
    }

    // 调用授权 API 激活
    $ch = curl_init($server . '/api/license.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'activate', 'license_key' => $key, 'domain' => $domain]),
    ]);
    $body   = curl_exec($ch);
    $http   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http >= 400) {
        $out(false, '无法连接授权服务器：' . ($curlErr ?: 'HTTP ' . $http) . '（可稍后重试，不影响系统使用）');
    }
    $resp = json_decode((string) $body, true);
    if (!is_array($resp) || (int) ($resp['code'] ?? -1) !== 0) {
        $out(false, '激活失败：' . (string) ($resp['msg'] ?? '授权服务器返回异常'));
    }

    // 写入 config.php（preg_replace_callback 纯字面量替换，与安装主流程同规）
    $nbQ = static function (string $v): string {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $v);
    };
    if (!preg_match("/'license_key'\s*=>\s*'[^']*'/", $cfgSrc)) {
        $out(false, 'config.php 缺少 license_key 配置项（请使用官方完整安装包）');
    }
    $cfgNew = preg_replace_callback(
        "/'license_key'\s*=>\s*'[^']*'/",
        static function ($m) use ($nbQ, $key) { return "'license_key' => '" . $nbQ($key) . "'"; },
        $cfgSrc
    );
    file_put_contents($configFile, $cfgNew);

    $out(true, '激活成功，已绑定 ' . $domain);
}



// ------------------------------------------------------------------
// 处理提交
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 数据库检测
    if ($action === 'check_db') {
        $host = trim($_POST['db_host'] ?? '127.0.0.1');
        $port = (int) ($_POST['db_port'] ?? 3306);
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = (string) ($_POST['db_pass'] ?? '');

        // 输入白名单校验：仅允许数字/英文/常用符号
        if (!preg_match('/^[A-Za-z0-9.\-:]{1,120}$/', $host)) {
            $err = '数据库地址格式错误：仅限字母/数字/./-/:';
            $step = '1';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            $err = '数据库名格式错误：仅限字母/数字/下划线，1-64 位';
            $step = '1';
        } elseif (!preg_match('/^[\x21-\x7E]{1,64}$/', $user)) {
            $err = '数据库用户名格式错误：仅限可见英文及常用符号，1-64 位';
            $step = '1';
        } elseif (preg_match('/[^\x21-\x7E]/', $pass) || strlen($pass) > 64) {
            $err = '数据库密码格式错误：仅限可见英文及常用符号，最长 64 位';
            $step = '1';
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', trim($_POST['admin_user'] ?? 'admin'))) {
            $err = '管理员账号格式错误：3-32 位，仅限字母/数字/下划线';
            $step = '1';
        } elseif (preg_match('/[^\x21-\x7E]/', (string) ($_POST['admin_pass'] ?? '')) || strlen((string) ($_POST['admin_pass'] ?? '')) > 64) {
            $err = '管理员密码格式错误：仅限可见英文及常用符号，最长 64 位';
            $step = '1';
        }

        if ($err === '') try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            // 库不存在则创建
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $pdo->exec("USE `{$name}`");

            $_SESSION['db'] = compact('host', 'port', 'name', 'user', 'pass');
            $_SESSION['admin'] = [
                'user' => trim($_POST['admin_user'] ?? 'admin'),
                'pass' => (string) ($_POST['admin_pass'] ?? ''),
            ];
            $_SESSION['site_name'] = trim($_POST['site_name'] ?? 'Nebula 网络验证');

            // 安全要求 1：必须修改后台目录名（不能保留默认的 admin）
            $adminPath = trim($_POST['admin_path'] ?? '');
            if ($adminPath === '' || strcasecmp($adminPath, 'admin') === 0) {
                $err = '必须修改后台目录名（不能使用默认的 admin），否则无法继续安装';
                $step = '1';
            } elseif (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,31}$/', $adminPath)) {
                $err = '后台目录名格式错误：3-32 位，字母开头，仅限字母/数字/-/_';
                $step = '1';
            } elseif (is_dir($root . '/' . $adminPath)) {
                $err = '目录 ' . htmlspecialchars($adminPath) . ' 已存在，请换一个名称';
                $step = '1';
            } elseif (in_array(strtolower($adminPath), ['api', 'web', 'config', 'lib', 'logs', 'install', 'docs', 'examples'], true)) {
                $err = '后台目录名不能与系统保留目录重名';
                $step = '1';
            } else {
                $_SESSION['admin_path'] = $adminPath;
            }

            // 安全要求 2：必须修改默认管理员密码才能继续安装
            $adminPass = $_SESSION['admin']['pass'];
            if ($err === '') {
                if ($adminPass === '') {
                    $err = '必须设置管理员密码，不能留空使用默认密码';
                    $step = '1';
                } elseif (strtolower($adminPass) === 'admin888') {
                    $err = '管理员密码不能使用默认值 admin888，请修改后再继续安装';
                    $step = '1';
                } elseif (strlen($adminPass) < 8) {
                    $err = '管理员密码长度至少 8 位';
                    $step = '1';
                } else {
                    // 检测库内是否已有本系统数据表（nb_ 前缀）：有则进入安装方式选择，无则直接全新安装
                    $st = $pdo->query("SHOW TABLES LIKE 'nb\\_%'");
                    $exist = $st->fetchAll(PDO::FETCH_COLUMN);
                    $_SESSION['existing_tables'] = $exist;
                    if ($exist) {
                        header('Location: install.php?step=data');
                    } else {
                        $_SESSION['install_mode'] = 'fresh';
                        header('Location: install.php?step=2');
                    }
                    exit;
                }
            }
        } catch (Throwable $e) {
            // 不回显 PDO 原始信息（含 DSN / 主机 / 用户名 / SQLSTATE）：
            // 未登录访客也能触达本步骤，原样回显等于免费给它做数据库探测。
            @error_log('[nebula install] db connect failed: ' . $e->getMessage());
            $err = '数据库连接失败，请检查地址 / 端口 / 库名 / 账号 / 密码是否正确';
            $step = '1';
        }
    }

    // 选择安装方式（数据库已有数据时）
    if ($action === 'choose_mode') {
        if (!($_SESSION['db'] ?? null)) {
            $err = '会话已失效，请重新开始';
            $step = '1';
        } else {
            $mode = ($_POST['mode'] ?? '') === 'keep' ? 'keep' : 'fresh';
            if ($mode === 'fresh' && empty($_POST['confirm_fresh'])) {
                $err = '全新安装会删除数据库中的全部本系统数据表，请先勾选「我确认要删除」';
                $step = 'data';
            } else {
                $_SESSION['install_mode'] = $mode;
                header('Location: install.php?step=2');
                exit;
            }
        }
    }

    // 执行安装
    if ($action === 'do_install') {
        $db = $_SESSION['db'] ?? null;
        if (!$db) {
            $err = '会话已失效，请重新开始';
            $step = '1';
        } else {
            try {
                $mode = ($_SESSION['install_mode'] ?? 'fresh') === 'keep' ? 'keep' : 'fresh';
                $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                // 全新安装：清空已有本系统数据表（仅 nb_ 前缀，不动库内其他系统的表）
                if ($mode === 'fresh') {
                    $st = $pdo->query("SHOW TABLES LIKE 'nb\\_%'");
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
                        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
                    }
                }

                // 执行建表 SQL
                $sql = file_get_contents($schemaFile);
                // 去掉注释行
                $sql = preg_replace('/^--.*$/m', '', $sql);
                if ($mode === 'keep') {
                    // 保留数据：初始数据转 INSERT IGNORE（缺失才补，绝不覆盖已有行）
                    $sql = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sql);
                }
                $pdo->exec($sql);

                // 创建管理员（密码在 step1 已强制校验：非空且非默认值）
                $adminUser = $_SESSION['admin']['user'] ?? 'admin';
                $adminPass = $_SESSION['admin']['pass'] ?? '';
                if ($adminPass === '' || strtolower($adminPass) === 'admin888') {
                    throw new RuntimeException('管理员密码未通过安全校验，请返回第一步重新设置');
                }
                $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 10]);

                $prefix = 'nb_';
                $st = $pdo->prepare("SELECT id FROM `{$prefix}admins` WHERE username = ?");
                $st->execute([$adminUser]);
                if ($st->fetch()) {
                    $pdo->prepare("UPDATE `{$prefix}admins` SET password = ? WHERE username = ?")
                        ->execute([$hash, $adminUser]);
                } else {
                    $pdo->prepare("INSERT INTO `{$prefix}admins` (username, password, nickname, role, status, created_at) VALUES (?,?,?,1,1,?)")
                        ->execute([$adminUser, $hash, $adminUser, time()]);
                }

                // 站点名
                $siteName = $_SESSION['site_name'] ?? 'Nebula 网络验证';
                $pdo->prepare("UPDATE `{$prefix}settings` SET svalue = ? WHERE skey = 'site_name'")
                    ->execute([$siteName]);

                // 创建默认软件（3.1 协议无静态通信密钥，app_key 唯一标识即可）
                $swStmt = "INSERT INTO `{$prefix}softwares` (id, name, app_key, min_version, latest_version, status, remark, created_at, updated_at)
                           VALUES (1, '默认软件', 'SWDEFAULT', '1.0.0', '1.0.0', 1, '安装向导自动创建', ?, ?)
                           ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)";
                $pdo->prepare($swStmt)->execute([time(), time()]);

                // 自动生成后台入口 token（访问 /admin/ 必须带 ?k=token）
                // keep 模式：沿用库中已有 token，缺失才生成
                $entryToken = bin2hex(random_bytes(16));

                if ($mode === 'keep') {
                    $pdo->prepare("INSERT IGNORE INTO `{$prefix}settings` (skey, svalue, remark, updated_at) VALUES ('admin_entry_key', ?, '后台入口token(安装时自动生成)', ?)")
                        ->execute([$entryToken, time()]);
                    $oldTok = trim((string) $pdo->query("SELECT svalue FROM `{$prefix}settings` WHERE skey = 'admin_entry_key'")->fetchColumn());
                    if ($oldTok !== '') { $entryToken = $oldTok; }
                } else {
                    // 入口 token 存入数据库（后台运行时优先读取）
                    $pdo->prepare("INSERT INTO `{$prefix}settings` (skey, svalue, remark, updated_at) VALUES ('admin_entry_key', ?, '后台入口token(安装时自动生成)', ?)
                                   ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = VALUES(updated_at)")
                        ->execute([$entryToken, time()]);
                }

                // 后台目录名：fresh 按表单新目录改名写入；keep 沿用库中现有目录（不改名、不重命名目录）
                if ($mode === 'keep') {
                    $oldPath = trim((string) $pdo->query("SELECT svalue FROM `{$prefix}settings` WHERE skey = 'admin_path'")->fetchColumn());
                    if ($oldPath !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,31}$/', $oldPath)) {
                        $_SESSION['admin_path'] = $oldPath;
                    }
                    $pdo->prepare("INSERT IGNORE INTO `{$prefix}settings` (skey, svalue, remark, updated_at) VALUES ('admin_path', ?, '后台目录名(安装时强制修改)', ?)")
                        ->execute([$_SESSION['admin_path'] ?? 'admin', time()]);
                } else {
                    $adminPath = $_SESSION['admin_path'] ?? '';
                    if ($adminPath === '') {
                        throw new RuntimeException('后台目录名未通过安全校验，请返回第一步重新设置');
                    }
                    $pdo->prepare("INSERT INTO `{$prefix}settings` (skey, svalue, remark, updated_at) VALUES ('admin_path', ?, '后台目录名(安装时强制修改)', ?)
                                   ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = VALUES(updated_at)")
                        ->execute([$adminPath, time()]);
                }
                $adminPath = $_SESSION['admin_path'] ?? $adminPath;

                // 重命名 admin 目录（仅全新安装：强制修改，消除被扫描的默认路径）
                $oldAdminDir = $root . '/admin';
                $newAdminDir = $root . '/' . $adminPath;
                if (is_dir($oldAdminDir)) {
                    clearstatcache();
                    if (!@rename($oldAdminDir, $newAdminDir)) {
                        throw new RuntimeException('后台目录重命名失败（admin → ' . $adminPath . '），请检查目录权限后重试');
                    }
                } elseif ($mode !== 'keep' && !is_dir($newAdminDir)) {
                    throw new RuntimeException('未找到后台目录 admin，安装包不完整');
                } elseif ($mode === 'keep' && !is_dir($newAdminDir) && !is_dir($oldAdminDir)) {
                    throw new RuntimeException('保留数据安装：未找到现有后台目录 ' . $adminPath . '，请确认目录是否存在');
                }

                // 同步更新项目内的 nginx / apache 规则副本（/admin/ → /新目录名/）
                // 两份都要改：nginx.htaccess 供 nginx 用户 include，.htaccess 供 Apache 直接生效。
                foreach (['nginx.htaccess', '.htaccess'] as $nbRuleFile) {
                    $nbRulePath = $root . '/' . $nbRuleFile;
                    if (is_file($nbRulePath) && is_writable($nbRulePath)) {
                        file_put_contents($nbRulePath,
                            str_replace('/admin', '/' . $adminPath, (string) file_get_contents($nbRulePath)));
                    }
                }

                // 写入配置
                //
                // ⚠ 必须用 preg_replace_callback，不能用 preg_replace。
                //   preg_replace 的替换串会被二次解析：`$1`、`\1`、`\\` 都是特殊语法，
                //   数据库密码里只要出现 `$` 或反斜杠，就会被当成反向引用写坏配置；
                //   而 `'` 未转义时可以截断字符串字面量，直接向 config.php 注入 PHP 代码
                //   （例如密码填  x';system($_GET[0]);#  ）。
                //   回调的返回值是纯字面量，不做任何二次解析，从根上堵死这条注入路径。
                //
                //   转义顺序：先转义反斜杠，再转义单引号（config.php 用的是单引号字面量）。
                $nbQ = static function ($v): string {
                    return str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v);
                };

                if (!is_writable($configFile)) {
                    throw new RuntimeException('config/config.php 不可写，请手动修改配置');
                }

                $config = (string) file_get_contents($configFile);

                // --------------------------------------------------
                // 强制校验更新服务器地址（update_server）
                // 该配置随官方安装包自带（指向官方版本更新系统），
                // 缺失 / 留空 / 被删改则拒绝安装，
                // 防止部署者自行移除后无法接收版本更新。
                // --------------------------------------------------
                if (!preg_match("/'update_server'\s*=>\s*'https?:\/\/[^']+'/", $config)) {
                    throw new RuntimeException(
                        '安装包缺少更新服务器地址配置：config/config.php 中的 update_server 缺失或为空。' .
                        '请使用官方完整安装包（自带更新地址），勿删除该配置，否则系统将无法检测版本更新。'
                    );
                }

                $config = preg_replace_callback("/'host'\s*=>\s*'[^']*'/",     function ($m) use ($nbQ, $db) { return "'host'    => '" . $nbQ($db['host']) . "'"; }, $config);
                $config = preg_replace_callback("/'port'\s*=>\s*\d+/",         function ($m) use ($db) { return "'port'    => " . (int) $db['port']; }, $config);
                $config = preg_replace_callback("/'name'\s*=>\s*'[^']*'/",     function ($m) use ($nbQ, $db) { return "'name'    => '" . $nbQ($db['name']) . "'"; }, $config);
                $config = preg_replace_callback("/'user'\s*=>\s*'[^']*'/",     function ($m) use ($nbQ, $db) { return "'user'    => '" . $nbQ($db['user']) . "'"; }, $config);
                $config = preg_replace_callback("/'pass'\s*=>\s*'[^']*'/",     function ($m) use ($nbQ, $db) { return "'pass'    => '" . $nbQ($db['pass']) . "'"; }, $config);
                $config = preg_replace_callback("/'entry_key'\s*=>\s*'[^']*'/", function ($m) use ($nbQ, $entryToken) { return "'entry_key' => '" . $nbQ($entryToken) . "'"; }, $config);
                $config = preg_replace_callback("/'path'\s*=>\s*'admin'/",      function ($m) use ($nbQ, $adminPath) { return "'path'     => '" . $nbQ($adminPath) . "'"; }, $config);

                file_put_contents($configFile, $config);

                file_put_contents($root . '/install/install.lock', date('Y-m-d H:i:s'));

                $_SESSION['install_result'] = [
                    'admin'       => $adminUser,
                    'pass'        => $adminPass,
                    'entry_token' => $entryToken,
                    'admin_path'  => $adminPath,
                ];

                header('Location: install.php?step=3');
                exit;
            } catch (Throwable $e) {
                $err = '安装失败: ' . $e->getMessage();
                $step = '2';
            }
        }
    }
}

// 已安装保护
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
<title>Nebula 网络验证 - 安装向导</title>
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
.steps div {
    flex: 1; height: 4px; background: rgba(255,255,255,.1); border-radius: 2px;
    transition: .3s;
}
.steps div.on { background: linear-gradient(90deg, #7c5cff, #5b9dff); }
.field { margin-bottom: 18px; }
.field label {
    display: block; font-size: 13px; font-weight: 600;
    margin-bottom: 6px; color: #cbd5e1;
}
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
code {
    background: rgba(124,92,255,.12); padding: 2px 7px; border-radius: 5px;
    font-size: 12px; color: #c4b5fd; word-break: break-all;
    border: 1px solid rgba(124,92,255,.2);
}
pre {
    background: #0b0e17; color: #86efac; padding: 16px; border-radius: 10px;
    font-size: 12px; overflow-x: auto; line-height: 1.6; margin: 12px 0;
    border: 1px solid rgba(255,255,255,.08);
}
.done-list { list-style: none; }
.done-list li {
    padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.06);
    font-size: 13px; display: flex; justify-content: space-between; gap: 12px;
}
.done-list li span:last-child { font-weight: 600; color: #c4b5fd; text-align: right; word-break: break-all; }
h3 { font-size: 15px; margin-bottom: 12px; color: #f1f5f9; }
/* 安装完成页：入口卡片 */
.link-cards { display: grid; gap: 10px; margin: 12px 0 4px; }
.link-card {
    display: flex; align-items: center; gap: 12px; padding: 13px 16px;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px; text-decoration: none; transition: .18s;
}
.link-card:hover { border-color: rgba(124,92,255,.55); background: rgba(124,92,255,.08); transform: translateY(-1px); }
.link-card .ic {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #7c5cff, #5b9dff); font-size: 18px; color: #fff;
}
.link-card .tx { flex: 1; min-width: 0; }
.link-card .t { font-size: 13.5px; font-weight: 600; color: #f1f5f9; }
.link-card .d { font-size: 12px; color: #94a3b8; margin-top: 2px; word-break: break-all; }
.link-card .go { color: #7c5cff; font-size: 16px; flex-shrink: 0; }
.mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; color: #c4b5fd; word-break: break-all; }
</style>
</head>
<body>
<div class="card">
    <div class="head">
        <h1>Nebula 网络验证</h1>
        <p>安装向导 · 支持心跳 / 登录 / 激活码 / 设备绑定 / 版本校验</p>
    </div>
    <div class="body">
        <div class="steps">
            <div class="<?= in_array($step, ['1','data','2','3','done']) ? 'on' : '' ?>"></div>
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
                'PHP 版本 >= 8.0'   => version_compare(PHP_VERSION, '8.0.0', '>='),
                'PDO MySQL 扩展'    => extension_loaded('pdo_mysql'),
                'OpenSSL 扩展'      => extension_loaded('openssl'),
                'JSON 扩展'         => extension_loaded('json'),
                'config 目录可写'   => is_writable($root . '/config'),
                'logs 目录可写'     => is_writable($root . '/logs'),
            ];
            $allOk = !in_array(false, $checks, true);
            ?>
            <ul class="done-list">
                <?php foreach ($checks as $k => $v): ?>
                    <li>
                        <span><?= $k ?></span>
                        <span style="color:<?= $v ? '#38a169' : '#e53e3e' ?>">
                            <?= $v ? '✓ 通过' : '✗ 失败' ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$allOk): ?>
                <div class="alert err" style="margin-top:16px">
                    请先解决上述环境问题再继续安装。
                </div>
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
                        <input name="db_name" value="nebula_auth" required>
                        <div class="tip">若不存在会自动创建</div>
                    </div>
                    <div class="row">
                        <div class="field">
                            <label>数据库用户名</label>
                            <input name="db_user" value="root" required data-filter="id">
                            <div class="tip">部分主机（如 serv00）的用户名带下划线</div>
                        </div>
                        <div class="field">
                            <label>数据库密码</label>
                            <input name="db_pass" type="password">
                        </div>
                    </div>
                    <h3 style="margin-top:24px">管理员与站点</h3>
                    <div class="field">
                        <label>站点名称</label>
                        <input name="site_name" value="Nebula 网络验证">
                    </div>
                    <div class="row">
                        <div class="field">
                            <label>管理员账号</label>
                            <input name="admin_user" value="admin" required data-filter="id">
                        </div>
                        <div class="field">
                            <label>管理员密码</label>
                            <input name="admin_pass" type="password" minlength="8" required placeholder="至少 8 位，不可使用默认密码">
                        </div>
                    </div>
                    <div class="field">
                        <label>后台目录名</label>
                        <input name="admin_path" value="nb<?= substr(bin2hex(random_bytes(4)), 0, 6) ?>" required pattern="[A-Za-z][A-Za-z0-9_-]{2,31}">
                        <div class="tip">⚠ 必须修改（不可用默认 admin）：3-32 位，字母开头，仅字母/数字/-/_。<br>同时请确保 nginx 配置中引用 /admin/ 的规则同步更新（安装程序会自动改写项目内的 nginx.htaccess）。</div>
                    </div>
                    <div class="alert warn" style="margin-top:16px">
                        安全要求：管理员密码必须修改（不能留空或 admin888），后台目录名必须改名 —— 否则无法继续安装。
                    </div>
                    <button class="btn" type="submit">下一步：开始安装</button>
                </form>
            <?php endif; ?>

        <?php elseif ($step === 'data'): ?>
            <?php $tables = $_SESSION['existing_tables'] ?? []; ?>
            <div class="alert warn">
                检测到数据库 <b><?= htmlspecialchars($_SESSION['db']['name'] ?? '') ?></b> 中已有
                <b><?= count($tables) ?></b> 张本系统数据表（nb_ 前缀），请选择安装方式。
            </div>
            <?php if ($tables): ?>
            <ul class="done-list" style="max-height:180px;overflow-y:auto">
                <?php foreach (array_slice($tables, 0, 12) as $t): ?>
                    <li><span><code><?= htmlspecialchars((string) $t) ?></code></span></li>
                <?php endforeach; ?>
                <?php if (count($tables) > 12): ?>
                    <li><span style="color:#94a3b8">… 其余 <?= count($tables) - 12 ?> 张略</span></li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>
            <form method="post" style="margin-top:18px">
                <input type="hidden" name="action" value="choose_mode">
                <div class="field">
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="radio" name="mode" value="keep" checked style="width:auto">
                        保留数据安装（推荐）
                    </label>
                    <div class="tip">仅补建缺失的数据表与初始数据，保留全部现有数据；软件密钥、后台入口与目录均沿用现有值，管理员账号按下方填写更新密码。</div>
                </div>
                <div class="field">
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="radio" name="mode" value="fresh" id="modeFresh" style="width:auto">
                        全新安装（清空重装）
                    </label>
                    <div class="tip">删除以上全部 nb_ 前缀数据表后重建，所有数据（管理员 / 软件 / 用户 / 卡密 / 订单）将<b style="color:#fca5a5">永久丢失且不可恢复</b>。</div>
                </div>
                <label id="confirmFreshBox" style="display:none;align-items:flex-start;gap:8px;padding:12px 14px;margin:4px 0 14px;border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.1);border-radius:10px;font-size:13px;color:#fca5a5;cursor:pointer">
                    <input type="checkbox" name="confirm_fresh" value="1" style="width:auto;margin-top:2px">
                    我确认要删除以上全部数据表并重新安装
                </label>
                <button class="btn" type="submit">下一步</button>
            </form>
            <script>
            (function () {
                var fresh = document.getElementById('modeFresh'), box = document.getElementById('confirmFreshBox');
                function sync() { box.style.display = fresh.checked ? 'flex' : 'none'; }
                fresh.addEventListener('change', sync);
                document.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', sync); });
                sync();
            })();
            </script>

        <?php elseif ($step === '2'): ?>
            <?php if (($_SESSION['install_mode'] ?? 'fresh') === 'keep'): ?>
            <div class="alert ok">
                安装方式：<b>保留数据安装</b> —— 仅补建缺失的表与初始数据，现有数据、软件密钥、后台入口与目录保持不变；管理员账号若已存在则更新为下方填写的新密码。
            </div>
            <?php else: ?>
            <div class="alert warn">
                即将创建数据库表并写入配置。此操作会重置 <code>config/config.php</code> 中的数据库与密钥信息。
            </div>
            <?php endif; ?>
            <ul class="done-list">
                <li><span>数据库</span><span><?= htmlspecialchars($_SESSION['db']['name'] ?? '') ?></span></li>
                <li><span>地址</span><span><?= htmlspecialchars(($_SESSION['db']['host'] ?? '') . ':' . ($_SESSION['db']['port'] ?? '')) ?></span></li>
                <li><span>管理员</span><span><?= htmlspecialchars($_SESSION['admin']['user'] ?? '') ?></span></li>
            </ul>
            <form method="post" style="margin-top:20px">
                <input type="hidden" name="action" value="do_install">
                <button class="btn" type="submit">确认安装</button>
            </form>

        <?php elseif ($step === '3' && $result): ?>
            <?php
            $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/install');
            $home   = htmlspecialchars($base . '/' . $result['admin_path'] . '/home.php?k=' . $result['entry_token']);
            $apilog = htmlspecialchars($base . '/' . $result['admin_path'] . '/index.php?action=login');
            ?>
            <div class="alert ok">🎉 安装完成！以下账号信息仅显示这一次，请立即复制保存。</div>

            <div class="alert warn" style="margin-bottom:16px">
                 进入管理后台 <b>「软件管理」</b> 可查看每个软件的
                app_key 与密钥，新增软件、重置密钥也都在那里操作。
            </div>

            <h3 style="margin-top:16px">👤 管理员账号</h3>
            <ul class="done-list">
                <li><span>账号</span><span><?= htmlspecialchars($result['admin']) ?></span></li>
                <li><span>密码</span><span><?= htmlspecialchars($result['pass']) ?></span></li>
            </ul>

            <h3 style="margin-top:20px">🔑 授权激活（建议立即完成）</h3>
            <div class="alert ok" id="licDone" style="display:none"></div>
            <div class="alert err" id="licFail" style="display:none"></div>
            <div class="alert warn" id="licHint">
                输入官方签发的授权码完成激活（绑定当前域名）。<b>不激活不影响系统使用</b>，
                但开启更新门禁后将无法在线获取新版本与安全补丁，建议尽快激活。
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
                <input type="text" id="licKey" class="mono" placeholder="32 位授权码" style="flex:1;min-width:0"
                       oninput="this.value=this.value.toLowerCase().replace(/[^0-9a-f]/g,'')">
                <button type="button" id="licBtn" onclick="doActivate()" class="btn">激活</button>
            </div>

            <h3 style="margin-top:20px">快捷入口（点击直达）</h3>
            <div class="link-cards">
                <a class="link-card" href="<?= $home ?>">
                    <div class="ic">🛠</div>
                    <div class="tx">
                        <div class="t">管理后台（带入口令牌，务必收藏）</div>
                        <div class="d mono"><?= $home ?></div>
                    </div>
                    <div class="go">→</div>
                </a>
                <a class="link-card" href="<?= $apilog ?>">
                    <div class="ic">🔑</div>
                    <div class="tx">
                        <div class="t">后台登录页（无令牌时从此进入，配合 ?k= 使用）</div>
                        <div class="d mono"><?= $apilog ?></div>
                    </div>
                    <div class="go">→</div>
                </a>
                <a class="link-card" href="<?= htmlspecialchars($base) ?>/web/">
                    <div class="ic">🌐</div>
                    <div class="tx">
                        <div class="t">官网首页（注册 / 登录 / 激活 / 公告）</div>
                        <div class="d mono"><?= htmlspecialchars($base) ?>/web/</div>
                    </div>
                    <div class="go">→</div>
                </a>
                <a class="link-card" href="<?= htmlspecialchars($base) ?>/shop/">
                    <div class="ic">🛒</div>
                    <div class="tx">
                        <div class="t">发卡商店（自动发货 · 支持易支付）</div>
                        <div class="d mono"><?= htmlspecialchars($base) ?>/shop/</div>
                    </div>
                    <div class="go">→</div>
                </a>
            </div>

            <div class="alert warn">
                客户端接口地址：<code><?= htmlspecialchars($base) ?>/api/index.php?action=init</code><br>
                代理商后台：<code><?= htmlspecialchars($base) ?>/agent/</code>（默认关闭，需在后台「系统设置 → 代理商设置」开启）
            </div>

            <div class="alert err" style="margin-top:20px">
                ⚠ 安装完成后，请立即手动删除整个 <code>install</code> 目录，否则存在被重装风险！<br>
                若 nginx 独立配置（vhost）中引用了 <code>/admin/</code> 路由规则，请手动同步改为 <code>/<?= htmlspecialchars($result['admin_path']) ?>/</code> 后重载 nginx。
            </div>

        <?php else: ?>
            <div class="alert warn">
                系统已安装。如需重新安装，请删除 <code>install/install.lock</code> 文件。
            </div>
            <div class="alert err">⚠ 请务必删除 <code>install</code> 目录。</div>
        <?php endif; ?>
    </div>
</div>
<script src="../assets/input-filter.js?v=<?= time() ?>"></script>
<script>
function doActivate() {
    var key = (document.getElementById('licKey').value || '').trim();
    var btn = document.getElementById('licBtn');
    var ok = document.getElementById('licDone'), fail = document.getElementById('licFail');
    ok.style.display = fail.style.display = 'none';
    if (!key) { fail.textContent = '请输入授权码'; fail.style.display = 'block'; return; }
    btn.disabled = true; btn.textContent = '激活中...';
    var fd = new FormData();
    fd.append('action', 'activate_license');
    fd.append('license_key', key);
    fetch(location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (r) {
            btn.disabled = false; btn.textContent = '激活';
            if (r.ok) {
                ok.textContent = '✅ ' + r.msg + '，可直接进入管理后台使用';
                ok.style.display = 'block';
                document.getElementById('licHint').style.display = 'none';
            } else {
                fail.textContent = r.msg;
                fail.style.display = 'block';
            }
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = '激活';
            fail.textContent = '网络请求失败，请稍后重试';
            fail.style.display = 'block';
        });
}
</script>
</body>
</html>

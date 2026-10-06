<?php
/**
 * admin action: system_update_do
 * ------------------------------------------------------------------
 * 一键自动更新：下载更新包 → SHA-256 校验 → 备份 → 解压覆盖 → 更新版本号
 *
 * 请求方式：POST
 * 参数：
 *   download_url  下载地址
 *   sha256       SHA-256 校验值
 *   version      目标版本号
 *   build        目标 Build 编号
 */

// ------------------------------------------------------------------
// 参数校验
// ------------------------------------------------------------------
$downloadUrl = trim((string)($input['download_url'] ?? ''));
$sha256      = trim((string)($input['sha256'] ?? ''));
$targetVer   = trim((string)($input['version'] ?? ''));
$targetBuild = (int)($input['build'] ?? 0);

if ($downloadUrl === '' || !preg_match('#^https://#i', $downloadUrl)) {
    Response::error(1001, '下载地址不合法（仅允许 HTTPS）');
}
if ($sha256 === '' || strlen($sha256) !== 64) {
    Response::error(1001, 'SHA-256 校验值不合法');
}
if ($targetVer === '' || !preg_match('/^\d+\.\d+\.\d+$/', $targetVer)) {
    Response::error(1001, '版本号不合法');
}

// ------------------------------------------------------------------
// 更新锁（防并发）
// ------------------------------------------------------------------
$lockFile = NB_ROOT . '/logs/update.lock';
if (is_file($lockFile)) {
    $lockAge = time() - (int)@filemtime($lockFile);
    if ($lockAge < 1800) {
        Response::error(1001, '系统正在更新中，请 ' . ceil((1800 - $lockAge) / 60) . ' 分钟后再试');
    }
    @unlink($lockFile);
}
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) @mkdir($lockDir, 0750, true);
$fp = @fopen($lockFile, 'x');
if ($fp === false) {
    Response::error(1001, '获取更新锁失败，请稍后重试');
}
fwrite($fp, json_encode(['pid' => getmypid(), 'time' => time(), 'ip' => Util::ip()]));
fclose($fp);

// ------------------------------------------------------------------
// 存储目录
// ------------------------------------------------------------------
$pkgDir   = NB_ROOT . '/logs/update_packages';
$bakDir   = NB_ROOT . '/logs/update_backups/' . date('Ymd_His');
if (!is_dir($pkgDir)) @mkdir($pkgDir, 0750, true);
if (!is_dir($bakDir)) @mkdir($bakDir, 0750, true);

$pkgPath = $pkgDir . '/update_' . $targetVer . '.zip';

// ------------------------------------------------------------------
// 保护目录（不被覆盖）
// ------------------------------------------------------------------
$protectedDirs = ['config', 'logs', 'data', 'uploads', '.catpaw', '.git', '.freebuff', '.workbuddy'];

try {

    // ----------------------------------------------------------------
    // 1. 下载更新包
    // ----------------------------------------------------------------
    $downloadOk = false;
    $downloadErr = '';
    // 域名白名单校验：只允许从配置的更新服务器下载
    $allowedHost = parse_url(Config::get('update_server', ''), PHP_URL_HOST);
    $dlHost = parse_url($downloadUrl, PHP_URL_HOST);
    if (!$dlHost || ($allowedHost && $dlHost !== $allowedHost)) {
        throw new RuntimeException('下载地址域名不在允许列表中，拒绝执行更新');
    }

    if (function_exists('curl_init')) {
        $fp2 = @fopen($pkgPath, 'wb');
        if ($fp2 === false) {
            throw new RuntimeException('无法创建下载文件，请检查 logs 目录权限');
        }
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp2,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Nebula-Updater/' . NB_VERSION,
        ]);
        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $effUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        fclose($fp2);
        if (!$ok || $httpCode >= 400) {
            @unlink($pkgPath);
            $downloadErr = '下载失败：' . ($err ?: 'HTTP ' . $httpCode);
        } else {
            // 2026-10-06 审计：重定向信任边界修复 —— FOLLOWLOCATION 跟随 302 后，最终主机
            // 必须仍在白名单内（此前只校验初始 URL，恶意 302 可把下载导向白名单外主机）。
            $effHost = parse_url($effUrl, PHP_URL_HOST);
            if ($allowedHost && ($effHost === false || $effHost === null || $effHost !== $allowedHost)) {
                @unlink($pkgPath);
                throw new RuntimeException('下载地址被重定向到非白名单域名，已中止更新：' . (string) $effHost);
            }
            $downloadOk = true;
        }
    } else {
        // 回退到 file_get_contents
        $ctx = stream_context_create(['http' => ['timeout' => 300], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $data = @file_get_contents($downloadUrl, false, $ctx);
        if ($data === false) {
            $downloadErr = '下载失败：无法连接更新服务器';
        } else {
            @file_put_contents($pkgPath, $data);
            $downloadOk = true;
        }
    }
    if (!$downloadOk) {
        throw new RuntimeException($downloadErr);
    }

    // ----------------------------------------------------------------
    // 2. SHA-256 校验
    // ----------------------------------------------------------------
    $actualHash = hash_file('sha256', $pkgPath);
    if ($actualHash === false) {
        throw new RuntimeException('无法计算下载文件 SHA-256');
    }
    if (!hash_equals(strtolower($sha256), strtolower($actualHash))) {
        @unlink($pkgPath);
        throw new RuntimeException('SHA-256 校验失败，文件可能已损坏或被篡改');
    }

    // ----------------------------------------------------------------
    // 3. 打开 ZIP 并验证 manifest
    // ----------------------------------------------------------------
    if (!extension_loaded('zip')) {
        throw new RuntimeException('服务器未安装 PHP zip 扩展，无法解压更新包');
    }
    $zip = new ZipArchive();
    if ($zip->open($pkgPath) !== true) {
        throw new RuntimeException('无法打开更新包，文件可能已损坏');
    }

    // 读取 manifest.json
    $manifestIdx = $zip->locateName('manifest.json');
    if ($manifestIdx === false) {
        $zip->close();
        throw new RuntimeException('更新包中未找到 manifest.json');
    }
    $manifestJson = $zip->getFromIndex($manifestIdx);
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest)) {
        $zip->close();
        throw new RuntimeException('manifest.json 格式错误');
    }

    // 校验产品标识
    $manifestProduct = $manifest['product'] ?? '';
    if ($manifestProduct !== 'nebula-verification') {
        $zip->close();
        throw new RuntimeException('产品标识不匹配（' . $manifestProduct . '），此更新包不适用于当前系统');
    }

    // ----------------------------------------------------------------
    // 4. 备份当前文件
    // ----------------------------------------------------------------
    // 更新包里的后台目录在打包时固定为 admin/，这里按本站实际后台目录名重写。
    // 优先使用 config 的 admin.path（单目录）；未配置或非法时自动探测站点根下
    // 的后台目录（特征：AdminAuth.php + assets/js/app.js + handlers/），可命中
    // 多个随机目录，保证改名后的站点后台也能被更新（2026-10-06 修复：
    // 生产曾因未配置 admin.path 导致后台目录整段漏更）。
    $adminNames = [];
    $sn = trim((string) Config::get('admin.path', ''), '/');
    if ($sn !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,31}$/', $sn)) {
        $adminNames[] = $sn;
    } else {
        $reserved = array_flip(['api', 'agent', 'shop', 'web', 'config', 'lib', 'logs',
            'data', 'uploads', 'update-system', 'install', 'pack', 'docs', 'assets',
            'deploy', 'tests', 'releases']);
        $scan = @scandir(NB_ROOT);
        if (is_array($scan)) {
            foreach ($scan as $name) {
                if ($name === '.' || $name === '..' || isset($reserved[$name])) {
                    continue;
                }
                $dir = NB_ROOT . '/' . $name;
                if (!is_dir($dir)) continue;
                if (is_file($dir . '/AdminAuth.php')
                    && is_file($dir . '/assets/js/app.js')
                    && is_dir($dir . '/handlers')) {
                    $adminNames[] = $name;
                }
            }
        }
    }
    if (!$adminNames) {
        $adminNames[] = 'admin';
    }
    sort($adminNames);
    // admin/xx → 每个实际后台目录映射后的路径（可能多个）
    $mapEntries = static function (string $path) use ($adminNames): array {
        if ($path === 'admin' || strpos($path, 'admin/') === 0) {
            $suffix = substr($path, 5);
            $out = [];
            foreach ($adminNames as $n) {
                $out[] = $n . $suffix;
            }
            return $out;
        }
        return [$path];
    };

    $filesBackedUp = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        // 用 substr 判断是否为目录条目（以 / 结尾）
        if (substr($entry, -1) === '/') continue;
        // 跳过 manifest.json 本身
        if ($entry === 'manifest.json') continue;
        $rawEntry = str_replace('\\', '/', $entry);
        foreach ($mapEntries($rawEntry) as $entryNorm) {
            // 跳过保护目录
            $skip = false;
            foreach ($protectedDirs as $pdir) {
                if ($entryNorm === $pdir || substr($entryNorm, 0, strlen($pdir) + 1) === $pdir . '/') { $skip = true; break; }
            }
            if ($skip) continue;
            // Zip Slip 防护
            if (substr($entryNorm, 0, 1) === '/' || strpos($entryNorm, '..') !== false) continue;
            if (preg_match('#^[a-zA-Z]:#', $entryNorm)) continue;

            $srcPath = NB_ROOT . '/' . $entryNorm;
            if (!is_file($srcPath)) continue;
            $bakPath = $bakDir . '/' . $entryNorm;
            $bakDirName = dirname($bakPath);
            if (!is_dir($bakDirName)) @mkdir($bakDirName, 0750, true);
            if (@copy($srcPath, $bakPath)) $filesBackedUp++;
        }
    }

    // 备份 version.php（lib/bootstrap.php 中的 NB_VERSION 定义）
    $bootstrapFile = NB_ROOT . '/lib/bootstrap.php';
    if (is_file($bootstrapFile)) {
        @copy($bootstrapFile, $bakDir . '/bootstrap.php.bak');
    }
    // 写备份信息
    @file_put_contents($bakDir . '/backup_info.json', json_encode([
        'from_version' => NB_VERSION,
        'to_version'   => $targetVer,
        'to_build'     => $targetBuild,
        'created_at'   => date('Y-m-d H:i:s'),
        'files_count'  => $filesBackedUp,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ----------------------------------------------------------------
    // 5. 解压覆盖文件
    // ----------------------------------------------------------------
    $updated = 0;
    $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        // 用 substr 判断是否为目录条目（以 / 结尾）
        if (substr($entry, -1) === '/') continue;
        if ($entry === 'manifest.json') continue;

        $rawEntry = str_replace('\\', '/', $entry);
        foreach ($mapEntries($rawEntry) as $entryNorm) {
            $skip = false;
            foreach ($protectedDirs as $pdir) {
                if ($entryNorm === $pdir || substr($entryNorm, 0, strlen($pdir) + 1) === $pdir . '/') { $skip = true; break; }
            }
            if ($skip) continue;
            // Zip Slip 防护
            if (substr($entryNorm, 0, 1) === '/' || strpos($entryNorm, '..') !== false) continue;
            if (preg_match('#^[a-zA-Z]:#', $entryNorm)) continue;

            $targetPath = NB_ROOT . '/' . $entryNorm;
            $targetDirName = dirname($targetPath);
            if (!is_dir($targetDirName)) @mkdir($targetDirName, 0750, true);
            $content = $zip->getFromIndex($i);
            if ($content === false) { $errors[] = "读取失败：{$entry}"; continue; }
            if (@file_put_contents($targetPath, $content) === false) { $errors[] = "写入失败：{$entry}"; continue; }
            $updated++;
        }
    }
    $zip->close();

    // ----------------------------------------------------------------
    // 6. 更新版本号（lib/bootstrap.php 中的 NB_VERSION）
    // ----------------------------------------------------------------
    if (is_file($bootstrapFile)) {
        $content = file_get_contents($bootstrapFile);
        if ($content !== false) {
            // 替换 NB_VERSION 定义
            // 必须用 preg_replace_callback：preg_replace 的替换串会被二次解析，
            // \1、$1、\\ 都是特殊语法，单引号未转义时还能截断字符串字面量注入 PHP 代码。
            // 回调只返回纯字面量，不做二次解析。（与 install/install.php 的写法保持一致）
            $newContent = preg_replace_callback(
                "/define\('NB_VERSION',\s*'[^']*'\)/",
                static function () use ($targetVer) {
                    return "define('NB_VERSION', '" . $targetVer . "')";
                },
                $content
            );
            if ($newContent !== null && $newContent !== $content) {
                @file_put_contents($bootstrapFile, $newContent);
            }
        }
    }

    // ----------------------------------------------------------------
    // 7. 清理缓存
    // ----------------------------------------------------------------
    Cache::del('system_update_check');
    // 清除模板缓存等
    $cacheDir = NB_ROOT . '/logs/cache';
    if (is_dir($cacheDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $f) {
            if ($f->isFile()) @unlink($f->getPathname());
        }
    }

    // ----------------------------------------------------------------
    // 8. 清理下载包
    // ----------------------------------------------------------------
    @unlink($pkgPath);

    // 释放锁
    @unlink($lockFile);

    // 记录日志
    Logger::log('admin_system_update', $admin['id'] ?? 0, "系统更新 {$targetVer}", [
        'from' => NB_VERSION,
        'to' => $targetVer,
        'updated_files' => $updated,
        'backed_up_files' => $filesBackedUp,
        'backup_path' => $bakDir,
    ]);

    Response::ok([
        'updated_files' => $updated,
        'backup_path' => basename($bakDir),
        'backup_files' => $filesBackedUp,
        'errors' => $errors,
        'new_version' => $targetVer,
    ], '系统更新完成');

} catch (Throwable $e) {
    // 释放锁
    @unlink($lockFile);
    // 清理下载的半成品
    if (isset($pkgPath) && is_file($pkgPath)) @unlink($pkgPath);

    Logger::log('admin_system_update', $admin['id'] ?? 0, '系统更新失败：' . $e->getMessage());
    Response::error(1001, '更新失败：' . $e->getMessage());
}

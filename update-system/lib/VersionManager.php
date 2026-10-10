<?php
/**
 * 版本更新引擎 - VersionManager
 * ==================================================================
 * 独立系统的核心更新引擎。
 *
 * 负责：
 *   getCurrentVersion()      获取当前版本
 *   checkLatestVersion()     检查官方最新版本（带缓存）
 *   compareVersion()         语义化版本比较
 *   downloadUpdate()         下载更新包
 *   verifySha256()           SHA-256 校验
 *   verifySignature()        Ed25519 数字签名验证
 *   validateManifest()       Manifest 校验
 *   backup()                 备份数据库与文件
 *   runMigrations()           执行数据库 Migration
 *   installUpdate()           安装更新（替换文件）
 *   rollback()               回滚
 *   healthCheck()             健康检查
 *
 * 安全链路：
 *   下载 → SHA-256 → 数字签名 → Manifest → 安全解压 → Migration → 更新文件
 */

require_once __DIR__ . '/../version.php';

class VersionManager
{
    // ------------------------------------------------------------------
    // 常量
    // ------------------------------------------------------------------

    const LOCK_FILE = 'storage/updates/update.lock';
    const LOCK_TIMEOUT = 1800; // 30 分钟
    const CACHE_FILE = 'storage/updates/version-cache.json';
    const CACHE_TTL = 21600; // 6 小时
    const PROTECTED_DIRS = ['config', 'uploads', 'storage', 'logs'];
    const PUBLIC_KEY_FILE = 'storage/updates/update-public-key.pem';

    // ------------------------------------------------------------------
    // 版本信息
    // ------------------------------------------------------------------

    public static function getCurrentVersion(): array
    {
        return [
            'version'        => APP_VERSION,
            'build'          => APP_BUILD,
            'channel'        => APP_CHANNEL,
            'product'        => APP_PRODUCT,
            'schema_version' => APP_SCHEMA_VERSION,
        ];
    }

    public static function versionString(): string
    {
        return APP_VERSION . ' (Build ' . APP_BUILD . ')';
    }

    // ------------------------------------------------------------------
    // 版本比较
    // ------------------------------------------------------------------

    public static function compareVersion(string $v1, string $v2): int
    {
        $p1 = self::parseVersion($v1);
        $p2 = self::parseVersion($v2);
        for ($i = 0; $i < 3; $i++) {
            $a = (int) ($p1[$i] ?? 0);
            $b = (int) ($p2[$i] ?? 0);
            if ($a < $b) return -1;
            if ($a > $b) return 1;
        }
        return 0;
    }

    private static function parseVersion(string $version): array
    {
        $version = trim($version);
        if (str_starts_with($version, 'v') || str_starts_with($version, 'V')) {
            $version = substr($version, 1);
        }
        return array_slice(explode('.', $version), 0, 3);
    }

    public static function isBelowMinVersion(?string $minVersion): bool
    {
        if ($minVersion === null || $minVersion === '') return false;
        return self::compareVersion(APP_VERSION, $minVersion) < 0;
    }

    public static function hasNewVersion(?string $latestVersion): bool
    {
        if ($latestVersion === null || $latestVersion === '') return false;
        return self::compareVersion(APP_VERSION, $latestVersion) < 0;
    }

    // ------------------------------------------------------------------
    // 版本检查（带缓存）
    // ------------------------------------------------------------------

    public static function checkLatestVersion(bool $force = false): array
    {
        $updateServer = self::getUpdateServer();
        if ($updateServer === '') {
            return ['success' => false, 'data' => null, 'error' => '未配置版本服务器地址'];
        }

        if (!$force) {
            $cache = self::readCache();
            if ($cache !== null) {
                return ['success' => true, 'data' => $cache, 'error' => null];
            }
        }

        $current = self::getCurrentVersion();
        $params = http_build_query([
            'product' => $current['product'],
            'version' => $current['version'],
            'build'   => $current['build'],
            'channel' => $current['channel'],
        ]);

        $url = rtrim($updateServer, '/') . '/api/version.php?' . $params;
        $result = self::httpGet($url);

        if (!$result['ok']) {
            $stale = self::readCache(true);
            if ($stale !== null) {
                return ['success' => true, 'data' => $stale, 'error' => '版本服务器不可用，使用缓存数据'];
            }
            return ['success' => false, 'data' => null, 'error' => $result['error']];
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data) || !($data['success'] ?? false)) {
            return ['success' => false, 'data' => null, 'error' => '版本服务器返回数据格式错误'];
        }

        self::writeCache($data);
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private static function readCache(bool $allowExpired = false): ?array
    {
        $path = self::rootPath(self::CACHE_FILE);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $cache = json_decode($raw, true);
        if (!is_array($cache)) return null;
        if (!$allowExpired) {
            $ts = (int) ($cache['_cached_at'] ?? 0);
            if ($ts > 0 && (time() - $ts) > self::CACHE_TTL) return null;
        }
        return $cache;
    }

    private static function writeCache(array $data): void
    {
        $path = self::rootPath(self::CACHE_FILE);
        self::ensureDir(dirname($path));
        $data['_cached_at'] = time();
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ------------------------------------------------------------------
    // 更新锁
    // ------------------------------------------------------------------

    public static function acquireLock(): array
    {
        $path = self::rootPath(self::LOCK_FILE);
        if (is_file($path)) {
            $lockTime = (int) @filemtime($path);
            $age = time() - $lockTime;
            if ($age < self::LOCK_TIMEOUT) {
                return ['ok' => false, 'msg' => '系统正在更新中，请 ' . ceil((self::LOCK_TIMEOUT - $age) / 60) . ' 分钟后再试'];
            }
            @unlink($path);
        }
        self::ensureDir(dirname($path));
        $fp = @fopen($path, 'x');
        if ($fp === false) {
            return ['ok' => false, 'msg' => '获取更新锁失败'];
        }
        fwrite($fp, json_encode(['pid' => getmypid(), 'acquired' => time(), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '-']));
        fclose($fp);
        return ['ok' => true, 'msg' => '已获取更新锁'];
    }

    public static function releaseLock(): void
    {
        $path = self::rootPath(self::LOCK_FILE);
        if (is_file($path)) @unlink($path);
    }

    public static function isLocked(): bool
    {
        $path = self::rootPath(self::LOCK_FILE);
        if (!is_file($path)) return false;
        $lockTime = (int) @filemtime($path);
        return (time() - $lockTime) < self::LOCK_TIMEOUT;
    }

    // ------------------------------------------------------------------
    // 环境检查
    // ------------------------------------------------------------------

    public static function preflightCheck(array $manifest): array
    {
        $checks = [];

        // PHP 版本
        $reqPhp = $manifest['requirements']['php'] ?? '>=8.0';
        $phpOk = self::checkPhpVersion($reqPhp);
        $checks[] = ['name' => 'PHP 版本', 'level' => $phpOk ? 'ok' : 'error',
            'text' => $phpOk ? "PHP " . PHP_VERSION . " (满足 {$reqPhp})" : "PHP " . PHP_VERSION . " 不满足 {$reqPhp}"];

        // MySQL 版本
        $reqMysql = $manifest['requirements']['mysql'] ?? '>=5.7';
        try {
            $mysqlVer = (string) DB::value('SELECT VERSION()');
            $mysqlOk = self::checkMysqlVersion($mysqlVer, $reqMysql);
        } catch (Throwable $e) {
            $mysqlOk = false; $mysqlVer = '未知';
        }
        $checks[] = ['name' => 'MySQL 版本', 'level' => $mysqlOk ? 'ok' : 'error',
            'text' => $mysqlOk ? "MySQL {$mysqlVer} (满足 {$reqMysql})" : "MySQL {$mysqlVer} 不满足 {$reqMysql}"];

        // 扩展
        $requiredExts = ['curl', 'openssl', 'pdo', 'pdo_mysql', 'zip', 'json'];
        $missing = [];
        foreach ($requiredExts as $ext) {
            if (!extension_loaded($ext)) $missing[] = $ext;
        }
        $checks[] = ['name' => 'PHP 扩展', 'level' => empty($missing) ? 'ok' : 'error',
            'text' => empty($missing) ? '必需扩展齐全' : '缺少扩展：' . implode(', ', $missing)];

        // 磁盘
        $free = @disk_free_space(self::rootPath());
        if ($free === false) {
            $checks[] = ['name' => '磁盘空间', 'level' => 'warn', 'text' => '无法读取磁盘空间'];
        } else {
            $need = 100 * 1024 * 1024;
            $checks[] = ['name' => '磁盘空间', 'level' => $free >= $need ? 'ok' : 'error',
                'text' => '可用 ' . self::humanBytes((int) $free) . ($free >= $need ? '（充足）' : '（不足）')];
        }

        // 目录
        $writableDirs = ['storage/updates', 'storage/updates/backups', 'storage/updates/packages'];
        $badDirs = [];
        foreach ($writableDirs as $d) {
            $p = self::rootPath($d);
            if (!is_dir($p)) @mkdir($p, 0750, true);
            if (!is_dir($p) || !is_writable($p)) $badDirs[] = $d;
        }
        $checks[] = ['name' => '目录权限', 'level' => empty($badDirs) ? 'ok' : 'error',
            'text' => empty($badDirs) ? '更新目录可写' : '不可写：' . implode(', ', $badDirs)];

        // 版本兼容
        $targetVer = $manifest['version'] ?? '';
        if ($targetVer !== '' && self::compareVersion($targetVer, APP_VERSION) <= 0) {
            $checks[] = ['name' => '版本兼容', 'level' => 'error', 'text' => "目标版本 {$targetVer} 不高于当前版本 " . APP_VERSION];
        } else {
            $checks[] = ['name' => '版本兼容', 'level' => 'ok', 'text' => "从 " . APP_VERSION . " 升级到 {$targetVer}"];
        }

        $hasError = false;
        foreach ($checks as $c) {
            if ($c['level'] === 'error') { $hasError = true; break; }
        }
        return ['ok' => !$hasError, 'checks' => $checks];
    }

    private static function checkPhpVersion(string $req): bool
    {
        if (preg_match('/^>=(\d+\.\d+)/', $req, $m)) return version_compare(PHP_VERSION, $m[1], '>=');
        return true;
    }

    private static function checkMysqlVersion(string $version, string $req): bool
    {
        if (preg_match('/^>=(\d+\.\d+)/', $req, $m)) return version_compare(preg_replace('/-[a-z].*$/i', '', $version), $m[1], '>=');
        return true;
    }

    // ------------------------------------------------------------------
    // 下载更新包
    // ------------------------------------------------------------------

    public static function downloadUpdate(string $url, string $sha256): array
    {
        $pkgDir = self::rootPath('storage/updates/packages');
        self::ensureDir($pkgDir);

        $filename = basename(parse_url($url, PHP_URL_PATH));
        if (!preg_match('/^[\w.\-]+\.zip$/', $filename)) {
            $filename = 'update_' . date('Ymd_His') . '.zip';
        }
        $path = $pkgDir . '/' . $filename;

        $result = self::httpDownload($url, $path);
        if (!$result['ok']) return ['ok' => false, 'path' => null, 'error' => $result['error']];

        $actualHash = hash_file('sha256', $path);
        if ($actualHash === false) {
            @unlink($path);
            return ['ok' => false, 'path' => null, 'error' => '无法计算 SHA-256'];
        }
        if (!hash_equals(strtolower($sha256), strtolower($actualHash))) {
            @unlink($path);
            return ['ok' => false, 'path' => null, 'error' => 'SHA-256 校验失败'];
        }

        return ['ok' => true, 'path' => $path, 'error' => null];
    }

    // ------------------------------------------------------------------
    // 数字签名验证
    // ------------------------------------------------------------------

    public static function verifySignature(string $packagePath, string $signature): array
    {
        $keyPath = self::rootPath(self::PUBLIC_KEY_FILE);
        if (!is_file($keyPath)) return ['ok' => false, 'error' => '未找到更新公钥文件'];

        $publicKey = @file_get_contents($keyPath);
        if ($publicKey === false || $publicKey === '') return ['ok' => false, 'error' => '公钥文件为空'];

        $sig = base64_decode($signature, true);
        if ($sig === false || $sig === '') return ['ok' => false, 'error' => '签名解码失败'];

        $data = @file_get_contents($packagePath);
        if ($data === false) return ['ok' => false, 'error' => '无法读取更新包'];

        // 优先用 libsodium 验签 Ed25519
        if (function_exists('sodium_crypto_sign_verify_detached')) {
            $pubKeyRaw = self::extractEd25519PublicKey($publicKey);
            if ($pubKeyRaw !== null) {
                $ok = sodium_crypto_sign_verify_detached($sig, $data, $pubKeyRaw);
                sodium_memzero($pubKeyRaw);
                return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'Ed25519 签名验证失败'];
            }
        }

        // 回退到 OpenSSL
        $verify = openssl_verify($data, $sig, $publicKey, OPENSSL_ALGO_SHA256);
        return $verify === 1 ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'OpenSSL 验签失败'];
    }

    private static function extractEd25519PublicKey(string $pem): ?string
    {
        $lines = explode("\n", trim($pem));
        $b64 = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '-----')) continue;
            $b64 .= $line;
        }
        $der = base64_decode($b64, true);
        if ($der === false || strlen($der) < 44) return null;
        $key = substr($der, -32);
        return strlen($key) === 32 ? $key : null;
    }

    // ------------------------------------------------------------------
    // Manifest 校验
    // ------------------------------------------------------------------

    public static function validateManifest(string $packagePath): array
    {
        if (!extension_loaded('zip')) return ['ok' => false, 'manifest' => null, 'error' => 'PHP 未安装 zip 扩展'];

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) return ['ok' => false, 'manifest' => null, 'error' => '无法打开更新包'];

        $idx = $zip->locateName('manifest.json');
        if ($idx === false) { $zip->close(); return ['ok' => false, 'manifest' => null, 'error' => '未找到 manifest.json']; }

        $json = $zip->getFromIndex($idx);
        $zip->close();
        if ($json === false) return ['ok' => false, 'manifest' => null, 'error' => '读取 manifest.json 失败'];

        $manifest = json_decode($json, true);
        if (!is_array($manifest)) return ['ok' => false, 'manifest' => null, 'error' => 'manifest.json 格式错误'];

        foreach (['product', 'version', 'build', 'min_version', 'schema_version', 'files'] as $field) {
            if (!array_key_exists($field, $manifest)) {
                return ['ok' => false, 'manifest' => null, 'error' => "manifest.json 缺少字段：{$field}"];
            }
        }

        if ($manifest['product'] !== APP_PRODUCT) {
            return ['ok' => false, 'manifest' => null, 'error' => '产品标识不匹配'];
        }

        if (self::compareVersion(APP_VERSION, $manifest['min_version']) < 0) {
            return ['ok' => false, 'manifest' => null, 'error' => '当前版本低于更新包要求的最低版本'];
        }

        if (!is_array($manifest['files']) || empty($manifest['files'])) {
            return ['ok' => false, 'manifest' => null, 'error' => 'files 字段为空'];
        }

        return ['ok' => true, 'manifest' => $manifest, 'error' => null];
    }

    // ------------------------------------------------------------------
    // Zip Slip 防护
    // ------------------------------------------------------------------

    public static function isSafePath(string $entryPath): bool
    {
        $entryPath = str_replace('\\', '/', $entryPath);
        if (preg_match('/^[a-zA-Z]:/', $entryPath) || str_starts_with($entryPath, '/')) return false;
        foreach (explode('/', $entryPath) as $part) {
            if ($part === '..') return false;
        }
        foreach (self::PROTECTED_DIRS as $dir) {
            if ($entryPath === $dir || str_starts_with($entryPath, $dir . '/')) return false;
        }
        return true;
    }

    // ------------------------------------------------------------------
    // 备份
    // ------------------------------------------------------------------

    public static function backup(array $manifest): array
    {
        $timestamp = date('Ymd_His');
        $backupDir = self::rootPath('storage/updates/backups/' . $timestamp);
        self::ensureDir($backupDir);

        $dbResult = self::backupDatabase($backupDir . '/database.sql');
        if (!$dbResult['ok']) return ['ok' => false, 'path' => null, 'error' => '数据库备份失败：' . $dbResult['error']];

        $filesBackedUp = 0;
        foreach ($manifest['files'] as $file) {
            $src = self::rootPath($file);
            if (!is_file($src)) continue;
            self::ensureDir($backupDir . '/files/' . dirname($file));
            if (@copy($src, $backupDir . '/files/' . $file)) $filesBackedUp++;
        }

        $versionSrc = self::rootPath('version.php');
        if (is_file($versionSrc)) @copy($versionSrc, $backupDir . '/version.php.bak');

        @file_put_contents($backupDir . '/metadata.json', json_encode([
            'version' => APP_VERSION, 'build' => APP_BUILD,
            'schema_version' => APP_SCHEMA_VERSION, 'created_at' => date('Y-m-d H:i:s'),
            'files_count' => $filesBackedUp,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @file_put_contents($backupDir . '/version.txt', APP_VERSION);

        return ['ok' => true, 'path' => $backupDir, 'error' => null];
    }

    private static function backupDatabase(string $outPath): array
    {
        self::ensureDir(dirname($outPath));
        $fh = @fopen($outPath, 'w');
        if ($fh === false) return ['ok' => false, 'error' => '无法创建备份文件'];

        try {
            $pdo = DB::pdo();
            fwrite($fh, "-- 版本更新系统数据库备份\n-- 时间: " . date('Y-m-d H:i:s') . "\n-- 版本: " . APP_VERSION . "\n\n");
            fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach (DB::all('SHOW TABLES') as $row) {
                $table = (string) reset($row);
                if ($table === '') continue;
                $quoted = '`' . str_replace('`', '``', $table) . '`';

                $create = DB::one('SHOW CREATE TABLE ' . $quoted);
                if (!$create) continue;
                $ddl = (string) ($create['Create Table'] ?? $create['Create View'] ?? '');
                fwrite($fh, "DROP TABLE IF EXISTS {$quoted};\n" . $ddl . ";\n\n");

                $stmt = $pdo->query('SELECT * FROM ' . $quoted);
                $buf = [];
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $vals = [];
                    foreach ($r as $v) {
                        if ($v === null) $vals[] = 'NULL';
                        elseif (is_int($v) || is_float($v)) $vals[] = (string) $v;
                        else $vals[] = $pdo->quote((string) $v);
                    }
                    $buf[] = '(' . implode(',', $vals) . ')';
                    if (count($buf) >= 200) {
                        fwrite($fh, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $buf) . ";\n");
                        $buf = [];
                    }
                }
                if ($buf) fwrite($fh, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $buf) . ";\n\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $e) {
            fclose($fh);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        fclose($fh);
        return ['ok' => true, 'error' => null];
    }

    public static function listBackups(): array
    {
        $dir = self::rootPath('storage/updates/backups');
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) as $d) {
            $name = basename($d);
            $metaPath = $d . '/metadata.json';
            $meta = [];
            if (is_file($metaPath)) $meta = json_decode(@file_get_contents($metaPath), true) ?: [];
            $size = 0;
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $f) $size += (int) $f->getSize();
            $out[] = [
                'name' => $name, 'size' => $size, 'size_text' => self::humanBytes($size),
                'version' => $meta['version'] ?? '-', 'created' => $meta['created_at'] ?? date('Y-m-d H:i:s', (int) @filemtime($d)),
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    // ------------------------------------------------------------------
    // Migration
    // ------------------------------------------------------------------

    public static function runMigrations(array $manifest, string $packagePath): array
    {
        $targetSchema = (int) $manifest['schema_version'];
        $currentSchema = (int) APP_SCHEMA_VERSION;
        if ($targetSchema <= $currentSchema) return ['ok' => true, 'executed' => [], 'error' => null];

        self::ensureMigrationsTable();

        if (!extension_loaded('zip')) return ['ok' => false, 'executed' => [], 'error' => 'PHP 未安装 zip 扩展'];

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) return ['ok' => false, 'executed' => [], 'error' => '无法打开更新包'];

        $executed = [];
        for ($v = $currentSchema + 1; $v <= $targetSchema; $v++) {
            $migrationFile = "install/migrations/{$v}.sql";
            $already = DB::one('SELECT version FROM ' . DB::t('migrations') . ' WHERE version = ?', [(string) $v]);
            if ($already) continue;

            $idx = $zip->locateName($migrationFile);
            if ($idx === false) continue;

            $sql = $zip->getFromIndex($idx);
            if ($sql === false || trim($sql) === '') continue;

            $checksum = hash('sha256', $sql);
            try {
                self::executeSql($sql);
            } catch (Throwable $e) {
                $zip->close();
                return ['ok' => false, 'executed' => $executed, 'error' => "Migration {$v} 失败：" . $e->getMessage()];
            }

            DB::insert('migrations', ['version' => (string) $v, 'checksum' => $checksum, 'executed_at' => date('Y-m-d H:i:s')]);
            $executed[] = (string) $v;
        }
        $zip->close();
        return ['ok' => true, 'executed' => $executed, 'error' => null];
    }

    private static function executeSql(string $sql): void
    {
        foreach (self::splitSql($sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            DB::pdo()->exec($stmt);
        }
    }

    private static function splitSql(string $sql): array
    {
        $result = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            if (!$inString && ($char === "'" || $char === '"')) {
                $inString = true; $stringChar = $char; $current .= $char; continue;
            }
            if ($inString) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $len) { $current .= $sql[++$i]; continue; }
                if ($char === $stringChar) $inString = false;
                continue;
            }
            if ($char === ';') { $result[] = $current; $current = ''; continue; }
            if ($char === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) { $current .= substr($sql, $i); break; }
                $i = $end; continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') $result[] = $current;
        return $result;
    }

    private static function ensureMigrationsTable(): void
    {
        try {
            DB::one('SELECT 1 FROM ' . DB::t('migrations') . ' LIMIT 1');
        } catch (Throwable $e) {
            DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('migrations') . " (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(32) NOT NULL,
                checksum CHAR(64) NOT NULL,
                executed_at DATETIME NOT NULL,
                UNIQUE KEY uk_version (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // ------------------------------------------------------------------
    // 更新历史
    // ------------------------------------------------------------------

    public static function ensureHistoryTable(): void
    {
        try {
            DB::one('SELECT 1 FROM ' . DB::t('update_history') . ' LIMIT 1');
        } catch (Throwable $e) {
            DB::exec("CREATE TABLE IF NOT EXISTS " . DB::t('update_history') . " (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                from_version VARCHAR(32) NOT NULL DEFAULT '',
                to_version VARCHAR(32) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                operator_id INT UNSIGNED NOT NULL DEFAULT 0,
                operator_name VARCHAR(64) NOT NULL DEFAULT '',
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                error_message TEXT,
                backup_path VARCHAR(500) DEFAULT NULL,
                INDEX idx_status (status),
                INDEX idx_started (started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public static function recordUpdateHistory(array $data): int
    {
        self::ensureHistoryTable();
        return DB::insert('update_history', $data);
    }

    public static function updateHistoryStatus(int $id, string $status, ?string $error = null, ?string $finishedAt = null): void
    {
        $updates = ['status' => $status];
        if ($error !== null) $updates['error_message'] = $error;
        if ($finishedAt !== null) $updates['finished_at'] = $finishedAt;
        DB::update('update_history', $updates, 'id = :id', ['id' => $id]);
    }

    public static function getHistory(int $page = 1, int $size = 20): array
    {
        self::ensureHistoryTable();
        $total = (int) DB::value('SELECT COUNT(*) FROM ' . DB::t('update_history'));
        $page = max(1, $page);
        $size = min(50, max(1, $size));
        $offset = ($page - 1) * $size;
        $rows = DB::all('SELECT * FROM ' . DB::t('update_history') . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset);
        return ['total' => $total, 'list' => $rows];
    }

    // ------------------------------------------------------------------
    // 安装更新文件
    // ------------------------------------------------------------------

    public static function installUpdate(string $packagePath, array $manifest): array
    {
        if (!extension_loaded('zip')) return ['ok' => false, 'updated' => 0, 'error' => 'PHP 未安装 zip 扩展'];

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) return ['ok' => false, 'updated' => 0, 'error' => '无法打开更新包'];

        $updated = 0;
        $errors = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($zip->isDir($entry)) continue;
            if (!self::isSafePath($entry)) { $errors[] = "跳过不安全路径：{$entry}"; continue; }

            $targetPath = self::rootPath($entry);
            self::ensureDir(dirname($targetPath));
            $content = $zip->getFromIndex($i);
            if ($content === false) { $errors[] = "读取失败：{$entry}"; continue; }
            if (@file_put_contents($targetPath, $content) === false) { $errors[] = "写入失败：{$entry}"; continue; }
            $updated++;
        }
        $zip->close();
        return ['ok' => empty($errors), 'updated' => $updated, 'error' => empty($errors) ? null : implode('; ', $errors)];
    }

    // ------------------------------------------------------------------
    // 回滚
    // ------------------------------------------------------------------

    public static function rollback(string $backupDir): array
    {
        $backupDir = self::rootPath('storage/updates/backups/' . basename($backupDir));
        if (!is_dir($backupDir)) return ['ok' => false, 'error' => '备份目录不存在'];

        $dbFile = $backupDir . '/database.sql';
        if (is_file($dbFile)) {
            $sql = @file_get_contents($dbFile);
            if ($sql !== false) {
                try { self::executeSql($sql); } catch (Throwable $e) { return ['ok' => false, 'error' => '恢复数据库失败：' . $e->getMessage()]; }
            }
        }

        $filesDir = $backupDir . '/files';
        if (is_dir($filesDir)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filesDir, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $f) {
                if (!$f->isFile()) continue;
                $relative = substr($f->getPathname(), strlen($filesDir) + 1);
                $target = self::rootPath($relative);
                self::ensureDir(dirname($target));
                @copy($f->getPathname(), $target);
            }
        }

        $versionBak = $backupDir . '/version.php.bak';
        if (is_file($versionBak)) @copy($versionBak, self::rootPath('version.php'));

        return ['ok' => true, 'error' => null];
    }

    // ------------------------------------------------------------------
    // 健康检查
    // ------------------------------------------------------------------

    public static function healthCheck(): array
    {
        $checks = [];

        // PHP 语法
        $criticalFiles = ['version.php', 'lib/DB.php', 'lib/VersionManager.php'];
        $syntaxErrors = [];
        foreach ($criticalFiles as $f) {
            $path = self::rootPath($f);
            if (!is_file($path)) continue;
            $output = []; $ret = 0;
            @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $ret);
            if ($ret !== 0) $syntaxErrors[] = $f . ': ' . implode(' ', $output);
        }
        $checks[] = ['name' => 'PHP 语法', 'level' => empty($syntaxErrors) ? 'ok' : 'error',
            'text' => empty($syntaxErrors) ? '核心文件语法正确' : implode('; ', $syntaxErrors)];

        // 数据库
        try {
            $ok = DB::value('SELECT 1');
            $checks[] = ['name' => '数据库连接', 'level' => ((string) $ok === '1') ? 'ok' : 'error',
                'text' => ((string) $ok === '1') ? '数据库连接正常' : '查询返回异常'];
        } catch (Throwable $e) {
            $checks[] = ['name' => '数据库连接', 'level' => 'error', 'text' => '连接失败'];
        }

        // 版本文件
        $versionFile = self::rootPath('version.php');
        $hasVersion = is_file($versionFile) && str_contains((string) @file_get_contents($versionFile), 'APP_VERSION');
        $checks[] = ['name' => '版本文件', 'level' => $hasVersion ? 'ok' : 'error',
            'text' => $hasVersion ? 'version.php 正常' : 'version.php 异常'];

        $hasError = false;
        foreach ($checks as $c) if ($c['level'] === 'error') { $hasError = true; break; }
        return ['ok' => !$hasError, 'checks' => $checks];
    }

    // ------------------------------------------------------------------
    // 完整更新流程
    // ------------------------------------------------------------------

    public static function doUpdate(array $versionInfo, array $admin, ?callable $progressCb = null): array
    {
        $cb = $progressCb ?? function () {};

        $cb('获取更新锁', 'running');
        $lock = self::acquireLock();
        if (!$lock['ok']) { $cb('获取更新锁', 'failed'); return ['ok' => false, 'error' => $lock['msg'], 'history_id' => null]; }
        $cb('获取更新锁', 'done');

        self::ensureHistoryTable();
        $historyId = self::recordUpdateHistory([
            'from_version'  => APP_VERSION,
            'to_version'    => $versionInfo['latest_version'] ?? '',
            'status'        => 'running',
            'operator_id'   => (int) ($admin['id'] ?? 0),
            'operator_name' => $admin['username'] ?? '',
            'started_at'    => date('Y-m-d H:i:s'),
        ]);

        $backupDir = null;
        try {
            $cb('下载更新包', 'running');
            $dl = self::downloadUpdate($versionInfo['download_url'], $versionInfo['sha256']);
            if (!$dl['ok']) { $cb('下载更新包', 'failed'); throw new RuntimeException($dl['error']); }
            $packagePath = $dl['path'];
            $cb('下载更新包', 'done');

            $cb('SHA-256 校验', 'done');

            $cb('数字签名验证', 'running');
            // 2026-09-30 审计 P1：签名强制。无签名的更新包一律拒绝安装，
            // 防止「更新服务器被攻破后改 SHA-256 + 去掉 signature」绕过信任链。
            if (empty($versionInfo['signature'])) {
                $cb('数字签名验证', 'failed');
                throw new RuntimeException('更新包缺少官方数字签名，已拒绝安装（供应链保护）');
            }
            $sig = self::verifySignature($packagePath, $versionInfo['signature']);
            if (!$sig['ok']) { $cb('数字签名验证', 'failed'); throw new RuntimeException($sig['error']); }
            $cb('数字签名验证', 'done');

            $cb('Manifest 校验', 'running');
            $man = self::validateManifest($packagePath);
            if (!$man['ok']) { $cb('Manifest 校验', 'failed'); throw new RuntimeException($man['error']); }
            $manifest = $man['manifest'];
            $cb('Manifest 校验', 'done');

            $cb('环境检查', 'running');
            $pf = self::preflightCheck($manifest);
            if (!$pf['ok']) { $cb('环境检查', 'failed'); throw new RuntimeException('环境检查未通过'); }
            $cb('环境检查', 'done');

            $cb('备份', 'running');
            $bak = self::backup($manifest);
            if (!$bak['ok']) { $cb('备份', 'failed'); throw new RuntimeException($bak['error']); }
            $backupDir = $bak['path'];
            $cb('备份', 'done');

            $cb('数据库 Migration', 'running');
            $mig = self::runMigrations($manifest, $packagePath);
            if (!$mig['ok']) { $cb('数据库 Migration', 'failed'); throw new RuntimeException($mig['error']); }
            $cb('数据库 Migration', 'done');

            $cb('更新文件', 'running');
            $inst = self::installUpdate($packagePath, $manifest);
            if (!$inst['ok']) { $cb('更新文件', 'failed'); throw new RuntimeException($inst['error']); }
            $cb('更新文件', 'done');

            $cb('清理缓存', 'running');
            self::clearCache();
            $cb('清理缓存', 'done');

            $cb('健康检查', 'running');
            $hc = self::healthCheck();
            $cb('健康检查', $hc['ok'] ? 'done' : 'failed');
            if (!$hc['ok']) {
                $cb('健康检查失败，启动回滚', 'running');
                self::rollback($backupDir);
                $cb('健康检查失败，启动回滚', 'done');
                throw new RuntimeException('健康检查失败，已回滚');
            }

            self::updateHistoryStatus($historyId, 'success', null, date('Y-m-d H:i:s'));
            self::releaseLock();
            if (isset($packagePath)) @unlink($packagePath);
            return ['ok' => true, 'error' => null, 'history_id' => $historyId];

        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            if ($backupDir !== null) {
                $cb('更新失败，启动回滚', 'running');
                self::rollback($backupDir);
                $cb('更新失败，启动回滚', 'done');
            }
            self::updateHistoryStatus($historyId, 'failed', $errorMsg, date('Y-m-d H:i:s'));
            self::releaseLock();
            return ['ok' => false, 'error' => $errorMsg, 'history_id' => $historyId];
        }
    }

    private static function clearCache(): void
    {
        $cacheFile = self::rootPath(self::CACHE_FILE);
        if (is_file($cacheFile)) @unlink($cacheFile);
    }

    // ------------------------------------------------------------------
    // HTTP 工具
    // ------------------------------------------------------------------

    private static function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'VersionUpdate/' . APP_VERSION,
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) return ['ok' => false, 'body' => null, 'error' => 'HTTP 请求失败：' . $err];
            if ($httpCode >= 400) return ['ok' => false, 'body' => null, 'error' => 'HTTP ' . $httpCode];
            return ['ok' => true, 'body' => $body, 'error' => null];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 30], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? ['ok' => false, 'body' => null, 'error' => '无法连接版本服务器'] : ['ok' => true, 'body' => $body, 'error' => null];
    }

    private static function httpDownload(string $url, string $savePath): array
    {
        // 2026-09-30 审计：更新包下载只允许 cURL 路径（手动跟随重定向且逐跳校验
        // host 不变）。file_get_contents 的 stream 回退会透明跟随 302 到任意 host，
        // 且无法做同等校验，安全策略不应取决于是否装了 curl 扩展。
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => '环境缺少 cURL 扩展，拒绝执行更新下载'];
        }

        $baseHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $current  = $url;

        for ($i = 0; $i < 4; $i++) {
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,   // 关闭自动跳转，手动逐跳校验
            ]);
            $resp     = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $err      = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                return ['ok' => false, 'error' => '下载失败：' . ($err ?: 'curl 执行失败')];
            }
            // 逐跳 host 校验：重定向只能落在同一主机（防 302 把更新包引去第三方）
            $host = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
            if ($host !== '' && $host !== $baseHost) {
                return ['ok' => false, 'error' => '下载重定向到非原主机，已拒绝'];
            }

            if ($httpCode >= 300 && $httpCode < 400) {
                if (preg_match('/^Location:\s*(\S+)/im', (string) $resp, $mm)) {
                    $loc = trim($mm[1]);
                    if ($loc !== '' && $loc[0] === '/') {
                        $p = parse_url($current);
                        $current = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $loc;
                        continue;
                    }
                    if ($loc !== '' && preg_match('#^https?://#i', $loc)) {
                        $current = $loc;
                        continue;
                    }
                }
                return ['ok' => false, 'error' => '下载失败：重定向缺少合法 Location'];
            }
            if ($httpCode >= 400) {
                return ['ok' => false, 'error' => '下载失败：HTTP ' . $httpCode];
            }
            if ($httpCode >= 200 && $httpCode < 300) {
                if (@file_put_contents($savePath, (string) $resp) === false) {
                    return ['ok' => false, 'error' => '写入文件失败'];
                }
                return ['ok' => true, 'error' => null];
            }
            return ['ok' => false, 'error' => '下载失败：HTTP ' . $httpCode];
        }
        return ['ok' => false, 'error' => '下载失败：重定向次数过多'];
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private static function rootPath(string $relative = ''): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        return $relative === '' ? $root : $root . '/' . $relative;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
    }

    private static function getUpdateServer(): string
    {
        // 从 config 读取
        $configFile = APP_ROOT . '/config/config.php';
        if (is_file($configFile)) {
            $config = require $configFile;
            return (string) ($config['update_server'] ?? '');
        }
        return '';
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    // ------------------------------------------------------------------
    // 大版本轮换：新版本上架后，删除更新系统中「之前所有大版本」的
    // release 记录与 releases/{v}/ 包文件。
    // 规则（2026-10-10 起）：每次大版本更新，只保留当前大版本的版本线，
    // 旧大版本（如 1.x 对 2.x）的记录与发行包一并清除，不再提供下载。
    // ------------------------------------------------------------------
    public static function prunePreviousMajors(string $keepVersion): array
    {
        $keepMajor = (int) explode('.', $keepVersion)[0];
        $removed = [];
        try {
            $rows = DB::all('SELECT id, version FROM ' . DB::t('releases'));
        } catch (\Throwable $e) {
            return $removed;
        }
        foreach ($rows as $row) {
            $v = (string) $row['version'];
            if ((int) explode('.', $v)[0] === $keepMajor) continue;
            // 先删发行包目录（releases/{v}/）
            $dir = APP_ROOT . '/releases/' . $v;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @is_dir($f) ? self::rrmdir($f) : @unlink($f);
                }
                @rmdir($dir);
            }
            DB::exec('DELETE FROM ' . DB::t('releases') . ' WHERE id = ?', [(int) $row['id']]);
            $removed[] = $v;
        }
        return $removed;
    }

    /** 递归删除目录（供 prunePreviousMajors 使用） */
    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}

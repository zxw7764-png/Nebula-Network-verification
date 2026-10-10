<?php
/**
 * 数据库封装 - PDO 单例
 * ==================================================================
 * 独立系统自带的数据库封装，不依赖外部项目。
 */

class DB
{
    private static ?PDO $pdo = null;
    private static string $prefix = '';

    public static function init(array $cfg): void
    {
        self::$prefix = $cfg['prefix'] ?? '';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]);
        } catch (PDOException $e) {
            @error_log('[version-update] db connect failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => 500, 'msg' => '数据库连接失败，请检查 config/config.php']);
            exit;
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database not initialized');
        }
        return self::$pdo;
    }

    /** 表名加前缀 */
    public static function t(string $name): string
    {
        return '`' . self::$prefix . $name . '`';
    }

    /** 查询多行 */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** 查询单行 */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** 查询单值 */
    public static function value(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 执行写入，返回影响行数 */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 插入并返回自增 ID */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $marks = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::t($table),
            '`' . implode('`,`', $cols) . '`',
            implode(',', $marks)
        );
        $st = self::pdo()->prepare($sql);
        $st->execute($data);
        return (int) self::pdo()->lastInsertId();
    }

    /** 按条件更新 */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) return 0;
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = "`$c` = :s_$c";
        }
        $params = [];
        foreach ($data as $k => $v) {
            $params['s_' . $k] = $v;
        }
        $whereFixed = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            fn($m) => ':w_' . $m[1],
            $where
        );
        foreach ($whereParams as $k => $v) {
            $params['w_' . $k] = $v;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::t($table), implode(',', $sets), $whereFixed);
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    // ------------------------------------------------------------------
    // 事务
    // ------------------------------------------------------------------
    private static int $txDepth = 0;

    public static function inTransaction(): bool
    {
        return self::$txDepth > 0;
    }

    public static function begin(): void
    {
        if (self::$txDepth === 0) {
            self::pdo()->beginTransaction();
        }
        self::$txDepth++;
    }

    public static function commit(): void
    {
        if (self::$txDepth === 0) return;
        self::$txDepth--;
        if (self::$txDepth === 0 && self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void
    {
        self::$txDepth = 0;
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}

<?php
/**
 * 数据库封装 - PDO 单例
 */
class Database
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
                // 部分 PHP 构建不带 mysqlnd，DSN 里的 charset 会被忽略（实际仍是 utf8），
                // emoji 等 4 字节字符会写库报 1366，这里显式 SET NAMES 兜底
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ]);
        } catch (PDOException $e) {
            // 绝不把 PDO 原始异常回显给调用方：里面含 DSN、主机、库名、用户名
            // 与 SQLSTATE，未认证调用方能据此摸清数据库结构。细节只进服务端日志。
            @error_log('[nebula] db connect failed: ' . $e->getMessage());
            $msg = Config::get('debug')
                ? '数据库连接失败: ' . $e->getMessage()
                : '数据库连接失败，请检查 config/config.php 的数据库配置或联系管理员';
            Response::error(500, $msg);
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

    /** 按条件更新（where 参数会自动加 w_ 前缀，避免与 SET 字段同名冲突） */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = "`$c` = :s_$c";
        }
        $params = [];
        foreach ($data as $k => $v) {
            $params['s_' . $k] = $v;
        }
        // where 中的 :name 自动映射到 w_name，防止与 SET 参数重名
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
    // 事务（支持嵌套，见下方注释）
    // ------------------------------------------------------------------
    /**
     * 事务嵌套深度。
     * MySQL 的 PDO 不支持真正的嵌套事务（内层 begin 会被静默忽略，
     * 内层 commit 会把整个外层事务一起提交）。
     * 这里用引用计数实现「扁平化嵌套」：
     *   begin()   深度 +1，仅第 1 层真正 BEGIN
     *   commit()  深度 -1，仅归 0 的那一层真正 COMMIT
     *   rollback() 无论层次，直接把深度清零并回滚整个事务
     * 这样内层方法（如 Card::generate / Card::activate）自带事务时，
     * 外层无需再关心「能不能 begin」，补偿逻辑也不会被内层 commit 提前提交。
     */
    private static int $txDepth = 0;

    /** 当前是否处于事务中 */
    public static function inTransaction(): bool
    {
        return self::$txDepth > 0;
    }

    /** 开启事务（可嵌套：仅最外层真正 BEGIN） */
    public static function begin(): void
    {
        if (self::$txDepth === 0) {
            self::pdo()->beginTransaction();
        }
        self::$txDepth++;
    }

    /** 提交事务（仅最外层真正 COMMIT 并清除连接状态） */
    public static function commit(): void
    {
        if (self::$txDepth === 0) {
            return; // 没有活动事务，忽略（保持与旧实现一致的容错）
        }
        self::$txDepth--;
        if (self::$txDepth === 0 && self::pdo()->inTransaction()) {
            // clearConn() 会把事务内的最后状态清掉，因此必须放在 commit 之后判断
            self::pdo()->commit();
        }
    }

    /**
     * 回滚整个事务（任意层次调用都全量回滚）
     * 语义说明：内层出现异常即代表整笔业务失败，不应只回滚内层，
     * 否则外层的补偿逻辑会在「半成品」状态上继续跑。
     */
    public static function rollback(): void
    {
        self::$txDepth = 0;
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    /**
     * 获取分页列表，返回 [总行数, 数据]
     *
     * count 查询策略：把 "SELECT xxx FROM ..." 改写成 "SELECT COUNT(*) FROM ..."，
     * 避免 MySQL 物化整个结果集再统计（数据量大时性能差异显著）。
     * 若 baseSql 含 GROUP BY / DISTINCT，则回退到子查询方式。
     */
    public static function paginate(string $baseSql, array $params, int $page, int $size, string $orderBy = ''): array
    {
        $upper = strtoupper($baseSql);
        $needSub = strpos($upper, 'GROUP BY') !== false || strpos($upper, 'DISTINCT') !== false;

        if ($needSub) {
            $countSql = "SELECT COUNT(*) FROM ($baseSql) AS _cnt";
        } else {
            // 定位第一个 FROM，把前面的投影替换成 COUNT(*)
            $pos = stripos($baseSql, ' FROM ');
            if ($pos === false) {
                $countSql = $baseSql;
            } else {
                $countSql = 'SELECT COUNT(*)' . substr($baseSql, $pos);
            }
        }

        $total = (int) self::value($countSql, $params);

        $page = max(1, $page);
        $size = min(500, max(1, $size));
        $offset = ($page - 1) * $size;
        $sql = $baseSql . ($orderBy ? " ORDER BY $orderBy" : '') . " LIMIT $size OFFSET $offset";
        return [$total, self::all($sql, $params)];
    }
}

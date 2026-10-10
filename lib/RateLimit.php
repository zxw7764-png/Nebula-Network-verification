<?php
/**
 * 限流器 - 基于数据库计数
 *
 * ⚠️ 故障语义（fail-open）：存储异常时计数失败 → 放行（可用性优先）。
 * 这意味着限流保护在存储故障期间静默失效 —— 运维必须对限流存储
 * （logs/cache 目录可写性、Redis/缓存驱动连接）做健康监控，
 * 尤其避免在数据库迁移或磁盘故障时误以为限流仍在生效。
 */
class RateLimit
{
    /**
     * 仅自增，返回自增后的次数（不做判定）
     * 计数失败返回 0，调用方据此放行（不得因限流组件异常影响正常业务）
     */
    public static function incr(string $key, int $window = 60): int
    {
        $now    = time();
        $bucket = $now - ($now % $window);
        $key    = substr($key, 0, 128);

        try {
            $sql = 'INSERT INTO ' . Database::t('rate_limit') . '
                    (bucket_key, window_at, hits) VALUES (:k, :w, 1)
                    ON DUPLICATE KEY UPDATE hits = hits + 1';
            Database::exec($sql, ['k' => $key, 'w' => $bucket]);

            return (int) Database::value(
                'SELECT hits FROM ' . Database::t('rate_limit') . ' WHERE bucket_key = ? AND window_at = ?',
                [$key, $bucket]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * 检查并自增，超限返回 false
     * @param string $key    限流键
     * @param int    $limit  窗口内最大次数
     * @param int    $window 窗口秒数
     */
    public static function hit(string $key, int $limit, int $window = 60): bool
    {
        $hits = self::incr($key, $window);
        if ($hits <= 0) {
            // 限流失败时放行，避免影响正常业务
            return true;
        }
        return $hits <= $limit;
    }

    /** 查询当前计数 */
    public static function count(string $key, int $window = 60): int
    {
        $now    = time();
        $bucket = $now - ($now % $window);
        return (int) Database::value(
            'SELECT hits FROM ' . Database::t('rate_limit') . ' WHERE bucket_key = ? AND window_at = ?',
            [substr($key, 0, 128), $bucket]
        );
    }

    /** 重置计数 */
    public static function reset(string $key, int $window = 60): void
    {
        $now    = time();
        $bucket = $now - ($now % $window);
        Database::exec(
            'DELETE FROM ' . Database::t('rate_limit') . ' WHERE bucket_key = ? AND window_at = ?',
            [substr($key, 0, 128), $bucket]
        );
    }

    /** 基于 IP 的通用限流 */
    public static function byIp(string $endpoint, int $limit): bool
    {
        return self::hit('ip:' . Util::ip() . ':' . $endpoint, $limit, 60);
    }

    /**
     * 按卡号维度限流：针对「同一张卡密被反复尝试」
     * ------------------------------------------------------------------
     * 键里只放卡密的 SHA-256 摘要，不落明文，避免限流表泄露卡密。
     * 可挡住：同一张卡的反复试探、一卡多账号轮番尝试。
     * 挡不住：随机枚举（每次换一个卡号），那类由 IP 维度的
     *         missKey() 计数负责，两者配合才完整。
     */
    public static function byCard(string $code, string $scope, int $limit, int $window = 600): bool
    {
        $key = self::cardKey($code, $scope);
        if ($key === '') {
            return true;
        }
        return self::hit($key, $limit, $window);
    }

    /** 卡号维度限流键（空卡号返回空串） */
    public static function cardKey(string $code, string $scope): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        return 'card:' . $scope . ':' . substr(hash('sha256', 'nbcard|' . $code), 0, 32);
    }

    /**
     * 「卡密不存在」枚举检测键（按来源 IP 计数）
     * 调用方在查询确认卡密不存在后 incr 一次；请求进来时先查 count 决定是否拒绝。
     */
    public static function missKey(string $scope): string
    {
        return 'cardmiss:' . $scope . ':' . Util::ip();
    }
}

<?php
/**
 * 运行时安全事件服务
 * ----------------------------------------------------------------------------
 * 设计文档 §12 §13 §14 §15 §16 §17 §18 §26 §27 §28 §46 §47 §48 §49 §50 §51 §52 §75 §96
 *
 * 职责：
 *   · 接收客户端 RuntimeGuard 上报的安全事件
 *   · 验证事件合法性（session 绑定、seq、timestamp）
 *   · 事件去重（§18: 相同事件短时间窗口内合并）
 *   · 持久化到 nb_security_events
 *   · 调用 RuntimeRiskEngine 计算风险
 *   · 同步更新 Session/Device 运行时状态
 *   · Critical 事件同步阻断 Session（§96）
 *
 * 安全边界（§49 §50 §51）：
 *   · 服务器不执行客户端上传内容，只解析/验证/存储/评分
 *   · Event Detail 只允许预定义字段，禁止 base64/binary/memory_dump
 *   · 客户端报告 = untrusted telemetry，不是 server fact
 *   · 服务端验证 session / seq / HMAC / device binding
 */
class RuntimeEventService
{
    /** §18: 去重窗口（秒）——相同事件类型+hash在此窗口内合并 */
    private const DEDUP_WINDOW = 10;

    /** §47: 单事件 payload 上限（字节） */
    private const MAX_PAYLOAD_SIZE = 16384;

    /** §48: event_detail 允许的字段白名单 */
    private const ALLOWED_DETAIL_FIELDS = [
        'region', 'address_rva', 'rva', 'length', 'module_name', 'module_path',
        'process_name', 'process_id', 'api_name', 'hook_type', 'bp_index',
        'vm_type', 'sandbox_type', 'detail',
    ];

    /** §46: 限流 — 每 session 每分钟最多 10 条事件 */
    private const RATE_PER_SESSION = 10;
    private const RATE_PER_DEVICE  = 50;

    /** §53: 允许的时钟偏差（秒） */
    private const MAX_TIME_SKEW = 300;

    /**
     * 处理客户端上报的安全事件
     *
     * @param array $event       事件数据（从3.1信封解密后的业务JSON）
     * @param array $session     当前业务会话（nb_sessions 行）
     * @param string $hsid       3.1 ECDH 会话ID
     * @param int   $seq         上报时的 seq
     * @return array             处理结果 [accepted, runtime]
     */
    public static function handle(array $event, array $session, string $hsid, int $seq): array
    {
        $now = time();

        // ----------------------------------------------------------
        // 1. 基础验证（§16 §51）
        // ----------------------------------------------------------
        $eventType = strtoupper(trim(Util::str($event, 'event_type', '')));
        if ($eventType === '' || !RuntimeRiskEngine::isKnownType($eventType)) {
            return self::reject('RUNTIME_EVENT_REJECTED', "未知事件类型: {$eventType}");
        }

        // §47: payload 大小限制
        $rawSize = strlen(json_encode($event) ?: '');
        if ($rawSize > self::MAX_PAYLOAD_SIZE) {
            return self::reject('RUNTIME_EVENT_REJECTED', "事件 payload 过大: {$rawSize} bytes");
        }

        // §53: Timestamp 校验 — 客户端时间与服务器时间偏差不超过 ±300 秒
        $clientTime = (int) ($event['client_time'] ?? 0);
        if ($clientTime > 0 && abs($clientTime - $now) > self::MAX_TIME_SKEW) {
            return self::reject('RUNTIME_EVENT_REJECTED', '客户端时间偏差超过允许范围');
        }

        // §52: seq 防重放 — Runtime Event 复用 Session seq，必须严格单调递增
        // 3.1 信封层已在 Handshake::openRequest 中做了 seq 原子校验（CAS UPDATE），
        // 这里做二次检查确保 event 内嵌 seq 与信封 seq 一致
        $eventSeq = (int) ($event['seq'] ?? $seq);
        if ($eventSeq > 0 && $seq > 0 && $eventSeq !== $seq) {
            return self::reject('RUNTIME_EVENT_REJECTED', '事件 seq 与会话 seq 不一致');
        }

        // §46: 限流
        $sessionId = (string) ($session['token'] ?? '');
        $deviceId  = (int) ($session['device_id'] ?? 0);
        $userId    = (int) ($session['user_id'] ?? 0);
        $softwareId = (int) ($session['software_id'] ?? 0);

        if (!RateLimit::hit("rt_evt:sess:{$sessionId}", self::RATE_PER_SESSION, 60)) {
            return self::reject('RUNTIME_EVENT_RATE_LIMITED', '会话事件上报频率超限');
        }

        // ----------------------------------------------------------
        // 2. 事件哈希 + 去重（§17 §18）
        // ----------------------------------------------------------
        $violationFlags = (int) ($event['violation_flags'] ?? 0);
        $clientTime = (int) ($event['client_time'] ?? 0);
        $detail = self::sanitizeDetail($event['details'] ?? []);

        // §17: event_hash = SHA-256(canonical_event_payload)
        // 手动 ksort 保证键序一致（JSON_SORT_KEYS 在 PHP 8.1+ 才可用）
        $canonicalData = [
            'detail'    => $detail,
            'flags'     => $violationFlags,
            'sid'       => $sessionId,
            'type'      => $eventType,
        ];
        ksort($canonicalData);
        $canonical = json_encode($canonicalData, JSON_UNESCAPED_UNICODE);
        $eventHash = hash('sha256', $canonical ?: '');

        // §18: 去重 — 窗口内相同 hash 直接合并
        // §97: 优先使用 Redis 缓存去重，减少数据库查询
        $dedupKey = "rt_dedup:{$eventHash}";
        $cachedDedup = Cache::get($dedupKey);
        if ($cachedDedup !== null) {
            // Redis 缓存命中：窗口内已上报过相同事件
            return [
                'accepted' => true,
                'deduped'  => true,
                'runtime'  => self::currentRuntimeState($sessionId),
            ];
        }

        // 数据库二次检查（Redis 不可用时的兜底）
        $dedup = Database::one(
            'SELECT id, created_at FROM ' . Database::t('security_events') . '
             WHERE event_hash = ? AND created_at > ? ORDER BY id DESC LIMIT 1',
            [$eventHash, $now - self::DEDUP_WINDOW]
        );
        if ($dedup) {
            // 去重命中：写入 Redis 缓存并返回
            Cache::set($dedupKey, $dedup['id'], self::DEDUP_WINDOW);
            return [
                'accepted' => true,
                'deduped'  => true,
                'runtime'  => self::currentRuntimeState($sessionId),
            ];
        }

        // ----------------------------------------------------------
        // 3. 风险评估（§19 §20 §76）
        //    评级**完全以客户端上报的真实判定为准**（SDK 按事件严重级别综合定级，
        //    与客户端实际执行的处置档同源；3.1 信封 + 会话保护传输）。
        //    不回落静态表：客户端未上报等级按 LOW、分数按 0 处理。
        //    会话/设备风险累计仍用静态表（不信任客户端）。
        //    处置动作按当前生效策略的三档配置取（与下发给 SDK 的动作同源）。
        // ----------------------------------------------------------
        $eval = RuntimeRiskEngine::evaluate($eventType);
        $clientLevel = strtoupper(trim((string) ($event['risk_level'] ?? '')));
        $riskLevel   = in_array($clientLevel, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true)
                     ? $clientLevel
                     : 'LOW';
        $riskScore   = max(0, min((int) ($event['risk_score'] ?? 0), 100000));
        $action      = RuntimePolicy::actionForLevel($riskLevel, $softwareId);
        $sticky      = $eval['sticky'];

        // ----------------------------------------------------------
        // 4. 持久化 + 更新 Session（§96 §99 §100: 事务）
        // ----------------------------------------------------------
        Database::begin();
        try {
            // 插入事件
            $eventId = Database::insert('security_events', [
                'software_id'     => $softwareId,
                'user_id'         => $userId,
                'device_id'       => $deviceId,
                'session_id'      => mb_substr($sessionId, 0, 128),
                'hsid'            => $hsid,
                'seq'             => $seq,
                'sdk_version'     => mb_substr((string) ($event['sdk_version'] ?? ''), 0, 32),
                'event_type'      => $eventType,
                'risk_level'      => $riskLevel,
                'risk_score'      => $riskScore,
                'violation_flags' => $violationFlags,
                'process_id'      => (int) ($event['process_id'] ?? 0) ?: null,
                'module_name'     => mb_substr((string) ($detail['module_name'] ?? ''), 0, 260) ?: null,
                'module_path'     => mb_substr((string) ($detail['module_path'] ?? ''), 0, 1024) ?: null,
                'event_hash'      => $eventHash,
                'event_detail'    => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                'client_ip'       => Util::ip(),
                'client_time'     => $clientTime > 0 ? $clientTime : $now,
                'server_time'     => $now,
                'handled'         => 0,
                'action_taken'    => $action,
                'sticky'          => $sticky ? 1 : 0,
                'created_at'      => $now,
            ]);

            // 累加 Session 风险（§19 累计评分）
            $sessEval = RuntimeRiskEngine::accumulate(
                (int) ($session['rt_risk_score'] ?? 0),
                $eventType
            );

            // 更新 nb_sessions 运行时状态
            $rtStatus = $riskLevel === 'CRITICAL' ? 'BLOCKED'
                      : ($riskLevel === 'HIGH'    ? 'RISK'
                      : ($sessEval['score'] > 0   ? 'RISK' : 'CLEAN'));

            Database::update('sessions', [
                'rt_status'       => $rtStatus,
                'rt_risk_score'   => $sessEval['score'],
                'rt_risk_level'   => $sessEval['level'],
                'rt_flags'        => ((int) ($session['rt_flags'] ?? 0)) | $violationFlags,
            ], 'token = :tok', ['tok' => $sessionId]);

            // §96: Critical 事件同步阻断 Session
            if ($riskLevel === 'CRITICAL' && $action === 'REVOKE_SESSION') {
                Database::update('sessions',
                    ['status' => 3, 'rt_status' => 'BLOCKED'],
                    'token = :tok', ['tok' => $sessionId]
                );
            }

            // 更新 nb_devices 运行时风险
            if ($deviceId > 0) {
                $devRow = Database::one(
                    'SELECT rt_risk_score FROM ' . Database::t('devices') . ' WHERE id = ?',
                    [$deviceId]
                );
                if ($devRow) {
                    $devEval = RuntimeRiskEngine::accumulate(
                        (int) $devRow['rt_risk_score'],
                        $eventType
                    );
                    Database::update('devices', [
                        'rt_risk_score' => $devEval['score'],
                        'rt_risk_level' => $devEval['level'],
                        'rt_flags'      => ((int) ($devRow['rt_risk_score'] ?? 0)) | $violationFlags,
                    ], 'id = :id', ['id' => $deviceId]);
                }
            }

            // 更新 nb_hsessions 运行时状态（3.1 会话层）
            if ($hsid !== '') {
                Database::update('hsessions', [
                    'rt_risk_score'   => $sessEval['score'],
                    'rt_risk_level'   => $sessEval['level'],
                    'rt_flags'        => ((int) ($session['rt_flags'] ?? 0)) | $violationFlags,
                    'rt_status'       => $rtStatus,
                    'rt_last_event_id'=> $eventId,
                    'rt_last_event_at'=> $now,
                ], 'sid = :sid', ['sid' => $hsid]);
            }

            Database::commit();

            // §97: 事务提交后更新缓存
            // 事件去重缓存
            Cache::set($dedupKey, $eventId, self::DEDUP_WINDOW);
            // Session Runtime 风险缓存
            $rtCacheKey = "rt_risk:{$sessionId}";
            Cache::set($rtCacheKey, [
                'status'     => $rtStatus,
                'risk_level' => $sessEval['level'],
                'risk_score' => $sessEval['score'],
            ], 300); // 5 分钟 TTL
        } catch (Throwable $e) {
            Database::rollback();
            // §100: 失败回滚，返回拒绝
            return self::reject('RUNTIME_EVENT_REJECTED', '事件处理失败: ' . $e->getMessage());
        }

        // 写日志
        Logger::log('runtime_event', 1, "{$eventType} ({$riskLevel}, score={$riskScore})", [
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'risk_level' => $riskLevel,
        ]);

        return [
            'accepted' => true,
            'deduped'  => false,
            'runtime'  => [
                'status'     => $rtStatus,
                'risk_level' => $sessEval['level'],
                'risk_score' => $sessEval['score'],
                'action'     => $action,
            ],
        ];
    }

    /**
     * §48: 清洗 event_detail，只保留白名单字段
     */
    private static function sanitizeDetail($detail): array
    {
        if (!is_array($detail)) {
            return [];
        }
        $clean = [];
        foreach (self::ALLOWED_DETAIL_FIELDS as $field) {
            if (array_key_exists($field, $detail)) {
                $val = $detail[$field];
                // 只允许标量值，拒绝数组/对象/二进制
                if (is_scalar($val)) {
                    $clean[$field] = $val;
                }
            }
        }
        return $clean;
    }

    /**
     * 获取当前会话的运行时状态
     */
    private static function currentRuntimeState(string $sessionId): array
    {
        $s = Database::one(
            'SELECT rt_status, rt_risk_score, rt_risk_level, software_id FROM ' . Database::t('sessions') . ' WHERE token = ?',
            [$sessionId]
        );
        if (!$s) {
            return ['status' => 'UNKNOWN', 'risk_level' => 'LOW', 'risk_score' => 0, 'action' => 'REPORT'];
        }
        return [
            'status'     => $s['rt_status'] ?? 'UNKNOWN',
            'risk_level' => $s['rt_risk_level'] ?? 'LOW',
            'risk_score' => (int) ($s['rt_risk_score'] ?? 0),
            // 动作按该会话所属软件的当前生效策略取（与下发 SDK 同源）
            'action'     => RuntimePolicy::actionForLevel(
                (string) ($s['rt_risk_level'] ?? 'LOW'),
                (int) ($s['software_id'] ?? 0)
            ),
        ];
    }

    /**
     * 返回拒绝响应
     */
    private static function reject(string $code, string $msg): array
    {
        return [
            'accepted' => false,
            'deduped'  => false,
            'error'    => ['code' => $code, 'msg' => $msg],
            'runtime'  => ['status' => 'UNKNOWN', 'risk_level' => 'LOW', 'risk_score' => 0, 'action' => 'REPORT'],
        ];
    }

    // ------------------------------------------------------------------
    // 后台查询
    // ------------------------------------------------------------------

    /**
     * 后台：安全事件列表
     */
    public static function listEvents(int $page, int $size, array $filters = []): array
    {
        $where = '1=1';
        $params = [];

        if (!empty($filters['event_type'])) {
            $where .= ' AND event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['risk_level'])) {
            $where .= ' AND risk_level = ?';
            $params[] = $filters['risk_level'];
        }
        if (!empty($filters['software_id'])) {
            $where .= ' AND software_id = ?';
            $params[] = (int) $filters['software_id'];
        }
        if (isset($filters['handled']) && $filters['handled'] !== '') {
            $where .= ' AND handled = ?';
            $params[] = (int) $filters['handled'];
        }

        $base = 'SELECT * FROM ' . Database::t('security_events') . ' WHERE ' . $where;
        return Database::paginate($base, $params, $page, $size, 'id DESC');
    }

    /**
     * 后台：事件详情
     */
    public static function getEvent(int $id): ?array
    {
        return Database::one(
            'SELECT * FROM ' . Database::t('security_events') . ' WHERE id = ?',
            [$id]
        );
    }

    /**
     * 后台：标记事件处理状态（§104 §105: 误报不删除，保留审计数据）
     */
    public static function markHandled(int $id, int $status, string $note = ''): bool
    {
        $allowed = [0, 1, 2]; // 0未处理 1已处理 2误报
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        return Database::update('security_events', [
            'handled' => $status,
        ], 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * 后台：批量标记事件处理状态（§104 §105: 误报不删除，保留审计数据）
     * @param int[] $ids 事件ID列表
     * @return int 实际更新的行数
     */
    public static function markHandledBatch(array $ids, int $status): int
    {
        $allowed = [0, 1, 2]; // 0未处理 1已处理 2误报
        if (!in_array($status, $allowed, true)) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn ($v) => $v > 0
        )));
        if (!$ids || count($ids) > 500) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return Database::exec(
            'UPDATE ' . Database::t('security_events') . " SET handled = ? WHERE id IN ($marks)",
            array_merge([$status], $ids)
        );
    }

    /**
     * 后台：Overview 统计（§38）
     */
    public static function overviewStats(int $softwareId = 0): array
    {
        $today = strtotime('today');
        $swFilter = $softwareId > 0 ? ' AND software_id = ' . (int) $softwareId : '';

        $total = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('security_events') . ' WHERE created_at >= ?' . $swFilter,
            [$today]
        );
        $critical = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('security_events') . ' WHERE created_at >= ? AND risk_level = ?' . $swFilter,
            [$today, 'CRITICAL']
        );
        $high = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('security_events') . ' WHERE created_at >= ? AND risk_level = ?' . $swFilter,
            [$today, 'HIGH']
        );
        $medium = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('security_events') . ' WHERE created_at >= ? AND risk_level = ?' . $swFilter,
            [$today, 'MEDIUM']
        );
        $blocked = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('sessions') . ' WHERE rt_status = ?' . $swFilter,
            ['BLOCKED']
        );

        return [
            'events_today'    => $total,
            'critical_today'  => $critical,
            'high_today'      => $high,
            'medium_today'    => $medium,
            'blocked_sessions'=> $blocked,
        ];
    }
}

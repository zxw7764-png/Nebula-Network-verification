<?php
/**
 * 设备绑定管理
 */
class Device
{
    /**
     * 校验设备是否可以登录
     * 已绑定 -> 通过；未绑定 -> 若未达上限则自动绑定
     *
     * 客户端上报 device_fp（多硬件组件）时，额外做加权指纹校验：
     * 组件漂移容忍、机器码伪造识别、模拟器/虚拟机识别、一机多号统计。
     * 未上报时全部跳过，行为与旧版本一致。
     *
     * @return array{ok:bool, code:int, msg:string, device:?array, auto_bound:bool, risk?:array}
     */
    public static function check(int $userId, string $machineId, array $info, int $maxDevices): array
    {
        if ($machineId === '') {
            return ['ok' => false, 'code' => 4003, 'msg' => '缺少机器码', 'device' => null, 'auto_bound' => false];
        }

        // 黑名单校验：被拉黑的机器码禁止登录/绑定（无论账号是否正常）
        $ban = self::banInfo($machineId);
        if ($ban) {
            $expire = (int) $ban['expire_at'];
            $msg = $expire === 0
                ? '设备已被拉黑（永久），禁止登录'
                : '设备已被拉黑，' . date('Y-m-d H:i', $expire) . ' 前禁止登录';
            return ['ok' => false, 'code' => 4004, 'msg' => $msg, 'device' => null, 'auto_bound' => false];
        }

        // 本次上报的设备指纹（客户端未上报时 hash 为 null，后续一律跳过）
        $fp = self::fingerprint($info);

        $row = Database::one(
            'SELECT * FROM ' . Database::t('devices') . ' WHERE user_id = ? AND machine_id = ?',
            [$userId, $machineId]
        );

        // 已存在且正常
        if ($row && (int) $row['status'] === 1) {
            $ev = self::evaluateFp($fp, $info, $userId, $row);
            if ($ev['block']) {
                self::logRisk($userId, $machineId, $ev['flags'], $ev['drift']);
                return [
                    'ok' => false, 'code' => 4005, 'msg' => $ev['msg'],
                    'device' => $row, 'auto_bound' => false, 'risk' => $ev['flags'],
                ];
            }
            if ($ev['cols']) {
                self::updateDevice($ev['cols'], (int) $row['id'], $userId);
            }
            if ($ev['flags']) {
                self::logRisk($userId, $machineId, $ev['flags'], $ev['drift']);
            }
            self::touch((int) $row['id'], $info['ip'] ?? null);
            return [
                'ok' => true, 'code' => 0, 'msg' => 'ok',
                'device' => $row, 'auto_bound' => false, 'risk' => $ev['flags'],
            ];
        }

        // 存在但被解绑 -> 重新激活
        if ($row && (int) $row['status'] === 0) {
            $ev = self::evaluateFp($fp, $info, $userId, $row);
            if ($ev['block']) {
                self::logRisk($userId, $machineId, $ev['flags'], $ev['drift']);
                return [
                    'ok' => false, 'code' => 4005, 'msg' => $ev['msg'],
                    'device' => $row, 'auto_bound' => false, 'risk' => $ev['flags'],
                ];
            }
            // ------------------------------------------------------------------
            // 设备数上限：与「全新设备」分支同样走原子化路径（P1-07）
            // 原先这里是「先 activeCount() 查，再 updateDevice() 写」，
            // 与插入分支一样存在并发超限。
            // ------------------------------------------------------------------
            $data = [
                'status'     => 1,
                'bind_at'    => time(),
                'last_seen'  => time(),
                'unbind_at'  => 0,
                'unbind_reason' => null,
                'ip'         => $info['ip'] ?? null,
                'device_name'=> $info['device_name'] ?? $row['device_name'],
                'os_info'    => $info['os_info'] ?? $row['os_info'],
            ];
            if ($ev['cols']) {
                $data += $ev['cols'];
            }
            $got = self::reactivateWithLimit((int) $row['id'], $userId, $data, $maxDevices);
            if (!$got) {
                return ['ok' => false, 'code' => 4001, 'msg' => '设备数量已达上限', 'device' => $row, 'auto_bound' => false];
            }
            if ($ev['flags']) {
                self::logRisk($userId, $machineId, $ev['flags'], $ev['drift']);
            }
            $row['status'] = 1;
            return [
                'ok' => true, 'code' => 0, 'msg' => '设备已重新绑定',
                'device' => $row, 'auto_bound' => true, 'risk' => $ev['flags'],
            ];
        }

        // 全新设备
        $ev = self::evaluateFp($fp, $info, $userId, null);
        if ($ev['block']) {
            self::logRisk($userId, $machineId, $ev['flags'], 0);
            return [
                'ok' => false, 'code' => 4005, 'msg' => $ev['msg'],
                'device' => null, 'auto_bound' => false, 'risk' => $ev['flags'],
            ];
        }

        $insert = [
            'user_id'    => $userId,
            'machine_id' => $machineId,
            'device_name'=> $info['device_name'] ?? '未知设备',
            'os_info'    => $info['os_info'] ?? null,
            'ip'         => $info['ip'] ?? null,
            'status'     => 1,
            'bind_at'    => time(),
            'last_seen'  => time(),
        ];
        if ($ev['cols']) {
            $insert += $ev['cols'];
        }

        // ------------------------------------------------------------------
        // 原子化「检查上限 + 插入」（P1-07）
        // ------------------------------------------------------------------
        // 修复前：先 activeCount() 查数量，再 insertDevice() 插入。两个请求
        // 可以同时读到 count = max-1，然后都插入成功 —— 设备数上限形同虚设，
        // 一个账号能绑定任意多台机器（这是「一卡多机」薅羊毛的主要手法）。
        // 现在放进事务，并用 SELECT ... FOR UPDATE 锁住该用户在本表的行，
        // 使「读计数 → 写插入」成为临界区，并发请求被迫串行。
        // ------------------------------------------------------------------
        $id = self::insertWithLimit($insert, $userId, $maxDevices);
        if ($id === null) {
            return ['ok' => false, 'code' => 4001, 'msg' => '设备数量已达上限', 'device' => null, 'auto_bound' => false];
        }
        if ($ev['flags']) {
            self::logRisk($userId, $machineId, $ev['flags'], 0);
        }

        return [
            'ok'         => true,
            'code'       => 0,
            'msg'        => '设备绑定成功',
            'device'     => Database::one('SELECT * FROM ' . Database::t('devices') . ' WHERE id = ?', [$id]),
            'auto_bound' => true,
            'risk'       => $ev['flags'],
        ];
    }

    /**
     * 事务内「检查设备数上限 + 插入」
     * ------------------------------------------------------------------
     * $maxDevices <= 0 表示不限量，直接插入（省掉事务开销）。
     * 达到上限时返回 null，调用方据此返回 4001。
     *
     * 并发正确性来源：
     *   锁 users 父行（SELECT ... FOR UPDATE），而不是 devices 子表行。
     *   旧实现锁 devices 行，当用户零设备时锁不到任何行，并发请求同时
     *   通过 count < max 检查后双双插入，设备上限形同虚设。
     *   users 行一定存在（注册时创建），锁它永远不会空转。
     */
    private static function insertWithLimit(array $data, int $userId, int $maxDevices): ?int
    {
        if ($maxDevices <= 0) {
            return self::insertDevice($data, $userId);
        }

        Database::begin();
        try {
            // 锁父行 users，把「查计数 + 插入」变成临界区
            Database::one(
                'SELECT id FROM ' . Database::t('users') . ' WHERE id = ? FOR UPDATE',
                [$userId]
            );
            $count = (int) Database::value(
                'SELECT COUNT(*) FROM ' . Database::t('devices') . ' WHERE user_id = ? AND status = 1',
                [$userId]
            );
            if ($count >= $maxDevices) {
                Database::rollback();
                return null;
            }
            $id = self::insertDevice($data, $userId);
            Database::commit();
            return $id;
        } catch (Throwable $e) {
            Database::rollback();
            // 唯一键冲突（同一机器并发首次绑定）：视为已绑定，交给上层重新查询
            Logger::log('device_bind', 0, '设备绑定失败：' . $e->getMessage(), ['user_id' => $userId]);
            return null;
        }
    }

    /**
     * 事务内「检查设备数上限 + 重新激活已解绑设备」
     * 与 insertWithLimit 同构，只是把插入换成状态迁移。
     * 同样锁 users 父行，避免零设备状态下的并发突破。
     */
    private static function reactivateWithLimit(int $deviceId, int $userId, array $data, int $maxDevices): bool
    {
        if ($maxDevices <= 0) {
            self::updateDevice($data, $deviceId, $userId);
            return true;
        }

        Database::begin();
        try {
            Database::one(
                'SELECT id FROM ' . Database::t('users') . ' WHERE id = ? FOR UPDATE',
                [$userId]
            );
            $count = (int) Database::value(
                'SELECT COUNT(*) FROM ' . Database::t('devices') . ' WHERE user_id = ? AND status = 1',
                [$userId]
            );
            if ($count >= $maxDevices) {
                Database::rollback();
                return false;
            }
            self::updateDevice($data, $deviceId, $userId);
            Database::commit();
            return true;
        } catch (Throwable $e) {
            Database::rollback();
            Logger::log('device_bind', 0, '设备重新绑定失败：' . $e->getMessage(), ['user_id' => $userId]);
            return false;
        }
    }

    /**
     * 取本次请求上报的设备指纹组件
     * 未开启或未上报时 hash 为 null，调用方据此跳过全部指纹逻辑。
     */
    private static function fingerprint(array $info): array
    {
        if (!DeviceFp::enabled()) {
            return ['hash' => null, 'score' => 0, 'count' => 0, 'components' => []];
        }
        return DeviceFp::compose(DeviceFp::fromRequest($info));
    }

    /**
     * 评估设备指纹
     * ------------------------------------------------------------------
     * $row 为已存在的设备行（全新设备传 null）：有旧指纹时做漂移判定。
     *
     * 返回：
     *   cols  为可直接写入 nb_devices 的字段（降级时为空数组，即不写指纹）
     *   flags 为命中的风险标记；block 表示是否应拒绝本次登录
     *
     * 任何异常（例如尚未执行指纹迁移导致列缺失）都降级为
     * 「不写指纹、不拦截」，保证登录链路不被指纹模块拖垮。
     *
     * @return array{cols:array, flags:array, block:bool, msg:string, drift:int}
     */
    private static function evaluateFp(array $fp, array $info, int $userId, ?array $row): array
    {
        if ($fp['hash'] === null) {
            return ['cols' => [], 'flags' => [], 'block' => false, 'msg' => '', 'drift' => 0];
        }

        try {
            $flags = DeviceFp::riskFlags($fp['components'], $info);
            $drift = 0;

            if ($row !== null) {
                $oldHash = (string) ($row['fp_hash'] ?? '');
                if ($oldHash !== '' && $oldHash !== $fp['hash']) {
                    $old = json_decode((string) ($row['fp_json'] ?? ''), true);
                    $chk = DeviceFp::driftCheck(is_array($old) ? $old : [], $fp['components']);
                    $drift = (int) $chk['similarity'];
                    if (!$chk['same']) {
                        $flags[] = 'fp_changed';
                    }
                }
            }

            $flags = array_values(array_unique(array_merge($flags, self::usageFlags($userId, (string) $fp['hash']))));

            $block = false;
            $msg   = '';
            if (in_array('vm', $flags, true) && Config::get('device_fp.block_vm', false)) {
                $block = true;
                $msg   = '检测到模拟器/虚拟机环境，已拒绝登录';
            }
            if (in_array('fp_changed', $flags, true) && Config::get('device_fp.block_on_drift', false)) {
                $block = true;
                $msg   = '设备指纹与绑定设备不符，已拒绝登录';
            }

            return [
                'cols' => [
                    'fp_hash'    => $fp['hash'],
                    'fp_json'    => json_encode($fp['components'], JSON_UNESCAPED_UNICODE),
                    'fp_score'   => (int) $fp['score'],
                    'vm_flag'    => DeviceFp::isVirtual($flags) ? 1 : 0,
                    'risk_flags' => $flags ? implode(',', $flags) : null,
                ],
                'flags' => $flags,
                'block' => $block,
                'msg'   => $msg,
                'drift' => $drift,
            ];
        } catch (Throwable $e) {
            Logger::log('device_fp', 0, '指纹处理降级：' . $e->getMessage(), ['user_id' => $userId]);
            return ['cols' => [], 'flags' => [], 'block' => false, 'msg' => '', 'drift' => 0];
        }
    }

    /**
     * 指纹复用统计
     *   multi_fp       同一账号在窗口内出现过多不同指纹（疑似伪造机器码绕过设备上限）
     *   shared_machine 同一指纹关联了过多账号（一机多号：共享机器 / 批量注册）
     */
    private static function usageFlags(int $userId, string $fpHash): array
    {
        if ($fpHash === '') {
            return [];
        }
        $flags = [];
        $since = time() - max(1, (int) Config::get('device_fp.fp_window_days', 7)) * 86400;

        $maxFp = (int) Config::get('device_fp.max_fp_per_user', 5);
        if ($maxFp > 0) {
            $others = (int) Database::value(
                'SELECT COUNT(DISTINCT fp_hash) FROM ' . Database::t('devices') . '
                 WHERE user_id = ? AND fp_hash IS NOT NULL AND CHAR_LENGTH(fp_hash) > 0
                   AND fp_hash <> ? AND bind_at > ?',
                [$userId, $fpHash, $since]
            );
            if ($others + 1 > $maxFp) {
                $flags[] = 'multi_fp';
            }
        }

        $maxUser = (int) Config::get('device_fp.max_user_per_fp', 3);
        if ($maxUser > 0) {
            $others = (int) Database::value(
                'SELECT COUNT(DISTINCT user_id) FROM ' . Database::t('devices') . '
                 WHERE fp_hash = ? AND user_id <> ? AND bind_at > ?',
                [$fpHash, $userId, $since]
            );
            if ($others + 1 > $maxUser) {
                $flags[] = 'shared_machine';
            }
        }

        return $flags;
    }

    /** 记录指纹风险日志 */
    private static function logRisk(int $userId, string $machineId, array $flags, int $drift): void
    {
        if (!$flags) {
            return;
        }
        $labels = array_map([DeviceFp::class, 'flagLabel'], $flags);
        Logger::log('device_risk', 0, '设备指纹风险：' . implode('、', $labels), [
            'user_id'    => $userId,
            'machine_id' => $machineId,
            'flags'      => $flags,
            'drift'      => $drift,
        ]);
    }

    /** 指纹相关列名（由 install/migrate_device_fp.php 添加） */
    private static function fpColumnNames(): array
    {
        return ['fp_hash', 'fp_json', 'fp_score', 'vm_flag', 'risk_flags'];
    }

    /**
     * 更新设备行；若因「未执行指纹迁移」导致列不存在，则去掉指纹列重试。
     * 目的是让指纹模块出问题时只丢指纹，不能把登录/绑定一起拖垮。
     */
    private static function updateDevice(array $data, int $deviceId, int $userId): void
    {
        try {
            Database::update('devices', $data, 'id = :id', ['id' => $deviceId]);
            return;
        } catch (Throwable $e) {
            $plain = array_diff_key($data, array_flip(self::fpColumnNames()));
            if (count($plain) === count($data)) {
                throw $e; // 与指纹无关的写入失败，照常抛出交由上层处理
            }
            Logger::log('device_fp', 0, '指纹写入降级：' . $e->getMessage(), ['user_id' => $userId]);
            Database::update('devices', $plain, 'id = :id', ['id' => $deviceId]);
        }
    }

    /** 新增设备行，同样在指纹列缺失时降级重试 */
    private static function insertDevice(array $data, int $userId): int
    {
        try {
            return Database::insert('devices', $data);
        } catch (Throwable $e) {
            $plain = array_diff_key($data, array_flip(self::fpColumnNames()));
            if (count($plain) === count($data)) {
                throw $e;
            }
            Logger::log('device_fp', 0, '指纹写入降级：' . $e->getMessage(), ['user_id' => $userId]);
            return Database::insert('devices', $plain);
        }
    }

    /**
     * 查询机器码是否在黑名单有效期内
     * expire_at = 0 表示永久；大于当前时间表示仍在拉黑期
     *
     * @return ?array 命中返回拉黑记录，未拉黑/已过期返回 null
     */
    public static function banInfo(string $machineId): ?array
    {
        if ($machineId === '') {
            return null;
        }
        $ban = Database::one(
            'SELECT * FROM ' . Database::t('device_bans') . ' WHERE machine_id = ?',
            [$machineId]
        );
        if (!$ban) {
            return null;
        }
        $expire = (int) $ban['expire_at'];
        if ($expire !== 0 && $expire <= time()) {
            return null;
        }
        return $ban;
    }

    /**
     * 自动拉黑设备（服务端自动处置，非管理员操作：admin_id=0）
     * 与后台 device_ban 动作等价：写黑名单 → 解绑该机器码全部绑定 → 踢下线全部会话。
     * 幂等：已拉黑则更新有效期（ON DUPLICATE KEY）；重复调用不会产生脏数据。
     *
     * @param string $machineId 机器码
     * @param string $reason    拉黑原因（记录到黑名单/解绑原因）
     * @param int    $expire    解除时间，0=永久
     * @param int    $adminId   操作者，0=系统自动
     */
    public static function addBan(string $machineId, string $reason = '违规自动冻结', int $expire = 0, int $adminId = 0): bool
    {
        if ($machineId === '') {
            return false;
        }
        $now = time();
        Database::exec(
            'INSERT INTO ' . Database::t('device_bans')
            . ' (machine_id, reason, admin_id, expire_at, created_at) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE reason = VALUES(reason), expire_at = VALUES(expire_at),'
            . ' admin_id = VALUES(admin_id)',
            [$machineId, mb_substr($reason, 0, 250), $adminId, $expire, $now]
        );
        Database::exec(
            'UPDATE ' . Database::t('devices')
            . ' SET status = 0, unbind_at = ?, unbind_reason = ? WHERE machine_id = ? AND status = 1',
            [$now, '违规冻结：' . $reason, $machineId]
        );
        Database::exec(
            'UPDATE ' . Database::t('sessions')
            . ' SET status = 3 WHERE machine_id = ? AND status = 1',
            [$machineId]
        );
        return true;
    }

    /** 更新设备最后活跃时间 */
    public static function touch(int $deviceId, ?string $ip = null): void
    {
        $data = ['last_seen' => time()];
        if ($ip) {
            $data['ip'] = $ip;
        }
        Database::update('devices', $data, 'id = :id', ['id' => $deviceId]);
    }

    /** 统计有效设备数 */
    public static function activeCount(int $userId): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::t('devices') . ' WHERE user_id = ? AND status = 1',
            [$userId]
        );
    }

    /** 解绑设备 */
    public static function unbind(int $userId, string $machineId, string $reason = '用户主动解绑'): bool
    {
        $n = Database::exec(
            'UPDATE ' . Database::t('devices') . '
             SET status = 0, unbind_at = ?, unbind_reason = ?
             WHERE user_id = ? AND machine_id = ? AND status = 1',
            [time(), $reason, $userId, $machineId]
        );
        return $n > 0;
    }

    /** 管理端强制解绑 */
    public static function forceUnbind(int $deviceId, string $reason = '管理员解绑'): bool
    {
        $n = Database::exec(
            'UPDATE ' . Database::t('devices') . ' SET status = 0, unbind_at = ?, unbind_reason = ? WHERE id = ?',
            [time(), $reason, $deviceId]
        );
        return $n > 0;
    }

    /** 解绑该用户全部设备 */
    public static function unbindAll(int $userId, string $reason = '全部解绑'): int
    {
        return Database::exec(
            'UPDATE ' . Database::t('devices') . '
             SET status = 0, unbind_at = ?, unbind_reason = ?
             WHERE user_id = ? AND status = 1',
            [time(), $reason, $userId]
        );
    }

    /** 设备列表 */
    public static function listByUser(int $userId): array
    {
        return Database::all(
            'SELECT * FROM ' . Database::t('devices') . ' WHERE user_id = ? ORDER BY status DESC, last_seen DESC',
            [$userId]
        );
    }

    /** 清理僵尸设备（长期未心跳） */
    public static function gcOffline(int $timeout): int
    {
        $deadline = time() - $timeout;
        return Database::exec(
            'UPDATE ' . Database::t('devices') . '
             SET status = 0, unbind_at = ?, unbind_reason = "心跳超时自动解绑"
             WHERE status = 1 AND last_seen > 0 AND last_seen < ?',
            [time(), $deadline]
        );
    }
}

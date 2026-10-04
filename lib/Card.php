<?php
/**
 * 激活码业务
 */
class Card
{
    const TYPE_DURATION = 1; // 时长卡
    const TYPE_POINTS   = 2; // 点数卡
    const TYPE_TIMES    = 3; // 次数卡
    const TYPE_FOREVER  = 4; // 永久卡

    /** 全部卡密类型（代理商按类型配额度/单价时按此顺序遍历） */
    const TYPE_LIST = [self::TYPE_DURATION, self::TYPE_POINTS, self::TYPE_TIMES, self::TYPE_FOREVER];

    const STATUS_UNUSED = 0;
    const STATUS_USED   = 1;
    const STATUS_VOID   = 2;
    const STATUS_SOLD   = 3; // 已售出未激活（发卡网售出后锁定，激活流程放行）

    /**
     * 激活
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function activate(array $in, array $user, int $softwareId = 0): array
    {
        $code = strtoupper(trim((string) Util::get($in, 'code', '')));
        if ($code === '') {
            return ['ok' => false, 'code' => 1001, 'msg' => '请输入激活码', 'data' => null];
        }

        // 多软件：$softwareId > 0 表示客户端 API 上下文（激活码必须属于该软件）；
        // 官网个人页激活传 0，按激活码自身所属软件自动绑定账号。
        if ($softwareId <= 0) {
            $softwareId = Software::currentId();
        }

        Database::begin();
        try {
            $card = Database::one(
                'SELECT * FROM ' . Database::t('cards') . ' WHERE code = ? FOR UPDATE',
                [$code]
            );
            // 兜底：用户可能漏输/混用分隔符（如手输 ABCDEFGH 或 AB2C.DE3F-GH4J 代替 AB2C-DE3F-GH4J），
            // 剥离所有非字母数字字符后再匹配一次（REPLACE 无法走索引，仅精确未命中时执行一次）
            if (!$card) {
                $flat = preg_replace('/[^A-Z0-9]/', '', $code);
                if ($flat !== '') {
                    $card = Database::one(
                        "SELECT * FROM " . Database::t('cards')
                            . " WHERE REPLACE(REPLACE(REPLACE(code,'-',''),'.',''),'_','') = ? FOR UPDATE",
                        [$flat]
                    );
                }
            }

            if (!$card) {
                Database::rollback();
                return ['ok' => false, 'code' => 3001, 'msg' => '激活码不存在', 'data' => null];
            }
            if ((int) $card['status'] === self::STATUS_VOID) {
                Database::rollback();
                return ['ok' => false, 'code' => 3003, 'msg' => '激活码已作废', 'data' => null];
            }
            if ((int) $card['status'] === self::STATUS_USED) {
                Database::rollback();
                return ['ok' => false, 'code' => 3002, 'msg' => '激活码已被使用', 'data' => null];
            }
            // 多软件隔离：激活码必须属于当前软件
            $cardSw = (int) ($card['software_id'] ?? 0);
            if ($softwareId > 0 && $cardSw > 0 && $cardSw !== $softwareId) {
                Database::rollback();
                return ['ok' => false, 'code' => 3008, 'msg' => '激活码不属于当前软件', 'data' => null];
            }
            // 账号已绑定其他软件 → 拒绝激活
            if ((int) ($user['software_id'] ?? 0) > 0 && $cardSw > 0
                && (int) $user['software_id'] !== $cardSw) {
                Database::rollback();
                return ['ok' => false, 'code' => 3008, 'msg' => '激活码不属于当前软件', 'data' => null];
            }
            // 卡本身有效期
            if ((int) $card['expire_at'] > 0 && (int) $card['expire_at'] < time()) {
                Database::rollback();
                return ['ok' => false, 'code' => 3004, 'msg' => '激活码已过期', 'data' => null];
            }

            // ------------------------------------------------------------------
            // 锁住用户行，防止两张卡并发激活导致 lost update（少到账）。
            // $user 是调用前查询的快照，vip_expire / points 可能已过期；
            // 这里在事务内重新锁行并读取最新值，保证叠加基于真实当前值。
            // ------------------------------------------------------------------
            $user = Database::one(
                'SELECT * FROM ' . Database::t('users') . ' WHERE id = ? FOR UPDATE',
                [(int) $user['id']]
            );
            if (!$user) {
                Database::rollback();
                return ['ok' => false, 'code' => 9999, 'msg' => '账号不存在', 'data' => null];
            }
            // 软件隔离校验需要用锁定后的最新行
            if ((int) ($user['software_id'] ?? 0) > 0 && $cardSw > 0
                && (int) $user['software_id'] !== $cardSw) {
                Database::rollback();
                return ['ok' => false, 'code' => 3008, 'msg' => '激活码不属于当前软件', 'data' => null];
            }

            $now  = time();
            $type = (int) $card['type'];
            $dur  = (int) $card['duration'];

            $updates = ['updated_at' => $now];
            $detail  = '';

            switch ($type) {
                case self::TYPE_DURATION:
                    // 时长卡：在原有效期上叠加
                    $base = (int) $user['vip_expire'];
                    if ($base === -1) {
                        $detail = '当前已是永久会员，未叠加时长';
                    } else {
                        $base = max($base, $now);
                        $updates['vip_expire'] = $base + $dur;
                        $detail = '增加时长 ' . Util::duration($dur);
                    }
                    break;

                case self::TYPE_POINTS:
                    $updates['points'] = (int) $user['points'] + $dur;
                    $detail = '增加点数 ' . $dur;
                    break;

                case self::TYPE_TIMES:
                    // 次数卡按点数处理，每次扣 1
                    $updates['points'] = (int) $user['points'] + $dur;
                    $detail = '增加次数 ' . $dur;
                    break;

                case self::TYPE_FOREVER:
                    $updates['vip_expire'] = -1;
                    $detail = '开通永久会员';
                    break;

                default:
                    Database::rollback();
                    return ['ok' => false, 'code' => 1001, 'msg' => '未知卡密类型', 'data' => null];
            }

            // 设备上限：取较大值
            if ((int) $card['max_devices'] > (int) $user['max_devices']) {
                $updates['max_devices'] = (int) $card['max_devices'];
            }

            // 激活后分配用户组（0=不换组，保持注册时的默认用户组）
            $toGroup = (int) ($card['group_id'] ?? 0);
            if ($toGroup > 0 && $toGroup !== (int) $user['group_id']) {
                $group = Database::one(
                    'SELECT * FROM ' . Database::t('groups') . ' WHERE id = ?',
                    [$toGroup]
                );
                if ($group) {
                    $updates['group_id'] = $toGroup;
                    $detail .= '，进入用户组「' . $group['name'] . '」';
                    // 组设备上限若更大则同步提升（与卡密设备上限取较大值）
                    if ((int) $group['max_devices'] > (int) ($updates['max_devices'] ?? $user['max_devices'])) {
                        $updates['max_devices'] = (int) $group['max_devices'];
                    }
                }
            }

            // 账号尚未绑定软件 → 激活时自动绑定到激活码所属软件
            if ((int) ($user['software_id'] ?? 0) <= 0 && $cardSw > 0) {
                $updates['software_id'] = $cardSw;
            }

            // 卡密快照：写进用户行，删卡后管理端仍能反查/找回账号（用存储规范值）
            $updates['card_code'] = (string) $card['code'];

            // 记录最近激活的点数/次数卡类型（决定扣点语义：次数卡=每次登录，点数卡=按配置模式）
            if (in_array((int) $card['type'], [self::TYPE_POINTS, self::TYPE_TIMES], true)) {
                $updates['card_type'] = (int) $card['type'];
            }

            Database::update('users', $updates, 'id = :id', ['id' => $user['id']]);

            // 标记卡密已用
            Database::update('cards', [
                'status'   => self::STATUS_USED,
                'used_by'  => (int) $user['id'],
                'used_at'  => $now,
                'used_ip'  => Util::ip(),
            ], 'id = :id', ['id' => $card['id']]);

            // 批次计数
            if ((int) $card['batch_id'] > 0) {
                Database::exec(
                    'UPDATE ' . Database::t('card_batches') . ' SET used_count = used_count + 1 WHERE id = ?',
                    [$card['batch_id']]
                );
            }

            // 审计（code 用存储规范值，便于与卡密表对应）
            Database::insert('card_logs', [
                'card_id'    => $card['id'],
                'code'       => $card['code'],
                'user_id'    => $user['id'],
                'action'     => 'activate',
                'detail'     => $detail,
                'ip'         => Util::ip(),
                'created_at' => $now,
            ]);

            Database::commit();

            $fresh = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$user['id']]);

            return [
                'ok'   => true,
                'code' => 0,
                'msg'  => '激活成功：' . $detail,
                'data' => [
                    'card_type'  => $type,
                    'detail'     => $detail,
                    'user'       => Auth::publicInfo($fresh),
                ],
            ];
        } catch (Throwable $e) {
            Database::rollback();
            return ['ok' => false, 'code' => 9999, 'msg' => '激活失败：' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * 批量生成
     * @param int $adminId 生成者管理员ID（代理端生成时为 0）
     * @param int $agentId 归属代理商ID（0=官方直发）
     * @return array{ok:bool, code:int, msg:string, data:?array}
     */
    public static function generate(array $in, int $adminId, int $agentId = 0): array
    {
        $softwareId = (int) Util::get($in, 'software_id', 0);
        if ($softwareId <= 0) {
            $softwareId = Software::currentId();
        }
        if ($softwareId <= 0 || !Software::find($softwareId)) {
            return ['ok' => false, 'code' => 1001, 'msg' => '请选择有效的软件', 'data' => null];
        }
        $count      = max(1, min(10000, (int) Util::get($in, 'count', 10)));
        $type       = (int) Util::get($in, 'type', self::TYPE_DURATION);
        $duration   = (int) Util::get($in, 'duration', 86400 * 30);
        $maxDevices = max(1, min(99, (int) Util::get($in, 'max_devices', 1)));
        $groupId    = (int) Util::get($in, 'group_id', 0);
        $prefix     = preg_replace('/[^A-Za-z0-9]/', '', (string) Util::get($in, 'prefix', ''));
        $name       = mb_substr((string) Util::get($in, 'name', ''), 0, 120);
        $expireDays = (int) Util::get($in, 'expire_days', 0); // 卡密本身有效期天数，0=永久
        $remark     = mb_substr((string) Util::get($in, 'remark', ''), 0, 250);
        // 卡密格式模板：X=字母数字（去易混淆） D=纯数字，其余字符原样（常用 -）
        $format     = strtoupper((string) Util::get($in, 'format', 'XXXX-XXXX-XXXX-XXXX'));
        if (($err = Util::validCardTemplate($format)) !== true) {
            return ['ok' => false, 'code' => 1001, 'msg' => '卡密格式：' . $err, 'data' => null];
        }

        if (!in_array($type, [1, 2, 3, 4], true)) {
            return ['ok' => false, 'code' => 1001, 'msg' => '卡密类型错误', 'data' => null];
        }
        if ($type !== self::TYPE_FOREVER && $duration <= 0) {
            return ['ok' => false, 'code' => 1001, 'msg' => '时长/点数必须大于 0', 'data' => null];
        }
        if ($groupId > 0 && !Database::value('SELECT id FROM ' . Database::t('groups') . ' WHERE id = ?', [$groupId])) {
            return ['ok' => false, 'code' => 1001, 'msg' => '激活后分配的用户组不存在', 'data' => null];
        }

        $now      = time();
        $expireAt = $expireDays > 0 ? $now + $expireDays * 86400 : 0;

        Database::begin();
        try {
            $batchId = Database::insert('card_batches', [
                'name'        => $name ?: ('批次 ' . date('Ymd-His')),
                'prefix'      => $prefix ?: null,
                'code_format' => $format,
                'software_id' => $softwareId,
                'type'        => $type,
                'duration'    => $duration,
                'max_devices' => $maxDevices,
                'group_id'    => $groupId,
                'count'       => 0,
                'admin_id'    => $adminId,
                'agent_id'    => $agentId,
                'created_at'  => $now,
            ]);

            // ------------------------------------------------------------------
            // 先在内存中生成去重后的卡密，再批量插入
            // 相比「每张查库 + 单条插入」，10000 张的耗时从数秒降到百毫秒级
            // ------------------------------------------------------------------
            $codes = [];
            $seen  = [];
            $attempts = 0;
            $maxAttempts = $count * 10;

            while (count($codes) < $count && $attempts < $maxAttempts) {
                $attempts++;
                $code = Util::cardCodeByTemplate($format, $prefix);
                if (isset($seen[$code])) {
                    continue; // 本轮内重复
                }
                $seen[$code] = true;
                $codes[] = $code;
            }

            // 与库中已有卡密去重（一次性查询）
            $created = 0;
            if ($codes) {
                $placeholders = implode(',', array_fill(0, count($codes), '?'));
                $exists = Database::all(
                    'SELECT code FROM ' . Database::t('cards') . " WHERE code IN ($placeholders)",
                    $codes
                );
                $existSet = [];
                foreach ($exists as $e) {
                    $existSet[$e['code']] = true;
                }
                if ($existSet) {
                    $codes = array_values(array_filter($codes, fn($c) => !isset($existSet[$c])));
                }
            }

            // 批量插入（每批 500 条，避免 SQL 过长）
            if ($codes) {
                $chunks = array_chunk($codes, 500);
                foreach ($chunks as $chunk) {
                    $rows   = [];
                    $params = [];
                    foreach ($chunk as $code) {
                    $rows[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?)';
                    array_push(
                        $params,
                        $code, $batchId, $softwareId, $type, $duration, $maxDevices, $groupId,
                        self::STATUS_UNUSED, $expireAt, $adminId, $agentId, $remark ?: null, $now
                    );
                    }
                    $sql = 'INSERT INTO ' . Database::t('cards') . '
                            (code, batch_id, software_id, type, duration, max_devices, group_id, status, expire_at, create_admin, agent_id, remark, created_at)
                            VALUES ' . implode(',', $rows);
                    try {
                        Database::exec($sql, $params);
                        $created += count($chunk);
                    } catch (Throwable $e) {
                        // 极端情况下批量插入失败，退化为逐条插入，保证不整体失败
                        foreach ($chunk as $code) {
                            try {
                                Database::insert('cards', [
                                    'code'         => $code,
                                    'batch_id'     => $batchId,
                                    'software_id'  => $softwareId,
                                    'type'         => $type,
                                    'duration'     => $duration,
                                    'max_devices'  => $maxDevices,
                                    'group_id'     => $groupId,
                                    'status'       => self::STATUS_UNUSED,
                                    'expire_at'    => $expireAt,
                                    'create_admin' => $adminId,
                                    'agent_id'     => $agentId,
                                    'remark'       => $remark ?: null,
                                    'created_at'   => $now,
                                ]);
                                $created++;
                            } catch (Throwable $e2) {
                                // 单条冲突，跳过
                            }
                        }
                    }
                }
            }

            $codes = array_slice($codes, 0, $created);

            Database::exec(
                'UPDATE ' . Database::t('card_batches') . ' SET count = ? WHERE id = ?',
                [$created, $batchId]
            );

            Database::commit();

            return [
                'ok'   => true,
                'code' => 0,
                'msg'  => "成功生成 {$created} 张卡密",
                'data' => [
                    'batch_id' => $batchId,
                    'count'    => $created,
                    'codes'    => $codes,
                    'type'     => $type,
                    'duration' => $duration,
                    'group_id' => $groupId,
                    'expire_at'=> $expireAt,
                ],
            ];
        } catch (Throwable $e) {
            Database::rollback();
            return ['ok' => false, 'code' => 9999, 'msg' => '生成失败：' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * 作废卡密
     * ------------------------------------------------------------------
     * 原子性：状态判定与写入合并为一条条件 UPDATE，用 affected_rows 决定成败。
     * 旧实现「先 SELECT 判 status，再 UPDATE」在并发下存在 TOCTOU：
     * 两个请求可能同时读到 status=0 并先后写入，导致重复写 card_logs、
     * 重复计审计，甚至与激活流程交错。
     * 现在只有真正把 0 -> 2 改成功的那一个请求才能往下走，
     * 拿到 rowCount()===1 才会记录日志。
     */
    public static function void(int $cardId, string $reason = ''): bool
    {
        // 需要卡号写日志：先取一次仅作展示用途，不作为判定依据
        $card = Database::one('SELECT code FROM ' . Database::t('cards') . ' WHERE id = ?', [$cardId]);
        if (!$card) {
            return false;
        }

        $n = Database::exec(
            'UPDATE ' . Database::t('cards') . '
             SET status = ?
             WHERE id = ? AND status = ?',
            [self::STATUS_VOID, $cardId, self::STATUS_UNUSED]
        );
        if ($n !== 1) {
            // 没抢到这次状态迁移：已使用 / 已作废 / 已被并发作废
            return false;
        }

        Database::insert('card_logs', [
            'card_id'    => $cardId,
            'code'       => $card['code'],
            'user_id'    => 0,
            'action'     => 'void',
            'detail'     => $reason,
            'ip'         => Util::ip(),
            'created_at' => time(),
        ]);
        return true;
    }

    /** 批量作废 */
    public static function voidBatch(int $batchId): int
    {
        return Database::exec(
            'UPDATE ' . Database::t('cards') . ' SET status = 2 WHERE batch_id = ? AND status = 0',
            [$batchId]
        );
    }

    /**
     * 代理商作废自己名下的卡密（带归属校验，防止越权作废他人卡密）
     * ------------------------------------------------------------------
     * 归属 + 状态判定与写入合并为一条条件 UPDATE，避免「先查后改」的竞态：
     * 判定依据是 WHERE 里的 agent_id / status，而不是此前的 SELECT 结果。
     * 先做一次轻量查询只为区分错误码（不存在 / 非本人 / 已使用 / 已作废）。
     *
     * @return array{ok:bool, code:int, msg:string}
     */
    public static function voidByAgent(int $cardId, int $agentId, string $reason = '代理商作废'): array
    {
        $card = Database::one(
            'SELECT agent_id, status FROM ' . Database::t('cards') . ' WHERE id = ?',
            [$cardId]
        );
        if (!$card) {
            return ['ok' => false, 'code' => 1004, 'msg' => '卡密不存在或不属于当前代理'];
        }
        if ((int) $card['agent_id'] !== $agentId) {
            return ['ok' => false, 'code' => 1004, 'msg' => '卡密不存在或不属于当前代理'];
        }

        // 真正的状态迁移：只有 status=0 且归属正确的行会被改到，一次原子完成
        $n = Database::exec(
            'UPDATE ' . Database::t('cards') . '
             SET status = ?
             WHERE id = ? AND agent_id = ? AND status = ?',
            [self::STATUS_VOID, $cardId, $agentId, self::STATUS_UNUSED]
        );
        if ($n !== 1) {
            // 条件未命中：期间已被使用或被并发作废，按当前状态回一个准确的错误
            $now = (int) Database::value(
                'SELECT status FROM ' . Database::t('cards') . ' WHERE id = ?',
                [$cardId]
            );
            if ($now === self::STATUS_USED) {
                return ['ok' => false, 'code' => 3002, 'msg' => '该卡密已被使用，无法作废'];
            }
            if ($now === self::STATUS_VOID) {
                return ['ok' => false, 'code' => 3003, 'msg' => '该卡密已是作废状态'];
            }
            return ['ok' => false, 'code' => 1004, 'msg' => '卡密不存在或不属于当前代理'];
        }

        // 状态迁移成功，补记日志（与 void() 的日志格式保持一致）
        $code = (string) Database::value(
            'SELECT code FROM ' . Database::t('cards') . ' WHERE id = ?',
            [$cardId]
        );
        Database::insert('card_logs', [
            'card_id'    => $cardId,
            'code'       => $code,
            'user_id'    => 0,
            'action'     => 'void',
            'detail'     => $reason,
            'ip'         => Util::ip(),
            'created_at' => time(),
        ]);

        return ['ok' => true, 'code' => 0, 'msg' => '卡密已作废'];
    }

    /** 卡密类型名称 */
    public static function typeName(int $type): string
    {
        return [
            1 => '时长卡',
            2 => '点数卡',
            3 => '次数卡',
            4 => '永久卡',
        ][$type] ?? '未知';
    }

    /** 卡密状态名称 */
    public static function statusName(int $status): string
    {
        return [
            0 => '未使用',
            1 => '已使用',
            2 => '已作废',
            3 => '已售出',
        ][$status] ?? '未知';
    }
}

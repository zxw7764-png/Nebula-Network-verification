<?php
/**
 * 管理端审计日志
 * ------------------------------------------------------------------
 * 记录管理员的写操作，包含变更前后值，用于追溯「谁改了什么」。
 * 与 Logger（业务日志）分离：Logger 记客户端行为，Audit 记管理端行为。
 */

class Audit
{
    /** 动作中文名映射 */
    public const ACTION_TEXT = [
        'user_create'        => '新增用户',
        'user_update'        => '编辑用户',
        'user_delete'        => '删除用户',
        'user_batch'         => '批量操作用户',
        'user_import'        => '导入用户',
        'user_export'        => '导出用户',
        'user_kick'          => '强制下线',
        'user_reset_pwd'     => '重置密码',

        'card_generate'      => '生成卡密',
        'card_void'          => '作废卡密',
        'card_update'        => '编辑卡密',
        'card_batch'         => '批量操作卡密',
        'card_export'        => '导出卡密',

        'device_unbind'      => '解绑设备',
        'device_ban'         => '拉黑设备',
        'device_unban'       => '解除拉黑设备',
        'device_gc'          => '清理离线设备',

        'session_kick'       => '踢出会话',

        'agent_create'       => '新增代理商',
        'agent_update'       => '编辑代理商',
        'agent_delete'       => '删除代理商',
        'agent_grant'        => '调整代理商额度',
        'agent_code_create'  => '生成代理商激活码',
        'agent_code_update'  => '编辑代理商激活码',
        'agent_code_delete'  => '删除代理商激活码',

        'notice_save'        => '保存公告',
        'notice_delete'      => '删除公告',
        'version_save'       => '保存版本',
        'version_delete'     => '删除版本',
        'group_save'         => '保存用户组',
        'group_delete'       => '删除用户组',

        'setting_save'       => '修改系统设置',
        'profile_update'     => '修改个人资料',
        'password_change'    => '修改密码',
        'admin_login'        => '管理员登录',
        'admin_logout'       => '管理员退出',
        'admin_create'       => '新增管理员',
        'admin_update'       => '编辑管理员',
        'admin_delete'       => '删除管理员',

        'rt_event_handle'    => '处理安全事件',
        'rt_policy_save'     => '保存运行时策略',
        'rt_policy_delete'   => '删除运行时策略',
        'rt_session_block'   => '阻断运行时会话',
        'rt_session_unblock' => '解除运行时会话',
        'rt_device_block'    => '阻断运行时设备',
        'rt_device_unblock'  => '解除运行时设备',
    ];

    /** 需要记录变更明细的字段中文名 */
    public const FIELD_LABEL = [
        'username'      => '用户名',
        'nickname'      => '昵称',
        'email'         => '邮箱',
        'status'        => '状态',
        'vip_expire'    => '会员到期',
        'points'        => '点数',
        'max_devices'   => '设备上限',
        'group_id'      => '用户组',
        'remark'        => '备注',
        'password'      => '密码',
        'duration'      => '时长/点数',
        'expire_at'     => '有效期',
        'code'          => '卡密',
        'type'          => '类型',
        'title'         => '标题',
        'content'       => '内容',
        'sort'          => '排序',
        'start_at'      => '生效时间',
        'end_at'        => '结束时间',
        'version'       => '版本号',
        'channel'       => '渠道',
        'download_url'  => '下载地址',
        'file_hash'     => '文件哈希',
        'force_update'  => '强制更新',
        'name'          => '名称',
        'daily_quota'   => '每日配额',
        'charge_mode'   => '控量模式',
        'quota_total'   => '额度张数',
        'quota_used'    => '已用张数',
        'balance'       => '余额（分）',
        'unit_price'    => '单价（分/张）',
        'contact'       => '联系方式',
        'can_void'      => '允许作废',
        'protection_level'  => '防护等级',
        'watchdog_interval_ms' => '看门狗间隔',
        'medium_action'     => '中风险动作',
        'high_action'       => '高风险动作',
        'critical_action'   => '严重风险动作',
        'enabled'           => '启用',
        'status'            => '状态',
        'policy_name'       => '策略名称',
        'policy_version'    => '策略版本',
    ];

    /**
     * 记录一条审计日志
     *
     * @param array $admin    当前管理员（含 id / username）
     * @param string $action  动作标识（见 ACTION_TEXT）
     * @param string $target  目标描述，如 "用户#12 smoketest"
     * @param string $summary 摘要
     * @param array $old      变更前的数据（关联数组）
     * @param array $new      变更后的数据（关联数组）
     * @param array $extra    额外信息（可选）
     */
    public static function log(
        array $admin,
        string $action,
        string $target = '',
        string $summary = '',
        array $old = [],
        array $new = [],
        array $extra = []
    ): void {
        try {
            // 计算字段级变更
            $changes = self::diff($old, $new);

            Database::insert('audit_logs', [
                'admin_id'    => (int) ($admin['id'] ?? 0),
                'admin_name'  => (string) ($admin['username'] ?? ''),
                'action'      => $action,
                'action_text' => self::ACTION_TEXT[$action] ?? $action,
                'target'      => mb_substr($target, 0, 190),
                'summary'     => mb_substr($summary, 0, 250),
                'changes'     => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'has_diff'    => $changes ? 1 : 0,
                'ip'          => Util::ip(),
                'ua'          => mb_substr(Util::ua(), 0, 250),
                'extra'       => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
                'created_at'  => time(),
            ]);
        } catch (Throwable $e) {
            // 审计失败不影响业务，但记入文件日志
            Logger::log('audit_fail', 0, $e->getMessage(), ['username' => $admin['username'] ?? '']);
        }
    }

    /**
     * 比较两组数据，返回变更明细
     * 返回格式：[['field'=>'status','label'=>'状态','old'=>1,'new'=>0], ...]
     */
    public static function diff(array $old, array $new): array
    {
        if (!$old && !$new) {
            return [];
        }
        $out = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($keys as $k) {
            // 跳过不该记录的字段
            if (in_array($k, ['password', 'token', 'aes_key', 'sign_salt'], true)) {
                $hasOld = isset($old[$k]);
                $hasNew = isset($new[$k]);
                if ($hasOld || $hasNew) {
                    $out[] = [
                        'field' => $k,
                        'label' => self::FIELD_LABEL[$k] ?? $k,
                        'old'   => $hasOld ? '（已设置）' : '',
                        'new'   => $hasNew ? '（已修改）' : '',
                    ];
                }
                continue;
            }

            $ov = $old[$k] ?? null;
            $nv = $new[$k] ?? null;

            // 只在 new 中出现的字段，视为新增
            if (!array_key_exists($k, $old) && array_key_exists($k, $new)) {
                $out[] = [
                    'field' => $k,
                    'label' => self::FIELD_LABEL[$k] ?? $k,
                    'old'   => '',
                    'new'   => self::fmt($nv, $k),
                ];
                continue;
            }
            // 只在 old 中出现的字段，忽略（不视为删除）
            if (!array_key_exists($k, $new)) {
                continue;
            }
            if (self::norm($ov) !== self::norm($nv)) {
                $out[] = [
                    'field' => $k,
                    'label' => self::FIELD_LABEL[$k] ?? $k,
                    'old'   => self::fmt($ov, $k),
                    'new'   => self::fmt($nv, $k),
                ];
            }
        }
        return $out;
    }

    /** 归一化比较值 */
    private static function norm($v): string
    {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        return (string) $v;
    }

    /** 格式化展示值 */
    private static function fmt($v, string $field): string
    {
        if ($v === null || $v === '') return '';
        // 数组 / 对象值（多规格 cards、分组设置等）：直接 JSON 展示。
        // 原先落到末尾的 (string) $v 会触发 "Array to string conversion"（bootstrap 的错误处理器
        // 把它升级成 ErrorException），异常被 Audit::log 的 catch 吞掉 → 整条审计日志写入失败。
        if (is_array($v) || is_object($v)) {
            $s = (string) json_encode($v, JSON_UNESCAPED_UNICODE);
            return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '...' : $s;
        }
        // 时间戳字段转可读时间
        if (in_array($field, ['vip_expire', 'expire_at', 'start_at', 'end_at'], true)) {
            if ((int) $v === -1) return '永久';
            if ((int) $v > 0) return date('Y-m-d H:i:s', (int) $v);
            return '';
        }
        if ($field === 'status') {
            $map = ['0' => '封禁', '1' => '正常', '2' => '冻结'];
            return $map[(string) $v] ?? (string) $v;
        }
        if ($field === 'duration' && is_numeric($v) && $v >= 86400 && $v % 86400 === 0) {
            return ((int) $v / 86400) . ' 天';
        }
        if ($field === 'file_size' && is_numeric($v)) {
            return Util::date(0) === '-' ? (string) $v : number_format((int) $v) . ' 字节';
        }
        $s = (string) $v;
        return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '...' : $s;
    }

    /** 动作中文名 */
    public static function actionText(string $action): string
    {
        return self::ACTION_TEXT[$action] ?? $action;
    }
}

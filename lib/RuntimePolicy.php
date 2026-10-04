<?php
/**
 * 运行时安全策略服务
 * ----------------------------------------------------------------------------
 * 设计文档 §4 §8 §9 §10 §11 §33 §34 §36 §60
 *
 * 职责：
 *   · 管理策略 CRUD（后台）
 *   · 按软件/SDK版本选择最具体且有效的策略（优先级：Software > Global）
 *   · 下发策略到客户端（Login / runtime_policy API）
 *   · 策略版本递增（每次修改 +1）
 *   · 策略缓存（Cache 30–300s，更新时主动失效）
 *
 * 安全规则（§10 §11 §36）：
 *   · 客户端只能读取策略，不能修改策略
 *   · 策略通过 3.1 GCM 信封下发，完整性由 ES256 签名保障
 *   · 普通代理不能任意关闭检测项，权限受 RBAC 控制
 */
class RuntimePolicy
{
    /** 缓存 TTL（秒） */
    private const CACHE_TTL = 60;

    /**
     * 获取指定软件的有效运行时策略。
     * 优先级：软件级策略 > 全局默认策略。
     * §9: 最具体且有效的 Policy。
     *
     * @param int $softwareId  软件ID（0=全局）
     * @return array|null  策略行；无策略时返回 null
     */
    public static function effectiveFor(int $softwareId): ?array
    {
        // 先查软件级
        $policy = self::loadFromCache($softwareId);
        if ($policy === null) {
            // 回落全局
            $policy = self::loadFromCache(0);
        }

        if ($policy === null) {
            // 表为空（全新安装未跑迁移种子），返回内置默认
            return self::builtinDefault();
        }

        return $policy;
    }

    /**
     * 获取客户端下发用的策略快照（只含客户端需要的字段）
     * §11: 策略下发时包含 policy_version / policy_id / policy_expire_at
     */
    public static function forClient(int $softwareId): array
    {
        $p = self::effectiveFor($softwareId);
        return [
            'policy_id'              => (int) ($p['id'] ?? 0),
            'policy_version'         => (int) ($p['policy_version'] ?? 1),
            'enabled'                => (int) ($p['enabled'] ?? 1) === 1,
            'protection_level'       => (int) ($p['protection_level'] ?? 2),
            'detect_code_integrity'  => (int) ($p['detect_code_integrity'] ?? 1) === 1,
            'detect_code_patch'      => (int) ($p['detect_code_patch'] ?? 1) === 1,
            'detect_inline_hook'     => (int) ($p['detect_inline_hook'] ?? 1) === 1,
            'detect_module_injection'=> (int) ($p['detect_module_injection'] ?? 1) === 1,
            'detect_manual_map'      => (int) ($p['detect_manual_map'] ?? 1) === 1,
            'detect_debugger'        => (int) ($p['detect_debugger'] ?? 1) === 1,
            'detect_process_access_risk' => (int) ($p['detect_process_access_risk'] ?? 1) === 1,
            'popup_on_violation'     => (int) ($p['popup_on_violation'] ?? 1) === 1,
            'terminate_on_violation' => (int) ($p['terminate_on_violation'] ?? 1) === 1,
            'report_security_event'  => (int) ($p['report_security_event'] ?? 1) === 1,
            'watchdog_enabled'       => (int) ($p['watchdog_enabled'] ?? 1) === 1,
            'watchdog_interval_ms'   => (int) ($p['watchdog_interval_ms'] ?? 3000),
            'medium_action'          => $p['medium_action'] ?? 'REPORT',
            'high_action'            => $p['high_action'] ?? 'TERMINATE',
            'critical_action'        => $p['critical_action'] ?? 'REVOKE_SESSION',
        ];
    }

    /**
     * 按当前生效策略取某风险等级档的处置动作名（事件入库 action_taken / runtime 状态用）。
     * 与下发给 SDK 的三档动作同源：MEDIUM→medium_action / HIGH→high_action / CRITICAL→critical_action。
     * 策略缺失或档位为空时回落风险引擎静态默认（§19）。
     */
    public static function actionForLevel(string $riskLevel, int $softwareId): string
    {
        $p = self::effectiveFor($softwareId);

        // 防护被关闭（enabled/status 任一关闭或等级=0）→ 事件只记录，不做自动处置
        $enabled = ((int) ($p['enabled'] ?? 1) === 1)
                && ((int) ($p['status'] ?? 1) === 1)
                && (int) ($p['protection_level'] ?? 2) > 0;
        if (!$enabled) {
            return 'RECORD';
        }

        $field = match (strtoupper($riskLevel)) {
            'MEDIUM'   => 'medium_action',
            'HIGH'     => 'high_action',
            'CRITICAL' => 'critical_action',
            default    => null,
        };
        $name = $field !== null ? strtoupper(trim((string) ($p[$field] ?? ''))) : '';
        return $name !== '' ? $name : RuntimeRiskEngine::actionForLevel($riskLevel);
    }

    /**
     * 等级 → 模块位掩码预设（等级即策略：各等级启用哪些检测模块由这里定义）。
     * 位定义与 sdk nebula/protect/runtime_policy.hpp 的 ModBit* 严格一致：
     *   1 反调试 / 2 反VM沙箱 / 4 API钩子 / 8 代码补丁 / 16 代码完整性
     *   32 模块守卫 / 64 内存守卫 / 128 进程守卫 / 256 时序 / 512 环境痕迹
     */
    public static function levelModuleMask(int $level): int
    {
        switch (max(0, min(3, $level))) {
            case 1: return 1 | 2 | 16;                          // 基础：反调试 + 反VM/沙箱 + 代码完整性
            case 2: return 1 | 2 | 16 | 4 | 8 | 32 | 64;        // 标准：+ API钩子 + 代码补丁 + 模块守卫 + 内存守卫
            case 3: return 1 | 2 | 16 | 4 | 8 | 32 | 64 | 128 | 256 | 512; // 严格：+ 进程守卫 + 时序 + 环境痕迹
            default: return 0;                                  // 关闭
        }
    }

    /**
     * 获取 SDK 下发用的策略快照（runtime_protection 格式）
     * ------------------------------------------------------------------
     * §47: SDK 在 init / heartbeat 响应中解析该对象并自动 apply，
     *      键名与字段必须与 sdk/nebula/protect/runtime_policy.hpp 严格一致：
     *        enabled(bool) / level(0-3)
     *        / action(全局兜底) + medium_action / high_action / critical_action
     *          （取值：0记录 1回调 2降级 3弹窗退出 4吊销会话，-1=未下发）
     *        / watchdog_ms(int) / strict(bool) / policy_version(int)
     *
     * 后台的细粒度字段 → SDK 字段的映射：
     *   enabled          ← enabled==1 且 status==1
     *   level            ← protection_level
     *   watchdog_ms      ← watchdog_interval_ms
     *   medium_action    ← medium_action（缺省 1 回调）
     *   high_action      ← high_action（缺省按布尔开关推导，不低于 1）
     *   critical_action  ← critical_action（缺省按布尔开关推导，不低于 3）
     *   action           ← critical_action（全局兜底；SDK 优先用上面三档）
     *   strict           ← critical_action == REVOKE_SESSION
     */
    public static function forSdkClient(int $softwareId): array
    {
        $p = self::effectiveFor($softwareId);

        $policyId  = (int) ($p['id'] ?? 0);
        $policyVer = (int) ($p['policy_version'] ?? 1);
        $watchdog  = (int) ($p['watchdog_interval_ms'] ?? 3000);

        // 防护总开关判定：策略 enabled/status 任一关闭，或防护等级 = 0（管理员明确关闭）
        // → 下发完全静默策略：SDK 停止扫描与看门狗，且不带任何处置动作与 strict 标记。
        //   （此前等级 0 时仍下发 enabled=true + TERMINATE/吊销 动作 + strict，
        //    SDK 靠 enabled && level>0 兜底关闭，但内部动作状态与服务端
        //    事件自动处置仍按开启处理，语义不一致。）
        $enabled = ((int) ($p['enabled'] ?? 1) === 1)
                && ((int) ($p['status'] ?? 1) === 1)
                && (int) ($p['protection_level'] ?? 2) > 0;

        if (!$enabled) {
            return [
                'enabled'         => false,
                'level'           => 0,
                'modules'         => 0,
                'action'          => 0,   // RECORD：只记录
                'medium_action'   => 0,
                'high_action'     => 0,
                'critical_action' => 0,
                'watchdog_ms'     => $watchdog,
                'strict'          => false,
                'policy_id'       => $policyId,
                'policy_version'  => $policyVer,
            ];
        }

        $report    = (int) ($p['report_security_event'] ?? 1) === 1;
        $terminate = (int) ($p['terminate_on_violation'] ?? 1) === 1;
        $popup     = (int) ($p['popup_on_violation'] ?? 1) === 1;

        // 处置动作编码（与 SDK ActionKind / 编译期 NEBULA_PROTECT_ACTION 一致）
        $actionMap = [
            'RECORD'         => 0,
            'REPORT'         => 1,
            'DEGRADE'        => 2,
            'TERMINATE'      => 3,
            'REVOKE_SESSION' => 4,
        ];

        // 旧配置（无动作名字段）兜底：用布尔开关推导基础动作
        $boolAction = 0;
        if ($report)                         $boolAction = 1;
        if ($report && $terminate)           $boolAction = 2;
        if ($report && $terminate && $popup) $boolAction = 3;

        // 动作名 → 编码（未配置的档回落 fallback）
        $pick = static function (string $name, int $fallback) use ($actionMap): int {
            $key = strtoupper(trim($name));
            return $actionMap[$key] ?? $fallback;
        };

        // 按等级分别下发，SDK 逐档执行（中危/高危/严重各用各的）
        $mediumAction   = $pick((string) ($p['medium_action']   ?? ''), 1);
        $highAction     = $pick((string) ($p['high_action']     ?? ''), max($boolAction, 1));
        $criticalAction = $pick((string) ($p['critical_action'] ?? ''), max($boolAction, 3));

        // 严格策略：最高处置为「吊销会话」时，疑似环境（VM/Hook）也按行为拦截
        $strict = strtoupper(trim((string) ($p['critical_action'] ?? ''))) === 'REVOKE_SESSION';

        return [
            'enabled'         => $enabled,
            'level'           => (int) ($p['protection_level'] ?? 2),
            'modules'         => self::levelModuleMask((int) ($p['protection_level'] ?? 2)),
            'action'          => $criticalAction,   // 全局兜底（SDK 优先用下面三档）
            'medium_action'   => $mediumAction,
            'high_action'     => $highAction,
            'critical_action' => $criticalAction,
            'watchdog_ms'     => (int) ($p['watchdog_interval_ms'] ?? 3000),
            'strict'          => $strict,
            'policy_id'       => (int) ($p['id'] ?? 0),
            'policy_version'  => (int) ($p['policy_version'] ?? 1),
        ];
    }

    /**
     * 后台：策略列表
     */
    public static function listAll(int $page = 1, int $size = 20): array
    {
$base = 'SELECT * FROM ' . Database::t('runtime_policies');
return Database::paginate($base, [], $page, $size, 'software_id ASC, id ASC');
    }

    /**
     * 后台：获取单条
     */
    public static function get(int $id): ?array
    {
        return Database::one(
            'SELECT * FROM ' . Database::t('runtime_policies') . ' WHERE id = ?',
            [$id]
        );
    }

    /**
     * 后台：创建/更新策略，返回策略ID
     * §34: 每次修改 policy_version 递增
     */
    public static function save(array $data): int
    {
        $now = time();
        $id = (int) ($data['id'] ?? 0);

        $fields = [
            'software_id'               => (int) ($data['software_id'] ?? 0),
            'policy_name'               => mb_substr((string) ($data['policy_name'] ?? 'default'), 0, 64),
            'sdk_version_min'           => $data['sdk_version_min'] ?? null,
            'sdk_version_max'           => $data['sdk_version_max'] ?? null,
            'enabled'                   => (int) ($data['enabled'] ?? 1),
            'protection_level'          => (int) ($data['protection_level'] ?? 2),
            'detect_code_integrity'     => (int) ($data['detect_code_integrity'] ?? 1),
            'detect_code_patch'         => (int) ($data['detect_code_patch'] ?? 1),
            'detect_inline_hook'        => (int) ($data['detect_inline_hook'] ?? 1),
            'detect_module_injection'   => (int) ($data['detect_module_injection'] ?? 1),
            'detect_manual_map'         => (int) ($data['detect_manual_map'] ?? 1),
            'detect_debugger'           => (int) ($data['detect_debugger'] ?? 1),
            'detect_process_access_risk'=> (int) ($data['detect_process_access_risk'] ?? 1),
            'popup_on_violation'        => (int) ($data['popup_on_violation'] ?? 1),
            'terminate_on_violation'    => (int) ($data['terminate_on_violation'] ?? 1),
            'report_security_event'     => (int) ($data['report_security_event'] ?? 1),
            'watchdog_enabled'          => (int) ($data['watchdog_enabled'] ?? 1),
            'watchdog_interval_ms'      => (int) ($data['watchdog_interval_ms'] ?? 3000),
            'medium_action'             => $data['medium_action'] ?? 'REPORT',
            'high_action'               => $data['high_action'] ?? 'TERMINATE',
            'critical_action'           => $data['critical_action'] ?? 'REVOKE_SESSION',
            'status'                    => (int) ($data['status'] ?? 1),
            'updated_at'                => $now,
        ];

        if ($id > 0) {
            // 更新：version +1
            $old = self::get($id);
            if ($old) {
                $fields['policy_version'] = (int) $old['policy_version'] + 1;
                Database::update('runtime_policies', $fields, 'id = :id', ['id' => $id]);
                self::invalidateCache((int) $old['software_id']);
                return $id;
            }
        }

        // 新建
        $fields['policy_version'] = 1;
        $fields['created_at'] = $now;
        $newId = Database::insert('runtime_policies', $fields);
        self::invalidateCache((int) $fields['software_id']);
        return $newId;
    }

    /**
     * 后台：删除策略
     */
    public static function delete(int $id): bool
    {
        $old = self::get($id);
        if (!$old) {
            return false;
        }
        $n = Database::exec('DELETE FROM ' . Database::t('runtime_policies') . ' WHERE id = ?', [$id]);
        if ($n > 0) {
            self::invalidateCache((int) $old['software_id']);
        }
        return $n > 0;
    }

    // ------------------------------------------------------------------
    // 内置默认策略（迁移未执行时的兜底）
    // §60: 推荐生产默认
    // ------------------------------------------------------------------
    private static function builtinDefault(): array
    {
        return [
            'id'                       => 0,
            'software_id'              => 0,
            'policy_name'              => 'builtin',
            'enabled'                  => 1,
            'protection_level'         => 2,
            'detect_code_integrity'    => 1,
            'detect_code_patch'        => 1,
            'detect_inline_hook'       => 1,
            'detect_module_injection'  => 1,
            'detect_manual_map'        => 1,
            'detect_debugger'          => 1,
            'detect_process_access_risk'=> 1,
            'popup_on_violation'       => 1,
            'terminate_on_violation'   => 1,
            'report_security_event'    => 1,
            'watchdog_enabled'         => 1,
            'watchdog_interval_ms'     => 3000,
            'medium_action'            => 'REPORT',
            'high_action'              => 'TERMINATE',
            'critical_action'          => 'REVOKE_SESSION',
            'policy_version'           => 1,
            'status'                   => 1,
        ];
    }

    // ------------------------------------------------------------------
    // 缓存
    // ------------------------------------------------------------------
    private static function loadFromCache(int $softwareId): ?array
    {
        $key = "rt_policy:{$softwareId}";
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $row = Database::one(
            'SELECT * FROM ' . Database::t('runtime_policies') . '
             WHERE software_id = ? AND enabled = 1 AND status = 1
             ORDER BY id DESC LIMIT 1',
            [$softwareId]
        );

        Cache::set($key, $row, self::CACHE_TTL);
        return $row;
    }

    private static function invalidateCache(int $softwareId): void
    {
        Cache::del("rt_policy:{$softwareId}", "rt_policy:0");
    }
}

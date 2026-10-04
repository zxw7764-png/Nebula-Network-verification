<?php
/**
 * Runtime Security 授权中间件
 * ----------------------------------------------------------------------------
 * 设计文档 §68 §69 §70 §71 §129
 *
 * §69: 不能只在 Heartbeat 检查 runtime_status
 * §70: 统一 authenticateSession() / verifyRuntimeStatus()
 * §71: 中间件顺序 = RateLimit → Decrypt → HMAC → Session → seq → Device → Runtime → License → Handler
 * §129: 最终授权模型 = Account AND License AND Device AND Session AND SessionNotRevoked
 *        AND RuntimePolicySatisfied → ALLOW
 *
 * 用法（在需要授权的 API handler 顶部调用）：
 *   $auth = RuntimeGuard::requireSession($requestData);
 *   // $auth['session'] / $auth['user'] 可用
 *   // BLOCKED / 过期 / 封号等在此统一拦截，handler 只关心业务逻辑
 */
class RuntimeGuard
{
    /**
     * 统一会话授权 + Runtime 状态检查
     *
     * 通过条件（§129）：
     *   · token 有效且未过期
     *   · machine_id 匹配
     *   · 账号状态正常
     *   · Runtime 状态非 BLOCKED
     *
     * 任一条件失败直接 Response::send() 终止，不返回。
     *
     * @param array $requestData  请求数据
     * @param bool  $requireMachine 是否强制 machine_id
     * @return array{session:array, user:array, userId:int}
     */
    public static function requireSession(array $requestData, bool $requireMachine = true): array
    {
        $token     = Util::str($requestData, 'token', '');
        $machineId = Util::str($requestData, 'machine_id', '');

        // 1. 会话验证
        $v = Session::validate($token, $machineId, $requireMachine);
        if (!$v['ok']) {
            Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
        }
        $session = $v['session'];
        $userId  = (int) $session['user_id'];

        // 2. 账号验证
        $user = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);
        if (!$user) {
            Response::error(1002, '账号不存在', ['need_relogin' => true]);
        }
        if ((int) $user['status'] !== 1) {
            // 限时封禁到期自动解封
            if ((int) $user['status'] === 0 && Auth::autoUnbanIfExpired($user)) {
                // 自动解封成功，继续
            } else {
                Session::kickUser($userId);
                $msg = (int) $user['status'] === 0 ? Auth::bannedText($user) : '账号已被冻结';
                Response::send(2002, $msg, ['kick' => true, 'need_relogin' => true]);
            }
        }

        // 3. §68: Runtime 状态检查 — BLOCKED 直接拒绝所有授权 API
        $rtStatus = (string) ($session['rt_status'] ?? 'UNKNOWN');
        if ($rtStatus === 'BLOCKED') {
            Response::send(7001, 'Runtime security verification failed.', [
                'runtime' => [
                    'status'      => 'BLOCKED',
                    'risk_level'  => $session['rt_risk_level'] ?? 'CRITICAL',
                    'risk_score'  => (int) ($session['rt_risk_score'] ?? 100),
                    'action'      => 'REVOKE_SESSION',
                ],
                'need_relogin' => true,
            ]);
        }

        return [
            'session' => $session,
            'user'    => $user,
            'userId'  => $userId,
        ];
    }

    /**
     * §89 §90: 检查是否要求 RuntimeGuard
     *
     * 用于 Login 流程：如果 License/Software 配置了 require_runtime_guard，
     * 且客户端不支持 RuntimeGuard → 拒绝登录或限制高价值功能
     *
     * @param array $software     软件配置
     * @param array $requestData  请求数据
     * @return bool  true=通过，false=不支持且强制要求
     */
    public static function checkRuntimeGuardRequired(array $software, array $requestData): bool
    {
        // 检查软件策略是否要求 RuntimeGuard
        $require = Policy::swPolicyVal($software, 'require_runtime_guard');
        if (!$require) {
            // 检查全局配置
            $require = Config::get('security.require_runtime_guard', false);
        }

        if (!$require) {
            return true; // 不强制要求，直接通过
        }

        // 检查客户端是否声明支持 RuntimeGuard
        $capabilities = $requestData['capabilities'] ?? [];
        if (is_array($capabilities) && !empty($capabilities['runtime_guard'])) {
            return true;
        }

        return false; // 强制要求但客户端不支持
    }

    /**
     * §127 §128: 处理心跳中的 Runtime 状态上报
     *
     * §128: Runtime Status 是 untrusted telemetry
     * §126: 如果客户端长时间上报 rt_enabled=false，可进入 RISK
     * 但不能因为一次 heartbeat runtime_enabled=false 就立即封禁
     *
     * @param array $session      当前会话
     * @param array $requestData  心跳请求数据
     * @return array  更新后的 runtime 状态摘要
     */
    public static function processHeartbeatRuntime(array $session, array $requestData): array
    {
        $token         = (string) ($session['token'] ?? '');
        $currentStatus = (string) ($session['rt_status'] ?? 'UNKNOWN');
        $serverScore   = (int) ($session['rt_risk_score'] ?? 0);
        $serverFlags   = (int) ($session['rt_flags'] ?? 0);
        $serverLevel   = (string) ($session['rt_risk_level'] ?? 'LOW');

        // ------------------------------------------------------------------
        // §45 §127 §128: 合并客户端上报的运行时摘要 security.{level,score,flags}
        // 客户端报告 = untrusted telemetry，只允许"提升"风险，绝不允许降级或清除
        // ------------------------------------------------------------------
        $sec = $requestData['security'] ?? null;
        if (is_array($sec)) {
            $cScore = max(0, (int) ($sec['score'] ?? 0));
            $cFlags = (int) ($sec['flags'] ?? 0);

            $newScore = max($serverScore, $cScore);
            $newFlags = $serverFlags | $cFlags;

            // 等级取「分数映射等级」与「服务端已有等级」中较高者
            $scoreLevel = RuntimeRiskEngine::levelFromScore($newScore);
            $newLevel = RuntimeRiskEngine::levelRank($scoreLevel) > RuntimeRiskEngine::levelRank($serverLevel)
                ? $scoreLevel : $serverLevel;

            // §128: 不信任客户端声称的 CLEAN，只能让状态升级到 RISK，不能回退 BLOCKED
            $newStatus = $currentStatus;
            if ($currentStatus !== 'BLOCKED') {
                $newStatus = $newScore >= 40 ? 'RISK'
                           : ($currentStatus === 'UNKNOWN' ? 'CLEAN' : $currentStatus);
            }

            if ($newScore !== $serverScore || $newFlags !== $serverFlags ||
                $newLevel !== $serverLevel || $newStatus !== $currentStatus) {
                Database::update('sessions', [
                    'rt_status'     => $newStatus,
                    'rt_risk_score' => $newScore,
                    'rt_risk_level' => $newLevel,
                    'rt_flags'      => $newFlags,
                ], 'token = :tok', ['tok' => $token]);

                $serverScore   = $newScore;
                $serverFlags   = $newFlags;
                $serverLevel   = $newLevel;
                $currentStatus = $newStatus;
            }
        }

        // ------------------------------------------------------------------
        // §126: 客户端明确上报 rt_enabled=0（未上报 security 摘要的旧客户端）
        // 连续多次心跳报告 disabled → 升级为 RISK，但不因一次就封禁
        // 注意：仅当显式上报 rt_enabled=0 时才计数，避免把"未上报"误判为"已关闭"
        // ------------------------------------------------------------------
        $rtEnabled = null;   // null = 本次心跳未上报启用状态
        if (array_key_exists('rt_enabled', $requestData)) {
            $rtEnabled = (int) $requestData['rt_enabled'];
        }

        if ($rtEnabled === 0 && $currentStatus !== 'BLOCKED') {
            $disabledCount = RateLimit::count("rt_disabled:{$token}", 600); // 10分钟窗口
            if ($disabledCount >= 5 && $currentStatus === 'CLEAN') {
                Database::update('sessions', [
                    'rt_status'     => 'RISK',
                    'rt_risk_level' => 'MEDIUM',
                    'rt_risk_score' => 20,
                ], 'token = :tok', ['tok' => $token]);

                $currentStatus = 'RISK';
                $serverLevel   = 'MEDIUM';
                $serverScore   = max($serverScore, 20);
            }
        }

        // ------------------------------------------------------------------
        // §33: 检查策略版本是否需要更新（客户端 version=0 表示尚未拉取策略）
        // ------------------------------------------------------------------
        $clientPolicyVersion = (int) ($requestData['rt_policy_version'] ?? 0);
        $swId = (int) ($session['software_id'] ?? 0);
        $rtPolicy = RuntimePolicy::effectiveFor($swId);
        $serverPolicyVersion = (int) ($rtPolicy['policy_version'] ?? 1);
        $policyUpdateAvailable = ($serverPolicyVersion > $clientPolicyVersion);

        // 记录客户端已确认的策略版本（用于后台审计；不参与下发判断）
        if ($clientPolicyVersion > (int) ($session['rt_policy_version'] ?? 0)) {
            Database::update('sessions',
                ['rt_policy_version' => $clientPolicyVersion],
                'token = :tok', ['tok' => $token]
            );
        }

        return [
            'status'        => $currentStatus,
            'risk_level'    => $serverLevel,
            'risk_score'    => $serverScore,
            'flags'         => $serverFlags,
            'policy_update' => $policyUpdateAvailable,
            'policy_version'=> $policyUpdateAvailable ? $serverPolicyVersion : 0,
        ];
    }
}

<?php
/**
 * 运行时风险引擎
 * ----------------------------------------------------------------------------
 * 设计文档 §19 §20 §21 §22 §62 §63 §64 §76 §77
 *
 * 职责：
 *   · 根据事件类型计算风险分数和等级
 *   · 判断是否为 sticky 事件（不可自动衰减）
 *   · 输出推荐动作 (REPORT / TERMINATE / REVOKE_SESSION)
 *
 * 设计原则（§19 §77）：
 *   · 不把风险规则写死在 Controller，统一由 RiskEngine 评估
 *   · 高可信事件（CODE_PATCH 等）直接 CRITICAL，不走累计分数
 *   · 低可信事件（PROCESS_ACCESS_RISK 等）采用评分和组合判断
 */
class RuntimeRiskEngine
{
    /**
     * 事件类型 → 基础分数 + sticky 标记
     * §19: 风险评分表
     */
    private const EVENT_SCORES = [
        // Code Integrity（高可信）
        'CODE_INTEGRITY_FAIL'     => ['score' => 100, 'sticky' => true,  'level' => 'CRITICAL'],
        'CODE_PATCH'              => ['score' => 90,  'sticky' => true,  'level' => 'CRITICAL'],
        'GUARDED_CODE_FAIL'       => ['score' => 100, 'sticky' => true,  'level' => 'CRITICAL'],

        // Module（高可信 → 中高）
        'MANUAL_MAP'              => ['score' => 70,  'sticky' => true,  'level' => 'HIGH'],
        'MODULE_INJECTION'        => ['score' => 60,  'sticky' => false, 'level' => 'HIGH'],
        'MODULE_TAMPER'           => ['score' => 50,  'sticky' => false, 'level' => 'HIGH'],
        'ABNORMAL_MODULE'         => ['score' => 30,  'sticky' => false, 'level' => 'MEDIUM'],

        // Memory
        'INLINE_HOOK'             => ['score' => 50,  'sticky' => false, 'level' => 'HIGH'],
        'ABNORMAL_EXECUTABLE_MEMORY' => ['score' => 30, 'sticky' => false, 'level' => 'MEDIUM'],
        'MEMORY_REGION_TAMPER'    => ['score' => 40,  'sticky' => false, 'level' => 'MEDIUM'],
        'CRITICAL_DATA_TAMPER'    => ['score' => 80,  'sticky' => true,  'level' => 'CRITICAL'],

        // Debug
        'DEBUGGER_DETECTED'       => ['score' => 30,  'sticky' => false, 'level' => 'MEDIUM'],
        'HARDWARE_BREAKPOINT'     => ['score' => 30,  'sticky' => false, 'level' => 'MEDIUM'],
        'DEBUG_TOOL_RISK'         => ['score' => 30,  'sticky' => false, 'level' => 'MEDIUM'],

        // Process Access（低可信，§61 §64）
        'PROCESS_ACCESS_RISK'     => ['score' => 20,  'sticky' => false, 'level' => 'LOW'],

        // Runtime 状态
        'WATCHDOG_FAILURE'        => ['score' => 10,  'sticky' => false, 'level' => 'LOW'],
        'PROTECTION_DISABLED'     => ['score' => 10,  'sticky' => false, 'level' => 'LOW'],
        'PROTECTION_DEGRADED'     => ['score' => 10,  'sticky' => false, 'level' => 'LOW'],
        'RUNTIME_POLICY_INVALID'  => ['score' => 20,  'sticky' => false, 'level' => 'MEDIUM'],
    ];

    /**
     * 风险等级阈值（§20）
     *   0–39   LOW
     *   40–69  MEDIUM
     *   70–89  HIGH
     *   90+    CRITICAL
     */
    private const THRESHOLDS = [
        'LOW'      => 0,
        'MEDIUM'   => 40,
        'HIGH'     => 70,
        'CRITICAL' => 90,
    ];

    /**
     * 评估单个事件的风险
     *
     * @param string $eventType     事件类型
     * @return array{score:int, level:string, action:string, sticky:bool}
     */
    public static function evaluate(string $eventType): array
    {
        $cfg = self::EVENT_SCORES[$eventType] ?? ['score' => 10, 'sticky' => false, 'level' => 'LOW'];

        $score  = $cfg['score'];
        $level  = $cfg['level'];
        $sticky = $cfg['sticky'];
        $action = self::actionForLevel($level);

        return [
            'score'  => $score,
            'level'  => $level,
            'action' => $action,
            'sticky' => $sticky,
        ];
    }

    /**
     * 累加风险到 Session/Device 当前分数
     * §21: 风险衰减 — 非sticky事件在无新事件后逐渐衰减
     *
     * @param int    $currentScore 当前累计分数
     * @param string $eventType    新事件类型
     * @return array{score:int, level:string}
     */
    public static function accumulate(int $currentScore, string $eventType): array
    {
        $eval = self::evaluate($eventType);

        // 高可信事件直接跳到其等级分数（不与旧分数累加，取较大值）
        if ($eval['sticky'] || $eval['score'] >= 90) {
            $newScore = max($currentScore, $eval['score']);
        } else {
            // 低可信事件累加但有衰减：旧分数先衰减10%再加入新分数
            $decayed = (int) ($currentScore * 0.9);
            $newScore = min(100, $decayed + $eval['score']);
        }

        $level = self::levelFromScore($newScore);
        // 如果新事件级别高于累计级别，取新事件级别
        if (self::levelRank($eval['level']) > self::levelRank($level)) {
            $level = $eval['level'];
        }

        return ['score' => $newScore, 'level' => $level];
    }

    /**
     * 风险衰减（§21）
     * 定时调用以降低非 sticky 事件的风险分数
     *
     * @param int    $currentScore 当前分数
     * @param int    $minutesSince  距上次事件的分钟数
     * @return int 衰减后的分数
     */
    public static function decay(int $currentScore, int $minutesSince): int
    {
        if ($minutesSince <= 0) {
            return $currentScore;
        }
        // 30 分钟后开始衰减，每分钟减 1
        if ($minutesSince <= 30) {
            return $currentScore;
        }
        $decayAmount = $minutesSince - 30;
        return max(0, $currentScore - $decayAmount);
    }

    /**
     * 按等级返回推荐动作（§22 §60）
     */
    public static function actionForLevel(string $level): string
    {
        return match (strtoupper($level)) {
            'CRITICAL' => 'REVOKE_SESSION',
            'HIGH'     => 'TERMINATE',
            'MEDIUM'   => 'REPORT',
            'LOW'      => 'REPORT',
            default    => 'REPORT',
        };
    }

    /**
     * 按分数返回等级
     */
    public static function levelFromScore(int $score): string
    {
        if ($score >= self::THRESHOLDS['CRITICAL']) return 'CRITICAL';
        if ($score >= self::THRESHOLDS['HIGH'])     return 'HIGH';
        if ($score >= self::THRESHOLDS['MEDIUM'])   return 'MEDIUM';
        return 'LOW';
    }

    /**
     * 等级排序值（供跨模块比较风险等级高低）
     */
    public static function levelRank(string $level): int
    {
        return match (strtoupper($level)) {
            'LOW'      => 1,
            'MEDIUM'   => 2,
            'HIGH'     => 3,
            'CRITICAL' => 4,
            default    => 0,
        };
    }

    /**
     * 判断事件类型是否已知（用于校验客户端上报）
     */
    public static function isKnownType(string $eventType): bool
    {
        return isset(self::EVENT_SCORES[$eventType]);
    }

    /**
     * 判断事件是否为 sticky（不可衰减）
     */
    public static function isSticky(string $eventType): bool
    {
        $cfg = self::EVENT_SCORES[$eventType] ?? null;
        return $cfg !== null && $cfg['sticky'];
    }
}

<?php
/**
 * 统一响应输出
 *
 * 业务码约定（HTTP 始终 200，业务状态看 code）：
 *   0    成功
 *   1001 参数错误
 *   1002 未登录 / 令牌无效
 *   1003 令牌过期
 *   1004 权限不足
 *   2001 用户名或密码错误
 *   2002 账号已被封禁
 *   2003 账号被锁定
 *   2004 账号已过期
 *   3001 卡密不存在
 *   3002 卡密已被使用
 *   3003 卡密已作废
 *   3004 卡密已过期
 *   4001 设备数超限
 *   4002 设备未绑定
 *   4003 机器码缺失
 *   5001 请求过于频繁
 *   5002 签名校验失败
 *   5003 请求已过期
 *   5004 重复请求
 *   5005 今日调用配额已用尽（用户组 daily_quota）
     *   6001 版本过低需强制更新
     *   6002 服务器维护中
     *   7001 Runtime安全验证失败（会话被阻断）
     *   7002 Runtime策略无效
     *   7003 Runtime事件被拒绝
     *   7004 Runtime事件上报频率超限
     *   7005 Runtime会话已被撤销
     *   9999 服务器内部错误
 */
class Response
{
    private static bool $encrypt = true;
    private static bool $sent = false;
    private static int $lastCode = 0;

    public static function setEncrypt(bool $v): void
    {
        self::$encrypt = $v;
    }

    /**
     * 最后一次发出的业务码。
     * 供 register_shutdown_function 里的统计逻辑判断本次调用是否成功：
     *   >= 0  → 已发出响应（0 为成功，其余为业务失败）
     *   -1    → 全程没有发出任何响应（致命错误等）
     */
    public static function lastCode(): int
    {
        return self::$sent ? self::$lastCode : -1;
    }

    /**
     * 输出业务结果
     */
    public static function send(int $code, string $msg = '', $data = null, array $extra = []): void
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;
        self::$lastCode = $code;

        $payload = [
            'code' => $code,
            'msg'  => $msg,
            'time' => time(),
        ];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($extra) {
            $payload += $extra;
        }

        header('Content-Type: application/json; charset=utf-8');
        // 不再回显 X-Powered-By 指纹（原本会暴露本系统名称与版本特征，
        // 方便攻击者按产品名去找现成 exp；PHP 自身的 X-Powered-By 由 bootstrap 清除）
        header_remove('X-Powered-By');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        if (self::$encrypt) {
            $body = Crypto::buildResponse($payload);
            $body['code'] = $code; // 明文附带状态码，便于客户端快速判断
            echo json_encode($body, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    /** 成功 */
    public static function ok($data = null, string $msg = 'ok'): void
    {
        self::send(0, $msg, $data);
    }

    /** 失败 */
    public static function error(int $code, string $msg = '', $data = null): void
    {
        self::send($code, $msg ?: self::msgOf($code), $data);
    }

    /** 业务码对应默认文案 */
    public static function msgOf(int $code): string
    {
        static $map = [
            0    => '成功',
            1001 => '参数错误',
            1002 => '未登录或登录状态无效',
            1003 => '登录状态已过期',
            1004 => '权限不足',
            2001 => '用户名或密码错误',
            2002 => '账号已被封禁',
            2003 => '账号已被锁定，请稍后再试',
            2004 => '账号已过期',
            3001 => '卡密不存在',
            3002 => '卡密已被使用',
            3003 => '卡密已作废',
            3004 => '卡密已过期',
            4001 => '设备数量已达上限',
            4002 => '当前设备未绑定',
            4003 => '缺少机器码',
            5001 => '请求过于频繁',
            5002 => '签名校验失败',
            5003 => '请求已过期',
            5004 => '重复请求',
            5005 => '今日调用配额已用尽',
            6001 => '客户端版本过低，请更新后使用',
            6002 => '服务器维护中',
            7001 => 'Runtime security verification failed',
            7002 => 'Runtime策略无效',
            7003 => 'Runtime事件被拒绝',
            7004 => 'Runtime事件上报频率超限',
            7005 => 'Runtime会话已被撤销',
            9999 => '服务器内部错误',
        ];
        return $map[$code] ?? '未知错误';
    }
}

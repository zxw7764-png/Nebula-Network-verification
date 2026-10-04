<?php
/**
 * action: runtime_policy
 * ------------------------------------------------------------------
 * 客户端拉取当前运行时安全策略（§72）
 *
 * 请求：需 3.1 会话信封
 * 响应：Runtime Policy 快照（forClient 格式）
 *
 * §72: 不要单独设计明文 JSON 安全接口，继续使用 Nebula encrypted response。
 */

$sw = Software::current();
$policy = RuntimePolicy::forClient((int) ($sw['id'] ?? 0));

Response::ok([
    'runtime_policy' => $policy,
], 'ok');

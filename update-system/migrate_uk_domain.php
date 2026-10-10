<?php
/**
 * 迁移：licenses.domain 唯一约束（试用「一域一次」并发防重复）
 * ==================================================================
 * 背景：domain 原为普通索引，试用接口「先查后插」在并发下可为同一
 * 域名创建多个试用授权。本迁移改为 NULL 允许多行 + 唯一索引，从
 * 数据库层面保证约束（NULL=未绑定，可多行）。
 *
 * 用法（二选一）：
 *   CLI:  php migrate_uk_domain.php
 *   Web:  浏览器访问一次本文件（输出 JSON，执行后建议删除本文件）
 *
 * 幂等：可重复执行，已应用过则直接提示跳过。
 */

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// 仅允许 CLI 或本机访问
$isCli = PHP_SAPI === 'cli';
if (!$isCli && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '仅允许 CLI 或本机执行']);
    exit;
}

// DB 由 lib/bootstrap.php 初始化（直接读 config 会导致键不匹配）

$t = DB::t('licenses');
$log = [];

// 1) domain 允许 NULL
$col = DB::one("SHOW COLUMNS FROM {$t} WHERE Field = 'domain'");
if ($col !== null && $col['Null'] === 'NO') {
    DB::exec("ALTER TABLE {$t} MODIFY `domain` VARCHAR(128) DEFAULT NULL
        COMMENT '绑定域名（归一化 host，NULL=未绑定；唯一约束防试用并发重复领取）'");
    $log[] = 'domain 列改为可 NULL';
} else {
    $log[] = 'domain 列已可 NULL，跳过';
}

// 2) 历史空串转 NULL（唯一索引下空串只允许出现一次）
try {
    $n = DB::exec("UPDATE {$t} SET `domain` = NULL WHERE `domain` = ''");
    $log[] = "空串域名转 NULL：{$n} 行";
} catch (Throwable $e) {
    $log[] = '空串域名转 NULL 失败：' . $e->getMessage();
}

// 3) 加唯一索引（重名会失败——先检测并报告，由管理员人工裁决）
$dup = DB::all("SELECT `domain`, COUNT(*) c FROM {$t} WHERE `domain` IS NOT NULL GROUP BY `domain` HAVING c > 1");
if ($dup) {
    echo json_encode(['ok' => false, 'msg' => '存在重复域名，需人工合并后再执行迁移', 'duplicates' => $dup], JSON_UNESCAPED_UNICODE);
    exit;
}
$idx = DB::one("SHOW INDEX FROM {$t} WHERE Key_name = 'uk_domain'");
if ($idx === null) {
    DB::exec("ALTER TABLE {$t} ADD UNIQUE KEY `uk_domain` (`domain`)");
    $log[] = '已添加唯一索引 uk_domain';
} else {
    $log[] = '唯一索引 uk_domain 已存在，跳过';
}

echo json_encode(['ok' => true, 'msg' => '迁移完成', 'log' => $log], JSON_UNESCAPED_UNICODE);

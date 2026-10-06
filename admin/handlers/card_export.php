<?php
/**
 * admin action: card_export
 * 导出卡密为 txt 或 csv
 * 参数: batch_id 或 ids(逗号分隔) 或 status 筛选，format=txt|csv
 */

$format  = Util::str($input, 'format', 'txt');
$batchId = Util::int($input, 'batch_id', 0);
$ids     = Util::str($input, 'ids', '');
$status  = Util::get($input, 'status', '0');
$agentId = Util::get($input, 'agent_id', '');
$limit   = min(50000, max(1, Util::int($input, 'limit', 10000)));

$where  = ['1=1'];
$params = [];

// 2026-10-06 审计：卡密导出按租户范围过滤（此前可跨租户导出任意卡密 = 商业凭证泄露）
Tenant::applyNamed($where, $params);

if ($batchId > 0) {
    $where[] = 'batch_id = :bid';
    $params['bid'] = $batchId;
}
if ($ids !== '') {
    $idArr = array_filter(array_map('intval', explode(',', $ids)));
    if ($idArr) {
        // 统一使用命名占位符：PDO 在真实预处理下不允许同一条语句混用 ? 与 :name
        $names = [];
        foreach (array_values($idArr) as $i => $v) {
            $key = 'id' . $i;
            $names[] = ':' . $key;
            $params[$key] = $v;
        }
        $where[] = 'id IN (' . implode(',', $names) . ')';
    }
}
if ($status !== '' && $status !== null) {
    $where[] = 'status = :st';
    $params['st'] = (int) $status;
}
// 来源筛选：'' 全部 / '0' 官方直发 / '>0' 指定代理商
if ($agentId !== '' && $agentId !== null) {
    $where[] = 'agent_id = :aid';
    $params['aid'] = (int) $agentId;
}

$sql = 'SELECT code, type, duration, max_devices, status, used_at, expire_at, created_at
        FROM ' . Database::t('cards') . '
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY id ASC LIMIT ' . $limit;

$rows = Database::all($sql, $params);

if (!$rows) {
    Response::error(1001, '没有符合条件的卡密');
}

$filename = 'cards_' . date('YmdHis') . '.' . ($format === 'csv' ? 'csv' : 'txt');

Audit::log($admin, 'card_export', '卡密导出', '导出 ' . count($rows) . ' 条卡密');

// 输出文件
header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/plain') . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// BOM 让 Excel 正确识别 UTF-8
echo "\xEF\xBB\xBF";

if ($format === 'csv') {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['卡密', '类型', '时长/点数', '最大设备', '状态', '使用时间', '卡密有效期', '生成时间']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['code'],
            Card::typeName((int) $r['type']),
            $r['duration'],
            $r['max_devices'],
            Card::statusName((int) $r['status']),
            Util::date((int) $r['used_at']),
            (int) $r['expire_at'] > 0 ? Util::date((int) $r['expire_at']) : '永久',
            Util::date((int) $r['created_at']),
        ]);
    }
    fclose($out);
} else {
    foreach ($rows as $r) {
        echo $r['code'] . "\r\n";
    }
}
exit;

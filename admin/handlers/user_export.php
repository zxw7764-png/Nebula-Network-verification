<?php
/**
 * admin action: user_export
 * 导出用户为 CSV / TXT
 */

$format = Util::str($input, 'format', 'csv');
$limit  = min(50000, max(1, Util::int($input, 'limit', 10000)));
$now    = time();

// 筛选条件
$where  = ['1=1'];
$params = [];
// 2026-10-06 审计：导出外带修复 —— 租户管理员仅可导出自己软件范围下的用户（此前可整库导出）
Tenant::applyPositional($where, $params);
$keyword = Util::str($input, 'keyword', '');
if ($keyword !== '') {
    $where[] = '(username LIKE ? OR nickname LIKE ? OR email LIKE ?)';
    $kw = '%' . $keyword . '%';
    $params[] = $kw; $params[] = $kw; $params[] = $kw;
}
$status = Util::str($input, 'status', '');
if ($status !== '' && in_array($status, ['0', '1', '2'], true)) {
    $where[] = 'status = ?';
    $params[] = (int) $status;
}
$groupId = Util::int($input, 'group_id', 0);
if ($groupId > 0) {
    $where[] = 'group_id = ?';
    $params[] = $groupId;
}

$sql = 'SELECT u.*, g.name AS group_name FROM ' . Database::t('users') . ' u'
     . ' LEFT JOIN ' . Database::t('groups') . ' g ON g.id = u.group_id'
     . ' WHERE ' . implode(' AND ', $where)
     . ' ORDER BY u.id DESC LIMIT ' . $limit;

$rows = Database::all($sql, $params);

// 软件名映射
$swName = [];
foreach (Software::options() as $s) {
    $swName[(int) $s['id']] = (string) $s['name'];
}

Audit::log($admin, 'user_export', '用户导出', '导出 ' . count($rows) . " 条用户记录（{$format}）",
    [], [], ['format' => $format, 'count' => count($rows)]);

$filename = 'users_' . date('Ymd_His') . '.' . ($format === 'txt' ? 'txt' : 'csv');
header('Content-Type: ' . ($format === 'txt' ? 'text/plain' : 'text/csv') . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// BOM 让 Excel 正确识别 UTF-8
echo "\xEF\xBB\xBF";

if ($format === 'txt') {
    // 纯用户名列表
    foreach ($rows as $r) {
        echo $r['username'] . "\n";
    }
    exit;
}

// CSV：字段与导入格式对齐
$head = ['用户名', '昵称', '邮箱', '状态', '会员到期', '剩余点数', '设备上限', '用户组', '归属软件', '最后登录IP', '注册时间', '备注'];
echo implode(',', $head) . "\n";

$statusMap = [0 => '封禁', 1 => '正常', 2 => '冻结'];
foreach ($rows as $r) {
    $vip = (int) $r['vip_expire'];
    $vipText = $vip === -1 ? '永久' : ($vip > 0 ? date('Y-m-d H:i:s', $vip) : '未激活');
    $line = [
        $r['username'],
        $r['nickname'] ?? '',
        $r['email'] ?? '',
        $statusMap[(int) $r['status']] ?? '正常',
        $vipText,
        $r['points'],
        $r['max_devices'],
        $r['group_name'] ?? '',
        (int) ($r['software_id'] ?? 0) > 0 ? ($swName[(int) $r['software_id']] ?? ('软件#' . $r['software_id'])) : '通用',
        $r['last_login_ip'] ?? '',
        $r['created_at'] > 0 ? date('Y-m-d H:i:s', (int) $r['created_at']) : '',
        $r['remark'] ?? '',
    ];
    // CSV 转义
    $line = array_map(function ($v) {
        $v = (string) $v;
        if (strpbrk($v, ",\"\n\r") !== false) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }, $line);
    echo implode(',', $line) . "\n";
}
exit;

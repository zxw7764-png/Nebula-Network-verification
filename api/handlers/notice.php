<?php
/**
 * action: notice
 * 获取公告列表（无需登录）
 * 参数: id (可选，获取单条)
 * 软件隔离：按请求 app_key 归属软件过滤（software_id=0 为全软件通用，2026-10-06 审计修复）
 */

$id   = (int) Util::get($requestData, 'id', 0);
$now  = time();
$swId = (int) Software::currentId();

if ($id > 0) {
    $row = Database::one(
        'SELECT id, title, content, type, created_at FROM ' . Database::t('notices') . '
         WHERE id = ? AND status = 1 AND (software_id = 0 OR software_id = ?)',
        [$id, $swId]
    );
    if (!$row) {
        Response::error(1001, '公告不存在');
    }
    $row['created_at_text'] = Util::date((int) $row['created_at']);
    Response::ok($row);
}

$list = Database::all(
    'SELECT id, title, content, type, created_at FROM ' . Database::t('notices') . '
     WHERE status = 1 AND type IN (2, 3, 4)
       AND (software_id = 0 OR software_id = ?)
       AND (start_at = 0 OR start_at <= ?)
       AND (end_at = 0 OR end_at >= ?)
     ORDER BY type ASC, sort DESC, id DESC LIMIT 20',
    [$swId, $now, $now]
);

foreach ($list as &$r) {
    $r['created_at_text'] = Util::date((int) $r['created_at']);
    $r['type_text'] = [2 => '弹窗公告', 3 => '立即公告', 4 => '列表公告'][(int) $r['type']] ?? '公告';
}
unset($r);

Response::ok(['list' => $list, 'total' => count($list)]);

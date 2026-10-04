<?php
/**
 * admin action: card_batch_list
 * 卡密批次列表
 */

$page = max(1, Util::int($input, 'page', 1));
$size = min(200, max(1, Util::int($input, 'size', 20)));

$baseSql = 'SELECT * FROM ' . Database::t('card_batches');
[$total, $rows] = Database::paginate($baseSql, [], $page, $size, '`id` DESC');

// 用户组名映射（组数量少，一次性载入）
$groupNames = [];
foreach (Database::all('SELECT id, name FROM ' . Database::t('groups') . ' ORDER BY id ASC') as $g) {
    $groupNames[(int) $g['id']] = $g['name'];
}

$list = array_map(function ($b) use ($groupNames) {
    $gid = isset($b['group_id']) ? (int) $b['group_id'] : 0;
    return [
        'id'          => (int) $b['id'],
        'name'        => $b['name'],
        'prefix'      => $b['prefix'],
        'code_format' => isset($b['code_format']) ? $b['code_format'] : null,
        'type'        => (int) $b['type'],
        // type=0 外部导入批次（发卡商品导入的外部卡密），不挂本系统卡规格
        'is_ext'      => (int) $b['type'] === 0 ? 1 : 0,
        'type_text'   => (int) $b['type'] === 0 ? '外部卡密' : Card::typeName((int) $b['type']),
        'duration'    => (int) $b['duration'],
        'duration_text' => (int) $b['type'] === 0 ? '-'
            : ((int) $b['type'] === 1 ? Util::duration((int) $b['duration']) : (string) $b['duration']),
        'max_devices' => (int) $b['max_devices'],
        'group_id'    => $gid,
        'group_name'  => $gid > 0 ? ($groupNames[$gid] ?? ('用户组#' . $gid)) : '',
        'count'       => (int) $b['count'],
        'used_count'  => (int) $b['used_count'],
        'unused_count'=> (int) $b['count'] - (int) $b['used_count'],
        'admin_id'    => (int) $b['admin_id'],
        'created_at'  => Util::date((int) $b['created_at']),
    ];
}, $rows);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'pages' => (int) ceil($total / $size),
    'list'  => $list,
]);

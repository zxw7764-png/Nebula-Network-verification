<?php
/**
 * admin action: file_delete
 * 删除受管文件（确认管理密码），路径白名单校验 + 运行必需文件保护。
 * 支持单删（file）与批量删（files 数组，最多 200 个）。
 *
 * 权限：settings.business（仅超管）。
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_BUSINESS);

/* 批量删除 */
if (isset($input['files']) && is_array($input['files'])) {
    $list = array_values(array_unique(array_filter(array_map(
        fn ($v) => trim((string) $v), $input['files']
    ), fn ($v) => $v !== '')));
    if (!$list) {
        Response::error(1001, '缺少 files 参数');
    }
    if (count($list) > 200) {
        // 1002 是前端登出码，业务错误一律用 1001
        Response::error(1001, '一次最多删除 200 个文件');
    }

    Deleter::confirmPassword($admin, $input, '批量删除 ' . count($list) . ' 个文件');

    $ok = $fail = [];
    foreach ($list as $rel) {
        $p = FileGuard::safePath($rel);
        if ($p === '' || !FileGuard::remove($rel)) {
            $fail[] = $rel;
            continue;
        }
        $ok[] = $rel;
        Audit::log($admin, 'file_delete', "文件 {$rel}", '批量删除文件',
            ['file' => $rel, 'size' => (int) @filesize($p)]);
    }
    Response::ok(
        ['deleted' => $ok, 'failed' => $fail],
        '已删除 ' . count($ok) . ' 个' . ($fail ? '，' . count($fail) . ' 个失败（受保护或不可操作）' : '')
    );
}

/* 单个删除 */
$rel = Util::str($input, 'file', '');
if ($rel === '') {
    Response::error(1001, '缺少 file 参数');
}
$p = FileGuard::safePath($rel);
if ($p === '') {
    Response::error(1004, '文件不存在或类型不允许操作');
}

Deleter::confirmPassword($admin, $input, '删除文件 ' . $rel);

if (!FileGuard::remove($rel)) {
    Response::error(5000, '删除失败（运行必需文件受保护或目录不可写）');
}
Audit::log($admin, 'file_delete', "文件 {$rel}", '删除文件',
    ['file' => $rel, 'size' => (int) @filesize($p)]);
Response::ok(['file' => $rel], '文件已删除');

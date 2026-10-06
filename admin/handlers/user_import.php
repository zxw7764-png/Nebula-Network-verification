<?php
/**
 * admin action: user_import
 * 从 CSV 文本批量导入用户
 *
 * 支持格式（每行，逗号分隔）：
 *   用户名,密码,昵称,邮箱,会员天数,点数,设备上限
 * 至少需要「用户名,密码」两列。首行若为表头会自动跳过。
 */

$content = (string) ($input['content'] ?? '');
if (trim($content) === '') {
    Response::error(1001, '导入内容为空');
}
if (strlen($content) > 2 * 1024 * 1024) {
    Response::error(1001, '导入内容过大（上限 2MB）');
}

$dupMode     = Util::str($input, 'dup', 'skip');   // skip | update
$defaultDays = Util::int($input, 'default_days', 0);
$now         = time();

$lines = preg_split('/\r\n|\r|\n/', $content);
$ok = $skip = $update = $fail = 0;
$errors = [];
$createdIds = [];
$updatedIds = [];

// 默认用户组
$defaultGroup = (int) (Database::value('SELECT id FROM ' . Database::t('groups') . ' ORDER BY id ASC LIMIT 1') ?: 1);

foreach ($lines as $idx => $line) {
    $lineNo = $idx + 1;
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    // 跳过表头
    if ($idx === 0 && (strpos($line, '用户名') !== false || stripos($line, 'username') !== false)) {
        continue;
    }

    $cols = str_getcsv($line);
    if (count($cols) < 2) {
        $fail++;
        if (count($errors) < 50) $errors[] = "第 {$lineNo} 行：列数不足（至少需要用户名和密码）";
        continue;
    }

    $username = trim($cols[0]);
    $password = trim($cols[1]);
    $nickname = isset($cols[2]) ? trim($cols[2]) : '';
    $email    = isset($cols[3]) ? trim($cols[3]) : '';
    $days     = isset($cols[4]) && $cols[4] !== '' ? (int) $cols[4] : $defaultDays;
    $points   = isset($cols[5]) && $cols[5] !== '' ? (int) $cols[5] : 0;
    $maxDev   = isset($cols[6]) && $cols[6] !== '' ? (int) $cols[6] : 1;

    // 校验
    if (!preg_match('/^[a-zA-Z0-9_@.\-]{3,32}$/', $username)) {
        $fail++;
        if (count($errors) < 50) $errors[] = "第 {$lineNo} 行：用户名「{$username}」不合法（3-32位字母数字下划线）";
        continue;
    }
    if (($pwIssue = Util::passwordIssue($password)) !== null) {
        $fail++;
        if (count($errors) < 50) $errors[] = "第 {$lineNo} 行：{$pwIssue}";
        continue;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fail++;
        if (count($errors) < 50) $errors[] = "第 {$lineNo} 行：邮箱格式不正确";
        continue;
    }
    $maxDev = max(1, min(99, $maxDev));
    $points = max(0, $points);

    $exists = Database::one('SELECT id FROM ' . Database::t('users') . ' WHERE username = ?', [$username]);

    if ($exists) {
        if ($dupMode !== 'update') {
            $skip++;
            continue;
        }
        // 更新
        $data = [
            'nickname'    => $nickname !== '' ? $nickname : null,
            'email'       => $email !== '' ? $email : null,
            'points'      => $points,
            'max_devices' => $maxDev,
            'updated_at'  => $now,
        ];
        if ($days > 0) {
            $data['vip_expire'] = $now + $days * 86400;
        }
        Database::update('users', $data, 'id = :id', ['id' => (int) $exists['id']]);
        $updatedIds[] = (int) $exists['id'];
        $update++;
    } else {
        try {
            $uid = Database::insert('users', [
                'username'     => $username,
                'password'     => Util::hashPassword($password),
                'nickname'     => $nickname !== '' ? $nickname : null,
                'email'        => $email !== '' ? $email : null,
                'group_id'     => $defaultGroup,
                'status'       => 1,
                'points'       => $points,
                'max_devices'  => $maxDev,
                'vip_expire'   => $days > 0 ? $now + $days * 86400 : 0,
                'register_ip'  => Util::ip(),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $createdIds[] = (int) $uid;
            $ok++;
        } catch (Throwable $e) {
            $fail++;
            if (count($errors) < 50) $errors[] = "第 {$lineNo} 行：写入失败（" . $e->getMessage() . "）";
        }
    }
}

Audit::log($admin, 'user_import', '导入用户',
    "导入完成：成功 {$ok}，更新 {$update}，跳过 {$skip}，失败 {$fail}",
    [], [], [
        'ok' => $ok, 'update' => $update, 'skip' => $skip, 'fail' => $fail,
        'created_ids' => array_slice($createdIds, 0, 200),
        'updated_ids' => array_slice($updatedIds, 0, 200),
    ]);

Response::ok([
    'ok'     => $ok,
    'update' => $update,
    'skip'   => $skip,
    'fail'   => $fail,
    'errors' => $errors,
], "导入完成：成功 {$ok}，更新 {$update}，跳过 {$skip}，失败 {$fail}");

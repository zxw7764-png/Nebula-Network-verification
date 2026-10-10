<?php
/**
 * 门户公共页头（单页滚动版）
 * ==================================================================
 * 页面在引入本文件前可设置以下变量：
 *   $PAGE       页面标识（门户为 home）
 *   $PAGE_TITLE 浏览器标题
 *   $PAGE_DESC  页面描述（meta description）
 *   $PAGE_FORM  背景粒子初始编队 0~7（区块滚动切换见 assets/js/story.js）
 */
$PAGE       = $PAGE       ?? 'home';
$PAGE_TITLE = $PAGE_TITLE ?? 'Nebula 网络验证';
$PAGE_DESC  = $PAGE_DESC  ?? 'Nebula 网络验证系统：加密信封通信、多层签名校验、壳级代码保护、自动更新分发，为你的软件提供工业级授权管理。';
$PAGE_FORM  = isset($PAGE_FORM) ? (int) $PAGE_FORM : 0;
$ASSET_VER  = '20261007a';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($PAGE_TITLE, ENT_QUOTES) ?></title>
<meta name="description" content="<?= htmlspecialchars($PAGE_DESC, ENT_QUOTES) ?>">
<link rel="stylesheet" href="assets/css/style.css?v=<?= $ASSET_VER ?>">
<link rel="icon" href="favicon.ico">
<link rel="apple-touch-icon" href="assets/img/logo.png">
</head>
<body class="page-<?= htmlspecialchars($PAGE, ENT_QUOTES) ?>">

<!-- ============ 滚动进度条 + 背景粒子画布 ============ -->
<div class="scroll-progress" id="scrollProgress"></div>
<canvas id="story-canvas" data-form="<?= $PAGE_FORM ?>" aria-hidden="true"></canvas>

<?php require __DIR__ . '/icons.php'; ?>
<?php require __DIR__ . '/nav.php'; ?>

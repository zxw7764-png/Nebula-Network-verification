<?php
/**
 * 门户顶部导航（单页滚动版）
 * 各导航项指向首页内对应区块，滚动时由 main.js 高亮当前板块。
 */
$navItems = [
    '#top'       => '首页',
    '#product'   => '产品构成',
    '#features'  => '核心功能',
    '#security'  => '安全防护',
    '#admin'     => '管理后台',
    '#frontends' => '门户 · 发卡',
    'docs.html'  => '开发文档',
    '#faq'       => '常见问题',
];
?>
<header class="nav" id="nav">
  <div class="container nav-inner">
    <a class="brand" href="index.php">
      <span class="brand-logo brand-logo-img"><img src="assets/img/logo.png" alt="Nebula Logo"></span>
      <span class="brand-name">Nebula<span class="brand-sub">网络验证</span></span>
    </a>
    <nav class="nav-links" id="navLinks">
      <?php foreach ($navItems as $href => $label): ?>
      <a href="<?= $href ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="nav-cta">
      <a class="btn btn-ghost" href="docs.html">接入指南</a>
      <a class="btn btn-primary" href="https://gitee.com/xinia/online-verification" target="_blank" rel="noopener">获取项目</a>
    </div>
    <button class="nav-burger" id="navBurger" aria-label="打开菜单">
      <svg class="icon" id="burgerIcon"><use href="#i-menu"/></svg>
    </button>
  </div>
</header>

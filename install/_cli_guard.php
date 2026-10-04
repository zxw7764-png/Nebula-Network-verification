<?php
/**
 * 安装 / 迁移脚本的「仅限命令行」守卫
 * ------------------------------------------------------------------
 * 为什么需要它：
 *   install/ 目录下的脚本（安装向导、迁移、清日志）都会直接连数据库，
 *   甚至执行 TRUNCATE / ALTER / DELETE。如果这些脚本能被浏览器请求到，
 *   攻击者无需登录即可：
 *     · 下载 schema.sql 拿到完整表结构
 *     · 让 migrate_*.php 在 Web 上真实执行（反复重跑，扰乱数据）
 *     · 让 clear_logs.php 清空审计日志（销毁罪证）
 *
 *   传统做法是依赖 Web 服务器配置（nginx 的 deny 规则 / Apache 的 .htaccess），
 *   但这属于「配置对不对」的问题：换服务器、漏拷 .htaccess、开错 AllowOverride
 *   都会让防护静默失效（本项目就真实发生过：install/ 目录一直没有 .htaccess）。
 *
 * 本守卫的原理（与 Web 服务器无关）：
 *   用 get_included_files() 取出本次请求【最先被执行的脚本】——无论中间
 *   经过多少层 require，列表第 0 项永远是入口脚本。再拿它和本文件比对：
 *     · 入口就是本守卫的调用方 → 说明是浏览器直接请求 install/xxx.php → 拦截
 *     · 入口是别的文件（如后台 index.php）→ 说明是被合法引入 → 放行
 *
 *   为什么不用 $_SERVER['SCRIPT_FILENAME']：
 *     php-fpm / FastCGI 下该值可能为空或被伪造，而 get_included_files()
 *     是 PHP 运行时自己的记录，攻击者改不了。
 *
 *   为什么必须显式放行 CLI：
 *     CLI 下入口脚本要么就是 install/xxx.php（与 Web 直接访问无法区分），
 *     要么是 cron.php 等；这里只需保证 `php install/migrate_xxx.php` 能跑，
 *     所以 PHP_SAPI === 'cli' 直接放行。
 *
 * 用法（放在脚本最顶部，紧跟在文档注释之后、任何其它 require 之前）：
 *   require_once __DIR__ . '/_cli_guard.php';
 */

if (PHP_SAPI !== 'cli') {
    $nbGuardThis    = __FILE__;
    $nbGuardEntry   = get_included_files()[0] ?? '';
    $nbGuardCaller  = '';

    // 调用方 = 调用栈里第一个不是本守卫文件的脚本
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $nbFrame) {
        if (!empty($nbFrame['file']) && $nbFrame['file'] !== $nbGuardThis) {
            $nbGuardCaller = $nbFrame['file'];
            break;
        }
    }

    // 判定：入口脚本就是调用方 → 浏览器直接打到了这个 install 脚本 → 拦截
    if ($nbGuardCaller !== '' && @realpath($nbGuardEntry) === @realpath($nbGuardCaller)) {
        require_once __DIR__ . '/../lib/error_page.php';
        nb_error_page(404);
    }
    unset($nbGuardThis, $nbGuardEntry, $nbGuardCaller, $nbFrame);
}

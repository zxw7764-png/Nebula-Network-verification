<?php
/**
 * 版本更新系统 - 版本信息
 * ==================================================================
 * 独立系统，不依附于任何项目。
 * 系统所有地方统一从这里获取版本信息，禁止在其他 PHP 文件中硬编码版本号。
 *
 * 版本号规范：Semantic Versioning (SemVer)
 *   MAJOR.MINOR.PATCH  例如 1.0.0
 *
 * Build 编号：YYYYMMDDNN 格式，用于区分同一版本号下的构建。
 */

define('APP_VERSION', '1.2.3');
define('APP_BUILD',   2026101015);
define('APP_CHANNEL', 'stable');

/** 产品标识，用于版本检查 API */
define('APP_PRODUCT', 'nebula-verification');

/** Schema 版本号（数据库结构版本），用于 Migration 顺序控制 */
define('APP_SCHEMA_VERSION', 1);

/** 系统名称 */
define('APP_NAME', '在线版本更新系统');

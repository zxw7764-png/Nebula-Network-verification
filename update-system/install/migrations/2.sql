-- ============================================================
-- Migration: Schema Version 2
-- ============================================================
-- 从 Schema 1 升级到 Schema 2 的数据库变更脚本
--
-- 示例场景：v1.1.0 新增「安装日志表」和「API 调用日志表」
-- 这些表也可以在运行时自动创建（api/version.php 中有容错逻辑），
-- 但通过 Migration 正式定义是更好的做法。
-- ============================================================

-- 安装日志表（记录每次版本检查的设备信息）
CREATE TABLE IF NOT EXISTS `vu_install_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `machine_id`  VARCHAR(128)   NOT NULL DEFAULT '' COMMENT '设备机器码',
  `product`     VARCHAR(64)    NOT NULL DEFAULT '' COMMENT '产品标识',
  `version`     VARCHAR(32)    NOT NULL DEFAULT '' COMMENT '版本号',
  `build`       INT UNSIGNED   NOT NULL DEFAULT 0  COMMENT 'Build 编号',
  `channel`     VARCHAR(20)    NOT NULL DEFAULT 'stable',
  `ip`          VARCHAR(64)    NOT NULL DEFAULT '',
  `created_at`  DATETIME       NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_machine` (`machine_id`),
  INDEX `idx_product` (`product`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安装日志';

-- API 调用日志表
CREATE TABLE IF NOT EXISTS `vu_api_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`      VARCHAR(64)    NOT NULL DEFAULT '' COMMENT '操作类型',
  `product`     VARCHAR(64)    NOT NULL DEFAULT '' COMMENT '产品标识',
  `version`     VARCHAR(32)    NOT NULL DEFAULT '' COMMENT '版本号',
  `ip`          VARCHAR(64)    NOT NULL DEFAULT '',
  `created_at`  DATETIME       NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API 调用日志';

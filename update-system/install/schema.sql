-- ============================================================
-- 在线版本更新系统 - MySQL 数据库结构
-- 字符集: utf8mb4  引擎: InnoDB
-- 表前缀: vu_  （与 config.php 中 db.prefix 保持一致）
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. 管理员表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_admins` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(32)  NOT NULL COMMENT '登录用户名',
  `password`        VARCHAR(255) NOT NULL COMMENT 'bcrypt 密码哈希',
  `nickname`        VARCHAR(64)  DEFAULT NULL COMMENT '昵称',
  `role`            TINYINT      NOT NULL DEFAULT 1 COMMENT '1=超级管理员',
  `status`          TINYINT      NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `login_fail_cnt`  TINYINT      NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `lock_until`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '锁定到期时间戳',
  `last_login_ip`   VARCHAR(64)  DEFAULT NULL,
  `last_login_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员';

-- ------------------------------------------------------------
-- 2. 管理员会话表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_admin_sessions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `token`      CHAR(64)     NOT NULL COMMENT '会话令牌',
  `csrf`       CHAR(32)     NOT NULL COMMENT 'CSRF 令牌',
  `ip`         VARCHAR(64)  DEFAULT NULL,
  `ua_hash`    CHAR(64)     NOT NULL DEFAULT '' COMMENT '客户端 UA 哈希（会话绑定）',
  `expires`    INT UNSIGNED NOT NULL,
  `created`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  INDEX `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员会话';

-- ------------------------------------------------------------
-- 2.B IP 登录防爆破表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_login_throttle` (
  `ip`         VARCHAR(64)  NOT NULL COMMENT '客户端 IP',
  `fail_cnt`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '窗口内失败次数',
  `lock_until` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '锁定到期时间戳',
  `last_try`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近失败时间戳',
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP 登录防爆破';

-- ------------------------------------------------------------
-- 3. 系统设置表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_settings` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey`       VARCHAR(64) NOT NULL,
  `svalue`     TEXT        DEFAULT NULL,
  `remark`     VARCHAR(255) DEFAULT NULL,
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';

-- ------------------------------------------------------------
-- 4. Migration 执行记录表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_migrations` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`     VARCHAR(32) NOT NULL COMMENT 'Migration 版本号',
  `checksum`    CHAR(64)   NOT NULL COMMENT 'SQL 文件 SHA-256',
  `executed_at` DATETIME   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库 Migration 执行记录';

-- ------------------------------------------------------------
-- 5. 系统更新历史表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_update_history` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_version`  VARCHAR(32)  NOT NULL DEFAULT '',
  `to_version`    VARCHAR(32)  NOT NULL DEFAULT '',
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending',
  `operator_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `operator_name` VARCHAR(64)  NOT NULL DEFAULT '',
  `started_at`    DATETIME     NOT NULL,
  `finished_at`   DATETIME     DEFAULT NULL,
  `error_message` TEXT,
  `backup_path`   VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统更新历史';

-- ------------------------------------------------------------
-- 6. 版本发布表（版本服务器端使用）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_releases` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`       VARCHAR(32)  NOT NULL COMMENT '版本号',
  `build`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Build 编号',
  `min_version`   VARCHAR(32)  NOT NULL DEFAULT '1.0.0' COMMENT '最低支持版本',
  `schema_version` INT         NOT NULL DEFAULT 1 COMMENT '数据库 Schema 版本',
  `channel`       VARCHAR(20)  NOT NULL DEFAULT 'stable' COMMENT '发布通道',
  `download_url`  VARCHAR(500) NOT NULL COMMENT '更新包下载地址',
  `sha256`        CHAR(64)     NOT NULL DEFAULT '' COMMENT '更新包 SHA-256',
  `signature`     TEXT         COMMENT 'Ed25519 签名（Base64）',
  `release_notes` TEXT         COMMENT '更新日志（JSON 数组）',
  `requirements`  TEXT         COMMENT '环境要求（JSON）',
  `files`         TEXT         COMMENT '文件列表（JSON 数组）',
  `published_at`  DATETIME     NOT NULL,
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1已发布 0下架',
  `created_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version` (`version`),
  INDEX `idx_channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='版本发布记录';

-- ------------------------------------------------------------
-- 初始数据
-- ------------------------------------------------------------
INSERT INTO `vu_settings` (`skey`, `svalue`, `remark`, `updated_at`) VALUES
('site_name', '在线版本更新系统', '站点名称', UNIX_TIMESTAMP()),
('update_server', '', '版本服务器地址（留空则不检查自身更新）', UNIX_TIMESTAMP()),
('license_gate', '0', '更新门禁：1=下发更新包需有效授权，0=关闭（兼容旧客户端）', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

-- ------------------------------------------------------------
-- 7. 授权码表（源码分发授权 / 安装激活 / 更新门禁）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vu_licenses` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_key`   CHAR(32)     NOT NULL COMMENT '授权码（32位十六进制）',
  `domain`        VARCHAR(128) DEFAULT NULL COMMENT '绑定域名（归一化 host，NULL=未绑定；唯一约束防试用并发重复领取）',
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `plan`          VARCHAR(20)  NOT NULL DEFAULT 'standard' COMMENT '授权档位',
  `note`          VARCHAR(255) DEFAULT NULL COMMENT '备注（客户名/订单号）',
  `issued_at`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '签发时间',
  `activated_at`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '激活（绑定域名）时间',
  `last_check_at` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近验签时间',
  `last_ip`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '最近验签 IP',
  `expires_at`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '到期时间，0=永久',
  `created_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`license_key`),
  UNIQUE KEY `uk_domain` (`domain`),
  INDEX `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权码';

SET FOREIGN_KEY_CHECKS = 1;

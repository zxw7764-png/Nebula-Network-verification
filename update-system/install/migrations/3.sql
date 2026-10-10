-- ============================================================
-- Migration: Schema Version 3
-- ============================================================
-- v1.2.0 新增授权码体系：vu_licenses 表 + 更新门禁开关
-- ============================================================

CREATE TABLE IF NOT EXISTS `vu_licenses` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_key`   CHAR(32)     NOT NULL COMMENT '授权码（32位十六进制）',
  `domain`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT '绑定域名（归一化 host，空=未绑定）',
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
  INDEX `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权码';

INSERT INTO `vu_settings` (`skey`, `svalue`, `remark`, `updated_at`) VALUES
('license_gate', '0', '更新门禁：1=下发更新包需有效授权，0=关闭（兼容旧客户端）', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

-- ============================================================
-- Nebula Auth System - MySQL 数据库结构
-- 字符集: utf8mb4  引擎: InnoDB
-- 表前缀: nb_  （与 config.php 中 db.prefix 保持一致）
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 26. 软件表（多软件网络验证：每个软件独立通信密钥与版本策略）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_softwares` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(64)  NOT NULL COMMENT '软件名称',
  `app_key`        VARCHAR(32)  NOT NULL COMMENT '客户端标识（请求外层携带 app_key）',
  `min_version`    VARCHAR(32)  NOT NULL DEFAULT '1.0.0' COMMENT '最低可用版本',
  `latest_version` VARCHAR(32)  NOT NULL DEFAULT '1.0.0' COMMENT '最新版本（无发布记录时使用）',
  `force_update`   TINYINT      NOT NULL DEFAULT 0,
  `update_url`     VARCHAR(255) DEFAULT NULL,
  `update_note`    TEXT         DEFAULT NULL,
  `login_methods`  VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '登录方式覆盖（空=跟随全局设置，password/username_code/code）',
  `feature_key`    VARCHAR(128) NOT NULL DEFAULT '' COMMENT '功能密钥（仅 login 成功后下发，空=未启用；接入方用于解密随程序分发的核心数据包）',
  `policy_json`    TEXT         DEFAULT NULL COMMENT '策略覆盖 JSON（空=全部跟随全局；键见 lib/Policy.php）',
  `status`         TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `owner_agent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属代理商ID（多租户：0=平台自营；租户管理员仅可见归属软件）',
  `remark`         VARCHAR(255) DEFAULT NULL,
  `created_at`     INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_key` (`app_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='软件（多应用）';

INSERT INTO `nb_softwares` (`id`, `name`, `app_key`, `status`, `created_at`)
VALUES (1, '默认软件', 'SWDEFAULT', 1, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 多软件归属字段：老站升级请运行 install/migrate_software.php
-- （新装安装向导会执行本 schema.sql，各表定义中已直接包含 software_id）

-- ------------------------------------------------------------
-- 1. 用户表（业务账号，非管理员）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(64)  NOT NULL COMMENT '用户名',
  `password`        VARCHAR(255) NOT NULL COMMENT 'bcrypt 密码哈希',
  `email`           VARCHAR(128) DEFAULT NULL COMMENT '邮箱',
  `nickname`        VARCHAR(64)  DEFAULT NULL COMMENT '昵称',
  `status`          TINYINT      NOT NULL DEFAULT 1 COMMENT '1正常 0封禁 2冻结',
  `ban_expire`      BIGINT       NOT NULL DEFAULT 0 COMMENT '封禁到期时间戳：status=0 时 >0 为限时封禁到期点，0=永久封禁',
  `group_id`        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '用户组ID',
  `software_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属软件ID，0=未绑定（激活时写入）',
  `card_code`       VARCHAR(64)  DEFAULT NULL COMMENT '激活卡密快照（激活时写入，删卡后仍可反查/找回账号）',
  `vip_expire`      BIGINT       NOT NULL DEFAULT 0 COMMENT '会员到期时间戳：-1=永久，0=未激活',
  `points`          INT          NOT NULL DEFAULT 0 COMMENT '剩余点数',
  `points_day`      INT          NOT NULL DEFAULT 0 COMMENT '最近「每日首次登录」扣点日序号（daily 扣点模式防重复）',
  `points_at`       INT          NOT NULL DEFAULT 0 COMMENT '最近一次在线扣点/登录计时起点（online 扣点模式）',
  `max_devices`     TINYINT      NOT NULL DEFAULT 1 COMMENT '最大设备数',
  `register_ip`     VARCHAR(64)  DEFAULT NULL,
  `last_login_ip`   VARCHAR(64)  DEFAULT NULL,
  `last_login_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `login_fail_cnt`  TINYINT      NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `lock_until`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '锁定到期时间戳',
  `remark`          VARCHAR(255) DEFAULT NULL COMMENT '管理员备注',
  `created_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`),
  KEY `idx_vip_expire` (`vip_expire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ------------------------------------------------------------
-- 2. 用户组表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_groups` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(64)  NOT NULL COMMENT '组名',
  `max_devices` TINYINT      NOT NULL DEFAULT 1 COMMENT '该组允许的最大设备数',
  `daily_quota` INT          NOT NULL DEFAULT 0 COMMENT '每日调用配额，0=不限',
  `remark`      VARCHAR(255) DEFAULT NULL,
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户组';

-- ------------------------------------------------------------
-- 3. 激活码 / 卡密表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_cards` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(64)  NOT NULL COMMENT '卡密（唯一）',
  `batch_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生成批次',
  `software_id`   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '归属软件ID',
  `type`          TINYINT      NOT NULL DEFAULT 1 COMMENT '1时长卡 2点数卡 3次数卡 4永久卡',
  `duration`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '时长卡:秒数; 点数卡:点数; 次数卡:次数',
  `max_devices`   TINYINT      NOT NULL DEFAULT 1 COMMENT '该卡激活后的设备上限',
  `group_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '激活后分配的用户组ID，0=不换组',
  `status`        TINYINT      NOT NULL DEFAULT 0 COMMENT '0未使用 1已使用 2已作废 3已售出未激活(发卡网售出后锁定)',
  `used_by`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '使用者 user_id',
  `used_at`       INT UNSIGNED NOT NULL DEFAULT 0,
  `used_ip`       VARCHAR(64)  DEFAULT NULL,
  `expire_at`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '卡本身的有效期，0=永久',
  `create_admin`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生成者管理员ID',
  `agent_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属代理商ID，0=官方直发',
  `remark`        VARCHAR(255) DEFAULT NULL,
  `created_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_used_by` (`used_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='激活码';

-- ------------------------------------------------------------
-- 4. 卡密批次表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_card_batches` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(128) DEFAULT NULL COMMENT '批次备注名',
  `prefix`      VARCHAR(16)  DEFAULT NULL COMMENT '卡密前缀',
  `code_format` VARCHAR(48)  DEFAULT NULL COMMENT '卡密格式模板（X=字母数字 D=数字 - 分隔符）',
  `software_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '归属软件ID',
  `type`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1时长 2点数 3次数 4永久 0=外部导入批次（发卡商品导入的外部卡密）',
  `duration`    INT UNSIGNED NOT NULL DEFAULT 0,
  `max_devices` TINYINT      NOT NULL DEFAULT 1,
  `group_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '激活后分配的用户组ID，0=不换组',
  `count`       INT          NOT NULL DEFAULT 0 COMMENT '生成数量',
  `used_count`  INT          NOT NULL DEFAULT 0 COMMENT '已使用数量',
  `admin_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `agent_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属代理商ID，0=官方直发',
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_agent` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='卡密批次';

-- ------------------------------------------------------------
-- 5. 设备绑定表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_devices` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `machine_id`  VARCHAR(128) NOT NULL COMMENT '机器码/设备指纹',
  `device_name` VARCHAR(128) DEFAULT NULL COMMENT '设备名称',
  `os_info`     VARCHAR(128) DEFAULT NULL,
  `fp_hash`     VARCHAR(64)  DEFAULT NULL COMMENT '加权设备指纹(board/cpu/disk/bios/mac/gpu 加权合成)',
  `fp_json`     TEXT         DEFAULT NULL COMMENT '设备指纹组件明细(JSON)',
  `fp_score`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '组件加权总分(0-110)',
  `vm_flag`     TINYINT      NOT NULL DEFAULT 0 COMMENT '1=疑似模拟器/虚拟机',
  `risk_flags`  VARCHAR(64)  DEFAULT NULL COMMENT '风险标记,逗号分隔',
  `ip`          VARCHAR(64)  DEFAULT NULL,
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1正常 0已解绑',
  `rt_risk_score` INT        NOT NULL DEFAULT 0 COMMENT '运行时风险分数',
  `rt_risk_level` VARCHAR(16) NOT NULL DEFAULT 'LOW' COMMENT 'LOW/MEDIUM/HIGH/CRITICAL',
  `rt_flags`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累积安全标志',
  `rt_status`   VARCHAR(16)  NOT NULL DEFAULT 'UNKNOWN' COMMENT 'UNKNOWN/CLEAN/RISK/BLOCKED',
  `bind_at`     INT UNSIGNED NOT NULL DEFAULT 0,
  `last_seen`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后一次心跳时间',
  `unbind_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `unbind_reason` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_machine` (`user_id`, `machine_id`),
  KEY `idx_machine` (`machine_id`),
  KEY `idx_fp` (`fp_hash`),
  KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备绑定';

-- ------------------------------------------------------------
-- 6. 在线会话表（客户端用户）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_sessions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`       VARCHAR(128) NOT NULL COMMENT '会话令牌',
  `user_id`     INT UNSIGNED NOT NULL,
  `software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录所属软件ID',
  `machine_id`  VARCHAR(128) DEFAULT NULL,
  `ip`          VARCHAR(64)  DEFAULT NULL,
  `client_ver`  VARCHAR(32)  DEFAULT NULL,
  `login_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  `last_active` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后心跳时间',
  `expire_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1在线 0已退出 2顶号 3被踢',
  `rt_status`       VARCHAR(16)  NOT NULL DEFAULT 'UNKNOWN' COMMENT 'UNKNOWN/CLEAN/RISK/BLOCKED',
  `rt_risk_score`   INT          NOT NULL DEFAULT 0,
  `rt_risk_level`   VARCHAR(16)  NOT NULL DEFAULT 'LOW',
  `rt_flags`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `rt_policy_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_last_active` (`last_active`),
  KEY `idx_status_active` (`status`, `last_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线会话';

-- ------------------------------------------------------------
-- 6b. 管理员会话表（与用户会话分离，避免 user_id 正负混用）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_admin_sessions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`       VARCHAR(128) NOT NULL COMMENT '管理端令牌',
  `admin_id`    INT UNSIGNED NOT NULL,
  `ip`          VARCHAR(64)  DEFAULT NULL,
  `ua`          VARCHAR(255) DEFAULT NULL,
  `sk_hash`     VARCHAR(64)  DEFAULT NULL COMMENT '会话密钥摘要(SHA-256),NULL=未启用绑定',
  `ua_hash`     VARCHAR(64)  DEFAULT NULL COMMENT '登录时User-Agent摘要(SHA-256)',
  `login_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  `last_active` INT UNSIGNED NOT NULL DEFAULT 0,
  `expire_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1有效 0已退出',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员会话';

-- ------------------------------------------------------------
-- 7. 公告表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_notices` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(128) NOT NULL,
  `content`     TEXT,
  `type`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1官网门户 2弹窗公告(客户端) 3立即公告(客户端,看过即读) 4列表公告(客户端)',
  `software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属软件ID，0=全部软件',
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `sort`        INT          NOT NULL DEFAULT 0,
  `start_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  `end_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_software` (`software_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告';

-- ------------------------------------------------------------
-- 8. 版本发布表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_versions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id`   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '归属软件ID',
  `version`       VARCHAR(32)  NOT NULL COMMENT '版本号 1.2.3',
  `channel`       VARCHAR(16)  NOT NULL DEFAULT 'stable' COMMENT '渠道 stable/beta',
  `download_url`  VARCHAR(255) DEFAULT NULL,
  `file_hash`     VARCHAR(128) DEFAULT NULL COMMENT '安装包 SHA256',
  `file_size`     BIGINT       DEFAULT 0,
  `changelog`     TEXT,
  `force_update`  TINYINT      NOT NULL DEFAULT 0 COMMENT '是否强制更新',
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1发布 0下架',
  `created_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ver_channel` (`version`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='版本发布';

-- ------------------------------------------------------------
-- 9. 操作日志 / 风控日志
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '业务用户ID，管理员操作为0',
  `admin_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '管理员ID，用户操作为0',
  `username`   VARCHAR(64)  DEFAULT NULL,
  `action`     VARCHAR(32)  NOT NULL COMMENT 'login/heartbeat/activate/unbind/...',
  `result`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `message`    VARCHAR(255) DEFAULT NULL,
  `ip`         VARCHAR(64)  DEFAULT NULL,
  `machine_id` VARCHAR(128) DEFAULT NULL,
  `ua`         VARCHAR(255) DEFAULT NULL,
  `raw`        TEXT         DEFAULT NULL COMMENT '原始报文(调试)',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

-- ------------------------------------------------------------
-- 10. API 调用统计表（按天聚合）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_api_stats` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stat_date`   DATE         NOT NULL COMMENT '统计日期',
  `endpoint`    VARCHAR(64)  NOT NULL COMMENT '接口名',
  `ip`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '来源IP',
  `call_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `fail_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_ms`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平均耗时(毫秒)',
  `updated_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_ep_ip` (`stat_date`, `endpoint`, `ip`),
  KEY `idx_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API调用统计';

CREATE TABLE IF NOT EXISTS `nb_online_stats` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stat_time`  INT UNSIGNED NOT NULL COMMENT '快照时间戳（按分钟对齐）',
  `online`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '在线会话数',
  `devices`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '在线设备数',
  `users`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户总数（快照）',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_time` (`stat_time`),
  KEY `idx_time` (`stat_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线数快照（大屏曲线用）';

-- ------------------------------------------------------------
-- 11. 限流计数表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_rate_limit` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket_key` VARCHAR(128) NOT NULL COMMENT '限流键 ip:endpoint 或 user:action',
  `window_at`  INT UNSIGNED NOT NULL COMMENT '窗口起始时间戳(取整分钟)',
  `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bucket_window` (`bucket_key`, `window_at`),
  KEY `idx_window` (`window_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='限流计数';

-- ------------------------------------------------------------
-- 12. 管理员表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_admins` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`    VARCHAR(64)  NOT NULL,
  `password`    VARCHAR(255) NOT NULL COMMENT 'bcrypt',
  `nickname`    VARCHAR(64)  DEFAULT NULL,
  `role`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1超级管理员 2操作员 3只读',
  `permissions` TEXT         DEFAULT NULL COMMENT '自定义权限点 JSON 数组（NULL=按 role 默认矩阵；仅 role 2/3 生效，admin.manage 恒为超管专属）',
  `status`      TINYINT      NOT NULL DEFAULT 1,
  `agent_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '绑定代理商ID（多租户：>0 时为租户管理员，仅可见归属软件数据）',
  `last_login_ip`   VARCHAR(64)  DEFAULT NULL,
  `last_login_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `login_fail_cnt`  TINYINT      NOT NULL DEFAULT 0,
  `lock_until`      INT UNSIGNED NOT NULL DEFAULT 0,
  `totp_secret`     VARCHAR(64)  DEFAULT NULL COMMENT 'TOTP 密钥(base32)，NULL=未绑定',
  `totp_enabled`    TINYINT      NOT NULL DEFAULT 0 COMMENT '1=已开启二次验证',
  `totp_recovery`   VARCHAR(1024) DEFAULT NULL COMMENT '恢复码(sha256 摘要)JSON 数组',
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员';

-- ------------------------------------------------------------
-- 13. 系统设置表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_settings` (
  `skey`       VARCHAR(64) NOT NULL,
  `svalue`     TEXT,
  `remark`     VARCHAR(255) DEFAULT NULL,
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';

-- ------------------------------------------------------------
-- 14. 卡密使用记录（审计）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_card_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_id`    BIGINT UNSIGNED NOT NULL,
  `code`       VARCHAR(64)  NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `action`     VARCHAR(32)  NOT NULL COMMENT 'activate/void/refund',
  `detail`     VARCHAR(255) DEFAULT NULL,
  `ip`         VARCHAR(64)  DEFAULT NULL,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_card` (`card_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='卡密审计';

-- ------------------------------------------------------------
-- 15. 管理端审计日志（记录谁改了什么、改前改后）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `admin_name`  VARCHAR(64)  DEFAULT NULL,
  `action`      VARCHAR(48)  NOT NULL COMMENT '动作标识',
  `action_text` VARCHAR(64)  DEFAULT NULL COMMENT '动作中文名',
  `target`      VARCHAR(190) DEFAULT NULL COMMENT '操作对象描述',
  `summary`     VARCHAR(255) DEFAULT NULL,
  `changes`     TEXT         DEFAULT NULL COMMENT '字段变更 JSON',
  `has_diff`    TINYINT      NOT NULL DEFAULT 0 COMMENT '是否有字段级变更',
  `ip`          VARCHAR(64)  DEFAULT NULL,
  `ua`          VARCHAR(255) DEFAULT NULL,
  `extra`       TEXT         DEFAULT NULL,
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理端审计日志';

-- ------------------------------------------------------------
-- 16. 设备黑名单
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_device_bans` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `machine_id` VARCHAR(128) NOT NULL,
  `reason`     VARCHAR(255) DEFAULT NULL,
  `admin_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `expire_at`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=永久',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_machine` (`machine_id`),
  KEY `idx_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备黑名单';

-- ------------------------------------------------------------
-- 17. 每日接口调用配额计数表（按用户 + 日期）
--     nb_groups.daily_quota > 0 时生效：该组每个用户每天可调用的
--     客户端接口次数上限。配额的检查与自增在 lib/Quota.php 完成。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_api_quota` (
  `id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `day`     DATE         NOT NULL COMMENT '统计日期（服务器本地日期）',
  `cnt`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日已调用次数',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_day` (`user_id`, `day`),
  KEY `idx_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日接口调用配额计数';

-- ------------------------------------------------------------
-- 18. 会话级签名密钥（init 下发，防主盐泄露后全量伪造请求）
--     客户端 init 成功后拿到 {k, s}（响应体主密钥加密传输），
--     之后所有非白名单请求的信封必须携带 k，验签/响应签名均用 s。
--     同一机器码仅保留最新一把（init 时旧行被删除），默认 7 天过期。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_sign_keys` (
  `kid`          CHAR(16)     NOT NULL COMMENT '会话密钥ID（信封k字段指认）',
  `skey`         VARCHAR(64)  NOT NULL COMMENT '签名密钥（48位hex）',
  `software_id`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '所属软件ID',
  `machine_id`   VARCHAR(128) NOT NULL DEFAULT '' COMMENT '绑定机器码',
  `created_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `expire_at`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '过期时间戳',
  `last_used_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`kid`),
  KEY `idx_machine` (`machine_id`),
  KEY `idx_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话级签名密钥（init下发）';

-- ------------------------------------------------------------
-- 18b. Nebula 3.1 ECDH 会话表（handshake 协商，见 lib/Handshake.php）
--      客户端不再持有跨会话对称机密：握手双方临时 EC 密钥 ECDH 后
--      HKDF 派生 sk_enc/sk_mac 落库；业务信封带 sid 走本表，
--      seq 严格单调递增（原子 UPDATE 防重放），会话 6 小时空闲过期。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_hsessions` (
  `sid`         CHAR(32)     NOT NULL COMMENT '会话ID（随机16字节hex）',
  `software_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '所属软件ID',
  `mhash`       CHAR(64)     NOT NULL DEFAULT '' COMMENT 'sha256(machine_id) hex（同机仅留最新会话）',
  `sk_enc`      BINARY(32)   NOT NULL COMMENT 'HKDF 派生的 AES-256-GCM 密钥',
  `sk_mac`      BINARY(32)   NOT NULL COMMENT 'HKDF 派生的请求 HMAC 密钥',
  `iv_prefix`   BINARY(4)    NOT NULL COMMENT 'GCM IV 前 4 字节随机前缀（后 8 字节 = seq 大端）',
  `seq`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已使用的最大请求序号',
  `rt_risk_score` INT        NOT NULL DEFAULT 0 COMMENT '运行时风险分数',
  `rt_risk_level` VARCHAR(16) NOT NULL DEFAULT 'LOW' COMMENT 'LOW/MEDIUM/HIGH/CRITICAL',
  `rt_flags`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累积安全标志(64-bit bitmap)',
  `rt_status`   VARCHAR(16)  NOT NULL DEFAULT 'UNKNOWN' COMMENT 'UNKNOWN/CLEAN/RISK/BLOCKED',
  `rt_last_event_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '最近安全事件ID',
  `rt_last_event_at` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近安全事件时间',
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  `expire_at`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '空闲过期时间戳',
  PRIMARY KEY (`sid`),
  KEY `idx_mhash` (`mhash`),
  KEY `idx_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Nebula 3.1 ECDH会话（handshake协商）';

-- ------------------------------------------------------------
-- 19. 代理商表
--     分销代理登录独立后台 /agent/ 生成卡密，卡密归属该代理（cards.agent_id）。
--     控量三选一：1=按张数额度 2=按余额单价计费 3=不限
--     ⚠️ 额度与单价自 v1.1 起下沉到 nb_agent_types（按卡类型分别配置），
--        本表的 quota_total / quota_used / unit_price 仅作历史字段保留，不再参与计费。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_agents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(64)  NOT NULL COMMENT '代理账号',
  `password`        VARCHAR(255) NOT NULL COMMENT 'bcrypt 密码哈希',
  `nickname`        VARCHAR(64)  DEFAULT NULL COMMENT '代理名称',
  `software_id`     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '归属软件ID，卡密只对该软件有效',
  `contact`         VARCHAR(128) DEFAULT NULL COMMENT '联系方式(QQ/微信/邮箱)',
  `charge_mode`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1张数额度 2余额计费 3不限',
  `quota_total`     INT          NOT NULL DEFAULT 0 COMMENT '【历史字段】统一额度，见 nb_agent_types',
  `quota_used`      INT          NOT NULL DEFAULT 0 COMMENT '【历史字段】统一已用量',
  `balance`         INT          NOT NULL DEFAULT 0 COMMENT '余额，单位：分（仅模式2生效）',
  `unit_price`      INT          NOT NULL DEFAULT 0 COMMENT '【历史字段】统一单价（分/张）',
  `reg_code`        VARCHAR(64)  DEFAULT NULL COMMENT '注册时使用的代理商激活码，NULL=后台直接创建',
  `max_devices`     TINYINT      NOT NULL DEFAULT 1 COMMENT '该代理生成卡密的设备上限（后台固定）',
  `group_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '该代理卡密激活后进入的用户组，0=不换组',
  `card_prefix`     VARCHAR(8)   DEFAULT NULL COMMENT '该代理生成卡密的固定前缀（激活码带入，代理不可改），NULL=由代理自填',
  `can_void`        TINYINT      NOT NULL DEFAULT 1 COMMENT '是否允许代理作废自己的未使用卡密',
  `status`          TINYINT      NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `remark`          VARCHAR(255) DEFAULT NULL,
  `last_login_ip`   VARCHAR(64)  DEFAULT NULL,
  `last_login_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `login_fail_cnt`  TINYINT      NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `lock_until`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '锁定到期时间戳',
  `created_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_username` (`username`),
  KEY `idx_agent_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商';

-- ------------------------------------------------------------
-- 20. 代理商会话表（与管理后台 nb_admin_sessions 同构，互不干扰）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_agent_sessions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`       VARCHAR(64)  NOT NULL,
  `agent_id`    INT UNSIGNED NOT NULL,
  `ip`          VARCHAR(64)  DEFAULT NULL,
  `ua`          VARCHAR(255) DEFAULT NULL,
  `sk_hash`     VARCHAR(64)  DEFAULT NULL COMMENT '会话密钥摘要(SHA-256),NULL=未启用绑定',
  `ua_hash`     VARCHAR(64)  DEFAULT NULL COMMENT '登录时User-Agent摘要(SHA-256)',
  `login_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  `last_active` INT UNSIGNED NOT NULL DEFAULT 0,
  `expire_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `status`      TINYINT      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_token` (`token`),
  KEY `idx_agent_sess` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商会话';

-- ------------------------------------------------------------
-- 21. 代理商操作日志（生成/作废/导出/登录，用于对账与追溯）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_agent_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`   INT UNSIGNED NOT NULL,
  `action`     VARCHAR(32)  NOT NULL COMMENT 'login/logout/generate/void/export/charge',
  `detail`     VARCHAR(255) DEFAULT NULL,
  `amount`     INT          NOT NULL DEFAULT 0 COMMENT '涉及张数（负数表示扣减）',
  `ip`         VARCHAR(64)  DEFAULT NULL,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_agent_log` (`agent_id`),
  KEY `idx_agent_log_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商操作日志';

-- ------------------------------------------------------------
-- 22. 代理商按卡类型的额度与单价（v1.1 起为额度/单价的唯一数据源）
--     · 配额模式：每种卡类型有各自的 quota_total（-1=不限）
--     · 余额模式：每种卡类型有各自的 price（分/张，0=用全局默认单价）
--     · enabled=0 表示该代理不开放此卡类型
--     · group_id 按卡类型指定「生成卡密激活后进入的用户组」，
--       0 = 跟随 nb_agents.group_id（代理默认组），两级都为 0 则激活后不换组
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_agent_types` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`    INT UNSIGNED NOT NULL,
  `card_type`   TINYINT      NOT NULL COMMENT '1时长卡 2点数卡 3次数卡 4永久卡',
  `enabled`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1开放 0不开放该类型',
  `quota_total` INT          NOT NULL DEFAULT 0 COMMENT '该类型可生成张数，-1=不限',
  `quota_used`  INT          NOT NULL DEFAULT 0 COMMENT '该类型已消耗张数',
  `price`       INT          NOT NULL DEFAULT 0 COMMENT '该类型单价（分/张），0=用全局默认单价',
  `group_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '该卡类型生成卡密激活后进入的用户组，0=跟随代理默认组',
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_card_type` (`agent_id`, `card_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商按卡类型的额度/单价与激活用户组';

-- ------------------------------------------------------------
-- 23. 代理商激活码（主管理员生成，代理商凭码自助注册）
--     注册时把 group_id / max_devices / can_void / charge_mode / card_prefix
--     / init_balance 与 preset 一次性复制到 nb_agents 与 nb_agent_types —— 注册后代理不可自改，
--     卡密激活后进入的用户组、卡密前缀与初始余额因此始终由主管理员决定。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_agent_codes` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(64)  NOT NULL COMMENT '激活码',
  `nickname`     VARCHAR(64)  DEFAULT NULL COMMENT '预填代理名称（注册时可修改）',
  `software_id`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '注册的代理归属软件',
  `group_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '卡密激活后进入的用户组（固定）',
  `max_devices`  TINYINT      NOT NULL DEFAULT 1 COMMENT '卡密设备上限（固定）',
  `card_prefix`  VARCHAR(8)   DEFAULT NULL COMMENT '代理生成卡密的固定前缀（固定，NULL=由代理自填）',
  `can_void`     TINYINT      NOT NULL DEFAULT 1 COMMENT '是否允许代理作废自己的卡密',
  `charge_mode`  TINYINT      NOT NULL DEFAULT 1 COMMENT '1张数额度 2余额计费 3不限',
  `init_balance` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '余额计费模式下注册后赠予代理的初始余额（单位：分）',
  `preset`       TEXT         DEFAULT NULL COMMENT '按卡类型预设 {"1":{"enabled":1,"quota":10,"price":"1.00","group_id":3}}，group_id=该类型激活后进入的用户组(0=跟随代理默认组)',
  `max_uses`     INT          NOT NULL DEFAULT 1 COMMENT '可注册次数，1=一次性',
  `used_count`   INT          NOT NULL DEFAULT 0 COMMENT '已注册次数',
  `expire_at`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '激活码有效期，0=永久',
  `status`       TINYINT      NOT NULL DEFAULT 1 COMMENT '1可用 0停用',
  `create_admin` INT UNSIGNED NOT NULL DEFAULT 0,
  `remark`       VARCHAR(255) DEFAULT NULL,
  `created_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_code` (`code`),
  KEY `idx_agent_code_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商激活码（自助注册用）';

-- ------------------------------------------------------------
-- 24. 代理商充值卡密（主管理员生成，代理商在 /agent/ 兑换）
--     · kind=1 余额充值：兑换后给代理余额加 amount（分），仅余额计费模式有意义
--     · kind=2 张数额度：兑换后给**一种或多种卡类型**分别加张数
--       （quota_map JSON 为主，如 {"1":10,"2":5,"3":-1}；-1=该类型设为不限）
--     与「注册用激活码」职责分开：激活码只用于开户，充值卡密只用于续费 / 加量。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_agent_recharge_codes` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(64)  NOT NULL COMMENT '充值卡密',
  `kind`          TINYINT      NOT NULL DEFAULT 1 COMMENT '1=余额充值 2=张数额度',
  `amount`        INT          NOT NULL DEFAULT 0 COMMENT '充值金额（分），kind=1 时有效',
  `card_type`     TINYINT      NOT NULL DEFAULT 0 COMMENT '目标卡类型（单类型旧字段，仅 quota_map 为空时兼容使用），kind=2 时有效',
  `quota`         INT          NOT NULL DEFAULT 0 COMMENT '增加张数（-1=设为不限），kind=2 时有效；配套 card_type 的旧字段',
  `quota_map`     TEXT         DEFAULT NULL COMMENT '多卡类型张数 JSON {"1":10,"2":-1}（-1=不限），kind=2 时优先使用',
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1可用 0停用',
  `max_uses`      INT          NOT NULL DEFAULT 1 COMMENT '可兑换次数，1=一次性',
  `used_count`    INT          NOT NULL DEFAULT 0 COMMENT '已兑换次数',
  `expire_at`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '有效期，0=永久',
  `last_agent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近兑换的代理商',
  `last_used_at`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近兑换时间',
  `create_admin`  INT UNSIGNED NOT NULL DEFAULT 0,
  `remark`        VARCHAR(255) DEFAULT NULL,
  `created_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_recharge` (`code`),
  KEY `idx_agent_recharge_kind` (`kind`),
  KEY `idx_agent_recharge_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理商充值卡密（余额/张数额度）';

-- ----------------------------------------------------------------------
-- 官网互动功能（留言板 / 反馈 / 套餐 / 截图）
-- 见 install/migrate_web_interact.php（老站升级用那个脚本）
-- ----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `nb_messages` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属软件ID，0=总站/通用（发布时按当前官网软件写入）',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '留言用户ID',
  `username`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '昵称快照（用户改昵称后旧留言仍显示当时的名字）',
  `content`     TEXT         NOT NULL COMMENT '留言内容',
  `parent_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回复的目标留言ID，0=主楼',
  `reply_to`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '被回复者的昵称快照',
  `likes`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数（冗余计数，展示用）',
  `status`      TINYINT      NOT NULL DEFAULT 0 COMMENT '0待审 1已通过 2已驳回',
  `admin_note`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '审核备注/驳回理由',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_time` (`status`, `id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官网留言板';

CREATE TABLE IF NOT EXISTS `nb_message_likes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_msg_user` (`message_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='留言点赞记录';

CREATE TABLE IF NOT EXISTS `nb_feedbacks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属软件ID，0=总站/通用（提交时按当前官网软件写入）',
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0,
  `username`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '提交时用户名快照',
  `type`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1功能建议 2问题反馈 3卡密/订单 4其他',
  `title`       VARCHAR(128) NOT NULL DEFAULT '',
  `content`     TEXT         NOT NULL,
  `contact`     VARCHAR(128) NOT NULL DEFAULT '' COMMENT '联系方式（选填，便于客服回访）',
  `status`      TINYINT      NOT NULL DEFAULT 0 COMMENT '0待处理 1处理中 2已回复 3已关闭',
  `reply`       TEXT         NOT NULL COMMENT '客服回复内容',
  `reply_admin` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '回复的管理员',
  `replied_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官网用户反馈';

CREATE TABLE IF NOT EXISTS `nb_plans` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '挂卡归属软件ID',
  `web_software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '官网展示归属软件ID（0=全部软件通用，N=仅该软件官网显示）',
  `name`        VARCHAR(64)  NOT NULL COMMENT '套餐名，如「月卡」',
  `price`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '价格文案，如「30」或「面议」（字符串，支持非数字）',
  `unit`        VARCHAR(16)  NOT NULL DEFAULT '元' COMMENT '价格单位',
  `duration`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '时长文案，如「30 天」',
  `desc`        TEXT         NOT NULL COMMENT '套餐说明（一行一条，前端按换行拆成要点）',
  `badge`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '角标，如「热销」「推荐」',
  `shop_category` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '商品分类（发卡商店分组页签）',
  `shop_name`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '发卡商品名（独立于官网套餐名，空则回退套餐名）',
  `shop_icon`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '商品图标URL（橱窗列表样式）',
  `shop_intro`  VARCHAR(200) NOT NULL DEFAULT '' COMMENT '商品简介（列表样式第二行）',
  `shop_detail` TEXT         NULL COMMENT '商品详情（整页详情宝贝详情区块，长文本）',
  `shop_badge`  VARCHAR(30) NOT NULL DEFAULT '' COMMENT '发卡角标（独立于官网角标）',
  `shop_highlight` TINYINT NOT NULL DEFAULT 0 COMMENT '发卡推荐高亮（独立于官网推荐）',
  `highlight`   TINYINT      NOT NULL DEFAULT 0 COMMENT '1=高亮展示（推荐款）',
  `sort`        INT          NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `shop_status` TINYINT      NOT NULL DEFAULT 0 COMMENT '发卡上架 0仅展示 1上架自动发卡',
  `shop_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '发卡售价（元），shop_status=1 时生效',
  `card_type`   TINYINT      NOT NULL DEFAULT 1 COMMENT '挂卡类型 1时长卡 2点数卡 3次数卡 4永久卡',
  `card_duration` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '挂卡规格：时长卡=秒数 点数卡=点数 次数卡=次数',
  `card_max_devices` TINYINT NOT NULL DEFAULT 1 COMMENT '挂卡设备上限',
  `card_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '激活后进入用户组，0=不换组',
  `card_source` TINYINT      NOT NULL DEFAULT 0 COMMENT '卡密来源 0=本系统卡（按挂卡规格取卡） 1=外部卡密（导入池发货）',
  `web_deleted` TINYINT      NOT NULL DEFAULT 0 COMMENT '官网侧删除标记 0正常 1已从官网价格套餐删除（发卡商品保留）',
  `shop_deleted` TINYINT     NOT NULL DEFAULT 0 COMMENT '发卡侧删除标记 0正常 1已从发卡商品删除（官网价格套餐保留）',
  `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官网价格套餐（官网价格套餐与发卡商品双面共用，双向软删互不影响）';

-- ------------------------------------------------------------
-- 24.1 发卡商品挂卡类型（一个商品可配多种：月卡/季卡/年卡/永久等）
--      每条 = 一种可购买规格（卡类型 + 时长 + 售价 + 设备上限 + 用户组）
--      买家详情页选一种下单，订单按所选规格发卡。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_shop_plan_cards` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属发卡商品ID（nb_shop_plans.id）',
  `card_type`        TINYINT      NOT NULL DEFAULT 1 COMMENT '挂卡类型 1时长卡 2点数卡 3次数卡 4永久卡',
  `card_duration`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '卡规格：时长卡=秒数 点数卡=点数 次数卡=次数 永久卡=0',
  `price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '该类型售价（元），0=使用商品默认售价',
  `card_max_devices` TINYINT      NOT NULL DEFAULT 1 COMMENT '设备上限',
  `card_group_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '激活后进入用户组 0=不换组',
  `sort`             INT          NOT NULL DEFAULT 0,
  `status`           TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`       INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发卡商品挂卡类型（多规格）';

-- ------------------------------------------------------------
-- 25. 发卡订单（官网 /shop/ 自动/人工发卡）
--     自动模式：待支付 → 易支付回调验签 → 从官方直发卡库取卡 → 已发卡
--     人工模式：待支付 → 买家展示收款码转账后留联系方式 → 3人工待处理
--               → 管理员后台确认 → 已发卡（或手动补卡）
--     卡规格以套餐挂卡快照为准：发卡时按 type/duration/max_devices/group_id
--     从 nb_cards（status=0 且 agent_id=0）取一张未使用卡标为 3 已售出。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_shop_orders` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`     VARCHAR(32)  NOT NULL COMMENT '订单号（全局唯一）',
  `plan_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '套餐ID',
  `software_id`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '归属软件ID（随商品）',
  `plan_name`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '套餐名快照',
  `card_type`    TINYINT      NOT NULL DEFAULT 1 COMMENT '卡类型快照 1时长 2点数 3次数 4永久',
  `duration`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '卡规格快照（秒/点/次）',
  `max_devices`  TINYINT      NOT NULL DEFAULT 1 COMMENT '设备上限快照',
  `group_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '激活用户组快照 0=不换组',
  `card_source`  TINYINT      NOT NULL DEFAULT 0 COMMENT '卡源快照 0=本系统卡 1=外部卡密',
  `amount`       INT          NOT NULL DEFAULT 0 COMMENT '实付金额（分）',
  `qty`          INT          NOT NULL DEFAULT 1 COMMENT '购买数量（张）',
  `pay_type`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1易支付自动 2人工确认',
  `channel`      VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '支付渠道 alipay/wxpay/qqpay',
  `status`       TINYINT      NOT NULL DEFAULT 0 COMMENT '0待支付 1已发卡 2已关闭 3人工待处理',
  `contact`      VARCHAR(128) NOT NULL DEFAULT '' COMMENT '买家联系方式（人工发货/售后对账）',
  `user_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下单用户ID（nb_users.id，0=游客）',
  `query_pwd`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '查询密码hash（凭联系方式+密码查单）',
  `card_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发出的卡密ID',
  `card_code`    TEXT         DEFAULT NULL COMMENT '发出的卡密（发卡后写入，多张换行分隔）',
  `trade_no`     VARCHAR(64)  DEFAULT NULL COMMENT '易支付平台流水号（对账/补单）',
  `wechat_code_url` TEXT DEFAULT NULL COMMENT '微信支付 Native code_url（扫码支付链接）',
  `jsapi_params`   TEXT DEFAULT NULL COMMENT '微信 JSAPI 支付参数（预下单结果，前端调起支付用）',
  `openid`         VARCHAR(64) DEFAULT NULL COMMENT '微信 openid（JSAPI 支付需传入，下单时从前端获取）',
  `paid_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  `delivered_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `client_ip`    VARCHAR(64)  DEFAULT NULL,
  `remark`       VARCHAR(255) DEFAULT NULL COMMENT '备注（人工处理记录/关闭原因）',
  `created_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_trade` (`trade_no`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发卡订单';

-- ------------------------------------------------------------
-- 25.1 外部卡密池（发卡商品 card_source=1 时从本表发货）
--      商品不挂本系统卡规格，管理员把外部平台的卡密按商品导入，
--      买家付款后取一条未售（status=0）内容直接交付。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_shop_cards` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属发卡商品ID（nb_plans.id）',
  `batch_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '导入批次ID（nb_card_batches.id，type=0 外部导入）',
  `card_type`  TINYINT      NOT NULL DEFAULT 0 COMMENT '挂卡类型ID（1时长/2点数/3次数/4永久；0=未分类，兼容旧数据）',
  `content`    VARCHAR(500) NOT NULL COMMENT '外部卡密内容（一行一条导入）',
  `status`     TINYINT      NOT NULL DEFAULT 0 COMMENT '0未售 1已售',
  `order_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '售出绑定的订单ID',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `sold_at`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_plan_status` (`plan_id`, `status`),
  KEY `idx_plan_type_status` (`plan_id`, `card_type`, `status`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发卡网外部卡密池（非本验证系统商品）';

CREATE TABLE IF NOT EXISTS `nb_shop_plans` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(120) NOT NULL DEFAULT '' COMMENT '商品名',
  `software_id`      INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '挂卡归属软件ID',
  `shop_software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '显示归属软件ID，0=全部软件通用（按官网/商店识别号过滤）',
  `shop_name`        VARCHAR(120) NOT NULL DEFAULT '' COMMENT '发卡商店展示名（空回退 name）',
  `shop_category`    VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '分类',
  `shop_icon`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '商品图片地址',
  `shop_intro`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '简介',
  `shop_detail`      TEXT         NULL COMMENT '宝贝详情（长文本）',
  `shop_badge`       VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '角标',
  `shop_highlight`   TINYINT      NOT NULL DEFAULT 0 COMMENT '推荐高亮',
  `shop_status`      TINYINT      NOT NULL DEFAULT 0 COMMENT '1=上架',
  `shop_price`       DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '售价（元，0=免费）',
  `shop_orig_price`  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '划线原价（元，0=无折扣；仅展示用，结算仍按售价）',
  `desc`             TEXT         NULL COMMENT '卖点（换行分隔）',
  `duration`         VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '时长文案',
  `sort`             INT          NOT NULL DEFAULT 0,
  `status`           TINYINT      NOT NULL DEFAULT 0 COMMENT '兼容保留（原官网状态）',
  `card_source`      TINYINT      NOT NULL DEFAULT 0 COMMENT '0系统卡 1外部卡密',
  `card_type`        TINYINT      NOT NULL DEFAULT 1 COMMENT '挂卡类型 1时长 2点数 3次数 4永久',
  `card_duration`    INT UNSIGNED NOT NULL DEFAULT 0,
  `card_max_devices` TINYINT      NOT NULL DEFAULT 1,
  `card_group_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       INT UNSIGNED NOT NULL DEFAULT 0,
  `shop_notice`      TEXT         NULL COMMENT '查单自定义提示（商品级文案，买家查到该商品已支付订单时展示）',
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`shop_status`, `sort`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发卡商品（独立于官网价格套餐）';

CREATE TABLE IF NOT EXISTS `nb_screenshots` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属软件ID，0=全部软件通用',
  `title`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '截图标题',
  `url`        VARCHAR(500) NOT NULL COMMENT '图片地址（http/https）',
  `sort`       INT          NOT NULL DEFAULT 0,
  `status`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官网客户端截图';

CREATE TABLE IF NOT EXISTS `nb_sellers` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属软件ID，0=全部软件通用',
  `name`        VARCHAR(64)  NOT NULL COMMENT '商家名，如「XX工作室」',
  `logo`       VARCHAR(500) NOT NULL DEFAULT '' COMMENT '商家 Logo 图片地址（http/https，可空）',
  `desc`       TEXT         NOT NULL COMMENT '商家简介（一行一条，前端按换行拆成要点）',
  `contact`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '购买联系方式（QQ/微信/邮箱/网址）',
  `url`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '店铺/网站链接（http/https，可空）',
  `badge`      VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '角标，如「官方」「授权」',
  `highlight`  TINYINT      NOT NULL DEFAULT 0 COMMENT '1=高亮展示（推荐商家）',
  `sort`       INT          NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `status`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官网购买商家';

CREATE TABLE IF NOT EXISTS `nb_game_scores` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `game`       VARCHAR(20)  NOT NULL COMMENT '游戏标识：farm/mario/ink/space',
  `name`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '玩家昵称（游客自填）',
  `score`      INT          NOT NULL DEFAULT 0 COMMENT '得分',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '提交 IP（审计用）',
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_game_score` (`game`, `score`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官网小游戏排行榜';

-- ------------------------------------------------------------
-- 27. 运行时安全策略（RuntimeGuard 策略下发）
--     设计文档 §5 §8 §60：多级别检测项 + 风险动作 + 看门狗
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_runtime_policies` (
  `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id`                 INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属软件ID，0=全局默认策略',
  `policy_name`                 VARCHAR(64)  NOT NULL DEFAULT 'default',
  `sdk_version_min`             VARCHAR(32)  DEFAULT NULL COMMENT '适用SDK最小版本(含)，NULL=不限',
  `sdk_version_max`             VARCHAR(32)  DEFAULT NULL COMMENT '适用SDK最大版本(含)，NULL=不限',
  `enabled`                     TINYINT      NOT NULL DEFAULT 1,
  `protection_level`            TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '0=off 1=basic 2=standard 3=strict',
  `detect_code_integrity`       TINYINT      NOT NULL DEFAULT 1,
  `detect_code_patch`           TINYINT      NOT NULL DEFAULT 1,
  `detect_inline_hook`          TINYINT      NOT NULL DEFAULT 1,
  `detect_module_injection`     TINYINT      NOT NULL DEFAULT 1,
  `detect_manual_map`           TINYINT      NOT NULL DEFAULT 1,
  `detect_debugger`             TINYINT      NOT NULL DEFAULT 1,
  `detect_process_access_risk`  TINYINT      NOT NULL DEFAULT 1,
  `popup_on_violation`          TINYINT      NOT NULL DEFAULT 1,
  `terminate_on_violation`      TINYINT      NOT NULL DEFAULT 1,
  `report_security_event`       TINYINT      NOT NULL DEFAULT 1,
  `watchdog_enabled`            TINYINT      NOT NULL DEFAULT 1,
  `watchdog_interval_ms`        INT UNSIGNED NOT NULL DEFAULT 3000,
  `medium_action`               VARCHAR(32)  NOT NULL DEFAULT 'REPORT',
  `high_action`                 VARCHAR(32)  NOT NULL DEFAULT 'TERMINATE',
  `critical_action`             VARCHAR(32)  NOT NULL DEFAULT 'REVOKE_SESSION',
  `policy_version`              INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '策略版本号(每次修改递增)',
  `status`                      TINYINT      NOT NULL DEFAULT 1 COMMENT '0=draft 1=published 2=disabled',
  `created_at`                  INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`                  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_software` (`software_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运行时安全策略';

INSERT INTO `nb_runtime_policies` (`software_id`, `policy_name`, `enabled`, `protection_level`, `created_at`, `updated_at`)
VALUES (0, 'Global Default', 1, 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `policy_name` = VALUES(`policy_name`);

-- ------------------------------------------------------------
-- 28. 运行时安全事件（客户端 RuntimeGuard 上报）
--     设计文档 §12 §13 §14：事件类型 + 风险等级 + violation_flags bitmap
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nb_security_events` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `software_id`     INT UNSIGNED NOT NULL DEFAULT 0,
  `user_id`         INT UNSIGNED NOT NULL DEFAULT 0,
  `device_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `session_id`      VARCHAR(128) NOT NULL DEFAULT '' COMMENT '业务会话token',
  `hsid`            CHAR(32)     NOT NULL DEFAULT '' COMMENT '3.1 ECDH会话ID',
  `seq`             BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上报时session seq',
  `sdk_version`     VARCHAR(32)  DEFAULT NULL,
  `event_type`      VARCHAR(64)  NOT NULL COMMENT 'CODE_PATCH/INLINE_HOOK/MODULE_INJECTION/...',
  `risk_level`      VARCHAR(16)  NOT NULL DEFAULT 'LOW' COMMENT 'LOW/MEDIUM/HIGH/CRITICAL',
  `risk_score`      INT          NOT NULL DEFAULT 0,
  `violation_flags` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '64-bit bitmap(§14)',
  `process_id`      INT UNSIGNED DEFAULT NULL,
  `module_name`     VARCHAR(260) DEFAULT NULL,
  `module_path`     VARCHAR(1024) DEFAULT NULL,
  `event_hash`      CHAR(64)     DEFAULT NULL COMMENT 'SHA-256(canonical_payload)',
  `event_detail`    JSON         DEFAULT NULL COMMENT '结构化诊断信息(§48: 只允许预定义字段)',
  `client_ip`       VARCHAR(64)  DEFAULT NULL,
  `client_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `server_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `handled`         TINYINT      NOT NULL DEFAULT 0 COMMENT '0=未处理 1=已处理 2=误报',
  `action_taken`    VARCHAR(32)  DEFAULT NULL COMMENT 'REPORT/TERMINATE/REVOKE_SESSION',
  `sticky`          TINYINT      NOT NULL DEFAULT 0 COMMENT '1=风险不可自动衰减',
  `created_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_session_time` (`session_id`, `created_at`),
  KEY `idx_device_time` (`device_id`, `created_at`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_risk_level` (`risk_level`),
  KEY `idx_software_time` (`software_id`, `created_at`),
  KEY `idx_hash` (`event_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运行时安全事件';

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO `nb_groups` (`id`, `name`, `max_devices`, `daily_quota`, `remark`, `created_at`)
VALUES
  (1, '默认用户组', 1, 0, '系统默认', UNIX_TIMESTAMP()),
  (2, '高级用户组', 3, 0, '可绑定3台设备', UNIX_TIMESTAMP()),
  (3, 'VIP用户组',  5, 0, '可绑定5台设备', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `nb_settings` (`skey`, `svalue`, `remark`, `updated_at`) VALUES
  ('schema_version', '2.65.16', '数据库结构版本（全新安装基线，等于 NB_VERSION）', UNIX_TIMESTAMP()),
  ('site_name',        'Nebula Menu',    '站点名称', UNIX_TIMESTAMP()),
  ('site_sub',         '次世代游戏增强菜单', '站点副标题', UNIX_TIMESTAMP()),
  ('site_notice',      '',               '全局公告', UNIX_TIMESTAMP()),
  ('register_enable',  '1',              '是否开放注册', UNIX_TIMESTAMP()),
  ('maintain_mode',    '0',              '维护模式', UNIX_TIMESTAMP()),
  ('maintain_msg',     '服务器维护中，请稍后再试', '维护提示', UNIX_TIMESTAMP()),
  ('agent_enable',     '1',              '是否开放代理商后台', UNIX_TIMESTAMP()),
  ('agent_register_enable', '1',         '是否开放代理商自助注册（需激活码）', UNIX_TIMESTAMP()),
  ('agent_unit_price', '0',              '代理商默认单价（元/张），0=不启用余额计费', UNIX_TIMESTAMP()),
  ('agent_entry_key',  '',               '代理商入口密钥，留空则不校验', UNIX_TIMESTAMP()),
  ('shop_enable',       '0',             '发卡网开关 1开启 0关闭', UNIX_TIMESTAMP()),
  ('shop_mode',         'built',         '发卡模式 built=内置发卡 external=跳转外部发卡站', UNIX_TIMESTAMP()),
  ('shop_external_url', '',              '外部发卡站地址，shop_mode=external 时官网购买入口跳转此链接', UNIX_TIMESTAMP()),
  ('shop_pay_mode',     'auto',          '内置发卡支付方式 auto=易支付自动发卡 manual=人工确认', UNIX_TIMESTAMP()),
  ('shop_epay_url',     '',              '易支付网关地址，如 https://pay.example.com/（留空自动降级人工）', UNIX_TIMESTAMP()),
  ('shop_epay_pid',     '',              '易支付商户ID', UNIX_TIMESTAMP()),
  ('shop_epay_key',     '',              '易支付商户密钥', UNIX_TIMESTAMP()),
  ('shop_qrcode',       '',              '人工收款码图片地址（manual 模式下单页展示）', UNIX_TIMESTAMP()),
  ('shop_contact',      '',              '人工发货联系方式（QQ/微信/邮箱）', UNIX_TIMESTAMP()),
  ('shop_title',        '',              '商店标题，留空显示「站点名 · 发卡商店」', UNIX_TIMESTAMP()),
  ('shop_notice',       '',              '商店公告（顶部横幅，留空不显示）', UNIX_TIMESTAMP()),
  ('shop_banner',       '',              '商店横幅大图地址（留空不显示）', UNIX_TIMESTAMP()),
  ('shop_theme',        '',              '商店主题色（#RRGGBB，留空用默认紫）', UNIX_TIMESTAMP()),
  ('shop_footer',       '',              '商店页脚自定义文案（留空显示默认版权）', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `skey` = VALUES(`skey`);


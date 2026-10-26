CREATE TABLE IF NOT EXISTS `galaxy_waf_runtime_config` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `policy_id` bigint unsigned NOT NULL,
  `config_hash` char(64) NOT NULL,
  `settings` json NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waf_runtime_config` (`policy_id`,`config_hash`),
  KEY `idx_waf_runtime_scope` (`org_id`,`cluster_id`,`policy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `galaxy_waf_ban` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `policy_id` bigint unsigned NOT NULL,
  `client_ip` varchar(45) NOT NULL,
  `config_hash` char(64) NOT NULL,
  `reason` varchar(32) NOT NULL,
  `events_count` int unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `triggered_at` int unsigned NOT NULL,
  `expires_at` int unsigned NOT NULL,
  `released_at` int unsigned NOT NULL DEFAULT 0,
  `released_by` bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waf_ban_ip` (`policy_id`,`client_ip`),
  KEY `idx_waf_ban_scope` (`org_id`,`cluster_id`,`policy_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `galaxy_waf_signal` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `policy_id` bigint unsigned NOT NULL,
  `signal_key` char(64) NOT NULL,
  `client_ip` varchar(45) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `occurred_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waf_signal` (`signal_key`),
  KEY `idx_waf_signal_count` (`policy_id`,`client_ip`,`kind`,`occurred_at`),
  KEY `idx_waf_signal_expiry` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

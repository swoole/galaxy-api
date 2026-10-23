CREATE TABLE IF NOT EXISTS `galaxy_waf_policy` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `route_source` varchar(16) NOT NULL,
  `route_id` bigint unsigned NOT NULL,
  `settings` json NOT NULL,
  `service_id` varchar(255) NOT NULL DEFAULT '',
  `service_name` varchar(255) NOT NULL DEFAULT '',
  `applied_mode` varchar(16) NOT NULL DEFAULT 'off',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `error` text,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  `synced_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waf_route` (`org_id`,`cluster_id`,`route_source`,`route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `galaxy_waf_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `policy_id` bigint unsigned NOT NULL,
  `event_key` char(64) NOT NULL,
  `occurred_at` int unsigned NOT NULL,
  `client_ip` varchar(45) NOT NULL DEFAULT '',
  `method` varchar(16) NOT NULL DEFAULT '',
  `uri` varchar(2048) NOT NULL DEFAULT '',
  `action` varchar(16) NOT NULL,
  `status_code` smallint unsigned NOT NULL DEFAULT 0,
  `messages` json NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waf_event` (`event_key`),
  KEY `idx_waf_event_time` (`org_id`,`cluster_id`,`occurred_at`,`id`),
  KEY `idx_waf_policy_time` (`policy_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

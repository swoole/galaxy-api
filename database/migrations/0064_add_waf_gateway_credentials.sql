CREATE TABLE IF NOT EXISTS `galaxy_waf_gateway_credential` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `policy_id` bigint unsigned NOT NULL,
  `token` char(64) NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_waf_gateway_policy` (`policy_id`),
  KEY `idx_waf_gateway_scope` (`org_id`,`cluster_id`,`policy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Galaxy database initialization schema.
-- Generated from the verified MySQL 8 schema on 2026-09-09.
-- DDL only: no application data, credentials, or migration history is included.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_acme_backup_policy` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `bucket_id` bigint unsigned NOT NULL DEFAULT '0',
  `directory` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy/acme-backups',
  `interval_seconds` int unsigned NOT NULL DEFAULT '86400',
  `last_attempt_at` int unsigned NOT NULL DEFAULT '0',
  `last_success_at` int unsigned NOT NULL DEFAULT '0',
  `next_backup_at` int unsigned NOT NULL DEFAULT '0',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_acme_backup_policy_org` (`org_id`),
  KEY `idx_acme_backup_policy_due` (`enabled`,`next_backup_at`),
  KEY `idx_acme_backup_policy_bucket` (`bucket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_acme_backup_record` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` bigint unsigned NOT NULL,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL DEFAULT '0',
  `gateway_id` bigint unsigned NOT NULL DEFAULT '0',
  `bucket_id` bigint unsigned NOT NULL DEFAULT '0',
  `object_key` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_size` bigint unsigned NOT NULL DEFAULT '0',
  `backup_size` bigint unsigned NOT NULL DEFAULT '0',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_acme_backup_record_policy` (`policy_id`,`id`),
  KEY `idx_acme_backup_record_org` (`org_id`,`id`),
  KEY `idx_acme_backup_record_cluster` (`cluster_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_app_market_kubernetes_installation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `tpl_id` bigint unsigned NOT NULL,
  `uid` bigint unsigned NOT NULL,
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `namespace` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cattle-system',
  `release_name` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'rancher',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `resource_refs` longtext COLLATE utf8mb4_unicode_ci,
  `form` longtext COLLATE utf8mb4_unicode_ci,
  `secret_payload` mediumtext COLLATE utf8mb4_unicode_ci,
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_k8s_app_install_uuid` (`uuid`),
  UNIQUE KEY `uk_k8s_app_install_release` (`cluster_id`,`namespace`,`release_name`),
  KEY `idx_k8s_app_install_org_cluster` (`org_id`,`cluster_id`),
  KEY `idx_k8s_app_install_tpl` (`tpl_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_app_market_swarm_installation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `tpl_id` bigint unsigned NOT NULL,
  `uid` bigint unsigned NOT NULL,
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_name` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `docker_service_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `docker_config_ids` longtext COLLATE utf8mb4_unicode_ci,
  `docker_secret_ids` longtext COLLATE utf8mb4_unicode_ci,
  `resource_config` longtext COLLATE utf8mb4_unicode_ci,
  `network_config` longtext COLLATE utf8mb4_unicode_ci,
  `port_mappings` longtext COLLATE utf8mb4_unicode_ci,
  `volume_mappings` longtext COLLATE utf8mb4_unicode_ci,
  `config_schema` longtext COLLATE utf8mb4_unicode_ci,
  `config_values` longtext COLLATE utf8mb4_unicode_ci,
  `template_snapshot` longtext COLLATE utf8mb4_unicode_ci,
  `form` longtext COLLATE utf8mb4_unicode_ci,
  `secret_payload` mediumtext COLLATE utf8mb4_unicode_ci,
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uuid` (`uuid`),
  UNIQUE KEY `uniq_cluster_service` (`cluster_id`,`service_name`),
  KEY `idx_org_cluster` (`org_id`,`cluster_id`),
  KEY `idx_tpl` (`tpl_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_app_market_tag` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` bigint NOT NULL DEFAULT '0',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `style` longtext COLLATE utf8mb4_unicode_ci,
  `sort` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_market_tag_type_title` (`type`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_app_market_tpl` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` bigint NOT NULL DEFAULT '0',
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `intro` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `details` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `depends` longtext COLLATE utf8mb4_unicode_ci,
  `client_config` longtext COLLATE utf8mb4_unicode_ci,
  `server_config` longtext COLLATE utf8mb4_unicode_ci,
  `pipeline` longtext COLLATE utf8mb4_unicode_ci,
  `used` bigint NOT NULL DEFAULT '0',
  `viewed` bigint NOT NULL DEFAULT '0',
  `sort` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  `updated_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_app_market_tpl_tag_rel` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tpl_id` bigint NOT NULL DEFAULT '0',
  `tag_id` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_market_tpl_tag` (`tpl_id`,`tag_id`),
  KEY `idx_tpl_id` (`tpl_id`),
  KEY `idx_tag_id` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_build` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `group_id` bigint NOT NULL DEFAULT '0',
  `project_id` bigint NOT NULL DEFAULT '0',
  `pipeline_id` bigint NOT NULL DEFAULT '0',
  `executor` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'buildkit',
  `runner_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `hook_id` bigint NOT NULL DEFAULT '0',
  `trigger_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `commit_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_revision` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `start_at` bigint NOT NULL DEFAULT '0',
  `end_at` bigint NOT NULL DEFAULT '0',
  `status` bigint NOT NULL DEFAULT '0',
  `cancel_requested` tinyint unsigned NOT NULL DEFAULT '0',
  `image_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error` text COLLATE utf8mb4_unicode_ci,
  `timeline` longtext COLLATE utf8mb4_unicode_ci,
  `spec_snapshot` json DEFAULT NULL,
  `result` json DEFAULT NULL,
  `log` longtext COLLATE utf8mb4_unicode_ci,
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_build_trigger_key` (`trigger_key`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_pipeline_id` (`pipeline_id`),
  KEY `idx_hook_id` (`hook_id`),
  KEY `idx_commit_id` (`commit_id`),
  KEY `idx_image_id` (`image_id`),
  KEY `idx_build_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_build_scope_status` (`org_id`,`group_id`,`project_id`,`status`),
  KEY `idx_build_runner_ref` (`runner_ref`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_build_artifact` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `build_id` bigint unsigned NOT NULL,
  `type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'container-image',
  `reference` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `digest` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `size` bigint unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_build_reference` (`build_id`,`reference`(191)),
  KEY `idx_artifact_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_digest` (`digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_build_secret` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `build_id` bigint unsigned NOT NULL,
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `encrypted_value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_size` int unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_build_secret_name` (`build_id`,`name`),
  KEY `idx_build_secret_scope` (`org_id`,`project_id`,`build_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cli_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `commit_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bin_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `md5` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `os` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `arch` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `size` bigint NOT NULL DEFAULT '0',
  `status` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  `updated_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_commit_id` (`commit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cloud_account` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_key_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `last_verified_at` int unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cloud_account` (`org_id`,`title`),
  KEY `idx_certificate_provider_org` (`org_id`,`provider`),
  KEY `idx_certificate_provider_status` (`status`,`last_verified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cluster` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `vendor` bigint NOT NULL DEFAULT '0',
  `type` bigint NOT NULL DEFAULT '0',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `orchestrator_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'docker_swarm',
  `swarm_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `agent_status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `current_credential_version` int unsigned NOT NULL DEFAULT '0',
  `registered_at` int unsigned NOT NULL DEFAULT '0',
  `endpoint` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `resolve` longtext COLLATE utf8mb4_unicode_ci,
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `monitor` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `extra` longtext COLLATE utf8mb4_unicode_ci,
  `status` bigint NOT NULL DEFAULT '0',
  `source` bigint NOT NULL DEFAULT '0',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cluster_swarm_id` (`swarm_id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_orchestrator_type` (`orchestrator_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cluster_agent_credential` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cluster_id` bigint unsigned NOT NULL,
  `kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '0',
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `expires_at` int unsigned NOT NULL DEFAULT '0',
  `used_at` int unsigned NOT NULL DEFAULT '0',
  `revoked_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_credential_token_hash` (`token_hash`),
  UNIQUE KEY `uk_agent_credential_version` (`cluster_id`,`kind`,`version`),
  KEY `idx_agent_credential_status` (`cluster_id`,`status`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cluster_agent_node` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cluster_id` bigint unsigned NOT NULL,
  `swarm_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `node_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `node_addr` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `hostname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `role` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `credential_version` int unsigned NOT NULL DEFAULT '0',
  `capabilities` json DEFAULT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `last_seen_at` int unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cluster_agent_node` (`cluster_id`,`node_id`),
  KEY `idx_cluster_agent_node_status` (`cluster_id`,`status`),
  KEY `idx_cluster_agent_node_swarm` (`swarm_id`,`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cluster_docker_credential` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cluster_id` bigint unsigned NOT NULL,
  `ca_cert` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_cert` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cluster_id` (`cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cluster_event_cursor` (
  `cluster_id` bigint unsigned NOT NULL,
  `last_event_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cluster_prometheus` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `image` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registry.cn-shanghai.aliyuncs.com/swoole-public/prometheus:v3.2.1',
  `service_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `service_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-prometheus',
  `network_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `network_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-web-control',
  `volume_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-prometheus-data',
  `config_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `config_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `scrape_interval` smallint unsigned NOT NULL DEFAULT '15',
  `retention_days` smallint unsigned NOT NULL DEFAULT '15',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error` text COLLATE utf8mb4_unicode_ci,
  `configuration` json DEFAULT NULL,
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  `synced_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cluster_prometheus` (`cluster_id`),
  KEY `idx_cluster_prometheus_org` (`org_id`,`cluster_id`),
  KEY `idx_cluster_prometheus_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_cluster_web_gateway` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'traefik',
  `image` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registry.cn-shanghai.aliyuncs.com/swoole-public/traefik:v3.7',
  `socket_proxy_image` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registry.cn-shanghai.aliyuncs.com/swoole-public/docker-socket-proxy:latest',
  `service_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `socket_proxy_service_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `service_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-web-gateway',
  `socket_proxy_service_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-web-gateway-socket-proxy',
  `network_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `control_network_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `network_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-web',
  `control_network_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-web-control',
  `http_port` smallint unsigned NOT NULL DEFAULT '80',
  `https_port` smallint unsigned NOT NULL DEFAULT '443',
  `publish_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ingress',
  `replicas` smallint unsigned NOT NULL DEFAULT '1',
  `placement_node_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `redirect_https` tinyint unsigned NOT NULL DEFAULT '0',
  `access_log_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `metrics_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `dashboard_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `dashboard_port` smallint unsigned NOT NULL DEFAULT '8080',
  `acme_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `acme_email` varchar(320) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `workspace_base_domain` varchar(253) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `workspace_certificate_id` bigint unsigned NOT NULL DEFAULT '0',
  `workspace_https_redirect` tinyint(1) NOT NULL DEFAULT '1',
  `cert_resolver` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'letsencrypt',
  `acme_volume_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error` text COLLATE utf8mb4_unicode_ci,
  `configuration` json DEFAULT NULL,
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  `synced_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cluster_web_gateway` (`cluster_id`),
  KEY `idx_web_gateway_org` (`org_id`,`cluster_id`),
  KEY `idx_web_gateway_status` (`status`),
  KEY `idx_workspace_certificate` (`workspace_certificate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_container_image_mapping` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '0 means all clusters in the organization',
  `source_image` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_image` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_org_cluster_source` (`org_id`,`cluster_id`,`source_image`(512)),
  KEY `idx_cluster_enabled` (`org_id`,`cluster_id`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_docker_agent` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cluster_id` bigint unsigned DEFAULT NULL,
  `uid` bigint unsigned NOT NULL,
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ssh',
  `target_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_seen_at` int unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  UNIQUE KEY `uk_cluster_id` (`cluster_id`),
  KEY `idx_last_seen_at` (`last_seen_at`),
  KEY `idx_uid_name` (`uid`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_dockerfile_template` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `language` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `framework` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workload` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `runtime_versions` json NOT NULL,
  `framework_versions` json NOT NULL,
  `platforms` json NOT NULL,
  `tags` json NOT NULL,
  `manifest` json NOT NULL,
  `dockerfile_template` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `dockerignore_profile` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default-secure-v1',
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'builtin',
  `org_id` bigint unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` bigint unsigned NOT NULL DEFAULT '0',
  `updated_at` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dockerfile_template_version` (`source`,`org_id`,`template_key`,`template_version`),
  KEY `idx_dockerfile_template_catalog` (`status`,`language`,`framework`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_env` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  `archived_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_env_org_title` (`org_id`,`title`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_env_org_type` (`org_id`),
  KEY `idx_env_org_archived` (`org_id`,`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_env_cluster_rel` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `cluster_id` bigint NOT NULL DEFAULT '0',
  `env_id` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_env_cluster_scope` (`org_id`,`env_id`,`cluster_id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_cluster_id` (`cluster_id`),
  KEY `idx_env_id` (`env_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_frp_client` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `server_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned DEFAULT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deployment_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'managed',
  `management_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `namespace` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-frp',
  `image` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registry.cn-shanghai.aliyuncs.com/swoole-public/frpc:v0.69.0',
  `frp_user` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `transport_pool_count` int unsigned NOT NULL DEFAULT '5' COMMENT 'FRPC transport.poolCount，共享于该客户端的全部规则',
  `runtime_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `runtime_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `runtime_status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_deployed',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `config_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_frp_client_server_title` (`server_id`,`title`),
  KEY `idx_frp_client_org` (`org_id`,`id`),
  KEY `idx_frp_client_cluster` (`cluster_id`),
  KEY `idx_frp_client_auto_route` (`server_id`,`management_mode`,`cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_frp_server` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deployment_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'managed',
  `management_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `cluster_id` bigint unsigned DEFAULT NULL,
  `namespace` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'galaxy-frp',
  `image` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registry.cn-shanghai.aliyuncs.com/swoole-public/frps:v0.69.0',
  `advertise_host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bind_port` int unsigned NOT NULL DEFAULT '7000',
  `vhost_http_port` int unsigned NOT NULL DEFAULT '0',
  `vhost_https_port` int unsigned NOT NULL DEFAULT '0',
  `dashboard_port` int unsigned NOT NULL DEFAULT '0',
  `dashboard_user` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `dashboard_password_ciphertext` text COLLATE utf8mb4_unicode_ci,
  `auth_token_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `runtime_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `runtime_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `runtime_status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_deployed',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `config_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_frp_server_org_title` (`org_id`,`title`),
  KEY `idx_frp_server_cluster` (`cluster_id`),
  KEY `idx_frp_server_status` (`org_id`,`runtime_status`),
  KEY `idx_frp_server_auto_cluster` (`org_id`,`management_mode`,`cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_frp_tunnel` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `client_id` bigint unsigned NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `proxy_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tcp',
  `local_host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_service_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_service_namespace` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_service_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `local_port` int unsigned NOT NULL,
  `remote_port` int unsigned NOT NULL DEFAULT '0',
  `custom_domains` json DEFAULT NULL,
  `locations` json DEFAULT NULL,
  `host_header_rewrite` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `transport_encryption` tinyint unsigned NOT NULL DEFAULT '1',
  `transport_compression` tinyint unsigned NOT NULL DEFAULT '0',
  `bandwidth_limit` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_frp_tunnel_client_proxy` (`client_id`,`proxy_name`),
  KEY `idx_frp_tunnel_org` (`org_id`,`id`),
  KEY `idx_frp_tunnel_client_enabled` (`client_id`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_gateway_vhost` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `vhost_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 of hostname + NUL + path_prefix',
  `hostname` varchar(253) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path_prefix` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '/',
  `path_match` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'prefix',
  `methods` json DEFAULT NULL,
  `target_service` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Docker Swarm service name',
  `target_port` int unsigned NOT NULL,
  `upstream_scheme` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'http',
  `pass_host_header` tinyint unsigned NOT NULL DEFAULT '1',
  `entrypoint` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `tls_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `certificate_id` bigint unsigned DEFAULT NULL,
  `https_redirect` tinyint unsigned NOT NULL DEFAULT '0',
  `rewrite_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'none, strip_prefix, replace_path_regex',
  `rewrite_pattern` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rewrite_replacement` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_allowlist` json DEFAULT NULL,
  `ip_denylist` json DEFAULT NULL,
  `rate_limit_average` int unsigned NOT NULL DEFAULT '0',
  `rate_limit_burst` int unsigned NOT NULL DEFAULT '0',
  `rate_limit_period_seconds` int unsigned NOT NULL DEFAULT '1',
  `max_inflight_requests` int unsigned NOT NULL DEFAULT '0',
  `retry_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `retry_initial_interval_ms` int unsigned NOT NULL DEFAULT '100',
  `dial_timeout_ms` int unsigned NOT NULL DEFAULT '30000',
  `response_header_timeout_ms` int unsigned NOT NULL DEFAULT '0',
  `idle_connection_timeout_ms` int unsigned NOT NULL DEFAULT '90000',
  `security_headers_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `compress_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `request_body_limit_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `custom_request_headers` json DEFAULT NULL,
  `custom_response_headers` json DEFAULT NULL,
  `cors_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `cors_allow_origins` json DEFAULT NULL,
  `cors_allow_methods` json DEFAULT NULL,
  `cors_allow_headers` json DEFAULT NULL,
  `cors_allow_credentials` tinyint unsigned NOT NULL DEFAULT '0',
  `cors_max_age_seconds` int unsigned NOT NULL DEFAULT '600',
  `circuit_breaker_expression` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `healthcheck_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `healthcheck_interval_ms` int unsigned NOT NULL DEFAULT '10000',
  `healthcheck_timeout_ms` int unsigned NOT NULL DEFAULT '3000',
  `sticky_cookie_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `sticky_cookie_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cg_session',
  `priority` int unsigned NOT NULL DEFAULT '0',
  `enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error` text COLLATE utf8mb4_unicode_ci,
  `synced_at` int unsigned NOT NULL DEFAULT '0',
  `creator` bigint unsigned NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cluster_vhost` (`cluster_id`,`vhost_key`),
  KEY `idx_org_cluster` (`org_id`,`cluster_id`),
  KEY `idx_gateway_vhost_status` (`cluster_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_gateway_vhost_rewrite` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vhost_id` bigint unsigned NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `rewrite_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `rewrite_pattern` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rewrite_replacement` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_vhost_rewrite_order` (`vhost_id`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_git_auth` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `vendor` bigint NOT NULL DEFAULT '0',
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `token` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_git_auth_user_vendor_endpoint` (`uid`,`vendor`,`domain`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_group` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `desc` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_group_org_alias` (`org_id`,`alias`),
  KEY `idx_group_org_creator` (`org_id`,`creator`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_group_member` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `group_id` bigint NOT NULL DEFAULT '0',
  `uid` bigint NOT NULL DEFAULT '0',
  `role` bigint NOT NULL DEFAULT '0',
  `join_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_group_member_scope_uid` (`org_id`,`group_id`,`uid`),
  KEY `idx_group_member_uid_role` (`uid`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_group_resource_grant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `resource_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` bigint unsigned NOT NULL,
  `granted_by` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_group_resource` (`org_id`,`group_id`,`resource_type`,`resource_id`),
  KEY `idx_resource_groups` (`org_id`,`resource_type`,`resource_id`,`group_id`),
  KEY `idx_group_resources` (`org_id`,`group_id`,`resource_type`,`resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_group_ssh_key` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `pubkey` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `privatekey_encrypted` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `algo` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ed25519',
  `generate_at` bigint unsigned NOT NULL DEFAULT '0',
  `updated_by` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_ssh_key` (`org_id`,`group_id`),
  KEY `idx_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_kubernetes_cluster_connection` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cluster_id` bigint unsigned NOT NULL,
  `server_url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `context_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cluster_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `default_namespace` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `ingress_http_port` smallint unsigned NOT NULL DEFAULT '80',
  `ingress_https_port` smallint unsigned NOT NULL DEFAULT '443',
  `credential_ciphertext` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `credential_fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_checked_at` int unsigned NOT NULL DEFAULT '0',
  `last_error` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kubernetes_connection_cluster` (`cluster_id`),
  KEY `idx_kubernetes_connection_status` (`status`,`last_checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_kubernetes_resource_snapshot` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `resource_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_uid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `namespace` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `node_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `workload_kind` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `workload_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phase` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `container_count` int unsigned NOT NULL DEFAULT '0',
  `cpu_percent` decimal(12,3) NOT NULL DEFAULT '0.000',
  `memory_usage` bigint unsigned NOT NULL DEFAULT '0',
  `memory_limit` bigint unsigned NOT NULL DEFAULT '0',
  `metric_available` tinyint(1) NOT NULL DEFAULT '0',
  `collected_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_k8s_resource_identity` (`cluster_id`,`resource_type`,`resource_uid`),
  KEY `idx_k8s_resource_scope` (`org_id`,`resource_type`,`collected_at`),
  KEY `idx_k8s_resource_cluster_namespace` (`cluster_id`,`namespace`),
  KEY `idx_k8s_resource_workload` (`cluster_id`,`namespace`,`workload_kind`,`workload_name`),
  KEY `idx_k8s_resource_node` (`cluster_id`,`node_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_log_sms_code` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `type` bigint NOT NULL DEFAULT '0',
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` bigint NOT NULL DEFAULT '0',
  `send_at` bigint NOT NULL DEFAULT '0',
  `expire_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_log_user_login` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `login_at` bigint NOT NULL DEFAULT '0',
  `status` bigint NOT NULL DEFAULT '0',
  `result` bigint NOT NULL DEFAULT '0',
  `channel` bigint NOT NULL DEFAULT '0',
  `ua` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `platform` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_managed_domain` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `org_id` int unsigned NOT NULL,
  `hostname` varchar(253) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allow_subdomains` tinyint unsigned NOT NULL DEFAULT '0',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` int unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_hostname` (`org_id`,`hostname`),
  KEY `idx_org_created` (`org_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_notify` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `scene` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `context` longtext COLLATE utf8mb4_unicode_ci,
  `read_at` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_object_storage_bucket` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '显示名称',
  `provider` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'cos|oss|s3',
  `cloud_account_id` int unsigned NOT NULL DEFAULT '0',
  `config` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'encrypted JSON credentials',
  `bucket` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `region` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `base_url` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_default` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Galaxy system files default bucket',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_storage_provider_bucket` (`org_id`,`provider`,`bucket`),
  KEY `idx_obj_bucket_org` (`org_id`),
  KEY `idx_obj_bucket_org_default` (`org_id`,`is_default`),
  KEY `idx_storage_cloud_account` (`org_id`,`cloud_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_object_storage_file` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `bucket_id` bigint unsigned NOT NULL,
  `filepath` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `size` bigint unsigned NOT NULL DEFAULT '0',
  `mime_type` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_obj_file_org` (`org_id`),
  KEY `idx_obj_file_bucket` (`bucket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_org` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` bigint NOT NULL DEFAULT '0',
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `realname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `desc` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  `status` bigint NOT NULL DEFAULT '0',
  `next_workcode` bigint NOT NULL DEFAULT '0',
  `registry_addr` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `registry_username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `registry_password` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `build_timeout` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_alias` (`alias`),
  KEY `idx_org_creator_status` (`creator`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_org_member` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `uid` bigint NOT NULL DEFAULT '0',
  `role` bigint NOT NULL DEFAULT '0',
  `realname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `workcode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `join_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_org_member_org_uid` (`org_id`,`uid`),
  KEY `idx_org_member_uid_role` (`uid`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_pipeline` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `group_id` bigint NOT NULL DEFAULT '0',
  `project_id` bigint NOT NULL DEFAULT '0',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `yml` longtext COLLATE utf8mb4_unicode_ci,
  `schema_version` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v1',
  `definition` json DEFAULT NULL,
  `runner_kind` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'buildkit',
  `cluster_id` bigint unsigned NOT NULL DEFAULT '0',
  `version` bigint NOT NULL DEFAULT '0',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  `archived_at` int unsigned NOT NULL DEFAULT '0',
  `is_default` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_pipeline_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_pipeline_scope_type` (`org_id`,`group_id`,`project_id`),
  KEY `idx_pipeline_cluster` (`cluster_id`),
  KEY `idx_pipeline_active` (`project_id`,`archived_at`),
  KEY `idx_pipeline_default` (`project_id`,`is_default`,`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_pipeline_githook` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `group_id` bigint NOT NULL DEFAULT '0',
  `project_id` bigint NOT NULL DEFAULT '0',
  `pipeline_id` bigint NOT NULL DEFAULT '0',
  `branch` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` bigint NOT NULL DEFAULT '0',
  `auto_deploy` bigint NOT NULL DEFAULT '0',
  `auto_deploy_target` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pipeline_hook_branch` (`project_id`,`pipeline_id`,`branch`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_pipeline_id` (`pipeline_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_pipeline_secret` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `pipeline_id` bigint unsigned NOT NULL,
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `encrypted_value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_size` int unsigned NOT NULL DEFAULT '0',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pipeline_secret_name` (`pipeline_id`,`name`),
  KEY `idx_pipeline_secret_scope` (`org_id`,`group_id`,`project_id`,`pipeline_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `group_id` bigint NOT NULL DEFAULT '0',
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `desc` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `develop` bigint NOT NULL DEFAULT '0',
  `build_cluster_id` bigint unsigned NOT NULL DEFAULT '0',
  `default_port` bigint NOT NULL DEFAULT '0',
  `image_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_group_title` (`org_id`,`group_id`,`title`),
  UNIQUE KEY `uk_project_org_alias` (`org_id`,`alias`),
  UNIQUE KEY `uk_project_org_image_name` (`org_id`,`image_name`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_project_scope_alias` (`org_id`,`group_id`,`alias`),
  KEY `idx_project_scope_creator` (`org_id`,`group_id`,`creator`),
  KEY `idx_build_cluster_id` (`build_cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_alert` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `runtime_id` bigint unsigned NOT NULL,
  `rule_id` bigint unsigned NOT NULL,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'warning',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `context` json DEFAULT NULL,
  `occurrences` int unsigned NOT NULL DEFAULT '1',
  `first_seen_at` int unsigned NOT NULL DEFAULT '0',
  `last_seen_at` int unsigned NOT NULL DEFAULT '0',
  `notified_at` int unsigned NOT NULL DEFAULT '0',
  `resolved_at` int unsigned NOT NULL DEFAULT '0',
  `notification_error` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_alert_fingerprint` (`fingerprint`),
  KEY `idx_alert_scope` (`org_id`,`group_id`,`project_id`,`status`),
  KEY `idx_alert_runtime` (`runtime_id`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_alert_rule` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `recipients` json DEFAULT NULL,
  `failure_threshold` int unsigned NOT NULL DEFAULT '2',
  `cooldown_seconds` int unsigned NOT NULL DEFAULT '1800',
  `notify_recovery` tinyint unsigned NOT NULL DEFAULT '1',
  `slo_availability_target` decimal(6,3) unsigned NOT NULL DEFAULT '99.900',
  `slo_error_rate_max` decimal(6,3) unsigned NOT NULL DEFAULT '1.000',
  `slo_p95_ms_max` int unsigned NOT NULL DEFAULT '500',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_alert_rule` (`org_id`,`group_id`,`project_id`),
  KEY `idx_alert_rule_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `uid` bigint unsigned NOT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `controller` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `http_status` smallint unsigned NOT NULL DEFAULT '200',
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `ip` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `metadata` json DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_project_audit_scope` (`org_id`,`group_id`,`project_id`,`created_at`),
  KEY `idx_project_audit_actor` (`uid`,`created_at`),
  KEY `idx_project_audit_action` (`project_id`,`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_build_profile` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `dockerfile_source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'repository',
  `build_context` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '.',
  `repository_dockerfile_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dockerfile',
  `template_id` bigint unsigned NOT NULL DEFAULT '0',
  `template_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `template_version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `options` json NOT NULL,
  `build_args` json NOT NULL,
  `rendered_dockerfile` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `rendered_checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rendered_dockerignore` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `dockerignore_profile` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default-secure-v1',
  `dockerignore_checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `renderer_version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1',
  `created_by` bigint unsigned NOT NULL DEFAULT '0',
  `updated_by` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` bigint unsigned NOT NULL DEFAULT '0',
  `updated_at` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_build_profile` (`org_id`,`group_id`,`project_id`),
  KEY `idx_project_build_profile_template` (`template_key`,`template_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_build_profile_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `revision` int unsigned NOT NULL,
  `profile` json NOT NULL,
  `profile_checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'save',
  `created_by` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_build_profile_revision` (`org_id`,`group_id`,`project_id`,`revision`),
  KEY `idx_project_build_profile_revision_time` (`project_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_configuration` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `env_id` bigint unsigned NOT NULL DEFAULT '0',
  `kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plain_value` mediumtext COLLATE utf8mb4_unicode_ci,
  `encrypted_value` mediumtext COLLATE utf8mb4_unicode_ci,
  `value_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `value_size` int unsigned NOT NULL DEFAULT '0',
  `target` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_mode` int unsigned NOT NULL DEFAULT '292',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `version` int unsigned NOT NULL DEFAULT '1',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_configuration_identity` (`project_id`,`env_id`,`kind`,`name`),
  KEY `idx_project_configuration_scope` (`org_id`,`group_id`,`project_id`,`env_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_member` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `group_id` bigint NOT NULL DEFAULT '0',
  `uid` bigint NOT NULL DEFAULT '0',
  `project_id` bigint NOT NULL DEFAULT '0',
  `role` bigint NOT NULL DEFAULT '0',
  `join_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_project_member_scope_uid` (`org_id`,`group_id`,`project_id`,`uid`),
  KEY `idx_project_member_uid_role` (`uid`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_registry_rel` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `registry_id` bigint unsigned NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_registry` (`project_id`,`registry_id`),
  KEY `idx_registry_project` (`org_id`,`registry_id`,`project_id`),
  KEY `idx_project_scope` (`org_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_release` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `env_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `artifact_id` bigint unsigned NOT NULL,
  `version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `operation` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'deploy',
  `previous_release_id` bigint unsigned NOT NULL DEFAULT '0',
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `desired_spec` json DEFAULT NULL,
  `runtime_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `error` text COLLATE utf8mb4_unicode_ci,
  `result` json DEFAULT NULL,
  `started_at` int unsigned NOT NULL DEFAULT '0',
  `finished_at` int unsigned NOT NULL DEFAULT '0',
  `creator` bigint unsigned NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_release_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_release_target` (`project_id`,`env_id`,`cluster_id`),
  KEY `idx_artifact_id` (`artifact_id`),
  KEY `idx_status` (`status`),
  KEY `idx_previous_release_id` (`previous_release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_release_config` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `release_id` bigint unsigned NOT NULL,
  `source_configuration_id` bigint unsigned NOT NULL DEFAULT '0',
  `source_version` int unsigned NOT NULL DEFAULT '0',
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_size` int unsigned NOT NULL DEFAULT '0',
  `file_mode` int unsigned NOT NULL DEFAULT '292',
  `docker_config_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `docker_config_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_release_config_name` (`release_id`,`name`),
  KEY `idx_release_config_scope` (`org_id`,`project_id`,`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_release_secret` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `release_id` bigint unsigned NOT NULL,
  `source_configuration_id` bigint unsigned NOT NULL DEFAULT '0',
  `source_version` int unsigned NOT NULL DEFAULT '0',
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `encrypted_value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_size` int unsigned NOT NULL DEFAULT '0',
  `file_mode` int unsigned NOT NULL DEFAULT '288',
  `docker_secret_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `docker_secret_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_release_secret_name` (`release_id`,`name`),
  KEY `idx_secret_scope` (`org_id`,`project_id`,`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_repository` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `type` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '0 managed, 2 external',
  `provider` smallint unsigned NOT NULL DEFAULT '0',
  `clone_url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `web_url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `default_branch` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `connection_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `check_error` text COLLATE utf8mb4_unicode_ci,
  `checked_at` int unsigned NOT NULL DEFAULT '0',
  `webhook_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `webhook_secret_encrypted` text COLLATE utf8mb4_unicode_ci,
  `webhook_secret_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `webhook_checked_at` int unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_id` (`project_id`),
  KEY `idx_repository_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_retention_policy` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `metric_days` smallint unsigned NOT NULL DEFAULT '30',
  `event_days` smallint unsigned NOT NULL DEFAULT '90',
  `resolved_alert_days` smallint unsigned NOT NULL DEFAULT '180',
  `build_log_days` smallint unsigned NOT NULL DEFAULT '90',
  `audit_days` smallint unsigned NOT NULL DEFAULT '365',
  `last_purged_at` int unsigned NOT NULL DEFAULT '0',
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_retention_policy` (`org_id`,`group_id`,`project_id`),
  KEY `idx_retention_enabled` (`enabled`,`last_purged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_route` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `runtime_id` bigint unsigned NOT NULL,
  `env_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `orchestrator_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'docker_swarm',
  `route_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hostname` varchar(253) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path_prefix` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '/',
  `path_match` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'prefix',
  `methods` json DEFAULT NULL,
  `target_port` int unsigned NOT NULL,
  `upstream_scheme` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'http',
  `pass_host_header` tinyint unsigned NOT NULL DEFAULT '1',
  `entrypoint` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `tls_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `cert_resolver` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `certificate_id` bigint unsigned NOT NULL DEFAULT '0',
  `https_redirect` tinyint unsigned NOT NULL DEFAULT '0',
  `https_redirect_port` smallint unsigned NOT NULL DEFAULT '443',
  `rewrite_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `rewrite_pattern` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rewrite_replacement` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_allowlist` json DEFAULT NULL,
  `ip_denylist` json DEFAULT NULL,
  `rate_limit_average` int unsigned NOT NULL DEFAULT '0',
  `rate_limit_burst` int unsigned NOT NULL DEFAULT '0',
  `rate_limit_period_seconds` int unsigned NOT NULL DEFAULT '1',
  `max_inflight_requests` int unsigned NOT NULL DEFAULT '0',
  `retry_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `retry_initial_interval_ms` int unsigned NOT NULL DEFAULT '100',
  `dial_timeout_ms` int unsigned NOT NULL DEFAULT '30000',
  `response_header_timeout_ms` int unsigned NOT NULL DEFAULT '0',
  `idle_connection_timeout_ms` int unsigned NOT NULL DEFAULT '90000',
  `security_headers_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `compress_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `request_body_limit_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `custom_request_headers` json DEFAULT NULL,
  `custom_response_headers` json DEFAULT NULL,
  `cors_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `cors_allow_origins` json DEFAULT NULL,
  `cors_allow_methods` json DEFAULT NULL,
  `cors_allow_headers` json DEFAULT NULL,
  `cors_allow_credentials` tinyint unsigned NOT NULL DEFAULT '0',
  `cors_max_age_seconds` int unsigned NOT NULL DEFAULT '600',
  `circuit_breaker_expression` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `healthcheck_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `healthcheck_interval_ms` int unsigned NOT NULL DEFAULT '10000',
  `healthcheck_timeout_ms` int unsigned NOT NULL DEFAULT '3000',
  `sticky_cookie_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `sticky_cookie_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cg_session',
  `network_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `network_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_metadata` json DEFAULT NULL,
  `priority` int unsigned NOT NULL DEFAULT '0',
  `enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error` text COLLATE utf8mb4_unicode_ci,
  `creator` bigint unsigned NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  `synced_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cluster_route` (`cluster_id`,`route_key`),
  KEY `idx_route_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_route_runtime` (`runtime_id`,`enabled`),
  KEY `idx_route_hostname` (`hostname`),
  KEY `idx_route_status` (`status`),
  KEY `idx_project_route_certificate` (`certificate_id`),
  KEY `idx_project_route_provider` (`orchestrator_type`,`cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_runtime` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `env_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `orchestrator_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'docker_swarm',
  `workload_kind` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'service',
  `release_id` bigint unsigned NOT NULL DEFAULT '0',
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `runtime_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `runtime_namespace` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `desired_count` int unsigned NOT NULL DEFAULT '1',
  `running_count` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `health` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `last_synced_at` int unsigned NOT NULL DEFAULT '0',
  `spec` json DEFAULT NULL,
  `provider_metadata` json DEFAULT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_runtime_cluster_service_name` (`cluster_id`,`service_name`),
  UNIQUE KEY `uk_runtime_project_name` (`project_id`,`name`),
  KEY `idx_runtime_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_cluster_status` (`cluster_id`,`status`),
  KEY `idx_release_id` (`release_id`),
  KEY `idx_runtime_provider` (`orchestrator_type`,`workload_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_runtime_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `runtime_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `attributes` json DEFAULT NULL,
  `occurred_at` int unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_runtime_event` (`fingerprint`),
  KEY `idx_runtime_event_scope` (`org_id`,`group_id`,`project_id`,`occurred_at`),
  KEY `idx_runtime_event_runtime` (`runtime_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_project_runtime_metric` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `runtime_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `collected_at` int unsigned NOT NULL,
  `cpu_percent` decimal(10,2) NOT NULL DEFAULT '0.00',
  `memory_usage` bigint unsigned NOT NULL DEFAULT '0',
  `memory_limit` bigint unsigned NOT NULL DEFAULT '0',
  `network_rx` bigint unsigned NOT NULL DEFAULT '0',
  `network_tx` bigint unsigned NOT NULL DEFAULT '0',
  `disk_read` bigint unsigned NOT NULL DEFAULT '0',
  `disk_write` bigint unsigned NOT NULL DEFAULT '0',
  `disk_io_coverage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `pids` int unsigned NOT NULL DEFAULT '0',
  `desired_tasks` int unsigned NOT NULL DEFAULT '0',
  `running_tasks` int unsigned NOT NULL DEFAULT '0',
  `failed_tasks` int unsigned NOT NULL DEFAULT '0',
  `local_containers` int unsigned NOT NULL DEFAULT '0',
  `metric_coverage` decimal(5,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_runtime_minute` (`runtime_id`,`collected_at`),
  KEY `idx_metric_scope_time` (`org_id`,`group_id`,`project_id`,`collected_at`),
  KEY `idx_metric_runtime_time` (`runtime_id`,`collected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_registry` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint NOT NULL DEFAULT '0',
  `proto` bigint NOT NULL DEFAULT '0',
  `address` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `namespace` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_push` bigint NOT NULL DEFAULT '0',
  `creator` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_org_id` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_registry_group_grant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `registry_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `namespace` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `granted_by` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_registry_group` (`org_id`,`registry_id`,`group_id`),
  KEY `idx_group_registries` (`org_id`,`group_id`,`registry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_swarm_resource_snapshot` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `cluster_id` bigint unsigned NOT NULL,
  `resource_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_uid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `node_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phase` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `desired_tasks` int unsigned NOT NULL DEFAULT '0',
  `running_tasks` int unsigned NOT NULL DEFAULT '0',
  `container_count` int unsigned NOT NULL DEFAULT '0',
  `cpu_percent` decimal(12,3) NOT NULL DEFAULT '0.000',
  `memory_usage` bigint unsigned NOT NULL DEFAULT '0',
  `memory_limit` bigint unsigned NOT NULL DEFAULT '0',
  `network_rx` bigint unsigned NOT NULL DEFAULT '0',
  `network_tx` bigint unsigned NOT NULL DEFAULT '0',
  `disk_read` bigint unsigned NOT NULL DEFAULT '0',
  `disk_write` bigint unsigned NOT NULL DEFAULT '0',
  `network_rx_bps` decimal(20,2) NOT NULL DEFAULT '0.00',
  `network_tx_bps` decimal(20,2) NOT NULL DEFAULT '0.00',
  `disk_read_bps` decimal(20,2) NOT NULL DEFAULT '0.00',
  `disk_write_bps` decimal(20,2) NOT NULL DEFAULT '0.00',
  `metric_available` tinyint(1) NOT NULL DEFAULT '0',
  `rate_available` tinyint(1) NOT NULL DEFAULT '0',
  `collected_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_swarm_resource_identity` (`cluster_id`,`resource_type`,`resource_uid`),
  KEY `idx_swarm_resource_scope` (`org_id`,`resource_type`,`collected_at`),
  KEY `idx_swarm_resource_cluster_node` (`cluster_id`,`node_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_tls_certificate` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `provider_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `domains` json NOT NULL,
  `common_name` varchar(253) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `issuer` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `serial_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `fingerprint_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `signature_algorithm` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `key_algorithm` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `key_bits` smallint unsigned NOT NULL DEFAULT '0',
  `certificate_ciphertext` mediumtext COLLATE utf8mb4_unicode_ci,
  `private_key_ciphertext` mediumtext COLLATE utf8mb4_unicode_ci,
  `valid_from` int unsigned NOT NULL DEFAULT '0',
  `valid_to` int unsigned NOT NULL DEFAULT '0',
  `auto_renew` tinyint unsigned NOT NULL DEFAULT '0',
  `renew_before_days` smallint unsigned NOT NULL DEFAULT '30',
  `last_renewed_at` int unsigned NOT NULL DEFAULT '0',
  `next_renew_at` int unsigned NOT NULL DEFAULT '0',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `creator` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tls_certificate_title` (`org_id`,`title`),
  KEY `idx_tls_certificate_status` (`org_id`,`status`,`valid_to`),
  KEY `idx_tls_certificate_fingerprint` (`org_id`,`fingerprint_sha256`),
  KEY `idx_tls_certificate_renewal` (`auto_renew`,`next_renew_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `auth_status` bigint NOT NULL DEFAULT '0',
  `identity` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` bigint NOT NULL DEFAULT '0',
  `last_login` bigint NOT NULL DEFAULT '0',
  `last_org` bigint NOT NULL DEFAULT '0',
  `register_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_user_email` (`email`(191)),
  KEY `idx_user_phone` (`phone`(64)),
  KEY `idx_user_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_user_cli_info` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `hostid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cli_version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cli_ip` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `os` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `platform` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `platform_family` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `platform_version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `kernel_version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `kernel_arch` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cpu_model` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cpu_count` bigint NOT NULL DEFAULT '0',
  `status` bigint NOT NULL DEFAULT '0',
  `created_at` bigint NOT NULL DEFAULT '0',
  `updated_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_user_notify` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `official_account` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_user_personal_ssh_key` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `pubkey` longtext COLLATE utf8mb4_unicode_ci,
  `fingerprint` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_user_profile` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `nickname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `gender` bigint NOT NULL DEFAULT '0',
  `company` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `position` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `wechat` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `qq` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `introduce` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id_auth` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_user_ssh_key` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `pubkey` longtext COLLATE utf8mb4_unicode_ci,
  `privatekey` longtext COLLATE utf8mb4_unicode_ci,
  `generate_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_wechat_mp_qrcode` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint NOT NULL DEFAULT '0',
  `scene` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` bigint NOT NULL DEFAULT '0',
  `expired_at` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_workspace` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `uid` bigint unsigned NOT NULL,
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cluster_id` bigint unsigned NOT NULL DEFAULT '0',
  `mode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web-ide',
  `runtime_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `volume_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `image` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `published_port` int unsigned NOT NULL DEFAULT '0',
  `gateway_vhost_id` bigint unsigned NOT NULL DEFAULT '0',
  `access_secret` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error` text COLLATE utf8mb4_unicode_ci,
  `spec` json DEFAULT NULL,
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workspace_group_user` (`org_id`,`group_id`,`uid`),
  KEY `idx_cluster_id` (`cluster_id`),
  KEY `idx_status` (`status`),
  KEY `idx_workspace_gateway_vhost` (`gateway_vhost_id`),
  KEY `idx_workspace_org_user` (`org_id`,`uid`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `galaxy_workspace_project_repository` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint unsigned NOT NULL,
  `group_id` bigint unsigned NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `repository_id` bigint unsigned NOT NULL,
  `branch` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main',
  `workdir` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error` text COLLATE utf8mb4_unicode_ci,
  `last_commit` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_sync_at` int unsigned NOT NULL DEFAULT '0',
  `credential_transport` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `credential_revision` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL DEFAULT '0',
  `updated_at` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workspace_repository` (`workspace_id`,`repository_id`),
  UNIQUE KEY `uk_workspace_project` (`workspace_id`,`project_id`),
  UNIQUE KEY `uk_workspace_workdir` (`workspace_id`,`workdir`),
  KEY `idx_workspace_project_repository_scope` (`org_id`,`group_id`,`project_id`),
  KEY `idx_workspace_repository_status` (`workspace_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

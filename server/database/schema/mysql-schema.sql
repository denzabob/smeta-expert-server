/*!40101 SET NAMES utf8mb4 */;
/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `admin_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) unsigned NOT NULL,
  `target_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `result` varchar(20) NOT NULL DEFAULT 'success',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_audit_logs_target_user_id_created_at_index` (`target_user_id`,`created_at`),
  KEY `admin_audit_logs_admin_user_id_created_at_index` (`admin_user_id`,`created_at`),
  KEY `admin_audit_logs_action_created_at_index` (`action`,`created_at`),
  CONSTRAINT `admin_audit_logs_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admin_audit_logs_target_user_id_foreign` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `input_hash` char(32) NOT NULL,
  `model_name` varchar(100) NOT NULL,
  `provider_name` varchar(50) DEFAULT NULL,
  `fallback_used` tinyint(1) NOT NULL DEFAULT 0,
  `failover_chain` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`failover_chain`)),
  `prompt_tokens` int(10) unsigned DEFAULT NULL,
  `completion_tokens` int(10) unsigned DEFAULT NULL,
  `cost_usd` decimal(10,6) DEFAULT NULL,
  `latency_ms` int(10) unsigned NOT NULL,
  `is_successful` tinyint(1) NOT NULL DEFAULT 1,
  `error_message` text DEFAULT NULL,
  `error_type` varchar(30) DEFAULT NULL,
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_logs_created_at_is_successful_index` (`created_at`,`is_successful`),
  KEY `ai_logs_model_name_created_at_index` (`model_name`,`created_at`),
  KEY `ai_logs_input_hash_index` (`input_hash`),
  KEY `ai_logs_provider_name_created_at_index` (`provider_name`,`created_at`),
  KEY `ai_logs_error_type_created_at_index` (`error_type`,`created_at`),
  KEY `ai_logs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `ai_logs_user_id_provider_name_created_at_index` (`user_id`,`provider_name`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_settings_key_unique` (`key`),
  KEY `app_settings_key_index` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_verification_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_verification_challenges` (
  `id` char(36) NOT NULL,
  `purpose` varchar(30) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts_left` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `resend_available_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `verified_at` datetime DEFAULT NULL,
  `current_channel` varchar(30) DEFAULT NULL,
  `channel_attempt_order` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`channel_attempt_order`)),
  `provider_message_id` varchar(255) DEFAULT NULL,
  `call_phone` varchar(20) DEFAULT NULL,
  `call_phone_pretty` varchar(40) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `provider_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_payload`)),
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `auth_verification_challenges_user_id_foreign` (`user_id`),
  KEY `auth_verification_challenges_phone_purpose_status_index` (`phone`,`purpose`,`status`),
  KEY `auth_verification_challenges_status_expires_at_index` (`status`,`expires_at`),
  KEY `auth_verification_challenges_phone_index` (`phone`),
  KEY `auth_verification_challenges_provider_msg_idx` (`provider_message_id`),
  CONSTRAINT `auth_verification_challenges_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_gate_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_gate_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `plan_code` varchar(255) DEFAULT NULL,
  `capability` varchar(255) NOT NULL,
  `limit_value` int(11) DEFAULT NULL,
  `usage_value` int(11) NOT NULL DEFAULT 0,
  `would_block` tinyint(1) NOT NULL DEFAULT 0,
  `enforced` tinyint(1) NOT NULL DEFAULT 0,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_gate_events_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `billing_gate_events_capability_created_at_index` (`capability`,`created_at`),
  KEY `billing_gate_events_would_block_created_at_index` (`would_block`,`created_at`),
  KEY `billing_gate_events_plan_code_index` (`plan_code`),
  KEY `billing_gate_events_capability_index` (`capability`),
  KEY `billing_gate_events_would_block_index` (`would_block`),
  KEY `billing_gate_events_enforced_index` (`enforced`),
  CONSTRAINT `billing_gate_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `subscription_id` bigint(20) unsigned DEFAULT NULL,
  `plan_code` varchar(255) NOT NULL,
  `period_start` datetime DEFAULT NULL,
  `period_end` datetime DEFAULT NULL,
  `amount_minor` bigint(20) unsigned NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `status` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `paid_at` datetime DEFAULT NULL,
  `canceled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_invoices_uuid_unique` (`uuid`),
  KEY `billing_invoices_user_id_index` (`user_id`),
  KEY `billing_invoices_subscription_id_index` (`subscription_id`),
  KEY `billing_invoices_plan_code_index` (`plan_code`),
  KEY `billing_invoices_status_index` (`status`),
  CONSTRAINT `billing_invoices_subscription_id_foreign` FOREIGN KEY (`subscription_id`) REFERENCES `billing_subscriptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_invoices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_payment_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_code` varchar(255) NOT NULL,
  `provider_payment_method_id` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `card_last4` varchar(4) DEFAULT NULL,
  `card_type` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `provider_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_payment_methods_user_id_index` (`user_id`),
  KEY `billing_payment_methods_provider_code_index` (`provider_code`),
  KEY `billing_payment_methods_provider_payment_method_id_index` (`provider_payment_method_id`),
  KEY `billing_payment_methods_status_index` (`status`),
  CONSTRAINT `billing_payment_methods_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_code` varchar(255) NOT NULL,
  `provider_payment_id` varchar(255) DEFAULT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `amount_minor` bigint(20) unsigned NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `status` varchar(255) NOT NULL,
  `confirmation_type` varchar(255) DEFAULT NULL,
  `confirmation_url` text DEFAULT NULL,
  `confirmation_token` text DEFAULT NULL,
  `provider_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_payload`)),
  `error_code` varchar(255) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `succeeded_at` datetime DEFAULT NULL,
  `canceled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_payments_uuid_unique` (`uuid`),
  UNIQUE KEY `billing_payments_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `billing_payments_provider_payment_unique` (`provider_code`,`provider_payment_id`),
  KEY `billing_payments_invoice_id_index` (`invoice_id`),
  KEY `billing_payments_user_id_index` (`user_id`),
  KEY `billing_payments_provider_code_index` (`provider_code`),
  KEY `billing_payments_status_index` (`status`),
  CONSTRAINT `billing_payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `billing_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `features_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features_json`)),
  `limits_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`limits_json`)),
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_plans_code_unique` (`code`),
  KEY `billing_plans_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_provider_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_provider_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider_code` varchar(255) NOT NULL,
  `event_type` varchar(255) NOT NULL,
  `provider_object_id` varchar(255) DEFAULT NULL,
  `provider_payment_id` varchar(255) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `headers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`headers`)),
  `processing_status` varchar(255) NOT NULL,
  `processing_error` text DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_provider_events_provider_code_index` (`provider_code`),
  KEY `billing_provider_events_event_type_index` (`event_type`),
  KEY `billing_provider_events_provider_object_id_index` (`provider_object_id`),
  KEY `billing_provider_events_provider_payment_id_index` (`provider_payment_id`),
  KEY `billing_provider_events_processing_status_index` (`processing_status`),
  KEY `billing_provider_events_created_at_index` (`created_at`),
  KEY `billing_provider_events_provider_type_object_idx` (`provider_code`,`event_type`,`provider_object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_subscription_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_subscription_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `admin_user_id` bigint(20) unsigned DEFAULT NULL,
  `subscription_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(255) NOT NULL,
  `old_plan_code` varchar(255) DEFAULT NULL,
  `new_plan_code` varchar(255) DEFAULT NULL,
  `old_status` varchar(255) DEFAULT NULL,
  `new_status` varchar(255) DEFAULT NULL,
  `old_period_end` datetime DEFAULT NULL,
  `new_period_end` datetime DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `billing_subscription_events_user_id_index` (`user_id`),
  KEY `billing_subscription_events_admin_user_id_index` (`admin_user_id`),
  KEY `billing_subscription_events_subscription_id_index` (`subscription_id`),
  KEY `billing_subscription_events_created_at_index` (`created_at`),
  KEY `billing_subscription_events_event_type_index` (`event_type`),
  CONSTRAINT `billing_subscription_events_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_subscription_events_subscription_id_foreign` FOREIGN KEY (`subscription_id`) REFERENCES `billing_subscriptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_subscription_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `plan_code` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `source` varchar(255) NOT NULL DEFAULT 'hidden',
  `current_period_start` datetime DEFAULT NULL,
  `current_period_end` datetime DEFAULT NULL,
  `trial_ends_at` datetime DEFAULT NULL,
  `overrides_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`overrides_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_subscriptions_user_id_index` (`user_id`),
  KEY `billing_subscriptions_plan_id_index` (`plan_id`),
  KEY `billing_subscriptions_plan_code_index` (`plan_code`),
  KEY `billing_subscriptions_status_index` (`status`),
  CONSTRAINT `billing_subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `billing_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `billing_subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `disk` varchar(20) NOT NULL DEFAULT 'local',
  `path` text NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `size` int(10) unsigned NOT NULL,
  `width` smallint(5) unsigned DEFAULT NULL,
  `height` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_attachments_message_id_index` (`message_id`),
  CONSTRAINT `chat_attachments_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_admin_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `subject` varchar(255) DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_conv_status_last_msg_idx` (`status`,`last_message_at`),
  KEY `chat_conv_admin_status_last_msg_idx` (`assigned_admin_id`,`status`,`last_message_at`),
  KEY `chat_conv_user_status_idx` (`created_by_user_id`,`status`),
  CONSTRAINT `chat_conversations_assigned_admin_id_foreign` FOREIGN KEY (`assigned_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_conversations_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `sender_id` bigint(20) unsigned DEFAULT NULL,
  `sender_role` varchar(30) NOT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'text',
  `body` text DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_msg_conv_created_idx` (`conversation_id`,`created_at`),
  KEY `chat_messages_sender_id_index` (`sender_id`),
  CONSTRAINT `chat_messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(30) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `left_at` timestamp NULL DEFAULT NULL,
  `last_read_message_id` bigint(20) unsigned DEFAULT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_part_conv_user_uniq` (`conversation_id`,`user_id`),
  KEY `chat_participants_conversation_id_index` (`conversation_id`),
  KEY `chat_participants_user_id_index` (`user_id`),
  CONSTRAINT `chat_participants_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chrome_ext_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chrome_ext_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `url` varchar(2048) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `action` enum('capture','save_template','extract','error') NOT NULL,
  `status` enum('success','partial','failed') NOT NULL,
  `extracted_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extracted_fields`)),
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`errors`)),
  `template_id` bigint(20) unsigned DEFAULT NULL,
  `material_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chrome_ext_logs_template_id_foreign` (`template_id`),
  KEY `chrome_ext_logs_material_id_foreign` (`material_id`),
  KEY `chrome_ext_logs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `chrome_ext_logs_domain_created_at_index` (`domain`,`created_at`),
  CONSTRAINT `chrome_ext_logs_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chrome_ext_logs_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `parser_supplier_collect_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chrome_ext_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `detail_type_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `detail_type_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `detail_type_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `quantity_formula` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `detail_type_operations_detail_type_id_foreign` (`detail_type_id`),
  KEY `detail_type_operations_operation_id_foreign` (`operation_id`),
  CONSTRAINT `detail_type_operations_detail_type_id_foreign` FOREIGN KEY (`detail_type_id`) REFERENCES `detail_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detail_type_operations_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `detail_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `detail_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `edge_processing` enum('none','O','=','||','L','П','long_one','short_one') NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `origin` varchar(255) NOT NULL DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `components` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`components`)),
  PRIMARY KEY (`id`),
  KEY `detail_types_user_id_foreign` (`user_id`),
  CONSTRAINT `detail_types_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estimate_evidence_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `estimate_evidence_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `evidence_run_id` bigint(20) unsigned NOT NULL,
  `cost_component` varchar(40) NOT NULL,
  `label` varchar(500) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `resolution_type` varchar(40) DEFAULT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `evidence_record_id` bigint(20) unsigned DEFAULT NULL,
  `source_url` varchar(255) DEFAULT NULL,
  `effective_value` decimal(14,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT NULL,
  `diagnostics_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`diagnostics_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `estimate_evidence_items_uuid_unique` (`uuid`),
  KEY `estimate_evidence_items_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `estimate_evidence_items_evidence_record_id_foreign` (`evidence_record_id`),
  KEY `estimate_evidence_items_evidence_run_id_status_index` (`evidence_run_id`,`status`),
  KEY `estimate_evidence_items_cost_component_index` (`cost_component`),
  CONSTRAINT `estimate_evidence_items_evidence_record_id_foreign` FOREIGN KEY (`evidence_record_id`) REFERENCES `evidence_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimate_evidence_items_evidence_run_id_foreign` FOREIGN KEY (`evidence_run_id`) REFERENCES `estimate_evidence_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estimate_evidence_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `estimate_evidence_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `initiated_by` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `total_items` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_items` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_items` int(10) unsigned NOT NULL DEFAULT 0,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot_json`)),
  `started_at` timestamp NULL DEFAULT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `estimate_evidence_runs_uuid_unique` (`uuid`),
  KEY `estimate_evidence_runs_initiated_by_foreign` (`initiated_by`),
  KEY `estimate_evidence_runs_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `estimate_evidence_runs_initiated_by_foreign` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimate_evidence_runs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evidence_artifacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evidence_artifacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `material_id` bigint(20) unsigned DEFAULT NULL,
  `revision_run_id` bigint(20) unsigned DEFAULT NULL,
  `revision_run_item_id` bigint(20) unsigned DEFAULT NULL,
  `mode` enum('auto','manual') NOT NULL,
  `capture_source` varchar(20) DEFAULT NULL,
  `cost_driver_type` varchar(20) DEFAULT NULL,
  `source_url_raw` text DEFAULT NULL,
  `source_url_normalized` varchar(2048) DEFAULT NULL,
  `source_domain` varchar(255) DEFAULT NULL,
  `page_type` varchar(64) DEFAULT NULL,
  `block_type` varchar(64) DEFAULT NULL,
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `parser_profile_id` bigint(20) unsigned DEFAULT NULL,
  `parser_version` varchar(64) DEFAULT NULL,
  `extracted_price` decimal(12,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT NULL,
  `extracted_name` varchar(1024) DEFAULT NULL,
  `extracted_article` varchar(255) DEFAULT NULL,
  `screenshot_path` varchar(255) DEFAULT NULL,
  `screenshot_sha256` varchar(64) DEFAULT NULL,
  `html_sha256` varchar(64) DEFAULT NULL,
  `viewport_w` smallint(5) unsigned DEFAULT NULL,
  `viewport_h` smallint(5) unsigned DEFAULT NULL,
  `user_agent_hash` varchar(64) DEFAULT NULL,
  `confidence_score` tinyint(3) unsigned DEFAULT NULL,
  `trust_score` tinyint(3) unsigned DEFAULT NULL,
  `reason_code` varchar(64) DEFAULT NULL,
  `reason_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reason_details_json`)),
  `captured_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `evidence_artifacts_uuid_unique` (`uuid`),
  KEY `evidence_artifacts_revision_run_id_foreign` (`revision_run_id`),
  KEY `evidence_artifacts_revision_run_item_id_foreign` (`revision_run_item_id`),
  KEY `evidence_artifacts_parser_profile_id_foreign` (`parser_profile_id`),
  KEY `evidence_artifacts_created_by_foreign` (`created_by`),
  KEY `evidence_artifacts_material_mode_idx` (`material_id`,`mode`),
  KEY `evidence_artifacts_domain_idx` (`source_domain`),
  KEY `evidence_artifacts_reason_idx` (`reason_code`),
  KEY `evidence_artifacts_norm_url_idx` (`source_url_normalized`(768)),
  CONSTRAINT `evidence_artifacts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `evidence_artifacts_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `evidence_artifacts_parser_profile_id_foreign` FOREIGN KEY (`parser_profile_id`) REFERENCES `parser_supplier_collect_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `evidence_artifacts_revision_run_id_foreign` FOREIGN KEY (`revision_run_id`) REFERENCES `revision_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `evidence_artifacts_revision_run_item_id_foreign` FOREIGN KEY (`revision_run_item_id`) REFERENCES `revision_run_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evidence_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evidence_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `evidence_artifact_id` bigint(20) unsigned NOT NULL,
  `asset_type` varchar(30) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `sha256` varchar(64) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `evidence_assets_uuid_unique` (`uuid`),
  KEY `evidence_assets_artifact_idx` (`evidence_artifact_id`),
  KEY `evidence_assets_type_idx` (`asset_type`),
  CONSTRAINT `evidence_assets_evidence_artifact_id_foreign` FOREIGN KEY (`evidence_artifact_id`) REFERENCES `evidence_artifacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evidence_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evidence_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `evidence_record_id` bigint(20) unsigned NOT NULL,
  `linkable_type` varchar(255) NOT NULL,
  `linkable_id` bigint(20) unsigned NOT NULL,
  `relation_type` varchar(40) NOT NULL DEFAULT 'primary',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `evidence_links_unique` (`evidence_record_id`,`linkable_type`,`linkable_id`),
  KEY `evidence_links_linkable_type_linkable_id_index` (`linkable_type`,`linkable_id`),
  CONSTRAINT `evidence_links_evidence_record_id_foreign` FOREIGN KEY (`evidence_record_id`) REFERENCES `evidence_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evidence_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evidence_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `cost_component` varchar(40) NOT NULL,
  `source_type` varchar(40) NOT NULL,
  `capture_method` varchar(40) NOT NULL,
  `verification_status` varchar(40) NOT NULL DEFAULT 'pending',
  `source_url` varchar(255) DEFAULT NULL,
  `source_domain` varchar(255) DEFAULT NULL,
  `observed_price` decimal(12,2) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'RUB',
  `observed_at` timestamp NULL DEFAULT NULL,
  `extracted_name` varchar(255) DEFAULT NULL,
  `extracted_article` varchar(255) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `confidence_score` smallint(5) unsigned DEFAULT NULL,
  `trust_score` smallint(5) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `evidence_records_uuid_unique` (`uuid`),
  KEY `evidence_records_created_by_foreign` (`created_by`),
  KEY `evidence_records_cost_component_index` (`cost_component`),
  KEY `evidence_records_verification_status_index` (`verification_status`),
  CONSTRAINT `evidence_records_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_currency` varchar(3) NOT NULL,
  `to_currency` varchar(3) NOT NULL,
  `rate` decimal(18,6) NOT NULL,
  `rate_date` date NOT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual' COMMENT 'cbr, ecb, manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exchange_rates_from_currency_to_currency_rate_date_unique` (`from_currency`,`to_currency`,`rate_date`),
  KEY `exchange_rates_from_currency_index` (`from_currency`),
  KEY `exchange_rates_to_currency_index` (`to_currency`),
  KEY `exchange_rates_rate_date_index` (`rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `origin` varchar(255) NOT NULL DEFAULT 'user',
  PRIMARY KEY (`id`),
  KEY `expenses_project_id_foreign` (`project_id`),
  KEY `expenses_user_id_origin_index` (`user_id`,`origin`),
  CONSTRAINT `expenses_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expenses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feature_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `feature_entitlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(40) NOT NULL DEFAULT 'user',
  `owner_id` bigint(20) unsigned NOT NULL,
  `feature_code` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `source` varchar(40) NOT NULL DEFAULT 'plan',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `feature_entitlements_owner_type_owner_id_index` (`owner_type`,`owner_id`),
  KEY `feature_entitlements_feature_code_index` (`feature_code`),
  KEY `feature_entitlements_starts_at_index` (`starts_at`),
  KEY `feature_entitlements_ends_at_index` (`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finished_product_aggregation_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finished_product_aggregation_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `finished_product_specification_id` bigint(20) unsigned DEFAULT NULL,
  `finished_product_material_id` bigint(20) unsigned DEFAULT NULL,
  `method` varchar(30) NOT NULL DEFAULT 'median',
  `include_only_active` tinyint(1) NOT NULL DEFAULT 1,
  `exclude_stale` tinyint(1) NOT NULL DEFAULT 1,
  `minimum_sources_count` int(10) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fp_aggregation_profiles_material_unique` (`finished_product_material_id`),
  UNIQUE KEY `fp_aggregation_profiles_spec_unique` (`finished_product_specification_id`),
  CONSTRAINT `fp_aggregation_profiles_material_fk` FOREIGN KEY (`finished_product_material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fp_aggregation_profiles_spec_fk` FOREIGN KEY (`finished_product_specification_id`) REFERENCES `finished_product_specifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finished_product_computed_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finished_product_computed_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `finished_product_specification_id` bigint(20) unsigned DEFAULT NULL,
  `finished_product_material_id` bigint(20) unsigned DEFAULT NULL,
  `computed_price_per_m2` decimal(18,4) DEFAULT NULL,
  `method` varchar(30) DEFAULT NULL,
  `source_count` int(10) unsigned NOT NULL DEFAULT 0,
  `min_price` decimal(18,4) DEFAULT NULL,
  `max_price` decimal(18,4) DEFAULT NULL,
  `computed_at` datetime DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fp_computed_prices_material_unique` (`finished_product_material_id`),
  UNIQUE KEY `fp_computed_prices_spec_unique` (`finished_product_specification_id`),
  CONSTRAINT `fp_computed_prices_material_fk` FOREIGN KEY (`finished_product_material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fp_computed_prices_spec_fk` FOREIGN KEY (`finished_product_specification_id`) REFERENCES `finished_product_specifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finished_product_price_evidence_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finished_product_price_evidence_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `finished_product_price_source_id` bigint(20) unsigned NOT NULL,
  `asset_type` varchar(30) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `source_url` varchar(2000) DEFAULT NULL,
  `content_hash` varchar(128) DEFAULT NULL,
  `captured_at` datetime DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fp_price_evidence_source_type_idx` (`finished_product_price_source_id`,`asset_type`),
  CONSTRAINT `fp_price_evidence_source_fk` FOREIGN KEY (`finished_product_price_source_id`) REFERENCES `finished_product_price_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finished_product_price_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finished_product_price_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `finished_product_specification_id` bigint(20) unsigned DEFAULT NULL,
  `finished_product_material_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `price_list_version_id` bigint(20) unsigned DEFAULT NULL,
  `source_kind` varchar(50) NOT NULL,
  `source_price` decimal(18,4) NOT NULL,
  `source_unit` varchar(50) DEFAULT NULL,
  `conversion_factor_to_m2` decimal(18,6) DEFAULT NULL,
  `price_per_m2_normalized` decimal(18,4) DEFAULT NULL,
  `captured_at` datetime DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `article` varchar(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `stale_reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fp_price_sources_material_status_idx` (`finished_product_material_id`,`status`),
  KEY `fp_price_sources_material_kind_idx` (`finished_product_material_id`,`source_kind`),
  KEY `fp_price_sources_supplier_fk` (`supplier_id`),
  KEY `fp_price_sources_version_fk` (`price_list_version_id`),
  KEY `fp_price_sources_spec_status_idx` (`finished_product_specification_id`,`status`),
  CONSTRAINT `fp_price_sources_material_fk` FOREIGN KEY (`finished_product_material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fp_price_sources_spec_fk` FOREIGN KEY (`finished_product_specification_id`) REFERENCES `finished_product_specifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fp_price_sources_supplier_fk` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fp_price_sources_version_fk` FOREIGN KEY (`price_list_version_id`) REFERENCES `price_list_versions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finished_product_specifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finished_product_specifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `product_type` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `article` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `facade_class` varchar(255) DEFAULT NULL,
  `base_type` varchar(255) DEFAULT NULL,
  `thickness_mm` int(10) unsigned DEFAULT NULL,
  `covering` varchar(255) DEFAULT NULL,
  `cover_type` varchar(255) DEFAULT NULL,
  `collection` varchar(255) DEFAULT NULL,
  `decor_label` varchar(255) DEFAULT NULL,
  `price_group_label` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fp_specs_user_type_active_idx` (`user_id`,`product_type`,`is_active`),
  KEY `fp_specs_user_updated_idx` (`user_id`,`updated_at`),
  KEY `fp_specs_user_article_idx` (`user_id`,`article`),
  CONSTRAINT `finished_product_specifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `generic_evidence_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `generic_evidence_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `evidence_record_id` bigint(20) unsigned NOT NULL,
  `asset_type` varchar(40) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `sha256` varchar(64) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `generic_evidence_assets_uuid_unique` (`uuid`),
  KEY `generic_evidence_assets_evidence_record_id_index` (`evidence_record_id`),
  KEY `generic_evidence_assets_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `generic_evidence_assets_evidence_record_id_foreign` FOREIGN KEY (`evidence_record_id`) REFERENCES `evidence_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `generic_evidence_assets_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `global_normohour_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `global_normohour_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `position_profile_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(255) NOT NULL COMMENT 'Источник (HH.ru, Avito, КП ООО, и т.д.)',
  `salary_value` decimal(12,2) NOT NULL COMMENT 'Значение зарплаты в выбранном периоде',
  `salary_value_min` decimal(10,2) DEFAULT NULL COMMENT 'Minimum salary in range',
  `salary_value_max` decimal(10,2) DEFAULT NULL COMMENT 'Maximum salary in range',
  `salary_period` enum('week','month','quarter','year') NOT NULL COMMENT 'Период зарплаты',
  `salary_month` decimal(12,2) NOT NULL COMMENT 'Приведённая зарплата за месяц (руб/мес)',
  `hours_per_month` decimal(10,2) NOT NULL DEFAULT 160.00 COMMENT 'Рабочие часы в месяц',
  `rate_per_hour` decimal(10,2) NOT NULL COMMENT 'Ставка (руб/час)',
  `min_rate` decimal(10,2) DEFAULT NULL COMMENT 'Minimum rate for this source (for visualization)',
  `max_rate` decimal(10,2) DEFAULT NULL COMMENT 'Maximum rate for this source (for visualization)',
  `source_date` date DEFAULT NULL COMMENT 'Дата актуальности источника',
  `link` varchar(500) DEFAULT NULL COMMENT 'Ссылка на источник',
  `note` text DEFAULT NULL COMMENT 'Примечание',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Активен ли источник',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `global_normohour_sources_position_profile_id_foreign` (`position_profile_id`),
  KEY `global_normohour_sources_region_id_foreign` (`region_id`),
  CONSTRAINT `global_normohour_sources_position_profile_id_foreign` FOREIGN KEY (`position_profile_id`) REFERENCES `position_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `global_normohour_sources_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idea_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `idea_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `idea_id` bigint(20) unsigned NOT NULL,
  `file_path` varchar(1024) NOT NULL,
  `mime_type` varchar(128) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idea_attachments_idea_id_foreign` (`idea_id`),
  CONSTRAINT `idea_attachments_idea_id_foreign` FOREIGN KEY (`idea_id`) REFERENCES `ideas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idea_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `idea_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `idea_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idea_comments_idea_id_foreign` (`idea_id`),
  KEY `idea_comments_user_id_foreign` (`user_id`),
  CONSTRAINT `idea_comments_idea_id_foreign` FOREIGN KEY (`idea_id`) REFERENCES `ideas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `idea_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idea_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `idea_tags` (
  `idea_id` bigint(20) unsigned NOT NULL,
  `tag_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`idea_id`,`tag_id`),
  KEY `idea_tags_tag_id_foreign` (`tag_id`),
  CONSTRAINT `idea_tags_idea_id_foreign` FOREIGN KEY (`idea_id`) REFERENCES `ideas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `idea_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idea_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `idea_votes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `idea_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `vote_type` varchar(16) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idea_votes_idea_id_user_id_unique` (`idea_id`,`user_id`),
  KEY `idea_votes_user_id_foreign` (`user_id`),
  KEY `idea_votes_idea_id_index` (`idea_id`),
  CONSTRAINT `idea_votes_idea_id_foreign` FOREIGN KEY (`idea_id`) REFERENCES `ideas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `idea_votes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ideas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ideas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'NEW',
  `votes_up` int(10) unsigned NOT NULL DEFAULT 0,
  `votes_down` int(10) unsigned NOT NULL DEFAULT 0,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ideas_user_id_foreign` (`user_id`),
  KEY `ideas_status_index` (`status`),
  KEY `ideas_created_at_index` (`created_at`),
  CONSTRAINT `ideas_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_column_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_column_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_session_id` bigint(20) unsigned NOT NULL,
  `column_index` int(10) unsigned NOT NULL,
  `field` enum('width','length','qty','name','ignore') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `import_column_mappings_import_session_id_column_index_unique` (`import_session_id`,`column_index`),
  CONSTRAINT `import_column_mappings_import_session_id_foreign` FOREIGN KEY (`import_session_id`) REFERENCES `import_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `storage_disk` varchar(255) NOT NULL DEFAULT 'local',
  `original_filename` varchar(255) NOT NULL,
  `file_type` enum('xlsx','xls','csv') NOT NULL,
  `status` enum('uploaded','mapped','imported','failed') NOT NULL DEFAULT 'uploaded',
  `header_row_index` int(11) DEFAULT NULL,
  `sheet_index` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0-based index for xlsx',
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'csv_encoding, csv_delimiter, units_length, default_qty_if_empty, etc.' CHECK (json_valid(`options`)),
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'created_count, skipped_count, errors array' CHECK (json_valid(`result`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `import_sessions_user_id_status_index` (`user_id`,`status`),
  KEY `import_sessions_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `import_sessions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `import_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labor_evidence_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `labor_evidence_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned NOT NULL,
  `labor_profile_id` bigint(20) unsigned DEFAULT NULL,
  `provider_id` bigint(20) unsigned NOT NULL,
  `evidence_record_id` bigint(20) unsigned DEFAULT NULL,
  `source_title` varchar(255) DEFAULT NULL,
  `source_url` text NOT NULL,
  `source_date` date DEFAULT NULL,
  `employer_name` varchar(255) DEFAULT NULL,
  `vacancy_title` varchar(255) DEFAULT NULL,
  `vacancy_description` longtext DEFAULT NULL,
  `vacancy_excerpt` text DEFAULT NULL,
  `salary_raw_text` text DEFAULT NULL,
  `salary_value` decimal(12,2) DEFAULT NULL,
  `salary_value_min` decimal(12,2) DEFAULT NULL,
  `salary_value_max` decimal(12,2) DEFAULT NULL,
  `salary_period` enum('hour','day','month','year','project') DEFAULT NULL,
  `hours_per_month` int(10) unsigned NOT NULL DEFAULT 160,
  `derived_hourly_rate` decimal(12,2) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'RUB',
  `note` text DEFAULT NULL,
  `captured_via` enum('manual','chrome','import') NOT NULL DEFAULT 'manual',
  `verification_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `labor_evidence_sources_evidence_record_id_unique` (`evidence_record_id`),
  KEY `labor_evidence_sources_user_id_index` (`user_id`),
  KEY `labor_evidence_sources_region_id_index` (`region_id`),
  KEY `labor_evidence_sources_labor_profile_id_index` (`labor_profile_id`),
  KEY `labor_evidence_sources_provider_id_index` (`provider_id`),
  KEY `labor_evidence_sources_user_id_is_active_index` (`user_id`,`is_active`),
  KEY `labor_evidence_sources_user_id_region_id_index` (`user_id`,`region_id`),
  KEY `labor_evidence_sources_user_id_labor_profile_id_index` (`user_id`,`labor_profile_id`),
  KEY `labor_evidence_sources_user_id_provider_id_index` (`user_id`,`provider_id`),
  CONSTRAINT `labor_evidence_sources_evidence_record_id_foreign` FOREIGN KEY (`evidence_record_id`) REFERENCES `evidence_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labor_evidence_sources_labor_profile_id_foreign` FOREIGN KEY (`labor_profile_id`) REFERENCES `labor_profiles` (`id`),
  CONSTRAINT `labor_evidence_sources_provider_id_foreign` FOREIGN KEY (`provider_id`) REFERENCES `labor_providers` (`id`),
  CONSTRAINT `labor_evidence_sources_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`),
  CONSTRAINT `labor_evidence_sources_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labor_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `labor_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labor_profiles_user_id_index` (`user_id`),
  KEY `labor_profiles_user_id_is_active_index` (`user_id`,`is_active`),
  KEY `labor_profiles_user_id_sort_order_index` (`user_id`,`sort_order`),
  CONSTRAINT `labor_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labor_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `labor_providers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `base_url` varchar(2048) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labor_providers_user_id_index` (`user_id`),
  KEY `labor_providers_user_id_is_active_index` (`user_id`,`is_active`),
  KEY `labor_providers_user_id_sort_order_index` (`user_id`,`sort_order`),
  CONSTRAINT `labor_providers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labor_work_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `labor_work_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `position_profile_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Наименование работы',
  `basis` text DEFAULT NULL COMMENT 'Основание/норма (ГОСТ, пункт заключения)',
  `default_hours` decimal(8,2) NOT NULL DEFAULT 0.00 COMMENT 'Стандартное кол-во часов',
  `note` text DEFAULT NULL COMMENT 'Что входит в работу',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labor_work_templates_position_profile_id_foreign` (`position_profile_id`),
  CONSTRAINT `labor_work_templates_position_profile_id_foreign` FOREIGN KEY (`position_profile_id`) REFERENCES `position_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_dimension_parse_failures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_dimension_parse_failures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fingerprint` varchar(64) NOT NULL,
  `raw_text` text NOT NULL,
  `normalized_text` text NOT NULL,
  `material_type` varchar(32) DEFAULT NULL,
  `source` varchar(64) DEFAULT NULL,
  `parse_error_reason` varchar(128) DEFAULT NULL,
  `occurrences` int(10) unsigned NOT NULL DEFAULT 1,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `resolved_length_mm` int(11) DEFAULT NULL,
  `resolved_width_mm` int(11) DEFAULT NULL,
  `resolved_thickness_mm` decimal(8,2) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `resolved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `last_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`last_result`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_dimension_parse_failures_fingerprint_unique` (`fingerprint`),
  KEY `material_dimension_parse_failures_resolved_by_user_id_foreign` (`resolved_by_user_id`),
  KEY `material_dimension_parse_failures_material_type_source_index` (`material_type`,`source`),
  KEY `material_dimension_parse_failures_resolved_at_last_seen_at_index` (`resolved_at`,`last_seen_at`),
  CONSTRAINT `material_dimension_parse_failures_resolved_by_user_id_foreign` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_dimension_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_dimension_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(10) unsigned NOT NULL DEFAULT 100,
  `material_type` varchar(32) DEFAULT NULL,
  `source` varchar(64) DEFAULT NULL,
  `rule_type` varchar(32) NOT NULL DEFAULT 'regex',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`config`)),
  `example_input` varchar(1024) DEFAULT NULL,
  `expected_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`expected_result`)),
  `confidence` decimal(4,2) NOT NULL DEFAULT 0.75,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `material_dimension_rules_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `material_dimension_rules_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `material_dimension_rules_is_active_priority_index` (`is_active`,`priority`),
  KEY `material_dimension_rules_material_type_source_index` (`material_type`,`source`),
  CONSTRAINT `material_dimension_rules_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_dimension_rules_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_price_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_price_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `material_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned DEFAULT NULL,
  `observed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `source_type` enum('web','manual','price_list','chrome_ext') NOT NULL DEFAULT 'manual',
  `parse_session_id` bigint(20) unsigned DEFAULT NULL,
  `snapshot_path` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(4) NOT NULL DEFAULT 0,
  `true_score` smallint(5) unsigned NOT NULL DEFAULT 100,
  `currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `availability` varchar(50) DEFAULT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `version` int(10) unsigned NOT NULL,
  `price_per_unit` decimal(12,2) NOT NULL,
  `source_url` text DEFAULT NULL,
  `raw_source_url` text DEFAULT NULL,
  `normalized_source_url` varchar(2048) DEFAULT NULL,
  `evidence_artifact_id` bigint(20) unsigned DEFAULT NULL,
  `evidence_mode` enum('auto','manual') DEFAULT NULL,
  `evidence_record_id` bigint(20) unsigned DEFAULT NULL,
  `is_auto_verified` tinyint(1) DEFAULT NULL,
  `validation_confidence` tinyint(3) unsigned DEFAULT NULL,
  `screenshot_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `material_price_histories_region_id_foreign` (`region_id`),
  KEY `material_price_histories_parse_session_id_foreign` (`parse_session_id`),
  KEY `mph_material_region_observed_idx` (`material_id`,`region_id`,`observed_at`),
  KEY `mph_material_observed_idx` (`material_id`,`observed_at`),
  KEY `mph_norm_price_region_idx` (`normalized_source_url`(191),`price_per_unit`,`region_id`),
  KEY `material_price_histories_evidence_artifact_id_foreign` (`evidence_artifact_id`),
  KEY `material_price_histories_evidence_record_id_foreign` (`evidence_record_id`),
  CONSTRAINT `material_price_histories_evidence_artifact_id_foreign` FOREIGN KEY (`evidence_artifact_id`) REFERENCES `evidence_artifacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_price_histories_evidence_record_id_foreign` FOREIGN KEY (`evidence_record_id`) REFERENCES `evidence_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_price_histories_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_price_histories_parse_session_id_foreign` FOREIGN KEY (`parse_session_id`) REFERENCES `parsing_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_price_histories_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `price_list_version_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `source_price` decimal(18,4) NOT NULL COMMENT 'Оригинальная цена от поставщика',
  `source_unit` varchar(50) DEFAULT NULL COMMENT 'Единица в прайсе',
  `conversion_factor` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `price_per_internal_unit` decimal(18,4) NOT NULL COMMENT '= source_price / conversion_factor',
  `price_type` enum('retail','wholesale') NOT NULL DEFAULT 'retail',
  `currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `source_row_index` int(10) unsigned DEFAULT NULL,
  `article` varchar(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `thickness` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_prices_price_list_version_id_material_id_unique` (`price_list_version_id`,`material_id`),
  KEY `material_prices_material_id_price_list_version_id_index` (`material_id`,`price_list_version_id`),
  KEY `material_prices_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `material_prices_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_prices_price_list_version_id_foreign` FOREIGN KEY (`price_list_version_id`) REFERENCES `price_list_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_prices_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_type_patterns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_type_patterns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(10) unsigned NOT NULL DEFAULT 100,
  `material_type` varchar(32) NOT NULL,
  `source` varchar(64) DEFAULT NULL,
  `rule_type` varchar(32) NOT NULL DEFAULT 'regex',
  `target_field` varchar(32) NOT NULL DEFAULT 'title',
  `pattern` text NOT NULL,
  `flags` varchar(16) NOT NULL DEFAULT 'iu',
  `use_normalized_text` tinyint(1) NOT NULL DEFAULT 1,
  `example_input` varchar(1024) DEFAULT NULL,
  `expected_material_type` varchar(32) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `material_type_patterns_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `material_type_patterns_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `material_type_patterns_is_active_priority_index` (`is_active`,`priority`),
  KEY `material_type_patterns_source_target_field_index` (`source`,`target_field`),
  KEY `material_type_patterns_material_type_is_active_index` (`material_type`,`is_active`),
  CONSTRAINT `material_type_patterns_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_type_patterns_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `materials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `origin` enum('user','parser') NOT NULL DEFAULT 'user',
  `name` varchar(255) NOT NULL,
  `search_name` varchar(255) DEFAULT NULL COMMENT 'Нормализованное имя для поиска (lowercase, без спецсимволов)',
  `article` varchar(255) NOT NULL,
  `type` enum('plate','edge','facade','hardware') NOT NULL DEFAULT 'plate',
  `material_tag` varchar(50) DEFAULT NULL COMMENT 'ldsp, mdf, pvc',
  `thickness` decimal(5,2) DEFAULT NULL,
  `waste_factor` decimal(3,2) NOT NULL DEFAULT 1.00,
  `price_per_unit` decimal(8,2) NOT NULL,
  `unit` enum('м²','м.п.','шт') NOT NULL,
  `length_mm` int(11) DEFAULT NULL COMMENT 'Длина листа в мм',
  `width_mm` int(11) DEFAULT NULL COMMENT 'Ширина листа в мм',
  `thickness_mm` int(11) DEFAULT NULL COMMENT 'Толщина листа в мм',
  `source_url` text DEFAULT NULL,
  `last_price_screenshot_path` varchar(255) DEFAULT NULL,
  `availability_status` varchar(50) DEFAULT NULL,
  `price_checked_at` timestamp NULL DEFAULT NULL COMMENT 'Момент последней успешной проверки цены парсером',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `visibility` enum('private','public','curated') NOT NULL DEFAULT 'private',
  `curator_user_id` bigint(20) unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `curated_at` timestamp NULL DEFAULT NULL,
  `trust_score` smallint(5) unsigned NOT NULL DEFAULT 0,
  `trust_level` enum('unverified','partial','verified') NOT NULL DEFAULT 'unverified',
  `data_origin` enum('manual','url_parse','price_list','chrome_ext') NOT NULL DEFAULT 'manual',
  `last_parsed_at` timestamp NULL DEFAULT NULL,
  `last_parse_status` enum('ok','failed','blocked','unsupported') DEFAULT NULL,
  `last_parse_error` varchar(255) DEFAULT NULL,
  `region_id` bigint(20) unsigned DEFAULT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `operation_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`operation_ids`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `facade_class` varchar(32) DEFAULT NULL COMMENT 'MVP: STANDARD|PREMIUM|GEOMETRY|RADIUS|VITRINA|RESHETKA|AKRIL|ALUMINIUM|MASSIV|ECONOMY',
  `facade_base_type` varchar(50) DEFAULT NULL COMMENT 'Base material: mdf, dsp, mdf_aglo, fanera, massiv',
  `facade_thickness_mm` smallint(5) unsigned DEFAULT NULL,
  `facade_covering` varchar(50) DEFAULT NULL COMMENT 'Covering type code: pvc_film, plastic, enamel, veneer, solid_wood, aluminum_frame, other',
  `facade_cover_type` varchar(50) DEFAULT NULL COMMENT 'Cover variant: matte, gloss, metallic, soft_touch, textured',
  `facade_collection` varchar(100) DEFAULT NULL,
  `facade_price_group_label` varchar(50) DEFAULT NULL COMMENT 'Price group label from supplier, informational only',
  `facade_decor_label` varchar(255) DEFAULT NULL COMMENT 'Decor description, informational only',
  `facade_article_optional` varchar(255) DEFAULT NULL COMMENT 'Alternative article if different from materials.article',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `materials_user_id_foreign` (`user_id`),
  KEY `materials_search_name_index` (`search_name`),
  KEY `materials_facade_strict_match_idx` (`type`,`facade_base_type`,`facade_thickness_mm`,`facade_covering`,`facade_cover_type`,`facade_class`),
  KEY `materials_curator_user_id_foreign` (`curator_user_id`),
  KEY `materials_region_visibility_idx` (`region_id`,`visibility`,`type`),
  KEY `materials_visibility_type_idx` (`visibility`,`type`),
  CONSTRAINT `materials_curator_user_id_foreign` FOREIGN KEY (`curator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `materials_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `materials_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `link_label` varchar(255) DEFAULT 'Подробнее',
  `link_type` enum('internal','external') NOT NULL DEFAULT 'internal',
  `audience_type` enum('all','users','segment') NOT NULL DEFAULT 'all',
  `audience_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`audience_payload`)),
  `status` enum('draft','scheduled','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
  `send_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_status_index` (`status`),
  KEY `notifications_send_at_index` (`send_at`),
  KEY `notifications_created_by_index` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operation_application_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operation_application_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'automatic',
  `applies_to` varchar(40) NOT NULL,
  `material_type` varchar(40) DEFAULT NULL,
  `material_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_method` varchar(40) DEFAULT NULL,
  `quantity_source` varchar(40) DEFAULT NULL,
  `pricing_unit` varchar(40) DEFAULT NULL,
  `tariff_binding_type` varchar(40) DEFAULT 'operation_resolver',
  `tariff_operation_id` bigint(20) unsigned DEFAULT NULL,
  `tariff_binding_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tariff_binding_json`)),
  `conditions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conditions_json`)),
  `quantity_config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`quantity_config_json`)),
  `priority` int(10) unsigned NOT NULL DEFAULT 100,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operation_application_rules_operation_id_foreign` (`operation_id`),
  KEY `operation_application_rules_match_idx` (`is_enabled`,`applies_to`,`material_type`),
  KEY `operation_application_rules_user_idx` (`user_id`,`is_enabled`),
  KEY `operation_application_rules_material_idx` (`material_id`,`is_enabled`),
  KEY `operation_application_rules_priority_idx` (`priority`,`id`),
  KEY `operation_application_rules_tariff_operation_id_foreign` (`tariff_operation_id`),
  KEY `operation_application_rules_quantity_source_idx` (`quantity_source`,`is_enabled`),
  KEY `operation_application_rules_tariff_idx` (`tariff_binding_type`,`tariff_operation_id`),
  CONSTRAINT `operation_application_rules_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `operation_application_rules_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operation_application_rules_tariff_operation_id_foreign` FOREIGN KEY (`tariff_operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `operation_application_rules_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operation_group_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operation_group_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_group_id` bigint(20) unsigned NOT NULL,
  `supplier_operation_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_supplier_op_unique` (`operation_group_id`,`supplier_operation_id`),
  KEY `operation_group_links_supplier_operation_id_foreign` (`supplier_operation_id`),
  CONSTRAINT `operation_group_links_operation_group_id_foreign` FOREIGN KEY (`operation_group_id`) REFERENCES `operation_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operation_group_links_supplier_operation_id_foreign` FOREIGN KEY (`supplier_operation_id`) REFERENCES `supplier_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operation_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operation_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `expected_unit` varchar(255) DEFAULT NULL COMMENT 'Ожидаемая единица измерения для группы',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operation_groups_user_id_name_index` (`user_id`,`name`),
  CONSTRAINT `operation_groups_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operation_price_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operation_price_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` bigint(20) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `value` decimal(12,2) NOT NULL,
  `unit` varchar(40) NOT NULL,
  `source_name` varchar(255) DEFAULT NULL,
  `document_ref` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `operation_price_sources_active_idx` (`operation_id`,`is_active`),
  CONSTRAINT `operation_price_sources_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operation_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operation_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `price_list_version_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned DEFAULT NULL,
  `source_price` decimal(18,4) NOT NULL COMMENT 'Оригинальная цена от поставщика',
  `source_unit` varchar(50) DEFAULT NULL COMMENT 'Единица в прайсе',
  `conversion_factor` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `price_per_internal_unit` decimal(18,4) NOT NULL COMMENT '= source_price / conversion_factor',
  `currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `price_type` varchar(20) NOT NULL DEFAULT 'retail' COMMENT 'retail или wholesale',
  `source_row_index` int(10) unsigned DEFAULT NULL COMMENT 'Номер строки в исходном файле',
  `source_name` varchar(500) DEFAULT NULL COMMENT 'Название как в прайсе поставщика',
  `external_key` varchar(255) DEFAULT NULL COMMENT 'SKU/артикул поставщика',
  `match_confidence` varchar(20) DEFAULT NULL COMMENT 'alias, exact, fuzzy, manual',
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `category` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `min_thickness` decimal(8,2) DEFAULT NULL,
  `max_thickness` decimal(8,2) DEFAULT NULL,
  `exclusion_group` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `op_supplier_version_idx` (`supplier_id`,`price_list_version_id`),
  KEY `op_external_key_idx` (`external_key`),
  KEY `op_source_name_idx` (`source_name`),
  KEY `op_prices_version_idx` (`price_list_version_id`),
  KEY `op_prices_op_version_idx` (`operation_id`,`price_list_version_id`),
  CONSTRAINT `operation_prices_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `operation_prices_price_list_version_id_foreign` FOREIGN KEY (`price_list_version_id`) REFERENCES `price_list_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operation_prices_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `search_name` varchar(255) DEFAULT NULL COMMENT 'Нормализованное имя для поиска (lowercase, без спецсимволов)',
  `category` varchar(255) NOT NULL,
  `operation_kind` varchar(20) NOT NULL,
  `exclusion_group` varchar(50) DEFAULT NULL,
  `min_thickness` decimal(5,2) DEFAULT NULL,
  `max_thickness` decimal(5,2) DEFAULT NULL,
  `unit` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `origin` varchar(255) NOT NULL DEFAULT 'user',
  PRIMARY KEY (`id`),
  KEY `operations_user_id_foreign` (`user_id`),
  KEY `operations_search_name_index` (`search_name`),
  CONSTRAINT `operations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parser_collect_cursors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parser_collect_cursors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `supplier_name` varchar(64) NOT NULL,
  `current_category` varchar(255) DEFAULT NULL,
  `current_page` int(10) unsigned NOT NULL DEFAULT 0,
  `visited_pages` int(10) unsigned NOT NULL DEFAULT 0,
  `urls_found_total` int(10) unsigned NOT NULL DEFAULT 0,
  `urls_unique_total` int(10) unsigned NOT NULL DEFAULT 0,
  `urls_sent_total` int(10) unsigned NOT NULL DEFAULT 0,
  `duplicates_dropped` int(10) unsigned NOT NULL DEFAULT 0,
  `elapsed_seconds` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_chunk_sent_at` timestamp NULL DEFAULT NULL,
  `stop_reason` varchar(64) DEFAULT NULL,
  `is_complete` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parser_collect_cursors_session_id_unique` (`session_id`),
  KEY `parser_collect_cursors_supplier_name_is_complete_index` (`supplier_name`,`is_complete`),
  CONSTRAINT `parser_collect_cursors_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `parsing_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parser_supplier_collect_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parser_supplier_collect_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `config_override` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`config_override`)),
  `url_patterns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`url_patterns`)),
  `selectors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`selectors`)),
  `extraction_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extraction_rules`)),
  `validation_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_rules`)),
  `test_case` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`test_case`)),
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(20) NOT NULL DEFAULT 'system',
  `visibility` enum('private','public') NOT NULL DEFAULT 'private',
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parser_supplier_collect_profiles_supplier_name_index` (`supplier_name`),
  KEY `parser_supplier_collect_profiles_user_id_foreign` (`user_id`),
  KEY `pscp_supplier_user_idx` (`supplier_name`,`user_id`),
  KEY `profiles_domain_scope_idx` (`supplier_name`,`source`,`visibility`,`status`),
  CONSTRAINT `parser_supplier_collect_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parser_supplier_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parser_supplier_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(255) NOT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parser_supplier_configs_supplier_name_unique` (`supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parsing_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parsing_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `url` varchar(255) NOT NULL,
  `level` enum('info','warning','error') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parsing_logs_session_id_foreign` (`session_id`),
  CONSTRAINT `parsing_logs_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `parsing_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parsing_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parsing_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(255) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','running','completed','failed','stopped','canceling') NOT NULL DEFAULT 'pending',
  `lifecycle_status` varchar(32) NOT NULL DEFAULT 'created',
  `result_status` varchar(16) DEFAULT NULL,
  `collect_started_at` timestamp NULL DEFAULT NULL,
  `collect_finished_at` timestamp NULL DEFAULT NULL,
  `reset_started_at` timestamp NULL DEFAULT NULL,
  `reset_finished_at` timestamp NULL DEFAULT NULL,
  `collect_urls_count` int(10) unsigned NOT NULL DEFAULT 0,
  `collect_stats_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`collect_stats_json`)),
  `parse_started_at` timestamp NULL DEFAULT NULL,
  `parse_finished_at` timestamp NULL DEFAULT NULL,
  `parse_stats_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parse_stats_json`)),
  `session_run_id` varchar(64) DEFAULT NULL,
  `pid` int(11) DEFAULT NULL,
  `last_heartbeat` timestamp NULL DEFAULT NULL,
  `last_heartbeat_at` timestamp NULL DEFAULT NULL,
  `pages_processed` int(11) NOT NULL DEFAULT 0,
  `items_updated` int(11) NOT NULL DEFAULT 0,
  `errors_count` int(11) NOT NULL DEFAULT 0,
  `total_urls` int(11) NOT NULL DEFAULT 0,
  `full_scan_run_id` varchar(64) DEFAULT NULL,
  `full_scan_prepared_at` timestamp NULL DEFAULT NULL,
  `full_scan_stage` enum('not_started','collect_done','reset_done','parsing_running','parsing_done') NOT NULL DEFAULT 'not_started',
  `error_reason` varchar(255) DEFAULT NULL,
  `stop_reason` varchar(64) DEFAULT NULL,
  `failed_reason` varchar(255) DEFAULT NULL,
  `failed_details` longtext DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `aborted_by` varchar(64) DEFAULT NULL,
  `aborted_at` timestamp NULL DEFAULT NULL,
  `max_collect_pages` int(10) unsigned DEFAULT NULL,
  `max_collect_urls` int(10) unsigned DEFAULT NULL,
  `max_collect_time_seconds` int(10) unsigned DEFAULT NULL,
  `job_dispatched_at` timestamp NULL DEFAULT NULL,
  `job_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parsing_sessions_session_run_id_unique` (`session_run_id`),
  KEY `idx_lifecycle_supplier` (`lifecycle_status`,`supplier_name`),
  KEY `idx_session_run_id` (`session_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `position_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `position_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT 'Наименование профиля должности',
  `description` text DEFAULT NULL COMMENT 'Описание',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Порядок сортировки',
  `rate_model` varchar(20) NOT NULL DEFAULT 'labor' COMMENT 'Модель формирования ставки: labor | contractor',
  `employer_contrib_pct` decimal(5,2) NOT NULL DEFAULT 30.00 COMMENT 'Страховые начисления работодателя, %',
  `base_hours_month` int(11) NOT NULL DEFAULT 160 COMMENT 'Рабочих часов в месяце',
  `billable_hours_month` int(11) NOT NULL DEFAULT 120 COMMENT 'Оплачиваемых/продаваемых часов в месяце',
  `profit_pct` decimal(5,2) NOT NULL DEFAULT 15.00 COMMENT 'Рентабельность подрядчика, %',
  `rounding_mode` varchar(10) NOT NULL DEFAULT 'none' COMMENT 'Округление ставки: none | int | 10 | 100',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `position_profiles_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_import_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_import_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `value` decimal(12,2) NOT NULL,
  `unit` varchar(40) NOT NULL,
  `parsed_operation_hint` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `price_import_items_import_id_created_at_index` (`import_id`,`created_at`),
  KEY `price_import_items_operation_id_foreign` (`operation_id`),
  KEY `price_import_items_import_id_status_index` (`import_id`,`status`),
  CONSTRAINT `price_import_items_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `price_imports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_import_items_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_import_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_import_sessions` (
  `id` char(36) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `price_list_version_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `target_type` enum('operations','materials') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `storage_disk` varchar(255) NOT NULL DEFAULT 'local',
  `original_filename` varchar(255) DEFAULT NULL,
  `file_type` enum('xlsx','xls','csv','html','paste') DEFAULT NULL,
  `file_hash` varchar(64) DEFAULT NULL COMMENT 'SHA256 hash of imported file for duplicate detection',
  `status` enum('created','parsing_failed','mapping_required','resolution_required','execution_running','completed','execution_failed','cancelled') DEFAULT 'created',
  `header_row_index` int(10) unsigned NOT NULL DEFAULT 0,
  `sheet_index` int(10) unsigned NOT NULL DEFAULT 0,
  `column_mapping` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '{"0": "name", "1": "cost_per_unit", ...}' CHECK (json_valid(`column_mapping`)),
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'csv_encoding, csv_delimiter, etc.' CHECK (json_valid(`options`)),
  `raw_rows` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Parsed rows before processing' CHECK (json_valid(`raw_rows`)),
  `resolution_queue` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of rows needing resolution' CHECK (json_valid(`resolution_queue`)),
  `stats` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'total, auto_matched, ambiguous, new, ignored' CHECK (json_valid(`stats`)),
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'created_count, updated_count, errors, etc.' CHECK (json_valid(`result`)),
  `error_message` text DEFAULT NULL,
  `error_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`error_details`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `price_import_sessions_supplier_id_foreign` (`supplier_id`),
  KEY `price_import_sessions_user_id_status_index` (`user_id`,`status`),
  KEY `price_import_sessions_price_list_version_id_index` (`price_list_version_id`),
  KEY `price_import_sessions_status_created_at_index` (`status`,`created_at`),
  KEY `pis_duplicate_check_idx` (`file_hash`,`supplier_id`,`target_type`),
  CONSTRAINT `price_import_sessions_price_list_version_id_foreign` FOREIGN KEY (`price_list_version_id`) REFERENCES `price_list_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `price_import_sessions_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `price_import_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `price_imports_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `price_imports_type_status_index` (`type`,`status`),
  CONSTRAINT `price_imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_list_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_list_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `price_list_id` bigint(20) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL COMMENT 'Автоинкремент внутри price_list',
  `sha256` varchar(64) DEFAULT NULL COMMENT 'Хэш содержимого для дедупликации',
  `size_bytes` bigint(20) unsigned DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `effective_date` date DEFAULT NULL COMMENT 'Дата начала действия прайса',
  `captured_at` timestamp NULL DEFAULT NULL COMMENT 'Дата импорта/захвата',
  `file_path` varchar(255) DEFAULT NULL,
  `storage_disk` varchar(255) NOT NULL DEFAULT 'local',
  `original_filename` varchar(255) DEFAULT NULL,
  `status` enum('inactive','active','archived') DEFAULT 'inactive',
  `source_type` enum('file','manual','url') NOT NULL DEFAULT 'file',
  `source_url` varchar(255) DEFAULT NULL,
  `manual_label` varchar(255) DEFAULT NULL COMMENT 'Название для ручного ввода',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'row_count, column_count, parsing_notes, etc.' CHECK (json_valid(`metadata`)),
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_list_versions_price_list_id_version_number_unique` (`price_list_id`,`version_number`),
  UNIQUE KEY `price_list_versions_price_list_id_sha256_unique` (`price_list_id`,`sha256`),
  KEY `price_list_versions_price_list_id_status_index` (`price_list_id`,`status`),
  KEY `price_list_versions_effective_date_index` (`effective_date`),
  CONSTRAINT `price_list_versions_price_list_id_foreign` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_lists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('operations','materials') NOT NULL,
  `description` text DEFAULT NULL,
  `default_currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_lists_supplier_id_name_unique` (`supplier_id`,`name`),
  KEY `price_lists_supplier_id_type_is_active_index` (`supplier_id`,`type`,`is_active`),
  KEY `price_lists_type_index` (`type`),
  CONSTRAINT `price_lists_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_fittings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_fittings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `article` varchar(255) DEFAULT NULL,
  `quantity` decimal(8,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `source_url` text DEFAULT NULL,
  `unit` varchar(255) NOT NULL DEFAULT 'шт',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_fittings_project_id_foreign` (`project_id`),
  KEY `project_fittings_material_id_foreign` (`material_id`),
  CONSTRAINT `project_fittings_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_fittings_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_labor_evidence_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_labor_evidence_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `labor_evidence_source_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_labor_evidence_sources_unique` (`project_id`,`labor_evidence_source_id`),
  KEY `project_labor_evidence_sources_labor_evidence_source_id_foreign` (`labor_evidence_source_id`),
  CONSTRAINT `project_labor_evidence_sources_labor_evidence_source_id_foreign` FOREIGN KEY (`labor_evidence_source_id`) REFERENCES `labor_evidence_sources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_labor_evidence_sources_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_labor_work_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_labor_work_steps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_labor_work_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `basis` varchar(255) DEFAULT NULL,
  `input_data` varchar(255) DEFAULT NULL,
  `hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `note` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent_sort` (`project_labor_work_id`,`sort_order`),
  CONSTRAINT `project_labor_work_steps_project_labor_work_id_foreign` FOREIGN KEY (`project_labor_work_id`) REFERENCES `project_labor_works` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_labor_works`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_labor_works` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `position_profile_id` bigint(20) unsigned DEFAULT NULL,
  `labor_profile_id` bigint(20) unsigned DEFAULT NULL,
  `project_profile_rate_id` bigint(20) unsigned DEFAULT NULL,
  `rate_per_hour` decimal(10,2) DEFAULT NULL,
  `cost_total` decimal(12,2) DEFAULT NULL,
  `rate_snapshot` longtext DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `basis` varchar(500) DEFAULT NULL,
  `hours` decimal(8,2) NOT NULL,
  `hours_source` enum('manual','from_steps') NOT NULL DEFAULT 'manual',
  `hours_manual` decimal(8,2) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_labor_works_project_id_sort_order_index` (`project_id`,`sort_order`),
  KEY `project_labor_works_position_profile_id_foreign` (`position_profile_id`),
  KEY `project_labor_works_project_id_position_profile_id_index` (`project_id`,`position_profile_id`),
  KEY `project_labor_works_project_profile_rate_id_index` (`project_profile_rate_id`),
  KEY `project_labor_works_labor_profile_id_index` (`labor_profile_id`),
  CONSTRAINT `project_labor_works_position_profile_id_foreign` FOREIGN KEY (`position_profile_id`) REFERENCES `position_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_labor_works_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_labor_works_project_profile_rate_id_foreign` FOREIGN KEY (`project_profile_rate_id`) REFERENCES `project_profile_rates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_manual_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_manual_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(12,4) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_manual_operations_project_id_foreign` (`project_id`),
  KEY `project_manual_operations_operation_id_foreign` (`operation_id`),
  CONSTRAINT `project_manual_operations_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_manual_operations_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_normohour_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_normohour_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `position_profile_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `source` varchar(255) NOT NULL,
  `salary_value` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Зарплата в исходных единицах',
  `salary_period` enum('week','month','quarter','year') NOT NULL DEFAULT 'month' COMMENT 'Период оплаты (week/month/quarter/year)',
  `salary_month` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Зарплата в месяц (руб)',
  `hours_per_month` decimal(8,2) NOT NULL DEFAULT 160.00 COMMENT 'Часов в месяц',
  `rate_per_hour` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Ставка в час (руб)',
  `is_included` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Учитывать в расчётах',
  `position_profile` varchar(255) DEFAULT NULL COMMENT 'Должность/профиль',
  `salary_range` varchar(255) DEFAULT NULL COMMENT 'Вилка/значение зарплаты (на руки)',
  `period` varchar(50) DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL COMMENT 'Ссылка на источник',
  `note` text DEFAULT NULL COMMENT 'Примечание',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_normohour_sources_project_id_foreign` (`project_id`),
  CONSTRAINT `project_normohour_sources_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_position_price_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_position_price_quotes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_position_id` bigint(20) unsigned NOT NULL,
  `material_price_id` bigint(20) unsigned NOT NULL,
  `price_list_version_id` bigint(20) unsigned NOT NULL COMMENT 'Denormalized from material_prices for fast joins',
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `price_per_m2_snapshot` decimal(12,2) NOT NULL,
  `captured_at` datetime NOT NULL,
  `mismatch_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Fields that did not match canonical facade in extended mode' CHECK (json_valid(`mismatch_flags`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pppq_position_price_unique` (`project_position_id`,`material_price_id`),
  KEY `project_position_price_quotes_material_price_id_foreign` (`material_price_id`),
  KEY `pppq_position_idx` (`project_position_id`),
  KEY `pppq_version_idx` (`price_list_version_id`),
  CONSTRAINT `project_position_price_quotes_material_price_id_foreign` FOREIGN KEY (`material_price_id`) REFERENCES `material_prices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_position_price_quotes_price_list_version_id_foreign` FOREIGN KEY (`price_list_version_id`) REFERENCES `price_list_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_position_price_quotes_project_position_id_foreign` FOREIGN KEY (`project_position_id`) REFERENCES `project_positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_positions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'panel',
  `detail_type_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `width` decimal(8,2) NOT NULL,
  `length` decimal(8,2) NOT NULL,
  `height` decimal(8,2) DEFAULT NULL,
  `material_id` bigint(20) unsigned DEFAULT NULL,
  `facade_material_id` bigint(20) unsigned DEFAULT NULL,
  `finished_product_specification_id` bigint(20) unsigned DEFAULT NULL,
  `material_price_id` bigint(20) unsigned DEFAULT NULL,
  `decor_label` varchar(255) DEFAULT NULL,
  `thickness_mm` smallint(5) unsigned DEFAULT NULL,
  `base_material_label` varchar(100) DEFAULT NULL,
  `finish_type` varchar(50) DEFAULT NULL,
  `finish_name` varchar(255) DEFAULT NULL,
  `price_per_m2` decimal(18,4) DEFAULT NULL,
  `area_m2` decimal(12,6) DEFAULT NULL,
  `total_price` decimal(18,4) DEFAULT NULL,
  `price_method` varchar(20) NOT NULL DEFAULT 'single' COMMENT 'single|mean|median|trimmed_mean',
  `price_sources_count` smallint(5) unsigned DEFAULT NULL,
  `price_min` decimal(12,2) DEFAULT NULL,
  `price_max` decimal(12,2) DEFAULT NULL,
  `finished_product_pricing_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`finished_product_pricing_snapshot`)),
  `requires_price_justification` tinyint(1) NOT NULL DEFAULT 1,
  `material_tag` varchar(50) DEFAULT NULL,
  `thickness` decimal(5,2) DEFAULT NULL,
  `waste_factor` decimal(3,2) NOT NULL DEFAULT 1.00,
  `edge_material_id` bigint(20) unsigned DEFAULT NULL,
  `edge_scheme` enum('none','O','=','||','L','П','long_one','short_one') NOT NULL DEFAULT 'none',
  `custom_name` varchar(255) DEFAULT NULL,
  `custom_fittings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_fittings`)),
  `custom_operations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_operations`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_positions_material_id_foreign` (`material_id`),
  KEY `project_positions_edge_material_id_foreign` (`edge_material_id`),
  KEY `project_positions_detail_type_id_foreign` (`detail_type_id`),
  KEY `project_positions_facade_material_id_foreign` (`facade_material_id`),
  KEY `project_positions_kind_index` (`kind`),
  KEY `project_positions_material_price_id_foreign` (`material_price_id`),
  KEY `project_positions_justification_idx` (`project_id`,`requires_price_justification`),
  KEY `project_positions_finished_product_specification_id_foreign` (`finished_product_specification_id`),
  CONSTRAINT `project_positions_detail_type_id_foreign` FOREIGN KEY (`detail_type_id`) REFERENCES `detail_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_edge_material_id_foreign` FOREIGN KEY (`edge_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_facade_material_id_foreign` FOREIGN KEY (`facade_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_finished_product_specification_id_foreign` FOREIGN KEY (`finished_product_specification_id`) REFERENCES `finished_product_specifications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_material_price_id_foreign` FOREIGN KEY (`material_price_id`) REFERENCES `material_prices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_price_list_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_price_list_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `price_list_version_id` bigint(20) unsigned NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'material_price' COMMENT 'material_price | operation_price | facade_price',
  `linked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pplv_project_version_role_unique` (`project_id`,`price_list_version_id`,`role`),
  KEY `project_price_list_versions_price_list_version_id_foreign` (`price_list_version_id`),
  CONSTRAINT `project_price_list_versions_price_list_version_id_foreign` FOREIGN KEY (`price_list_version_id`) REFERENCES `price_list_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_price_list_versions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_profile_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_profile_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `profile_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned DEFAULT NULL,
  `rate_fixed` decimal(10,2) NOT NULL COMMENT 'Фиксированная ставка руб/ч',
  `fixed_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Когда зафиксирована',
  `calculation_method` varchar(255) DEFAULT NULL COMMENT 'Метод расчёта: average, median, manual',
  `sources_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Снимок источников для расчёта' CHECK (json_valid(`sources_snapshot`)),
  `justification_snapshot` text DEFAULT NULL COMMENT 'Обоснование/примечание',
  `is_locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Заблокирована ли ставка от изменений',
  `locked_at` timestamp NULL DEFAULT NULL,
  `locked_reason` varchar(255) DEFAULT NULL,
  `lock_reason` text DEFAULT NULL COMMENT 'Причина блокировки',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_profile_rates_project_id_profile_id_region_id_unique` (`project_id`,`profile_id`,`region_id`),
  KEY `project_profile_rates_region_id_foreign` (`region_id`),
  KEY `project_profile_rates_project_id_index` (`project_id`),
  KEY `project_profile_rates_profile_id_region_id_index` (`profile_id`,`region_id`),
  KEY `project_profile_rates_project_id_profile_id_is_locked_index` (`project_id`,`profile_id`,`is_locked`),
  CONSTRAINT `project_profile_rates_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `position_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_profile_rates_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_profile_rates_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_revisions` (
  `id` char(36) NOT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `number` int(10) unsigned NOT NULL,
  `status` enum('locked','published','stale') NOT NULL DEFAULT 'locked',
  `snapshot_json` longtext DEFAULT NULL,
  `snapshot_hash` char(64) DEFAULT NULL,
  `app_version` varchar(50) DEFAULT NULL,
  `calculation_engine_version` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `locked_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `stale_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_revisions_project_id_number_unique` (`project_id`,`number`),
  KEY `project_revisions_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `project_revisions_project_id_number_index` (`project_id`,`number`),
  KEY `project_revisions_project_id_status_index` (`project_id`,`status`),
  KEY `project_revisions_snapshot_hash_index` (`snapshot_hash`),
  CONSTRAINT `project_revisions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_revisions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` varchar(32) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned DEFAULT NULL,
  `number` varchar(255) NOT NULL,
  `expert_name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `waste_coefficient` decimal(4,2) NOT NULL DEFAULT 1.20,
  `repair_coefficient` decimal(4,2) NOT NULL DEFAULT 1.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `waste_plate_coefficient` decimal(5,2) DEFAULT NULL COMMENT 'Коэффициент отходов для плитных материалов',
  `waste_plate_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_plate_description`)),
  `show_waste_plate_description` tinyint(1) NOT NULL DEFAULT 0,
  `waste_edge_coefficient` decimal(5,2) DEFAULT NULL COMMENT 'Коэффициент отходов для кромки',
  `waste_edge_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_edge_description`)),
  `show_waste_edge_description` tinyint(1) NOT NULL DEFAULT 0,
  `waste_operations_coefficient` decimal(5,2) DEFAULT NULL COMMENT 'Коэффициент отходов для операций',
  `waste_operations_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_operations_description`)),
  `show_waste_operations_description` tinyint(1) NOT NULL DEFAULT 0,
  `normohour_rate` decimal(10,2) DEFAULT NULL COMMENT 'Ставка, руб/час',
  `normohour_profile_id` bigint(20) unsigned DEFAULT NULL,
  `normohour_rate_fixed` decimal(10,2) DEFAULT NULL COMMENT 'Ставка, зафиксированная в проекте (при режиме fixed)',
  `normohour_fixed_at` timestamp NULL DEFAULT NULL COMMENT 'Когда была зафиксирована ставка',
  `normohour_sources_snapshot` longtext DEFAULT NULL COMMENT 'JSON снимок источников и расчётов на момент фиксации',
  `normohour_rate_mode` enum('auto','manual','fixed') NOT NULL DEFAULT 'auto' COMMENT 'Режим: auto (текущая из справочника), manual (ручной ввод), fixed (зафиксирована)',
  `normohour_region` varchar(255) DEFAULT NULL COMMENT 'Город/регион',
  `normohour_date` date DEFAULT NULL COMMENT 'Дата актуальности ставки',
  `normohour_method` enum('market_vacancies','commercial_proposals','contractor_estimate','other') DEFAULT NULL COMMENT 'Метод определения (рыночный/КП/договор/иное)',
  `normohour_justification` longtext DEFAULT NULL COMMENT 'Текст обоснования ставки',
  `price_confirmation_freshness_days` smallint(5) unsigned DEFAULT 7 COMMENT 'Срок актуальности подтверждения цены материала, дней',
  `apply_waste_to_plate` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Применять коэффициент отходов к плитным материалам',
  `apply_waste_to_edge` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Применять коэффициент отходов к кромке',
  `apply_waste_to_operations` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Применять коэффициент отходов к операциям',
  `use_area_calc_mode` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Использовать расчёт по площади вместо по листам',
  `default_plate_material_id` bigint(20) unsigned DEFAULT NULL,
  `default_edge_material_id` bigint(20) unsigned DEFAULT NULL,
  `text_blocks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON массив текстовых блоков для вывода в конец сметы' CHECK (json_valid(`text_blocks`)),
  `report_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`report_settings`)),
  `sawing_price_per_m2` decimal(10,2) DEFAULT 50.00 COMMENT 'Цена распиловки за м²',
  `gluing_price_per_m` decimal(10,2) DEFAULT 30.00 COMMENT 'Цена оклейки кромок за м',
  `facade_width_allowance_mm` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Припуск фасада по ширине, мм',
  `facade_height_allowance_mm` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Припуск фасада по высоте, мм',
  PRIMARY KEY (`id`),
  UNIQUE KEY `projects_public_id_unique` (`public_id`),
  KEY `projects_user_id_foreign` (`user_id`),
  KEY `projects_default_plate_material_id_foreign` (`default_plate_material_id`),
  KEY `projects_default_edge_material_id_foreign` (`default_edge_material_id`),
  KEY `projects_normohour_profile_id_foreign` (`normohour_profile_id`),
  KEY `projects_region_id_foreign` (`region_id`),
  KEY `projects_archived_at_index` (`archived_at`),
  CONSTRAINT `projects_default_edge_material_id_foreign` FOREIGN KEY (`default_edge_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_default_plate_material_id_foreign` FOREIGN KEY (`default_plate_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_normohour_profile_id_foreign` FOREIGN KEY (`normohour_profile_id`) REFERENCES `position_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `regions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `regions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `region_name` varchar(255) NOT NULL,
  `capital_city` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `regions_region_name_unique` (`region_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `revision_publication_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revision_publication_views` (
  `id` char(36) NOT NULL,
  `revision_publication_id` char(36) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rev_pub_views_pubid_viewed_at_idx` (`revision_publication_id`,`viewed_at`),
  CONSTRAINT `revision_publication_views_revision_publication_id_foreign` FOREIGN KEY (`revision_publication_id`) REFERENCES `revision_publications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `revision_publications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revision_publications` (
  `id` char(36) NOT NULL,
  `project_revision_id` char(36) NOT NULL,
  `public_id` varchar(32) NOT NULL,
  `public_token_hash` varchar(128) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` timestamp NULL DEFAULT NULL,
  `access_level` enum('public_readonly','restricted_token','auth_only') NOT NULL DEFAULT 'public_readonly',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `revision_publications_public_id_unique` (`public_id`),
  KEY `revision_publications_project_revision_id_is_active_index` (`project_revision_id`,`is_active`),
  CONSTRAINT `revision_publications_project_revision_id_foreign` FOREIGN KEY (`project_revision_id`) REFERENCES `project_revisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `revision_run_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revision_run_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `revision_run_id` bigint(20) unsigned NOT NULL,
  `project_position_id` bigint(20) unsigned DEFAULT NULL,
  `project_fitting_id` bigint(20) unsigned DEFAULT NULL,
  `material_id` bigint(20) unsigned DEFAULT NULL,
  `source_url` text DEFAULT NULL,
  `status` enum('PENDING','OK','BLOCKED','TIMEOUT','PARSE_ERROR','NO_TEMPLATE','NEEDS_MANUAL') NOT NULL DEFAULT 'PENDING',
  `state` varchar(32) DEFAULT NULL,
  `stage` varchar(64) DEFAULT NULL,
  `reason_code` varchar(64) DEFAULT NULL,
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_error_at` timestamp NULL DEFAULT NULL,
  `diagnostics_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`diagnostics_json`)),
  `message` text DEFAULT NULL,
  `price_history_id` bigint(20) unsigned DEFAULT NULL,
  `cost_driver_type` varchar(20) DEFAULT NULL,
  `evidence_subject_type` varchar(100) DEFAULT NULL,
  `evidence_subject_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `revision_run_items_material_id_foreign` (`material_id`),
  KEY `revision_run_items_price_history_id_foreign` (`price_history_id`),
  KEY `revision_run_items_run_status_idx` (`revision_run_id`,`status`),
  KEY `revision_run_items_position_idx` (`project_position_id`),
  KEY `revision_run_items_run_state_idx` (`revision_run_id`,`state`),
  KEY `revision_run_items_project_fitting_id_foreign` (`project_fitting_id`),
  KEY `revision_run_items_run_driver_idx` (`revision_run_id`,`cost_driver_type`),
  KEY `revision_run_items_subject_idx` (`evidence_subject_type`,`evidence_subject_id`),
  CONSTRAINT `revision_run_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `revision_run_items_price_history_id_foreign` FOREIGN KEY (`price_history_id`) REFERENCES `material_price_histories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `revision_run_items_project_fitting_id_foreign` FOREIGN KEY (`project_fitting_id`) REFERENCES `project_fittings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `revision_run_items_project_position_id_foreign` FOREIGN KEY (`project_position_id`) REFERENCES `project_positions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `revision_run_items_revision_run_id_foreign` FOREIGN KEY (`revision_run_id`) REFERENCES `revision_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `revision_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revision_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `initiator_user_id` bigint(20) unsigned NOT NULL,
  `status` enum('PENDING','IN_PROGRESS','NEEDS_MANUAL','READY','FINALIZED','FAILED') NOT NULL DEFAULT 'PENDING',
  `total_items` int(10) unsigned NOT NULL DEFAULT 0,
  `ok_items` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_items` int(10) unsigned NOT NULL DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `project_revision_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `revision_runs_initiator_user_id_foreign` (`initiator_user_id`),
  KEY `revision_runs_project_status_idx` (`project_id`,`status`),
  KEY `revision_runs_project_revision_id_idx` (`project_revision_id`),
  CONSTRAINT `revision_runs_initiator_user_id_foreign` FOREIGN KEY (`initiator_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `revision_runs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `revision_runs_project_revision_id_foreign` FOREIGN KEY (`project_revision_id`) REFERENCES `project_revisions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `social_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(30) NOT NULL,
  `provider_user_id` varchar(255) NOT NULL,
  `provider_username` varchar(255) DEFAULT NULL,
  `provider_email` varchar(255) DEFAULT NULL,
  `provider_phone` varchar(20) DEFAULT NULL,
  `linked_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `unlinked_at` timestamp NULL DEFAULT NULL,
  `raw_profile_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_profile_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_accounts_provider_provider_user_id_unique` (`provider`,`provider_user_id`),
  UNIQUE KEY `social_accounts_user_provider_unique` (`user_id`,`provider`),
  KEY `social_accounts_user_id_index` (`user_id`),
  KEY `social_accounts_user_active_idx` (`user_id`,`is_active`),
  KEY `social_accounts_provider_identity_active_idx` (`provider`,`provider_user_id`,`is_active`),
  CONSTRAINT `social_accounts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifier_active_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifier_active_versions` (
  `classifier_id` bigint(20) unsigned NOT NULL,
  `classifier_version_id` bigint(20) unsigned NOT NULL,
  `activated_at` datetime NOT NULL,
  `activated_by` bigint(20) unsigned DEFAULT NULL,
  `activation_reason` varchar(512) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`classifier_id`),
  UNIQUE KEY `stat_cls_active_versions_version_unique` (`classifier_version_id`),
  KEY `statistical_classifier_active_versions_activated_by_foreign` (`activated_by`),
  KEY `stat_cls_active_versions_membership_fk` (`classifier_id`,`classifier_version_id`),
  CONSTRAINT `stat_cls_active_versions_classifier_fk` FOREIGN KEY (`classifier_id`) REFERENCES `statistical_classifiers` (`id`),
  CONSTRAINT `stat_cls_active_versions_membership_fk` FOREIGN KEY (`classifier_id`, `classifier_version_id`) REFERENCES `statistical_classifier_versions` (`classifier_id`, `id`),
  CONSTRAINT `statistical_classifier_active_versions_activated_by_foreign` FOREIGN KEY (`activated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifier_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifier_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `classifier_id` bigint(20) unsigned NOT NULL,
  `source_file_id` bigint(20) unsigned NOT NULL,
  `attempt` int(10) unsigned NOT NULL,
  `status` enum('pending','parsing','validating','ready','failed') NOT NULL,
  `parser_code` varchar(128) NOT NULL,
  `parser_version` varchar(64) NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `nodes_parsed` int(10) unsigned DEFAULT NULL,
  `sections_count` int(10) unsigned DEFAULT NULL,
  `validation_errors_count` int(10) unsigned NOT NULL DEFAULT 0,
  `validation_warnings_count` int(10) unsigned NOT NULL DEFAULT 0,
  `validation_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_summary_json`)),
  `error_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`error_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_cls_imports_source_attempt_unique` (`source_file_id`,`attempt`),
  UNIQUE KEY `stat_cls_imports_classifier_id_unique` (`classifier_id`,`id`),
  UNIQUE KEY `statistical_classifier_imports_public_id_unique` (`public_id`),
  KEY `stat_cls_imports_classifier_source_fk` (`classifier_id`,`source_file_id`),
  CONSTRAINT `stat_cls_imports_classifier_fk` FOREIGN KEY (`classifier_id`) REFERENCES `statistical_classifiers` (`id`),
  CONSTRAINT `stat_cls_imports_classifier_source_fk` FOREIGN KEY (`classifier_id`, `source_file_id`) REFERENCES `statistical_classifier_source_files` (`classifier_id`, `id`),
  CONSTRAINT `stat_cls_imports_attempt_positive_chk` CHECK (`attempt` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifier_item_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifier_item_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `statistical_classifier_item_id` bigint(20) unsigned NOT NULL,
  `classifier_version_id` bigint(20) unsigned NOT NULL,
  `classifier_node_id` bigint(20) unsigned DEFAULT NULL,
  `mapping_type` enum('exact','parent_aggregate','local_rosstat','ambiguous','unmapped') NOT NULL,
  `review_status` enum('proposed','needs_review','confirmed','rejected') NOT NULL,
  `method` varchar(128) NOT NULL,
  `confidence` decimal(5,4) DEFAULT NULL,
  `evidence_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evidence_json`)),
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_cls_item_mappings_item_version_unique` (`statistical_classifier_item_id`,`classifier_version_id`),
  UNIQUE KEY `statistical_classifier_item_mappings_public_id_unique` (`public_id`),
  KEY `statistical_classifier_item_mappings_confirmed_by_foreign` (`confirmed_by`),
  KEY `stat_cls_item_mappings_version_node_review_idx` (`classifier_version_id`,`classifier_node_id`,`review_status`),
  CONSTRAINT `stat_cls_item_mappings_item_fk` FOREIGN KEY (`statistical_classifier_item_id`) REFERENCES `statistical_classifier_items` (`id`),
  CONSTRAINT `stat_cls_item_mappings_version_fk` FOREIGN KEY (`classifier_version_id`) REFERENCES `statistical_classifier_versions` (`id`),
  CONSTRAINT `stat_cls_item_mappings_version_node_fk` FOREIGN KEY (`classifier_version_id`, `classifier_node_id`) REFERENCES `statistical_classifier_nodes` (`classifier_version_id`, `id`),
  CONSTRAINT `statistical_classifier_item_mappings_confirmed_by_foreign` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifier_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifier_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `classifier_code` varchar(64) NOT NULL,
  `item_code` varchar(128) NOT NULL,
  `name` varchar(512) NOT NULL,
  `normalized_name` varchar(512) NOT NULL,
  `parent_item_id` bigint(20) unsigned DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_classifier_dataset_classifier_item_unique` (`dataset_id`,`classifier_code`,`item_code`),
  UNIQUE KEY `statistical_classifier_items_public_id_unique` (`public_id`),
  KEY `statistical_classifier_items_parent_item_id_foreign` (`parent_item_id`),
  KEY `stat_classifier_item_code_idx` (`item_code`),
  KEY `stat_classifier_dataset_normalized_name_idx` (`dataset_id`,`normalized_name`),
  CONSTRAINT `statistical_classifier_items_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_classifier_items_parent_item_id_foreign` FOREIGN KEY (`parent_item_id`) REFERENCES `statistical_classifier_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifier_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifier_nodes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `classifier_version_id` bigint(20) unsigned NOT NULL,
  `code` varchar(128) NOT NULL,
  `name` varchar(512) NOT NULL,
  `normalized_name` varchar(512) NOT NULL,
  `semantic_level` enum('section','class','subclass','group','subgroup','type','category','subcategory') NOT NULL,
  `formal_depth` tinyint(3) unsigned NOT NULL,
  `parent_node_id` bigint(20) unsigned DEFAULT NULL,
  `source_order` int(10) unsigned DEFAULT NULL,
  `notes_text` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_cls_nodes_version_code_unique` (`classifier_version_id`,`code`),
  UNIQUE KEY `stat_cls_nodes_version_id_unique` (`classifier_version_id`,`id`),
  UNIQUE KEY `statistical_classifier_nodes_public_id_unique` (`public_id`),
  KEY `stat_cls_nodes_parent_order_idx` (`classifier_version_id`,`parent_node_id`,`source_order`),
  CONSTRAINT `stat_cls_nodes_version_parent_fk` FOREIGN KEY (`classifier_version_id`, `parent_node_id`) REFERENCES `statistical_classifier_nodes` (`classifier_version_id`, `id`),
  CONSTRAINT `statistical_classifier_nodes_classifier_version_id_foreign` FOREIGN KEY (`classifier_version_id`) REFERENCES `statistical_classifier_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifier_source_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifier_source_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `classifier_id` bigint(20) unsigned NOT NULL,
  `trust_tier` enum('official_authoritative','operator_official_upload','reference_fixture') NOT NULL,
  `source_page_url` text DEFAULT NULL,
  `download_url` text DEFAULT NULL,
  `resolved_url` text DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `storage_disk` varchar(64) NOT NULL,
  `storage_path` text NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `size_bytes` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `etag` varchar(512) DEFAULT NULL,
  `last_modified_at` datetime DEFAULT NULL,
  `downloaded_at` datetime DEFAULT NULL,
  `declared_version_label` varchar(128) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_cls_src_classifier_sha_unique` (`classifier_id`,`sha256`),
  UNIQUE KEY `stat_cls_src_classifier_id_unique` (`classifier_id`,`id`),
  UNIQUE KEY `statistical_classifier_source_files_public_id_unique` (`public_id`),
  CONSTRAINT `stat_cls_src_classifier_fk` FOREIGN KEY (`classifier_id`) REFERENCES `statistical_classifiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifier_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifier_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `classifier_id` bigint(20) unsigned NOT NULL,
  `classifier_import_id` bigint(20) unsigned NOT NULL,
  `version_label` varchar(128) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `approved_at` date DEFAULT NULL,
  `source_published_at` datetime DEFAULT NULL,
  `status` enum('ready','scheduled','superseded') NOT NULL,
  `node_count` int(10) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_cls_versions_classifier_label_unique` (`classifier_id`,`version_label`),
  UNIQUE KEY `stat_cls_versions_classifier_id_unique` (`classifier_id`,`id`),
  UNIQUE KEY `statistical_classifier_versions_public_id_unique` (`public_id`),
  UNIQUE KEY `stat_cls_versions_import_unique` (`classifier_import_id`),
  KEY `stat_cls_versions_classifier_import_fk` (`classifier_id`,`classifier_import_id`),
  CONSTRAINT `stat_cls_versions_classifier_import_fk` FOREIGN KEY (`classifier_id`, `classifier_import_id`) REFERENCES `statistical_classifier_imports` (`classifier_id`, `id`),
  CONSTRAINT `statistical_classifier_versions_classifier_id_foreign` FOREIGN KEY (`classifier_id`) REFERENCES `statistical_classifiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_classifiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_classifiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `code` varchar(64) NOT NULL,
  `standard_code` varchar(128) NOT NULL,
  `name` varchar(512) NOT NULL,
  `issuing_authority` varchar(255) NOT NULL,
  `responsible_body` varchar(255) DEFAULT NULL,
  `official_distributor` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `statistical_classifiers_public_id_unique` (`public_id`),
  UNIQUE KEY `stat_classifiers_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_dataset_active_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_dataset_active_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `reporting_year` smallint(5) unsigned NOT NULL,
  `reporting_month` tinyint(3) unsigned NOT NULL,
  `source_file_id` bigint(20) unsigned NOT NULL,
  `activated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `activated_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_active_dataset_period_unique` (`dataset_id`,`reporting_year`,`reporting_month`),
  UNIQUE KEY `statistical_dataset_active_files_public_id_unique` (`public_id`),
  KEY `statistical_dataset_active_files_source_file_id_foreign` (`source_file_id`),
  KEY `statistical_dataset_active_files_activated_by_user_id_foreign` (`activated_by_user_id`),
  CONSTRAINT `statistical_dataset_active_files_activated_by_user_id_foreign` FOREIGN KEY (`activated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `statistical_dataset_active_files_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_dataset_active_files_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `statistical_source_files` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_dataset_active_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_dataset_active_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `import_id` bigint(20) unsigned NOT NULL,
  `published_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `published_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_active_imports_dataset_unique` (`dataset_id`),
  UNIQUE KEY `stat_active_imports_import_unique` (`import_id`),
  UNIQUE KEY `statistical_dataset_active_imports_public_id_unique` (`public_id`),
  KEY `statistical_dataset_active_imports_published_by_user_id_foreign` (`published_by_user_id`),
  CONSTRAINT `statistical_dataset_active_imports_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_dataset_active_imports_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `statistical_imports` (`id`),
  CONSTRAINT `statistical_dataset_active_imports_published_by_user_id_foreign` FOREIGN KEY (`published_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_datasets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_datasets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `code` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `provider_code` varchar(64) NOT NULL,
  `provider_name` varchar(255) NOT NULL,
  `data_kind` varchar(64) NOT NULL,
  `frequency` varchar(32) NOT NULL,
  `classifier_code` varchar(64) DEFAULT NULL,
  `territory_scope` varchar(64) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `automatic_check_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `check_schedule` varchar(64) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `statistical_datasets_public_id_unique` (`public_id`),
  UNIQUE KEY `statistical_datasets_code_unique` (`code`),
  KEY `stat_datasets_enabled_auto_idx` (`is_enabled`,`automatic_check_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_import_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_import_issues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `import_id` bigint(20) unsigned NOT NULL,
  `severity` varchar(32) NOT NULL,
  `code` varchar(128) NOT NULL,
  `message` text NOT NULL,
  `sheet_name` varchar(255) DEFAULT NULL,
  `source_row` int(10) unsigned DEFAULT NULL,
  `source_column` varchar(16) DEFAULT NULL,
  `classifier_item_code` varchar(128) DEFAULT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `statistical_import_issues_public_id_unique` (`public_id`),
  KEY `stat_import_issues_import_severity_idx` (`import_id`,`severity`),
  KEY `stat_import_issues_code_idx` (`code`),
  KEY `stat_import_issues_import_created_idx` (`import_id`,`created_at`),
  CONSTRAINT `statistical_import_issues_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `statistical_imports` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_import_previews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_import_previews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `source_file_id` bigint(20) unsigned NOT NULL,
  `importer_code` varchar(128) NOT NULL,
  `importer_version` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL,
  `cache_key` char(64) NOT NULL,
  `requested_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `sheets_total` int(10) unsigned NOT NULL DEFAULT 0,
  `supported_sheets` int(10) unsigned NOT NULL DEFAULT 0,
  `ignored_sheets` int(10) unsigned NOT NULL DEFAULT 0,
  `commodity_occurrences` bigint(20) unsigned NOT NULL DEFAULT 0,
  `unique_classifier_items` bigint(20) unsigned NOT NULL DEFAULT 0,
  `observation_candidates` bigint(20) unsigned NOT NULL DEFAULT 0,
  `numeric_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `missing_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `footnoted_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `warnings_count` int(10) unsigned NOT NULL DEFAULT 0,
  `fatal_errors_count` int(10) unsigned NOT NULL DEFAULT 0,
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `failure_code` varchar(128) DEFAULT NULL,
  `failure_message` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `statistical_import_previews_public_id_unique` (`public_id`),
  KEY `statistical_import_previews_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `stat_import_previews_cache_key_idx` (`cache_key`),
  KEY `stat_import_previews_source_created_idx` (`source_file_id`,`created_at`),
  KEY `stat_import_previews_dataset_status_idx` (`dataset_id`,`status`),
  KEY `stat_import_previews_status_expires_idx` (`status`,`expires_at`),
  KEY `stat_import_previews_created_idx` (`created_at`),
  CONSTRAINT `statistical_import_previews_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_import_previews_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `statistical_import_previews_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `statistical_source_files` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `source_file_id` bigint(20) unsigned NOT NULL,
  `importer_code` varchar(128) NOT NULL,
  `importer_version` varchar(64) NOT NULL,
  `attempt_no` int(10) unsigned NOT NULL,
  `retry_of_import_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `successful_dedupe_key` char(64) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `superseded_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `rows_scanned` bigint(20) unsigned NOT NULL DEFAULT 0,
  `observations_parsed` bigint(20) unsigned NOT NULL DEFAULT 0,
  `observations_valid` bigint(20) unsigned NOT NULL DEFAULT 0,
  `observations_rejected` bigint(20) unsigned NOT NULL DEFAULT 0,
  `warnings_count` int(10) unsigned NOT NULL DEFAULT 0,
  `errors_count` int(10) unsigned NOT NULL DEFAULT 0,
  `initiated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `published_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `supersedes_import_id` bigint(20) unsigned DEFAULT NULL,
  `failure_code` varchar(128) DEFAULT NULL,
  `failure_message` text DEFAULT NULL,
  `validation_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_summary_json`)),
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_imports_file_importer_version_attempt_unique` (`source_file_id`,`importer_code`,`importer_version`,`attempt_no`),
  UNIQUE KEY `statistical_imports_public_id_unique` (`public_id`),
  UNIQUE KEY `statistical_imports_successful_dedupe_key_unique` (`successful_dedupe_key`),
  KEY `statistical_imports_retry_of_import_id_foreign` (`retry_of_import_id`),
  KEY `statistical_imports_initiated_by_user_id_foreign` (`initiated_by_user_id`),
  KEY `statistical_imports_published_by_user_id_foreign` (`published_by_user_id`),
  KEY `statistical_imports_supersedes_import_id_foreign` (`supersedes_import_id`),
  KEY `stat_imports_dataset_status_idx` (`dataset_id`,`status`),
  KEY `stat_imports_status_created_idx` (`status`,`created_at`),
  KEY `stat_imports_importer_version_idx` (`importer_code`,`importer_version`),
  CONSTRAINT `statistical_imports_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_imports_initiated_by_user_id_foreign` FOREIGN KEY (`initiated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `statistical_imports_published_by_user_id_foreign` FOREIGN KEY (`published_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `statistical_imports_retry_of_import_id_foreign` FOREIGN KEY (`retry_of_import_id`) REFERENCES `statistical_imports` (`id`),
  CONSTRAINT `statistical_imports_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `statistical_source_files` (`id`),
  CONSTRAINT `statistical_imports_supersedes_import_id_foreign` FOREIGN KEY (`supersedes_import_id`) REFERENCES `statistical_imports` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_indicators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_indicators` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `code` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `data_kind` varchar(64) NOT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_indicators_dataset_code_unique` (`dataset_id`,`code`),
  UNIQUE KEY `statistical_indicators_public_id_unique` (`public_id`),
  KEY `stat_indicators_data_kind_idx` (`data_kind`),
  CONSTRAINT `statistical_indicators_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_observations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `import_id` bigint(20) unsigned NOT NULL,
  `series_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `value` decimal(20,10) DEFAULT NULL,
  `missing_reason` varchar(64) DEFAULT NULL,
  `source_file_id` bigint(20) unsigned NOT NULL,
  `sheet_name` varchar(255) NOT NULL,
  `source_row` int(10) unsigned NOT NULL,
  `source_column` varchar(16) NOT NULL,
  `source_cell_address` varchar(32) DEFAULT NULL,
  `source_value_raw` varchar(255) DEFAULT NULL,
  `footnote_marker` varchar(64) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_observations_import_series_period_unique` (`import_id`,`series_id`,`period_start`),
  UNIQUE KEY `statistical_observations_public_id_unique` (`public_id`),
  KEY `statistical_observations_source_file_id_foreign` (`source_file_id`),
  KEY `stat_observations_series_period_idx` (`series_id`,`period_start`),
  KEY `stat_observations_import_series_idx` (`import_id`,`series_id`),
  KEY `stat_observations_import_period_idx` (`import_id`,`period_start`),
  CONSTRAINT `statistical_observations_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `statistical_imports` (`id`),
  CONSTRAINT `statistical_observations_series_id_foreign` FOREIGN KEY (`series_id`) REFERENCES `statistical_series` (`id`),
  CONSTRAINT `statistical_observations_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `statistical_source_files` (`id`),
  CONSTRAINT `stat_observations_value_missing_chk` CHECK (`value` is not null and `missing_reason` is null or `value` is null and `missing_reason` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_public_series_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_public_series_pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `import_id` bigint(20) unsigned NOT NULL,
  `series_id` bigint(20) unsigned NOT NULL,
  `classifier_item_id` bigint(20) unsigned NOT NULL,
  `source_file_id` bigint(20) unsigned NOT NULL,
  `slug` varchar(191) DEFAULT NULL,
  `is_indexable` tinyint(1) NOT NULL DEFAULT 0,
  `indexability_status` varchar(64) NOT NULL,
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `observations_count` int(10) unsigned NOT NULL DEFAULT 0,
  `factors_count` int(10) unsigned NOT NULL DEFAULT 0,
  `coefficient_raw` decimal(38,20) DEFAULT NULL,
  `coefficient` decimal(38,12) DEFAULT NULL,
  `change_percent_raw` decimal(38,20) DEFAULT NULL,
  `change_percent` decimal(38,2) DEFAULT NULL,
  `min_index_value` decimal(20,10) DEFAULT NULL,
  `min_index_period` date DEFAULT NULL,
  `max_index_value` decimal(20,10) DEFAULT NULL,
  `max_index_period` date DEFAULT NULL,
  `generated_at` datetime NOT NULL,
  `source_published_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `statistical_public_series_pages_public_id_unique` (`public_id`),
  UNIQUE KEY `statistical_public_series_pages_series_id_unique` (`series_id`),
  UNIQUE KEY `statistical_public_series_pages_slug_unique` (`slug`),
  KEY `statistical_public_series_pages_classifier_item_id_foreign` (`classifier_item_id`),
  KEY `statistical_public_series_pages_source_file_id_foreign` (`source_file_id`),
  KEY `stat_public_pages_dataset_indexable_idx` (`dataset_id`,`is_indexable`),
  KEY `stat_public_pages_import_idx` (`import_id`),
  KEY `statistical_public_series_pages_is_indexable_index` (`is_indexable`),
  CONSTRAINT `statistical_public_series_pages_classifier_item_id_foreign` FOREIGN KEY (`classifier_item_id`) REFERENCES `statistical_classifier_items` (`id`),
  CONSTRAINT `statistical_public_series_pages_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_public_series_pages_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `statistical_imports` (`id`),
  CONSTRAINT `statistical_public_series_pages_series_id_foreign` FOREIGN KEY (`series_id`) REFERENCES `statistical_series` (`id`),
  CONSTRAINT `statistical_public_series_pages_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `statistical_source_files` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_series`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_series` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `indicator_id` bigint(20) unsigned NOT NULL,
  `classifier_item_id` bigint(20) unsigned NOT NULL,
  `territory_id` bigint(20) unsigned NOT NULL,
  `frequency` varchar(32) NOT NULL,
  `comparison_basis` varchar(64) NOT NULL,
  `unit` varchar(32) NOT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_series_dimensions_unique` (`dataset_id`,`indicator_id`,`classifier_item_id`,`territory_id`,`frequency`,`comparison_basis`,`unit`),
  UNIQUE KEY `statistical_series_public_id_unique` (`public_id`),
  KEY `statistical_series_indicator_id_foreign` (`indicator_id`),
  KEY `statistical_series_classifier_item_id_foreign` (`classifier_item_id`),
  KEY `statistical_series_territory_id_foreign` (`territory_id`),
  KEY `stat_series_dataset_frequency_basis_idx` (`dataset_id`,`frequency`,`comparison_basis`),
  KEY `stat_series_dataset_classifier_idx` (`dataset_id`,`classifier_item_id`),
  CONSTRAINT `statistical_series_classifier_item_id_foreign` FOREIGN KEY (`classifier_item_id`) REFERENCES `statistical_classifier_items` (`id`),
  CONSTRAINT `statistical_series_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_series_indicator_id_foreign` FOREIGN KEY (`indicator_id`) REFERENCES `statistical_indicators` (`id`),
  CONSTRAINT `statistical_series_territory_id_foreign` FOREIGN KEY (`territory_id`) REFERENCES `statistical_territories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_source_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_source_checks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `candidate_url` text DEFAULT NULL,
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `content_type` varchar(255) DEFAULT NULL,
  `content_length` bigint(20) unsigned DEFAULT NULL,
  `etag` varchar(255) DEFAULT NULL,
  `last_modified` varchar(255) DEFAULT NULL,
  `downloaded_file_id` bigint(20) unsigned DEFAULT NULL,
  `error_code` varchar(128) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `statistical_source_checks_public_id_unique` (`public_id`),
  KEY `stat_checks_source_started_idx` (`source_id`,`started_at`),
  KEY `stat_checks_status_started_idx` (`status`,`started_at`),
  KEY `stat_checks_downloaded_file_idx` (`downloaded_file_id`),
  CONSTRAINT `statistical_source_checks_downloaded_file_id_foreign` FOREIGN KEY (`downloaded_file_id`) REFERENCES `statistical_source_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `statistical_source_checks_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `statistical_sources` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_source_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_source_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `acquisition_method` varchar(32) NOT NULL,
  `reporting_year` smallint(5) unsigned DEFAULT NULL,
  `reporting_month` tinyint(3) unsigned DEFAULT NULL,
  `source_url` text DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_path` text NOT NULL,
  `storage_disk` varchar(64) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `http_etag` varchar(255) DEFAULT NULL,
  `http_last_modified` varchar(255) DEFAULT NULL,
  `downloaded_at` datetime DEFAULT NULL,
  `uploaded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `detected_at` datetime NOT NULL,
  `status` varchar(32) NOT NULL,
  `validation_status` varchar(32) NOT NULL,
  `validation_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_summary_json`)),
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `activated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `supersedes_file_id` bigint(20) unsigned DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_files_dataset_sha_unique` (`dataset_id`,`sha256`),
  UNIQUE KEY `statistical_source_files_public_id_unique` (`public_id`),
  KEY `statistical_source_files_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `statistical_source_files_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `statistical_source_files_activated_by_user_id_foreign` (`activated_by_user_id`),
  KEY `statistical_source_files_supersedes_file_id_foreign` (`supersedes_file_id`),
  KEY `stat_files_dataset_period_status_idx` (`dataset_id`,`reporting_year`,`reporting_month`,`status`),
  KEY `stat_files_source_detected_idx` (`source_id`,`detected_at`),
  KEY `stat_files_status_detected_idx` (`status`,`detected_at`),
  CONSTRAINT `statistical_source_files_activated_by_user_id_foreign` FOREIGN KEY (`activated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `statistical_source_files_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`),
  CONSTRAINT `statistical_source_files_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `statistical_source_files_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `statistical_sources` (`id`),
  CONSTRAINT `statistical_source_files_supersedes_file_id_foreign` FOREIGN KEY (`supersedes_file_id`) REFERENCES `statistical_source_files` (`id`),
  CONSTRAINT `statistical_source_files_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stat_files_month_chk` CHECK (`reporting_month` is null or `reporting_month` between 1 and 12),
  CONSTRAINT `stat_files_period_pair_chk` CHECK (`reporting_year` is null and `reporting_month` is null or `reporting_year` is not null and `reporting_month` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `dataset_id` bigint(20) unsigned NOT NULL,
  `code` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `source_page_url` text DEFAULT NULL,
  `download_url_template` text DEFAULT NULL,
  `filename_template` varchar(255) DEFAULT NULL,
  `http_method` varchar(8) NOT NULL DEFAULT 'GET',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `automatic_check_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_checked_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `next_check_at` datetime DEFAULT NULL,
  `consecutive_failures` int(10) unsigned NOT NULL DEFAULT 0,
  `last_http_status` smallint(5) unsigned DEFAULT NULL,
  `last_error_code` varchar(128) DEFAULT NULL,
  `last_error_message` text DEFAULT NULL,
  `settings_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stat_sources_dataset_code_unique` (`dataset_id`,`code`),
  UNIQUE KEY `statistical_sources_public_id_unique` (`public_id`),
  KEY `stat_sources_dataset_enabled_idx` (`dataset_id`,`is_enabled`),
  KEY `stat_sources_enabled_auto_next_idx` (`is_enabled`,`automatic_check_enabled`,`next_check_at`),
  CONSTRAINT `statistical_sources_dataset_id_foreign` FOREIGN KEY (`dataset_id`) REFERENCES `statistical_datasets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `statistical_territories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statistical_territories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `normalized_name` varchar(255) NOT NULL,
  `type` varchar(64) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `provider_code` varchar(128) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `statistical_territories_public_id_unique` (`public_id`),
  UNIQUE KEY `statistical_territories_code_unique` (`code`),
  KEY `stat_territories_parent_type_idx` (`parent_id`,`type`),
  KEY `stat_territories_normalized_name_idx` (`normalized_name`),
  CONSTRAINT `statistical_territories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `statistical_territories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `step_up_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `step_up_challenges` (
  `id` char(36) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `scope` varchar(50) NOT NULL,
  `allowed_methods` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`allowed_methods`)),
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `completed_method` varchar(30) DEFAULT NULL,
  `token` varchar(64) DEFAULT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `phone_challenge_id` char(36) DEFAULT NULL,
  `email_challenge_id` char(36) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `step_up_challenges_token_unique` (`token`),
  KEY `step_up_challenges_user_id_status_index` (`user_id`,`status`),
  KEY `step_up_challenges_token_index` (`token`),
  KEY `step_up_challenges_expires_at_index` (`expires_at`),
  CONSTRAINT `step_up_challenges_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_operation_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_operation_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_operation_id` bigint(20) unsigned NOT NULL,
  `price_list_version_id` bigint(20) unsigned NOT NULL,
  `price_value` decimal(12,2) NOT NULL,
  `unit` varchar(255) DEFAULT NULL COMMENT 'Единица измерения как в прайсе для аудита',
  `price_type` enum('retail','wholesale') NOT NULL DEFAULT 'retail',
  `currency` varchar(3) NOT NULL DEFAULT 'RUB',
  `source_row_index` int(10) unsigned DEFAULT NULL COMMENT 'Номер строки в файле импорта',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_op_price_unique` (`supplier_operation_id`,`price_list_version_id`,`price_type`),
  KEY `supplier_operation_prices_price_list_version_id_index` (`price_list_version_id`),
  CONSTRAINT `supplier_operation_prices_price_list_version_id_foreign` FOREIGN KEY (`price_list_version_id`) REFERENCES `price_list_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_operation_prices_supplier_operation_id_foreign` FOREIGN KEY (`supplier_operation_id`) REFERENCES `supplier_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(255) DEFAULT NULL COMMENT 'Единица измерения как в прайсе поставщика',
  `category` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `external_key` varchar(255) DEFAULT NULL COMMENT 'SKU/article или hash от name',
  `search_name` varchar(255) DEFAULT NULL COMMENT 'Нормализованное имя для поиска',
  `origin` enum('import','manual') NOT NULL DEFAULT 'import',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_operations_supplier_id_name_index` (`supplier_id`,`name`),
  KEY `supplier_operations_supplier_id_external_key_index` (`supplier_id`,`external_key`),
  KEY `supplier_operations_search_name_index` (`search_name`),
  CONSTRAINT `supplier_operations_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_product_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_product_aliases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `external_key` varchar(255) NOT NULL COMMENT 'SKU/article или стабильный ключ (hash от name)',
  `external_name` varchar(255) DEFAULT NULL COMMENT 'Оригинальное название от поставщика',
  `internal_item_type` enum('material','operation') NOT NULL,
  `internal_item_id` bigint(20) unsigned NOT NULL,
  `supplier_unit` varchar(50) DEFAULT NULL COMMENT 'Единица поставщика: упак., лист, компл.',
  `internal_unit` varchar(50) DEFAULT NULL COMMENT 'Внутренняя единица: шт, м², п.м.',
  `conversion_factor` decimal(18,6) NOT NULL DEFAULT 1.000000 COMMENT 'Сколько внутренних единиц в 1 единице поставщика',
  `price_transform` enum('divide','multiply','none') NOT NULL DEFAULT 'divide' COMMENT 'MVP: всегда divide. Price_internal = Price_supplier / conversion_factor',
  `confidence` enum('manual','auto_exact','auto_fuzzy') NOT NULL DEFAULT 'manual',
  `similarity_score` decimal(5,4) DEFAULT NULL COMMENT 'Similarity score при auto matching',
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `usage_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spa_supplier_key_type_unique` (`supplier_id`,`external_key`,`internal_item_type`),
  KEY `spa_supplier_internal_idx` (`supplier_id`,`internal_item_type`,`internal_item_id`),
  KEY `supplier_product_aliases_external_key_index` (`external_key`),
  CONSTRAINT `supplier_product_aliases_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_urls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_urls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(255) NOT NULL,
  `material_type` varchar(255) DEFAULT NULL,
  `url` text NOT NULL,
  `is_valid` tinyint(1) NOT NULL DEFAULT 1,
  `collected_at` timestamp NULL DEFAULT NULL,
  `validated_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_session_id` bigint(20) unsigned DEFAULT NULL,
  `collect_chunk_id` int(10) unsigned DEFAULT NULL,
  `retries` int(11) NOT NULL DEFAULT 0,
  `validation_error` text DEFAULT NULL,
  `status` enum('pending','processing','done','failed','blocked') NOT NULL DEFAULT 'pending' COMMENT 'Статус обработки URL',
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Количество попыток парсинга',
  `locked_by` varchar(64) DEFAULT NULL COMMENT 'ID воркера, который обрабатывает URL',
  `locked_at` timestamp NULL DEFAULT NULL COMMENT 'Время блокировки воркером',
  `last_attempt_at` timestamp NULL DEFAULT NULL COMMENT 'Время последней попытки парсинга',
  `last_parsed_at` timestamp NULL DEFAULT NULL COMMENT 'Время последнего успешного парсинга',
  `next_retry_at` timestamp NULL DEFAULT NULL COMMENT 'Когда можно повторить попытку',
  `error_code` varchar(64) DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `last_error_code` varchar(50) DEFAULT NULL COMMENT 'Код последней ошибки',
  `last_error_message` text DEFAULT NULL COMMENT 'Текст последней ошибки',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_url_unique` (`supplier_name`,`url`) USING HASH,
  UNIQUE KEY `idx_supplier_url_unique` (`supplier_name`,`url`) USING HASH,
  KEY `supplier_urls_supplier_name_material_type_is_valid_index` (`supplier_name`,`material_type`,`is_valid`),
  KEY `supplier_urls_supplier_name_index` (`supplier_name`),
  KEY `supplier_urls_material_type_index` (`material_type`),
  KEY `supplier_urls_is_valid_index` (`is_valid`),
  KEY `idx_queue_claim` (`supplier_name`,`status`,`next_retry_at`),
  KEY `idx_queue_claim_type` (`supplier_name`,`material_type`,`status`,`next_retry_at`),
  KEY `idx_reparsing` (`supplier_name`,`last_parsed_at`),
  KEY `idx_stale_processing` (`status`,`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL COMMENT 'Короткий код поставщика (например, SKM)',
  `description` text DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(255) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Дополнительные данные' CHECK (json_valid(`metadata`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `suppliers_user_id_name_unique` (`user_id`,`name`),
  KEY `suppliers_user_id_is_active_index` (`user_id`,`is_active`),
  CONSTRAINT `suppliers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tags_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trusted_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trusted_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_id` char(36) NOT NULL,
  `device_secret_hash` varchar(255) NOT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `ip_first` varchar(45) DEFAULT NULL,
  `ip_last` varchar(45) DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trusted_devices_device_id_unique` (`device_id`),
  KEY `trusted_devices_user_id_revoked_at_index` (`user_id`,`revoked_at`),
  KEY `trusted_devices_device_id_index` (`device_id`),
  CONSTRAINT `trusted_devices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `origin` varchar(255) NOT NULL DEFAULT 'system',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `units_name_unique` (`name`),
  KEY `units_user_id_foreign` (`user_id`),
  CONSTRAINT `units_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usage_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usage_counters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(40) NOT NULL DEFAULT 'user',
  `owner_id` bigint(20) unsigned NOT NULL,
  `metric_code` varchar(100) NOT NULL,
  `period_start` datetime NOT NULL,
  `period_end` datetime NOT NULL,
  `quantity` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `limit_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`limit_snapshot`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usage_counters_owner_metric_period_unique` (`owner_type`,`owner_id`,`metric_code`,`period_start`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usage_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usage_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(40) NOT NULL DEFAULT 'user',
  `owner_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `metric_code` varchar(100) NOT NULL,
  `feature_code` varchar(100) DEFAULT NULL,
  `quantity` decimal(20,4) NOT NULL DEFAULT 1.0000,
  `unit` varchar(40) DEFAULT NULL,
  `subject_type` varchar(120) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `request_id` varchar(120) DEFAULT NULL,
  `idempotency_key` varchar(120) DEFAULT NULL,
  `source` varchar(40) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `occurred_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usage_events_owner_type_owner_id_index` (`owner_type`,`owner_id`),
  KEY `usage_events_user_id_index` (`user_id`),
  KEY `usage_events_project_id_index` (`project_id`),
  KEY `usage_events_metric_code_index` (`metric_code`),
  KEY `usage_events_feature_code_index` (`feature_code`),
  KEY `usage_events_occurred_at_index` (`occurred_at`),
  KEY `usage_events_idempotency_key_index` (`idempotency_key`),
  CONSTRAINT `usage_events_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `usage_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_material_library`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_material_library` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned NOT NULL,
  `pinned` tinyint(4) NOT NULL DEFAULT 0,
  `preferred_region_id` bigint(20) unsigned DEFAULT NULL,
  `preferred_price_source_url` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uml_user_material_unique` (`user_id`,`material_id`),
  KEY `user_material_library_material_id_foreign` (`material_id`),
  KEY `user_material_library_preferred_region_id_foreign` (`preferred_region_id`),
  KEY `uml_user_pinned_idx` (`user_id`,`pinned`),
  CONSTRAINT `user_material_library_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_material_library_preferred_region_id_foreign` FOREIGN KEY (`preferred_region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_material_library_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `clicked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_notifications_notification_id_user_id_unique` (`notification_id`,`user_id`),
  KEY `user_notifications_user_id_read_at_index` (`user_id`,`read_at`),
  KEY `user_notifications_notification_id_read_at_index` (`notification_id`,`read_at`),
  KEY `user_notifications_user_id_created_at_index` (`user_id`,`created_at`),
  CONSTRAINT `user_notifications_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned DEFAULT NULL,
  `default_expert_name` varchar(255) DEFAULT NULL,
  `default_number` varchar(255) DEFAULT NULL,
  `waste_coefficient` double NOT NULL DEFAULT 1,
  `repair_coefficient` double NOT NULL DEFAULT 1,
  `waste_plate_coefficient` double DEFAULT NULL,
  `waste_edge_coefficient` double DEFAULT NULL,
  `waste_operations_coefficient` double DEFAULT NULL,
  `apply_waste_to_plate` tinyint(1) NOT NULL DEFAULT 1,
  `apply_waste_to_edge` tinyint(1) NOT NULL DEFAULT 1,
  `apply_waste_to_operations` tinyint(1) NOT NULL DEFAULT 0,
  `use_area_calc_mode` tinyint(1) NOT NULL DEFAULT 0,
  `default_plate_material_id` bigint(20) unsigned DEFAULT NULL,
  `default_edge_material_id` bigint(20) unsigned DEFAULT NULL,
  `text_blocks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`text_blocks`)),
  `report_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`report_settings`)),
  `waste_plate_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_plate_description`)),
  `waste_edge_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_edge_description`)),
  `waste_operations_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_operations_description`)),
  `show_waste_plate_description` tinyint(1) NOT NULL DEFAULT 0,
  `show_waste_edge_description` tinyint(1) NOT NULL DEFAULT 0,
  `show_waste_operations_description` tinyint(1) NOT NULL DEFAULT 0,
  `labor_employer_insurance_rate` decimal(5,4) NOT NULL DEFAULT 0.3000,
  `labor_load_factor_calendar_hours` int(11) NOT NULL DEFAULT 160,
  `labor_load_factor_productive_hours` int(11) NOT NULL DEFAULT 120,
  `labor_planned_profitability_rate` decimal(5,4) NOT NULL DEFAULT 0.1500,
  `labor_aggregation_strategy` varchar(20) NOT NULL DEFAULT 'auto',
  `labor_salary_range_strategy` varchar(20) NOT NULL DEFAULT 'avg',
  `labor_rate_rounding_scale` int(11) NOT NULL DEFAULT 2,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `facade_width_allowance_mm` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Припуск фасада по ширине, мм',
  `facade_height_allowance_mm` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Припуск фасада по высоте, мм',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_settings_user_id_unique` (`user_id`),
  KEY `user_settings_default_plate_material_id_foreign` (`default_plate_material_id`),
  KEY `user_settings_default_edge_material_id_foreign` (`default_edge_material_id`),
  KEY `user_settings_region_id_index` (`region_id`),
  CONSTRAINT `user_settings_default_edge_material_id_foreign` FOREIGN KEY (`default_edge_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_settings_default_plate_material_id_foreign` FOREIGN KEY (`default_plate_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_settings_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `admin_chat_alias` varchar(100) DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `activity_profile` varchar(255) DEFAULT NULL,
  `registration_completed_at` timestamp NULL DEFAULT NULL,
  `last_login_channel` varchar(30) DEFAULT NULL,
  `auth_status` varchar(20) NOT NULL DEFAULT 'active',
  `role` varchar(30) NOT NULL DEFAULT 'user',
  `blocked_reason` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `pin_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pin_hash` varchar(255) DEFAULT NULL,
  `pin_changed_at` timestamp NULL DEFAULT NULL,
  `pin_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `pin_locked_until` timestamp NULL DEFAULT NULL,
  `current_session_id` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `blocked_by` bigint(20) unsigned DEFAULT NULL,
  `blocked_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  KEY `users_blocked_by_foreign` (`blocked_by`),
  KEY `users_auth_status_index` (`auth_status`),
  KEY `users_role_index` (`role`),
  CONSTRAINT `users_blocked_by_foreign` FOREIGN KEY (`blocked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_presets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_presets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `normalized_title` varchar(500) NOT NULL,
  `context_hash` char(32) NOT NULL,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `steps_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`steps_json`)),
  `total_hours` decimal(8,2) NOT NULL,
  `fingerprint` char(32) NOT NULL,
  `usage_count` int(10) unsigned NOT NULL DEFAULT 1,
  `status` enum('draft','candidate','verified','deprecated') NOT NULL DEFAULT 'draft',
  `source` enum('manual','ai','imported') NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_presets_unique_combo` (`context_hash`,`normalized_title`,`fingerprint`),
  KEY `work_presets_context_hash_normalized_title_status_index` (`context_hash`,`normalized_title`,`status`),
  KEY `work_presets_normalized_title_index` (`normalized_title`),
  KEY `work_presets_context_hash_index` (`context_hash`),
  KEY `work_presets_fingerprint_index` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*M!999999\- enable the sandbox mode */ 
SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_01_04_000000_add_total_urls_to_parsing_sessions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_01_04_100000_create_supplier_urls_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_01_07_000000_add_dimensions_to_materials_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_01_07_000001_add_thickness_to_materials_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_01_07_add_granular_waste_coefficients',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_01_08_add_use_area_calc_mode_to_projects',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_01_08_add_default_materials_to_projects',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_01_09_add_operation_ids_to_materials',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_01_10_123829_add_custom_name_to_project_positions',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_01_11_add_text_blocks_to_projects',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_01_11_migrate_text_blocks_format',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_01_11_add_enabled_to_text_blocks',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_01_11_add_waste_coefficients_descriptions',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_01_11_add_normohour_to_projects',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_01_11_create_project_normohour_sources_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_01_11_create_project_labor_works_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_01_11_130000_update_project_normohour_sources_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_01_11_140000_add_salary_fields_to_normohour_sources',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_01_11_150000_create_position_profiles_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_01_11_150042_create_global_normohour_sources_table',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_01_11_150100_create_labor_work_templates_table',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_01_11_150109_update_projects_for_normohour_snapshot',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_01_12_060417_create_regions_table',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_01_12_061517_add_region_id_to_global_normohour_sources',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_01_12_062151_create_project_profile_rates_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_01_12_075329_add_position_profile_id_to_project_labor_works_table',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_01_12_130000_add_min_max_rates_to_global_normohour_sources',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_01_12_140000_add_salary_range_to_global_normohour_sources',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_01_12_150000_add_region_id_to_projects',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_01_12_143505_create_user_settings_table',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_01_14_063128_add_sawing_gluing_prices_to_projects',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_01_14_103032_create_global_normohour_sources_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_01_14_add_locked_fields_to_project_profile_rates',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_01_14_add_rate_fields_to_project_labor_works',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_01_14_update_expenses_table',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_01_14_fix_rate_to_1125',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_01_15_000001_create_project_labor_work_steps_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_01_15_000002_add_hours_flags_to_project_labor_works',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_01_15_000003_initialize_hours_source_for_existing_works',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_01_17_add_region_id_to_user_settings',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_01_19_000001_add_price_checked_at_to_materials_table',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_01_19_000002_add_queue_fields_to_supplier_urls_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_01_19_000003_initialize_supplier_urls_status',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_01_19_000004_add_canceling_status_to_parsing_sessions',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_01_20_090000_add_last_seen_at_to_supplier_urls',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_01_20_130000_add_full_scan_fields_to_parsing_sessions',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_01_20_150000_create_project_revisions_table',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_01_20_180000_create_revision_publications_table',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_01_20_180500_create_revision_publication_views_table',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_01_21_100000_create_import_sessions_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_01_21_100001_create_import_column_mappings_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_01_21_000000_implement_deterministic_session_lifecycle',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_01_21_160000_add_stats_and_reset_fields',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_01_21_170000_add_failed_details_and_cursor',43);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_01_23_000000_create_parser_supplier_configs_table',44);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_01_23_000001_create_parser_supplier_collect_profiles_table',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_01_23_150000_add_name_to_import_column_mappings_field',46);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_01_24_080000_make_import_sessions_header_row_index_signed',47);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_01_27_000001_create_work_presets_table',48);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_01_27_000002_create_ai_logs_table',48);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_01_28_000001_create_app_settings_table',49);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_01_28_000002_add_llm_fields_to_ai_logs_table',49);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_01_28_000003_add_user_id_to_ai_logs_table',50);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_02_02_000001_create_suppliers_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_02_02_000002_create_price_lists_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_02_02_000003_create_price_list_versions_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_02_02_000004_create_price_import_sessions_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_02_02_000005_create_supplier_product_aliases_table',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_02_02_000006_create_operation_prices_table',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_02_02_000007_create_material_prices_table',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_02_02_000008_add_search_name_to_operations_materials',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_02_02_000009_create_exchange_rates_table',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_02_04_000001_create_supplier_operations_table',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_02_04_000002_create_operation_groups_table',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_02_04_000003_create_operation_group_links_table',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_02_04_000004_create_supplier_operation_prices_table',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_02_04_000005_update_material_prices_add_supplier',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_02_05_000001_update_price_list_versions_status_and_source',54);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_02_06_000001_extend_operation_prices_for_snapshot_architecture',55);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_02_06_000002_add_file_hash_to_price_import_sessions',56);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_02_06_000003_remove_cost_per_unit_from_operations',57);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_02_08_000001_make_operation_id_nullable_in_operation_prices',58);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_02_11_000001_add_pin_fields_to_users_table',59);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_02_11_000002_create_trusted_devices_table',59);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_02_11_100000_create_notifications_tables',60);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_02_11_add_expert_name_number_to_user_settings',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_02_13_000001_add_facade_support_to_materials',62);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_02_13_000002_add_facade_fields_to_project_positions',62);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_02_13_100001_create_project_price_list_versions_table',63);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_02_13_100002_rename_supplier_price_item_id_to_material_price_id',63);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_02_13_200000_add_archived_at_to_projects',64);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_02_13_200001_add_price_aggregation_to_project_positions',65);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_02_13_200002_create_project_position_price_quotes_table',65);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_02_15_000001_add_contact_person_notes_to_suppliers_table',66);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_02_15_100001_add_facade_class_columns_to_materials',67);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_02_16_100001_add_mismatch_flags_to_project_position_price_quotes',68);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_02_16_add_rate_model_to_position_profiles',69);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_02_16_200001_add_rate_model_to_position_profiles',70);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_02_17_000001_add_cancelled_status_to_price_import_sessions',71);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_02_23_000001_extend_materials_for_catalog',72);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_02_23_000002_extend_material_price_histories_for_observations',72);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_02_23_000003_create_user_material_library_table',72);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_02_23_000004_add_user_id_to_parser_supplier_collect_profiles',72);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_02_24_000001_extend_collect_profiles_for_chrome_ext',73);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_02_24_000002_widen_source_url_columns',74);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_03_05_000001_extend_material_price_histories_for_revision_gate',75);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_03_05_000002_create_revision_runs_tables',75);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_03_05_000003_add_revision_justification_flags',75);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_03_05_043538_parsing_sessions',76);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_03_06_000101_create_evidence_artifacts_table',77);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_03_06_000102_extend_revision_items_and_price_histories_for_evidence_pipeline',78);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_03_07_000001_add_pending_to_revision_run_items_status',79);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_03_07_000002_add_finalized_to_revision_runs_status',80);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_03_07_000003_create_material_dimension_rules_table',81);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_03_07_000004_create_material_dimension_parse_failures_table',81);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_03_08_000001_create_material_type_patterns_table',82);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_03_09_000001_create_ideas_module_tables',83);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_03_12_000001_extend_project_fittings_and_revision_items_for_hardware',84);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_03_15_000001_add_phone_auth_fields_to_users_table',85);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_03_15_000002_create_auth_verification_challenges_table',85);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_03_15_000003_create_social_accounts_table',85);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_03_15_000004_create_password_reset_tokens_table',85);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_03_18_000001_extend_social_accounts_for_auth_methods',86);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_03_18_000002_add_unlinked_at_to_social_accounts',87);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_03_18_000003_add_call_flow_fields_to_auth_verification_challenges',88);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_03_18_000004_fix_auth_challenge_expires_at_column',89);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_03_20_000001_create_admin_audit_logs_table',90);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_03_20_000002_add_admin_fields_to_users_table',90);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_03_28_000001_extend_revision_run_items_for_generic_evidence',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_03_28_000002_extend_evidence_artifacts_for_generic_evidence',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_03_28_000003_create_evidence_assets_table',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_03_28_100001_fix_cost_driver_type_backfill',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_03_29_000001_create_evidence_records_table',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_03_29_000002_create_estimate_evidence_runs_table',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_03_29_000003_add_evidence_record_id_to_material_price_histories',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_03_29_100001_add_snapshot_and_item_fields',91);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_04_01_000001_add_price_confirmation_freshness_days_to_projects',92);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_04_08_000001_create_step_up_challenges_table',93);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_04_09_000001_add_email_challenge_id_to_step_up_challenges',94);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_04_10_000000_fix_project_revisions_created_by_user_id_nullable',95);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_04_10_100000_create_chat_conversations_table',96);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_04_10_100001_create_chat_participants_table',96);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_04_10_100002_create_chat_messages_table',96);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_04_10_100003_add_read_state_to_chat_participants',96);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_04_10_100004_create_chat_attachments_table',97);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_04_10_100005_make_chat_messages_body_nullable',97);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_04_15_000001_create_operation_selected_sources_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_04_15_000002_create_operation_application_rules_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_04_15_000003_replace_operation_rule_quantity_model',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_04_16_000001_add_operation_kind_to_operations_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_04_17_000001_create_operation_price_sources_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_04_17_000003_drop_operation_selected_sources_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_04_17_000004_create_price_imports_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_04_17_000005_create_price_import_items_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_04_17_000006_add_binding_fields_to_price_import_items_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_04_20_120000_create_pricing_labor_evidence_tables',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_04_20_130000_add_uploaded_by_to_generic_evidence_assets_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_04_20_140000_make_labor_profile_nullable_in_labor_evidence_sources',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_04_20_150000_create_project_labor_evidence_sources_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_04_20_170000_add_labor_calculation_settings_to_user_settings',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_04_20_180000_add_labor_profile_id_to_project_labor_works_table',98);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_04_21_120000_add_labor_salary_range_strategy_to_user_settings',99);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_04_22_120001_create_finished_product_price_sources_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_04_22_120002_create_finished_product_price_evidence_assets_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_04_22_120003_create_finished_product_aggregation_profiles_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_04_22_120004_create_finished_product_computed_prices_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_04_22_130001_create_finished_product_specifications_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_04_22_130002_add_specification_root_to_finished_product_pricing_tables',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_04_22_140001_add_finished_product_snapshot_to_project_positions_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_04_22_190001_make_finished_product_material_id_nullable_on_price_sources',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_04_22_190101_make_finished_product_material_id_nullable_on_aggregation_profiles',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_04_22_190201_make_finished_product_material_id_nullable_on_computed_prices',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2026_04_27_000001_create_billing_foundation_tables',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2026_04_27_000002_create_billing_payment_tables',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2026_04_27_000003_create_billing_gate_events_table',102);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2026_04_27_000004_create_billing_subscription_events_table',103);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (221,'2026_05_02_000001_add_public_id_to_projects_table',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (222,'2026_05_05_173600_extend_edge_scheme_enum_values',105);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (223,'2026_05_07_000001_add_facade_allowances_to_user_settings_and_projects',105);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (224,'2026_05_08_000001_add_project_revision_id_to_revision_runs_table',106);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (225,'2026_05_09_160000_add_report_settings_to_user_settings_and_projects',107);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (226,'2026_05_11_120000_add_admin_chat_alias_to_users_table',108);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (227,'2026_08_07_000001_create_statistical_datasets_table',109);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (228,'2026_08_07_000002_create_statistical_sources_table',109);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (229,'2026_08_07_000003_create_statistical_source_files_table',109);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (230,'2026_08_07_000004_create_statistical_source_checks_table',109);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (231,'2026_08_07_000005_create_statistical_dataset_active_files_table',109);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (232,'2026_08_10_000001_create_statistical_indicators_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (233,'2026_08_10_000002_create_statistical_classifier_items_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (234,'2026_08_10_000003_create_statistical_territories_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (235,'2026_08_10_000004_create_statistical_imports_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (236,'2026_08_10_000005_create_statistical_import_issues_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (237,'2026_08_10_000006_create_statistical_series_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (238,'2026_08_10_000007_create_statistical_observations_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (239,'2026_08_10_000008_create_statistical_dataset_active_imports_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (240,'2026_08_10_000009_create_statistical_import_previews_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (241,'2026_08_12_000001_create_statistical_public_series_pages_table',111);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (242,'2026_08_24_000001_create_statistical_classifiers_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (243,'2026_08_24_000002_create_statistical_classifier_versions_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (244,'2026_08_24_000003_create_statistical_classifier_nodes_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (245,'2026_08_24_000004_create_statistical_classifier_active_versions_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (246,'2026_08_24_000005_create_statistical_classifier_source_files_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (247,'2026_08_24_000006_create_statistical_classifier_imports_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (248,'2026_08_24_000007_add_import_provenance_to_statistical_classifier_versions_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (249,'2026_08_25_000001_create_statistical_classifier_item_mappings_table',112);
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

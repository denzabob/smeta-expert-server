/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `edge_processing` enum('none','O','=','||','П','L') NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `origin` varchar(255) NOT NULL DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `components` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`components`)),
  PRIMARY KEY (`id`),
  KEY `detail_types_user_id_foreign` (`user_id`),
  CONSTRAINT `detail_types_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=349 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_price_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_price_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `material_id` bigint(20) unsigned NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `version` int(10) unsigned NOT NULL,
  `price_per_unit` decimal(8,2) NOT NULL,
  `source_url` varchar(255) DEFAULT NULL,
  `screenshot_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `material_price_histories_material_id_foreign` (`material_id`),
  CONSTRAINT `material_price_histories_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1051 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `type` enum('plate','edge','facade') NOT NULL DEFAULT 'plate',
  `material_tag` varchar(50) DEFAULT NULL COMMENT 'ldsp, mdf, pvc',
  `thickness` decimal(5,2) DEFAULT NULL,
  `waste_factor` decimal(3,2) NOT NULL DEFAULT 1.00,
  `price_per_unit` decimal(8,2) NOT NULL,
  `unit` enum('м²','м.п.','шт') NOT NULL,
  `length_mm` int(11) DEFAULT NULL COMMENT 'Длина листа в мм',
  `width_mm` int(11) DEFAULT NULL COMMENT 'Ширина листа в мм',
  `thickness_mm` int(11) DEFAULT NULL COMMENT 'Толщина листа в мм',
  `source_url` varchar(255) DEFAULT NULL,
  `last_price_screenshot_path` varchar(255) DEFAULT NULL,
  `availability_status` varchar(50) DEFAULT NULL,
  `price_checked_at` timestamp NULL DEFAULT NULL COMMENT 'Момент последней успешной проверки цены парсером',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
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
  CONSTRAINT `materials_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=689 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=560 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `search_name` varchar(255) DEFAULT NULL COMMENT 'Нормализованное имя для поиска (lowercase, без спецсимволов)',
  `category` varchar(255) NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `supplier_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `config_override` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`config_override`)),
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parser_supplier_collect_profiles_supplier_name_index` (`supplier_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=2213 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_fittings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_fittings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `article` varchar(255) DEFAULT NULL,
  `quantity` decimal(8,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `unit` varchar(255) NOT NULL DEFAULT 'шт',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_fittings_project_id_foreign` (`project_id`),
  CONSTRAINT `project_fittings_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=154 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_labor_works`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_labor_works` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `position_profile_id` bigint(20) unsigned DEFAULT NULL,
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
  CONSTRAINT `project_labor_works_position_profile_id_foreign` FOREIGN KEY (`position_profile_id`) REFERENCES `position_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_labor_works_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_labor_works_project_profile_rate_id_foreign` FOREIGN KEY (`project_profile_rate_id`) REFERENCES `project_profile_rates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `material_tag` varchar(50) DEFAULT NULL,
  `thickness` decimal(5,2) DEFAULT NULL,
  `waste_factor` decimal(3,2) NOT NULL DEFAULT 1.00,
  `edge_material_id` bigint(20) unsigned DEFAULT NULL,
  `edge_scheme` enum('none','=','||','П','L','O') NOT NULL DEFAULT 'none',
  `custom_name` varchar(255) DEFAULT NULL,
  `custom_fittings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_fittings`)),
  `custom_operations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_operations`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_positions_project_id_foreign` (`project_id`),
  KEY `project_positions_material_id_foreign` (`material_id`),
  KEY `project_positions_edge_material_id_foreign` (`edge_material_id`),
  KEY `project_positions_detail_type_id_foreign` (`detail_type_id`),
  KEY `project_positions_facade_material_id_foreign` (`facade_material_id`),
  KEY `project_positions_kind_index` (`kind`),
  KEY `project_positions_material_price_id_foreign` (`material_price_id`),
  CONSTRAINT `project_positions_detail_type_id_foreign` FOREIGN KEY (`detail_type_id`) REFERENCES `detail_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_edge_material_id_foreign` FOREIGN KEY (`edge_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_facade_material_id_foreign` FOREIGN KEY (`facade_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_material_price_id_foreign` FOREIGN KEY (`material_price_id`) REFERENCES `material_prices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_positions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=170 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_revisions` (
  `id` char(36) NOT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
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
  CONSTRAINT `project_revisions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `project_revisions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
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
  `apply_waste_to_plate` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Применять коэффициент отходов к плитным материалам',
  `apply_waste_to_edge` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Применять коэффициент отходов к кромке',
  `apply_waste_to_operations` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Применять коэффициент отходов к операциям',
  `use_area_calc_mode` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Использовать расчёт по площади вместо по листам',
  `default_plate_material_id` bigint(20) unsigned DEFAULT NULL,
  `default_edge_material_id` bigint(20) unsigned DEFAULT NULL,
  `text_blocks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON массив текстовых блоков для вывода в конец сметы' CHECK (json_valid(`text_blocks`)),
  `sawing_price_per_m2` decimal(10,2) DEFAULT 50.00 COMMENT 'Цена распиловки за м²',
  `gluing_price_per_m` decimal(10,2) DEFAULT 30.00 COMMENT 'Цена оклейки кромок за м',
  PRIMARY KEY (`id`),
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
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=309 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=187 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=236 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1968 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `waste_plate_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_plate_description`)),
  `waste_edge_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_edge_description`)),
  `waste_operations_description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`waste_operations_description`)),
  `show_waste_plate_description` tinyint(1) NOT NULL DEFAULT 0,
  `show_waste_edge_description` tinyint(1) NOT NULL DEFAULT 0,
  `show_waste_operations_description` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_settings_user_id_unique` (`user_id`),
  KEY `user_settings_default_plate_material_id_foreign` (`default_plate_material_id`),
  KEY `user_settings_default_edge_material_id_foreign` (`default_edge_material_id`),
  KEY `user_settings_region_id_index` (`region_id`),
  CONSTRAINT `user_settings_default_edge_material_id_foreign` FOREIGN KEY (`default_edge_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_settings_default_plate_material_id_foreign` FOREIGN KEY (`default_plate_material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_settings_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `pin_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pin_hash` varchar(255) DEFAULT NULL,
  `pin_changed_at` timestamp NULL DEFAULT NULL,
  `pin_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `pin_locked_until` timestamp NULL DEFAULT NULL,
  `current_session_id` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- PHP 8.3 / Laravel 12 upgrade script (idempotent)
-- Safe to run multiple times. Only additive changes; no column/table drops.
-- Run against the existing dujiaoka database.

-- 1) dcat 2.2 extension tables
CREATE TABLE IF NOT EXISTS `admin_extensions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `version` varchar(20) NOT NULL DEFAULT '',
  `is_enabled` tinyint NOT NULL DEFAULT '0',
  `options` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_extensions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_extension_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` tinyint NOT NULL DEFAULT '1',
  `version` varchar(20) NOT NULL DEFAULT '0',
  `detail` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_extension_histories_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Register dcat migrations so `php artisan admin:update` is a no-op
INSERT INTO `migrations` (`migration`, `batch`)
SELECT v.migration, 1 FROM (
  SELECT '2016_01_04_173148_create_admin_tables' AS migration UNION ALL
  SELECT '2020_09_07_090635_create_admin_settings_table' UNION ALL
  SELECT '2020_09_22_015815_create_admin_extensions_table' UNION ALL
  SELECT '2020_11_01_083237_update_admin_menu_table'
) v
WHERE NOT EXISTS (SELECT 1 FROM `migrations` m WHERE m.migration = v.migration);

-- 3) failed_jobs.uuid (Laravel 8+ queue failure recording)
CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) DEFAULT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'failed_jobs' AND COLUMN_NAME = 'uuid'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `failed_jobs` ADD COLUMN `uuid` varchar(255) NULL AFTER `id`, ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) Disable every non-yipay channel, and every yipay row whose credentials are
--    still empty or the public install.sql placeholders. Those placeholders are
--    not secrets and must never be usable as signing keys (PQ-01).
--
--    A yipay row with real credentials keeps its current is_open value: on a
--    fresh install it is already 0, and re-running this script must not override
--    an operator who deliberately disabled it. Enable it manually after filling
--    in the three credential fields:
--      UPDATE `pays` SET `is_open` = 1 WHERE `pay_handleroute` = '/pay/yipay';
UPDATE `pays`
   SET `is_open` = 0
 WHERE `deleted_at` IS NOT NULL
    OR TRIM(BOTH '/' FROM COALESCE(`pay_handleroute`, '')) <> 'pay/yipay'
    OR `merchant_id` IS NULL
    OR TRIM(`merchant_id`) IN ('', '商户号')
    OR `merchant_key` IS NULL
    OR TRIM(`merchant_key`) = ''
    OR `merchant_pem` IS NULL
    OR TRIM(`merchant_pem`) IN ('', '密钥');

-- 5) Payment credentials are encrypted with APP_KEY; the old varchar(200)
--    merchant_id cannot hold the encrypted payload. TEXT is backwards
--    compatible with the old code (it only reads the value).
ALTER TABLE `pays`
  MODIFY `merchant_id` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '商户 ID（加密后需要更长空间）';

-- After deploying this script, run once to encrypt legacy plaintext payment
-- credentials with the application's APP_KEY:
--   php artisan pay:encrypt-secrets
-- Before rolling back to old code, decrypt them:
--   php artisan pay:decrypt-secrets

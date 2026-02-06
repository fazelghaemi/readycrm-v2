-- =============================================================================
-- ReadyCRM v2 - Migration 002: کاتالوگ مشتریان (customers، customer_notes)
-- =============================================================================
-- جداول مربوط به مخاطبین و مشتریان CRM و یادداشت‌های آنان.
-- مورد استفاده: Dashboard، SMS، WooCommerce Sync، AI (خلاصه/تگ)، Auth و غیره.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- customers: مشتریان و مخاطبین (CRM + WooCommerce + AI)
-- ستون‌ها مطابق App\Domain\Entities\Customer و وب‌هوک WooCommerce
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `woo_customer_id` BIGINT UNSIGNED NULL COMMENT 'شناسه مشتری در ووکامرس (همگام‌سازی دوطرفه)',
    `woo_synced_at` DATETIME NULL COMMENT 'آخرین زمان همگام‌سازی با ووکامرس',

    `full_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'نام کامل',
    `first_name` VARCHAR(120) NULL,
    `last_name` VARCHAR(120) NULL,

    `email` VARCHAR(190) NULL,
    `phone` VARCHAR(50) NULL,

    `national_id` VARCHAR(30) NULL COMMENT 'کد ملی',
    `company` VARCHAR(255) NULL,
    `vat_number` VARCHAR(80) NULL,

    `billing_address1` VARCHAR(255) NULL,
    `billing_address2` VARCHAR(255) NULL,
    `billing_city` VARCHAR(120) NULL,
    `billing_state` VARCHAR(120) NULL,
    `billing_postcode` VARCHAR(20) NULL,
    `billing_country` VARCHAR(2) NULL,

    `shipping_address1` VARCHAR(255) NULL,
    `shipping_address2` VARCHAR(255) NULL,
    `shipping_city` VARCHAR(120) NULL,
    `shipping_state` VARCHAR(120) NULL,
    `shipping_postcode` VARCHAR(20) NULL,
    `shipping_country` VARCHAR(2) NULL,

    `status` VARCHAR(30) NOT NULL DEFAULT 'active' COMMENT 'active|inactive|blocked|lead|archived',
    `notes` TEXT NULL,

    `tags` JSON NULL COMMENT 'آرایه برچسب‌ها (JSON)',
    `meta_json` TEXT NULL COMMENT 'متادیتای اختیاری (JSON)؛ استفاده در وب‌هوک ووکامرس',

    `ai_summary` TEXT NULL COMMENT 'خلاصه تولیدشده توسط هوش مصنوعی',
    `ai_score` DECIMAL(5,2) NULL COMMENT 'امتیاز لید/مشتری',
    `ai_updated_at` DATETIME NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL COMMENT 'soft delete',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_woo_customer_id` (`woo_customer_id`),
    KEY `idx_email` (`email`),
    KEY `idx_phone` (`phone`(20)),
    KEY `idx_status` (`status`),
    KEY `idx_deleted_at` (`deleted_at`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_ai_updated` (`ai_updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- customer_notes: یادداشت‌های مرتبط با هر مشتری (برای AI و گزارش)
-- مورد استفاده: RefreshSummariesJob و ماژول یادداشت‌ها
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_notes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` BIGINT UNSIGNED NOT NULL COMMENT 'ارجاع به customers.id',
    `note` TEXT NOT NULL COMMENT 'متن یادداشت',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_customer_notes_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

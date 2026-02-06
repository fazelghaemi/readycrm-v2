-- =============================================================================
-- ReadyCRM v2 - Migration 004: فروش و پرداخت (sales، payments)
-- =============================================================================
-- جداول فروش/فاکتور و پرداخت‌ها؛ سازگار با Sale/Payment Entity، ووکامرس و داشبورد.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- sales: فروش / فاکتور / سفارش (CRM و همگام‌سازی woo_order_id)
-- ستون total برای وب‌هوک ووکامرس؛ total_amount برای Entity و داشبورد.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sales` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` BIGINT UNSIGNED NULL COMMENT 'ارجاع به customers.id',
    `woo_order_id` BIGINT UNSIGNED NULL COMMENT 'شناسه سفارش در ووکامرس',
    `woo_synced_at` DATETIME NULL,
    `source` VARCHAR(30) NOT NULL DEFAULT 'crm' COMMENT 'crm|woocommerce|api|import|manual',

    `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|completed|cancelled|refunded|failed',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'IRR',

    `total` DECIMAL(18,4) NULL COMMENT 'مبلغ کل (استفاده در وب‌هوک ووکامرس)',
    `total_amount` DECIMAL(18,4) NULL COMMENT 'مبلغ کل (Entity و داشبورد)',
    `subtotal` DECIMAL(18,4) NULL,
    `discount_total` DECIMAL(18,4) NULL,
    `shipping_total` DECIMAL(18,4) NULL,
    `tax_total` DECIMAL(18,4) NULL,

    `payment_method` VARCHAR(80) NULL,
    `payment_method_title` VARCHAR(120) NULL,
    `gateway` VARCHAR(60) NULL,
    `transaction_id` VARCHAR(120) NULL,
    `paid_via` VARCHAR(40) NULL COMMENT 'gateway|cash|pos|...',
    `paid_at` DATETIME NULL,

    `billing_json` JSON NULL COMMENT 'آدرس صورتحساب (JSON)؛ استفاده در AI/RefreshSummaries',
    `shipping_json` JSON NULL COMMENT 'آدرس ارسال (JSON)',
    `items_json` JSON NULL COMMENT 'آیتم‌های فروش / خطوط فاکتور (JSON)',
    `meta_json` TEXT NULL COMMENT 'متادیتا (ووکامرس و سایر)',

    `customer_note` TEXT NULL,
    `internal_note` TEXT NULL,

    `ai_summary` TEXT NULL,
    `ai_updated_at` DATETIME NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_woo_order_id` (`woo_order_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_deleted_at` (`deleted_at`),
    KEY `idx_source` (`source`),
    CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- payments: پرداخت‌های ثبت‌شده (وابسته به فروش یا مستقل)
-- مورد استفاده: Dashboard (مجموع پرداخت ۳۰ روز)، SalesController، Payment Entity
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sale_id` BIGINT UNSIGNED NULL COMMENT 'فروش مرتبط',
    `customer_id` BIGINT UNSIGNED NULL,
    `woo_order_id` BIGINT UNSIGNED NULL,

    `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|failed|refunded|cancelled',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'IRR',
    `amount` DECIMAL(18,4) NOT NULL COMMENT 'مبلغ پرداخت',

    `method` VARCHAR(60) NULL COMMENT 'zarinpal|paypal|cash|...',
    `gateway` VARCHAR(60) NULL,
    `reference` VARCHAR(120) NULL COMMENT 'کد پیگیری / authority',
    `transaction_id` VARCHAR(120) NULL,
    `paid_at` DATETIME NULL,

    `raw` JSON NULL COMMENT 'پاسخ خام درگاه (JSON)',
    `meta` JSON NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_sale_id` (`sale_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_paid_at` (`paid_at`),
    CONSTRAINT `fk_payments_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

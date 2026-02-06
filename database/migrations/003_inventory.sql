-- =============================================================================
-- ReadyCRM v2 - Migration 003: انبار و محصولات (products، product_variants)
-- =============================================================================
-- جداول محصولات و تنوع آن‌ها؛ سازگار با API محصولات و همگام‌سازی ووکامرس.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- products: محصولات (ساده یا متغیر)
-- ستون‌ها مطابق ProductsApiController و WooWebhookController
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(20) NOT NULL DEFAULT 'simple' COMMENT 'simple|variable',
    `sku` VARCHAR(100) NULL COMMENT 'کد یکتای محصول',
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `description` LONGTEXT NULL,
    `short_description` TEXT NULL,

    `status` VARCHAR(20) NOT NULL DEFAULT 'publish' COMMENT 'publish|draft|private|trash',

    `price` DECIMAL(18,4) NULL,
    `regular_price` DECIMAL(18,4) NULL,
    `sale_price` DECIMAL(18,4) NULL,
    `currency` VARCHAR(10) NULL DEFAULT 'IRR',

    `stock_status` VARCHAR(20) NULL COMMENT 'instock|outofstock|onbackorder',
    `stock_quantity` INT NULL,
    `manage_stock` TINYINT(1) NOT NULL DEFAULT 0,

    `categories_json` JSON NULL,
    `images_json` JSON NULL,
    `attributes_json` JSON NULL,
    `meta_json` TEXT NULL,

    `woo_product_id` BIGINT UNSIGNED NULL COMMENT 'شناسه محصول در ووکامرس',
    `woo_synced_at` DATETIME NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_woo_product_id` (`woo_product_id`),
    KEY `idx_sku` (`sku`(50)),
    KEY `idx_status` (`status`),
    KEY `idx_type` (`type`),
    KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- product_variants: تنوع محصول (برای نوع variable)
-- هر ردیف یک واریانت (مثلاً سایز/رنگ) از یک محصول است.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_variants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL COMMENT 'ارجاع به products.id',

    `sku` VARCHAR(100) NULL,
    `name` VARCHAR(255) NULL COMMENT 'نام نمایشی واریانت',

    `price` DECIMAL(18,4) NULL,
    `regular_price` DECIMAL(18,4) NULL,
    `sale_price` DECIMAL(18,4) NULL,

    `stock_status` VARCHAR(20) NULL,
    `stock_quantity` INT NULL,
    `manage_stock` TINYINT(1) NOT NULL DEFAULT 0,

    `attributes_json` JSON NULL COMMENT 'ویژگی‌های واریانت (مثلاً رنگ، سایز)',
    `image_json` JSON NULL,
    `meta_json` TEXT NULL,

    `woo_variation_id` BIGINT UNSIGNED NULL COMMENT 'شناسه واریانت در ووکامرس',
    `woo_synced_at` DATETIME NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_woo_variation_id` (`woo_variation_id`),
    KEY `idx_product_id` (`product_id`),
    KEY `idx_product_sku` (`product_id`, `sku`(50)),
    KEY `idx_updated_at` (`updated_at`),
    CONSTRAINT `fk_product_variants_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

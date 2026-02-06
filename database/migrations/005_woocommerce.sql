-- =============================================================================
-- ReadyCRM v2 - Migration 005: یکپارچه‌سازی ووکامرس
-- =============================================================================
-- جداول همگام‌سازی، صف Outbox، وب‌هوک و گزارش تطبیق.
-- مورد استفاده: InitialImport*، OutboxPushJob، WooWebhookController، ReconcileJob
-- =============================================================================

-- -----------------------------------------------------------------------------
-- woo_sync_state: وضعیت و کرسر هر نوع سینک اولیه (محصولات، مشتریان، سفارشات)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `woo_sync_state` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id` BIGINT NOT NULL DEFAULT 1 COMMENT 'شناسه سایت ووکامرس (چندفروشگاهی)',
    `sync_key` VARCHAR(80) NOT NULL COMMENT 'مثلاً initial_products، initial_customers، initial_orders',
    `cursor_json` MEDIUMTEXT NULL COMMENT 'کرسر برای ادامه سینک (صفحه، since و ...)',
    `last_run_at` DATETIME NULL,
    `last_success_at` DATETIME NULL,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_site_key` (`site_id`, `sync_key`),
    KEY `idx_site` (`site_id`),
    KEY `idx_key` (`sync_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- woo_outbox: صف ارسال تغییرات CRM به ووکامرس (Outbox Pattern)
-- سازگار با WooOutboxPublisher (event_type، woo_id، tries) و OutboxPushJob (site_id، action، attempts)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `woo_outbox` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id` BIGINT NOT NULL DEFAULT 1,
    `event_type` VARCHAR(60) NULL COMMENT 'نوع رویداد برای Publisher',
    `entity_type` VARCHAR(40) NOT NULL COMMENT 'product|variant|customer|order',
    `entity_id` BIGINT NOT NULL COMMENT 'شناسه داخلی CRM',
    `woo_id` BIGINT NULL COMMENT 'شناسه در ووکامرس (در صورت وجود)',
    `action` VARCHAR(40) NULL COMMENT 'upsert|delete|sync_stock|... برای Job',
    `payload_json` MEDIUMTEXT NULL,
    `idempotency_key` VARCHAR(120) NULL COMMENT 'کلید یکتایی برای جلوگیری از دوباره‌کاری',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|sending|sent|failed|dead',
    `tries` INT NOT NULL DEFAULT 0 COMMENT 'تعداد تلاش (Publisher)',
    `attempts` INT NOT NULL DEFAULT 0 COMMENT 'تعداد تلاش (Job)',
    `max_attempts` INT NOT NULL DEFAULT 5,
    `last_error` TEXT NULL,
    `locked_at` DATETIME NULL,
    `available_at` DATETIME NOT NULL COMMENT 'زمان آزاد شدن برای ارسال مجدد',
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_idem` (`idempotency_key`),
    KEY `idx_status_available` (`status`, `available_at`),
    KEY `idx_entity` (`entity_type`, `entity_id`),
    KEY `idx_site` (`site_id`),
    KEY `idx_event` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- woo_webhook_events: رویدادهای دریافتی وب‌هوک ووکامرس (دِدوباره‌کاری با payload_hash)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `woo_webhook_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `delivery_id` VARCHAR(120) NULL,
    `webhook_id` BIGINT NULL,
    `topic` VARCHAR(120) NULL,
    `resource` VARCHAR(60) NULL,
    `event` VARCHAR(60) NULL,
    `signature` VARCHAR(255) NULL,
    `payload_hash` CHAR(64) NOT NULL COMMENT 'هش payload برای تشخیص تکراری',
    `status` VARCHAR(20) NOT NULL DEFAULT 'received' COMMENT 'received|processed|failed',
    `error_message` TEXT NULL,
    `entity_type` VARCHAR(40) NULL,
    `entity_id` BIGINT NULL,
    `raw_payload` MEDIUMTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_payload_hash` (`payload_hash`),
    KEY `idx_created` (`created_at`),
    KEY `idx_topic` (`topic`),
    KEY `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- woo_sync_queue: صف پردازش دستی/غیرهمزمان (job_type + woo_id)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `woo_sync_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_type` VARCHAR(60) NOT NULL COMMENT 'نوع job',
    `woo_id` BIGINT NOT NULL,
    `payload_json` MEDIUMTEXT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `tries` INT NOT NULL DEFAULT 0,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_created` (`created_at`),
    KEY `idx_job` (`job_type`, `woo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- woo_reconcile_reports: گزارش‌های تطبیق CRM با ووکامرس
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `woo_reconcile_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id` BIGINT NOT NULL DEFAULT 1,
    `mode` VARCHAR(30) NOT NULL COMMENT 'محصولات|سفارشات|...',
    `strategy` VARCHAR(30) NOT NULL,
    `repair` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا اصلاح انجام شد',
    `dry_run` TINYINT(1) NOT NULL DEFAULT 0,
    `summary_json` MEDIUMTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_site` (`site_id`),
    KEY `idx_mode` (`mode`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

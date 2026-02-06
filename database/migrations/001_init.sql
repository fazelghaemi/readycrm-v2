-- =============================================================================
-- ReadyCRM v2 - Migration 001: جداول پایه (کاربران، تنظیمات، احراز هویت، API)
-- =============================================================================
-- این فایل جداول اولیه‌ای را ایجاد می‌کند که برای نصب و اجرای هسته CRM لازم هستند.
-- جدول migrations توسط Migrator به‌صورت خودکار ساخته می‌شود؛ اینجا تعریف نمی‌شود.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- users: کاربران سیستم (ادمین و کاربران لاگین‌شده)
-- مورد استفاده: AuthController (لاگین)، Install (ساخت ادمین اول)، api_tokens
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(190) NOT NULL COMMENT 'نام کاربری یکتا برای ورود',
    `email` VARCHAR(190) NULL COMMENT 'ایمیل (اختیاری؛ برای بازیابی رمز)',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'هش رمز عبور (مثلاً password_hash با PASSWORD_DEFAULT)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=فعال، 0=غیرفعال',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    UNIQUE KEY `uniq_email` (`email`),
    KEY `idx_is_active` (`is_active`),
    KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- settings: تنظیمات key/value (ووکامرس، GapGPT، و سایر یکپارچه‌سازی‌ها)
-- مورد استفاده: DashboardController، صفحات تنظیمات
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(190) NOT NULL COMMENT 'کلید یکتا (مثلاً woo_ck، gapgpt_api_key)',
    `value` TEXT NULL COMMENT 'مقدار (ممکن است طولانی یا JSON باشد)',
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_key` (`key`),
    KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- password_resets: توکن‌های بازیابی رمز عبور (یک‌بار مصرف، با انقضا)
-- مورد استفاده: AuthController (فراموشی رمز، ریست با لینک ایمیل)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'ارجاع به users.id',
    `token_hash` CHAR(64) NOT NULL COMMENT 'هش SHA-256 توکن ارسالی به کاربر',
    `expires_at` DATETIME NOT NULL COMMENT 'زمان انقضای توکن',
    `used_at` DATETIME NULL COMMENT 'زمان استفاده (NULL = هنوز استفاده نشده)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) NULL COMMENT 'IP درخواست دهنده',
    `user_agent` VARCHAR(255) NULL COMMENT 'User-Agent مرورگر',
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_token_hash` (`token_hash`),
    KEY `idx_expires_used` (`expires_at`, `used_at`),
    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- api_tokens: توکن‌های دسترسی API (Bearer) برای احراز هویت درخواست‌های API
-- مورد استفاده: ProductsApiController، AIController (اعتبارسنجی Authorization: Bearer)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'کاربر مالک توکن',
    `token_hash` CHAR(64) NOT NULL COMMENT 'هش SHA-256 توکن (توکن خام ذخیره نشود)',
    `name` VARCHAR(120) NULL COMMENT 'نام اختیاری برای شناسایی توکن',
    `last_used_at` DATETIME NULL COMMENT 'آخرین استفاده',
    `expires_at` DATETIME NULL COMMENT 'انقضا (NULL = بدون انقضا)',
    `revoked_at` DATETIME NULL COMMENT 'لغو شده (NULL = فعال)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token_hash` (`token_hash`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_revoked_expires` (`revoked_at`, `expires_at`),
    CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- api_rate_limits: محدودیت نرخ درخواست API (پنجره زمانی + شمارنده)
-- مورد استفاده: ProductsApiController، AIController (Rate limit per key)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rl_key` VARCHAR(190) NOT NULL COMMENT 'کلید محدودیت (مثلاً IP یا token_id)',
    `window_start` INT NOT NULL COMMENT 'شروع پنجره زمانی (مثلاً Unix timestamp تقسیم‌شده)',
    `counter` INT NOT NULL DEFAULT 0 COMMENT 'تعداد درخواست در این پنجره',
    `updated_at` DATETIME NOT NULL COMMENT 'آخرین بروزرسانی',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_rl` (`rl_key`, `window_start`),
    KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

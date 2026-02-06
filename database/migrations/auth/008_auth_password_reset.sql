-- =============================================================================
-- ReadyCRM v2 - Migration 008 (Auth): تکمیل بازیابی رمز عبور
-- =============================================================================
-- جدول password_resets در 001_init.sql ایجاد شده است.
-- این فایل جدول کمکی برای ثبت درخواست‌های بازیابی رمز (محدودیت نرخ و audit) اضافه می‌کند.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- password_reset_attempts: ثبت هر درخواست «فراموشی رمز» (برای محدودیت نرخ و لاگ امنیتی)
-- جلوگیری از سوءاستفاده: حداکثر N درخواست در بازه زمانی برای هر identifier
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NULL COMMENT 'کاربر درخواست‌دهنده (در صورت شناخته بودن)',
    `identifier` VARCHAR(190) NOT NULL COMMENT 'ایمیل یا نام کاربری واردشده',
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=ایمیل ارسال شد، 0=خطا یا کاربر یافت نشد',
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_identifier_requested` (`identifier`(80), `requested_at`),
    KEY `idx_requested_at` (`requested_at`),
    KEY `idx_ip` (`ip`(20)),
    CONSTRAINT `fk_password_reset_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

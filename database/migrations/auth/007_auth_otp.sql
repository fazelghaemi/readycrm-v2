-- =============================================================================
-- ReadyCRM v2 - Migration 007 (Auth): ورود و بازیابی رمز با OTP (SMS / پیام‌رسان)
-- =============================================================================
-- جداول درخواست و تأیید کد یکبارمصرف برای ماژول Auth/SmsOtp.
-- نکته: Migrator فعلاً فقط فایل‌های *.sql داخل database/migrations/ را اسکن می‌کند.
-- برای اجرای این فایل می‌توان مسیر auth/ را جداگانه به Migrator داد یا اسکن زیرپوشه اضافه کرد.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- otp_requests: هر درخواست ارسال کد OTP (یک رکورد به ازای هر ارسال)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` VARCHAR(190) NOT NULL COMMENT 'شماره موبایل یا ایمیل کاربر',
    `purpose` VARCHAR(40) NOT NULL DEFAULT 'login' COMMENT 'login|forgot_password|register|verify_mobile',
    `code_hash` CHAR(64) NOT NULL COMMENT 'هش کد OTP (مثلاً SHA-256)؛ خود کد ذخیره نشود',
    `reference_id` VARCHAR(120) NULL COMMENT 'شناسه مرجع از سرویس پیامک (مثلاً MsgWay)',
    `provider` VARCHAR(40) NULL COMMENT 'msgway|...',
    `expires_at` DATETIME NOT NULL COMMENT 'زمان انقضای کد',
    `verified_at` DATETIME NULL COMMENT 'زمان تأیید موفق (NULL = هنوز تأیید نشده)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_identifier_purpose` (`identifier`(50), `purpose`),
    KEY `idx_expires_at` (`expires_at`),
    KEY `idx_reference_id` (`reference_id`(40)),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- otp_attempts: ثبت تلاش‌های تأیید کد (برای محدودیت نرخ و امنیت)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `otp_request_id` BIGINT UNSIGNED NULL COMMENT 'ارجاع به otp_requests (اختیاری)',
    `identifier` VARCHAR(190) NOT NULL COMMENT 'شماره/ایمیل برای گروه‌بندی بدون وابستگی به request',
    `success` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=تأیید موفق، 0=ناموفق',
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_otp_request_id` (`otp_request_id`),
    KEY `idx_identifier_attempted` (`identifier`(50), `attempted_at`),
    KEY `idx_attempted_at` (`attempted_at`),
    CONSTRAINT `fk_otp_attempts_request` FOREIGN KEY (`otp_request_id`) REFERENCES `otp_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

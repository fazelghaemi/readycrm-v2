-- =============================================================================
-- ReadyCRM v2 - Migration 006: ماژول هوش مصنوعی (GapGPT)
-- =============================================================================
-- جداول خلاصه‌ها، درخواست‌ها و لاگ AI؛ سازگار با RefreshSummariesJob، RunAIRequestJob و AIController
-- =============================================================================

-- -----------------------------------------------------------------------------
-- ai_summaries: خلاصه‌های تولیدشده برای هر موضوع (مشتری، محصول، سفارش)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_summaries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_type` VARCHAR(40) NOT NULL COMMENT 'customer|product|order',
    `subject_id` BIGINT NOT NULL,
    `summary_key` VARCHAR(120) NOT NULL COMMENT 'مثلاً customer.profile.summarize',
    `summary_text` MEDIUMTEXT NULL,
    `summary_json` MEDIUMTEXT NULL,
    `warnings_json` MEDIUMTEXT NULL,
    `provider` VARCHAR(80) NULL,
    `model` VARCHAR(120) NULL,
    `refreshed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subject_key` (`subject_type`, `subject_id`, `summary_key`),
    KEY `idx_subject` (`subject_type`, `subject_id`),
    KEY `idx_refreshed` (`refreshed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ai_requests: درخواست‌های پردازش AI (صف و وضعیت اجرا)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|queued|running|done|failed',
    `scenario_key` VARCHAR(120) NOT NULL,
    `input_json` MEDIUMTEXT NULL,
    `options_json` MEDIUMTEXT NULL,
    `output_text` MEDIUMTEXT NULL,
    `output_json` MEDIUMTEXT NULL,
    `warnings_json` MEDIUMTEXT NULL,
    `provider` VARCHAR(80) NULL,
    `model` VARCHAR(120) NULL,
    `tokens_prompt` INT NULL,
    `tokens_completion` INT NULL,
    `tokens_total` INT NULL,
    `duration_sec` INT NULL,
    `error_message` TEXT NULL,
    `started_at` DATETIME NULL,
    `finished_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_scenario` (`scenario_key`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ai_logs: لاگ درخواست‌های API (AIController) و لاگ Job (RunAIRequestJob)
-- ستون‌های request_id/level/event/meta_json برای Job؛ user_id/scenario/entity/request_json/response_json برای API
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` BIGINT UNSIGNED NULL COMMENT 'ارجاع به ai_requests (برای RunAIRequestJob)',
    `level` VARCHAR(20) NULL COMMENT 'info|warning|error (Job)',
    `event` VARCHAR(80) NULL COMMENT 'رویداد (Job)',
    `meta_json` MEDIUMTEXT NULL COMMENT 'متادیتا (Job)',
    `user_id` BIGINT UNSIGNED NULL COMMENT 'کاربر درخواست‌دهنده (API)',
    `scenario` VARCHAR(80) NULL,
    `entity_type` VARCHAR(30) NULL,
    `entity_id` BIGINT NULL,
    `model` VARCHAR(80) NULL,
    `request_json` MEDIUMTEXT NULL,
    `response_json` MEDIUMTEXT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'ok' COMMENT 'ok|error (API)',
    `error_message` TEXT NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_request` (`request_id`),
    KEY `idx_level` (`level`),
    KEY `idx_event` (`event`),
    KEY `idx_entity` (`entity_type`, `entity_id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

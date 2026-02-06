<?php
/**
 * File: app/Domain/Enums/SMSCampaignStatus.php
 * 
 * وضعیت‌های کمپین پیامکی
 */

declare(strict_types=1);

namespace App\Domain\Enums;

enum SMSCampaignStatus: string
{
    case DRAFT = 'draft';           // پیش‌نویس
    case SCHEDULED = 'scheduled';   // زمان‌بندی شده
    case PROCESSING = 'processing'; // در حال ارسال
    case COMPLETED = 'completed';   // تکمیل شده
    case PAUSED = 'paused';         // متوقف شده
    case CANCELLED = 'cancelled';   // لغو شده
    case FAILED = 'failed';         // خطا

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'پیش‌نویس',
            self::SCHEDULED => 'زمان‌بندی شده',
            self::PROCESSING => 'در حال ارسال',
            self::COMPLETED => 'تکمیل شده',
            self::PAUSED => 'متوقف شده',
            self::CANCELLED => 'لغو شده',
            self::FAILED => 'خطا',
        };
    }

    public function canEdit(): bool
    {
        return match ($this) {
            self::DRAFT, self::PAUSED => true,
            default => false,
        };
    }

    public function canStart(): bool
    {
        return match ($this) {
            self::DRAFT, self::SCHEDULED, self::PAUSED => true,
            default => false,
        };
    }
}

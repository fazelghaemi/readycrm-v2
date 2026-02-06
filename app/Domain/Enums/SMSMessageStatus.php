<?php
/**
 * File: app/Domain/Enums/SMSMessageStatus.php
 * 
 * وضعیت‌های پیام SMS
 */

declare(strict_types=1);

namespace App\Domain\Enums;

enum SMSMessageStatus: string
{
    case PENDING = 'pending';       // در صف
    case SENDING = 'sending';       // در حال ارسال
    case SENT = 'sent';             // ارسال شده
    case DELIVERED = 'delivered';   // تحویل داده شده
    case FAILED = 'failed';         // خطا
    case REJECTED = 'rejected';     // رد شده
    case EXPIRED = 'expired';       // منقضی شده

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'در صف',
            self::SENDING => 'در حال ارسال',
            self::SENT => 'ارسال شده',
            self::DELIVERED => 'تحویل داده شده',
            self::FAILED => 'خطا',
            self::REJECTED => 'رد شده',
            self::EXPIRED => 'منقضی شده',
        };
    }

    public function isSuccess(): bool
    {
        return match ($this) {
            self::SENT, self::DELIVERED => true,
            default => false,
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::DELIVERED, self::FAILED, self::REJECTED, self::EXPIRED => true,
            default => false,
        };
    }
}

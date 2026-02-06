<?php
/**
 * File: app/Domain/Enums/SMSCampaignType.php
 * 
 * انواع کمپین پیامکی
 */

declare(strict_types=1);

namespace App\Domain\Enums;

enum SMSCampaignType: string
{
    case MARKETING = 'marketing';         // تبلیغاتی
    case TRANSACTIONAL = 'transactional'; // خدماتی/تراکنشی
    case NOTIFICATION = 'notification';   // اطلاع‌رسانی
    case OTP = 'otp';                     // کد یکبار مصرف
    case BULK = 'bulk';                   // ارسال انبوه

    public function label(): string
    {
        return match ($this) {
            self::MARKETING => 'تبلیغاتی',
            self::TRANSACTIONAL => 'خدماتی',
            self::NOTIFICATION => 'اطلاع‌رسانی',
            self::OTP => 'OTP',
            self::BULK => 'ارسال انبوه',
        };
    }
}

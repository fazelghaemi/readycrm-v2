<?php
/**
 * File: app/Domain/Enums/PaymentStatus.php
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class PaymentStatus
{
    public const PENDING   = 'pending';
    public const PAID      = 'paid';
    public const FAILED    = 'failed';
    public const REFUNDED  = 'refunded';
    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [self::PENDING, self::PAID, self::FAILED, self::REFUNDED, self::CANCELLED];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        return in_array($value, self::all(), true);
    }

    public static function normalize(?string $value, string $default = self::PENDING): string
    {
        $value = is_string($value) ? trim($value) : '';
        return self::isValid($value) ? $value : $default;
    }
}

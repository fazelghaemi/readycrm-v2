<?php
/**
 * File: app/Domain/Enums/SaleStatus.php
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class SaleStatus
{
    public const PENDING    = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED  = 'completed';
    public const CANCELLED  = 'cancelled';
    public const REFUNDED   = 'refunded';
    public const FAILED     = 'failed';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::CANCELLED,
            self::REFUNDED,
            self::FAILED,
        ];
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

<?php
/**
 * File: app/Domain/Enums/CustomerStatus.php
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class CustomerStatus
{
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';
    public const BLOCKED  = 'blocked';
    public const LEAD     = 'lead';
    public const ARCHIVED = 'archived';

    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::INACTIVE,
            self::BLOCKED,
            self::LEAD,
            self::ARCHIVED,
        ];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        return in_array($value, self::all(), true);
    }

    public static function normalize(?string $value, string $default = self::ACTIVE): string
    {
        $value = is_string($value) ? trim($value) : '';
        return self::isValid($value) ? $value : $default;
    }
}

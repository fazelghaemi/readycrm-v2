<?php
/**
 * File: app/Domain/Enums/ProductType.php
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class ProductType
{
    public const SIMPLE   = 'simple';
    public const VARIABLE = 'variable';

    public static function all(): array
    {
        return [self::SIMPLE, self::VARIABLE];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        return in_array($value, self::all(), true);
    }

    public static function normalize(?string $value, string $default = self::SIMPLE): string
    {
        $value = is_string($value) ? trim($value) : '';
        return self::isValid($value) ? $value : $default;
    }
}

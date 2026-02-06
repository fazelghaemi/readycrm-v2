<?php
/**
 * File: app/Domain/Enums/ProductStatus.php
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class ProductStatus
{
    public const PUBLISH = 'publish';
    public const DRAFT   = 'draft';
    public const PRIVATE = 'private';
    public const TRASH   = 'trash';

    public static function all(): array
    {
        return [self::PUBLISH, self::DRAFT, self::PRIVATE, self::TRASH];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        return in_array($value, self::all(), true);
    }

    public static function normalize(?string $value, string $default = self::PUBLISH): string
    {
        $value = is_string($value) ? trim($value) : '';
        return self::isValid($value) ? $value : $default;
    }
}

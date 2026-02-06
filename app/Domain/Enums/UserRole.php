<?php
/**
 * File: app/Domain/Enums/UserRole.php
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class UserRole
{
    public const ADMIN  = 'admin';
    public const STAFF  = 'staff';
    public const VIEWER = 'viewer';

    public static function all(): array
    {
        return [self::ADMIN, self::STAFF, self::VIEWER];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        return in_array($value, self::all(), true);
    }

    public static function normalize(?string $value, string $default = self::STAFF): string
    {
        $value = is_string($value) ? trim($value) : '';
        return self::isValid($value) ? $value : $default;
    }

    public static function canManageEverything(string $role): bool
    {
        return $role === self::ADMIN;
    }
}

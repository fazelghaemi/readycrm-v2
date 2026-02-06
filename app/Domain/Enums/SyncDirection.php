<?php
/**
 * File: app/Domain/Enums/SyncDirection.php
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class SyncDirection
{
    public const WOO_TO_CRM = 'woo_to_crm';
    public const CRM_TO_WOO = 'crm_to_woo';
    public const BIDIRECTIONAL = 'bidirectional';

    public static function all(): array
    {
        return [self::WOO_TO_CRM, self::CRM_TO_WOO, self::BIDIRECTIONAL];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        return in_array($value, self::all(), true);
    }

    public static function normalize(?string $value, string $default = self::BIDIRECTIONAL): string
    {
        $value = is_string($value) ? trim($value) : '';
        return self::isValid($value) ? $value : $default;
    }
}

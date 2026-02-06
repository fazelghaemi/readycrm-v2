<?php
/**
 * File: app/Domain/Enums/AIScenario.php
 *
 * سناریوهای AI که در GapGPT استفاده می‌کنیم.
 */

declare(strict_types=1);

namespace App\Domain\Enums;

final class AIScenario
{
    public const SUMMARIZE_CUSTOMER = 'summarize_customer';
    public const TAG_CUSTOMER       = 'tag_customer';
    public const SUMMARIZE_SALE     = 'summarize_sale';
    public const DRAFT_FOLLOWUP     = 'draft_followup_message';

    public static function all(): array
    {
        return [
            self::SUMMARIZE_CUSTOMER,
            self::TAG_CUSTOMER,
            self::SUMMARIZE_SALE,
            self::DRAFT_FOLLOWUP,
        ];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) return false;
        return in_array($value, self::all(), true);
    }
}

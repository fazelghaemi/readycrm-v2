<?php
/**
 * File: app/Domain/Entities/Customer.php
 *
 * CRM V2 - Domain Entity: Customer
 * ------------------------------------------------------------
 * هدف:
 *  - مدل دامنه برای مشتری (Customer) مستقل از دیتابیس/فریمورک
 *  - قابل استفاده برای:
 *      - CRM داخلی
 *      - همگام‌سازی WooCommerce (customer_id)
 *      - ویژگی‌های AI (خلاصه، برچسب‌ها، امتیاز)
 *
 * نکته:
 *  - این Entity برای ذخیره/خواندن از DB با کلیدهای snake_case آماده است.
 */

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTimeImmutable;
use JsonSerializable;

final class Customer implements JsonSerializable
{
    // Identifiers
    public ?int $id = null;
    public ?int $woo_customer_id = null;

    // Core profile
    public string $full_name = '';
    public ?string $first_name = null;
    public ?string $last_name = null;

    public ?string $email = null;
    public ?string $phone = null;

    // Optional identity fields
    public ?string $national_id = null; // کد ملی (اختیاری)
    public ?string $company = null;
    public ?string $vat_number = null;

    // Address (normalized, can map to Woo billing/shipping)
    public ?string $billing_address1 = null;
    public ?string $billing_address2 = null;
    public ?string $billing_city = null;
    public ?string $billing_state = null;
    public ?string $billing_postcode = null;
    public ?string $billing_country = null;

    public ?string $shipping_address1 = null;
    public ?string $shipping_address2 = null;
    public ?string $shipping_city = null;
    public ?string $shipping_state = null;
    public ?string $shipping_postcode = null;
    public ?string $shipping_country = null;

    // CRM fields
    public string $status = 'active'; // active|inactive|blocked|lead|archived
    public ?string $notes = null;

    /**
     * Tag list (CRM + AI).
     * Stored as JSON string in DB usually.
     * @var array<int,string>
     */
    public array $tags = [];

    // AI fields
    public ?string $ai_summary = null;
    public ?float $ai_score = null; // e.g. lead score
    public ?DateTimeImmutable $ai_updated_at = null;

    // Timestamps
    public ?DateTimeImmutable $created_at = null;
    public ?DateTimeImmutable $updated_at = null;
    public ?DateTimeImmutable $deleted_at = null;

    // Extra metadata (safe extension point)
    /** @var array<string,mixed> */
    public array $meta = [];

    private function __construct()
    {
        // use factories
    }

    // ---------------------------------------------------------------------
    // Factories
    // ---------------------------------------------------------------------

    /**
     * Create from array (DB row, request payload, Woo-mapped payload)
     * Accepts snake_case keys by default.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $e = new self();

        $e->id = self::toIntOrNull($data['id'] ?? null);
        $e->woo_customer_id = self::toIntOrNull($data['woo_customer_id'] ?? $data['woo_id'] ?? null);

        $e->full_name = self::toString($data['full_name'] ?? $data['name'] ?? '');
        $e->first_name = self::toStringOrNull($data['first_name'] ?? null);
        $e->last_name  = self::toStringOrNull($data['last_name'] ?? null);

        // Auto-generate full_name if missing
        if (trim($e->full_name) === '') {
            $parts = [];
            if ($e->first_name) $parts[] = $e->first_name;
            if ($e->last_name)  $parts[] = $e->last_name;
            $e->full_name = trim(implode(' ', $parts));
        }

        $e->email = self::normalizeEmail(self::toStringOrNull($data['email'] ?? null));
        $e->phone = self::normalizePhone(self::toStringOrNull($data['phone'] ?? null));

        $e->national_id = self::toStringOrNull($data['national_id'] ?? null);
        $e->company = self::toStringOrNull($data['company'] ?? null);
        $e->vat_number = self::toStringOrNull($data['vat_number'] ?? null);

        // Billing
        $e->billing_address1 = self::toStringOrNull($data['billing_address1'] ?? null);
        $e->billing_address2 = self::toStringOrNull($data['billing_address2'] ?? null);
        $e->billing_city = self::toStringOrNull($data['billing_city'] ?? null);
        $e->billing_state = self::toStringOrNull($data['billing_state'] ?? null);
        $e->billing_postcode = self::toStringOrNull($data['billing_postcode'] ?? null);
        $e->billing_country = self::toStringOrNull($data['billing_country'] ?? null);

        // Shipping
        $e->shipping_address1 = self::toStringOrNull($data['shipping_address1'] ?? null);
        $e->shipping_address2 = self::toStringOrNull($data['shipping_address2'] ?? null);
        $e->shipping_city = self::toStringOrNull($data['shipping_city'] ?? null);
        $e->shipping_state = self::toStringOrNull($data['shipping_state'] ?? null);
        $e->shipping_postcode = self::toStringOrNull($data['shipping_postcode'] ?? null);
        $e->shipping_country = self::toStringOrNull($data['shipping_country'] ?? null);

        $e->status = self::toString($data['status'] ?? 'active');
        $e->notes = self::toStringOrNull($data['notes'] ?? null);

        $tags = $data['tags'] ?? [];
        $e->tags = self::normalizeTags($tags);

        $e->ai_summary = self::toStringOrNull($data['ai_summary'] ?? null);
        $e->ai_score = self::toFloatOrNull($data['ai_score'] ?? null);
        $e->ai_updated_at = self::toDateTimeOrNull($data['ai_updated_at'] ?? null);

        $e->created_at = self::toDateTimeOrNull($data['created_at'] ?? null);
        $e->updated_at = self::toDateTimeOrNull($data['updated_at'] ?? null);
        $e->deleted_at = self::toDateTimeOrNull($data['deleted_at'] ?? null);

        $meta = $data['meta'] ?? [];
        $e->meta = is_array($meta) ? $meta : self::decodeJsonArray((string)$meta);

        return $e;
    }

    /**
     * To array for DB insert/update (snake_case)
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'woo_customer_id' => $this->woo_customer_id,

            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,

            'email' => $this->email,
            'phone' => $this->phone,

            'national_id' => $this->national_id,
            'company' => $this->company,
            'vat_number' => $this->vat_number,

            'billing_address1' => $this->billing_address1,
            'billing_address2' => $this->billing_address2,
            'billing_city' => $this->billing_city,
            'billing_state' => $this->billing_state,
            'billing_postcode' => $this->billing_postcode,
            'billing_country' => $this->billing_country,

            'shipping_address1' => $this->shipping_address1,
            'shipping_address2' => $this->shipping_address2,
            'shipping_city' => $this->shipping_city,
            'shipping_state' => $this->shipping_state,
            'shipping_postcode' => $this->shipping_postcode,
            'shipping_country' => $this->shipping_country,

            'status' => $this->status,
            'notes' => $this->notes,

            // Store as JSON string typically
            'tags' => json_encode(array_values($this->tags), JSON_UNESCAPED_UNICODE),

            'ai_summary' => $this->ai_summary,
            'ai_score' => $this->ai_score,
            'ai_updated_at' => self::formatDateTime($this->ai_updated_at),

            'created_at' => self::formatDateTime($this->created_at),
            'updated_at' => self::formatDateTime($this->updated_at),
            'deleted_at' => self::formatDateTime($this->deleted_at),

            'meta' => json_encode($this->meta, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function jsonSerialize(): array
    {
        // For API response (do not leak meta if you don't want)
        return $this->toArray();
    }

    // ---------------------------------------------------------------------
    // Domain helpers
    // ---------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function displayName(): string
    {
        $n = trim($this->full_name);
        return $n !== '' ? $n : ('Customer #' . ($this->id ?? 0));
    }

    public function addTag(string $tag): void
    {
        $tag = self::normalizeTag($tag);
        if ($tag === '') return;
        if (!in_array($tag, $this->tags, true)) $this->tags[] = $tag;
        sort($this->tags);
    }

    public function removeTag(string $tag): void
    {
        $tag = self::normalizeTag($tag);
        $this->tags = array_values(array_filter($this->tags, fn($t) => $t !== $tag));
    }

    // ---------------------------------------------------------------------
    // Casting / normalization
    // ---------------------------------------------------------------------

    private static function toString(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_numeric($v)) return (string)$v;
        return trim((string)$v);
    }

    private static function toStringOrNull(mixed $v): ?string
    {
        $s = trim(self::toString($v));
        return $s === '' ? null : $s;
    }

    private static function toIntOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    private static function toFloatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_float($v)) return $v;
        if (is_int($v)) return (float)$v;
        if (is_numeric($v)) return (float)$v;
        return null;
    }

    private static function toDateTimeOrNull(mixed $v): ?DateTimeImmutable
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof DateTimeImmutable) return $v;

        if ($v instanceof \DateTimeInterface) {
            return new DateTimeImmutable($v->format('c'));
        }

        if (is_numeric($v)) {
            // unix timestamp
            $ts = (int)$v;
            return (new DateTimeImmutable())->setTimestamp($ts);
        }

        $s = trim((string)$v);
        if ($s === '') return null;

        // Try common DB formats
        try {
            return new DateTimeImmutable($s);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function formatDateTime(?DateTimeImmutable $dt): ?string
    {
        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    private static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) return null;
        $email = trim(mb_strtolower($email));
        if ($email === '') return null;
        // Very light validation
        if (strpos($email, '@') === false) return $email;
        return $email;
    }

    private static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) return null;
        $p = trim($phone);
        if ($p === '') return null;

        // Keep + and digits, drop spaces/dashes
        $p = preg_replace('/[^\d\+]/u', '', $p) ?? $p;
        return $p === '' ? null : $p;
    }

    /**
     * @param mixed $tags
     * @return array<int,string>
     */
    private static function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $arr = self::decodeJsonArray($tags);
            return self::normalizeTags($arr);
        }

        if (!is_array($tags)) return [];

        $out = [];
        foreach ($tags as $t) {
            $tt = self::normalizeTag((string)$t);
            if ($tt !== '' && !in_array($tt, $out, true)) $out[] = $tt;
        }
        sort($out);
        return $out;
    }

    private static function normalizeTag(string $tag): string
    {
        $tag = trim($tag);
        $tag = preg_replace('/\s+/u', ' ', $tag) ?? $tag;
        return trim($tag);
    }

    /**
     * Decode JSON that might represent array.
     * @return array<mixed>
     */
    private static function decodeJsonArray(string $json): array
    {
        $json = trim($json);
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

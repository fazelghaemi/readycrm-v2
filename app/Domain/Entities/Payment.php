<?php
/**
 * File: app/Domain/Entities/Payment.php
 *
 * CRM V2 - Domain Entity: Payment
 * ------------------------------------------------------------
 * پرداخت‌ها می‌توانند:
 *  - مربوط به فروش CRM باشند
 *  - از WooCommerce وارد شوند
 *  - از چند درگاه مختلف باشند
 */

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTimeImmutable;
use JsonSerializable;

final class Payment implements JsonSerializable
{
    public ?int $id = null;

    public ?int $sale_id = null;
    public ?int $customer_id = null;

    public ?int $woo_order_id = null;

    public string $status = 'pending'; // pending|paid|failed|refunded|cancelled
    public string $currency = 'IRR';
    public ?float $amount = null;

    public ?string $method = null; // e.g. 'zarinpal', 'paypal', 'cash'
    public ?string $gateway = null;
    public ?string $reference = null; // ref id, authority, tracking code
    public ?string $transaction_id = null;

    public ?DateTimeImmutable $paid_at = null;

    /** @var array<string,mixed> */
    public array $raw = []; // raw payload from gateway/woo (json)

    public ?DateTimeImmutable $created_at = null;
    public ?DateTimeImmutable $updated_at = null;

    /** @var array<string,mixed> */
    public array $meta = [];

    private function __construct() {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $e = new self();

        $e->id = self::toIntOrNull($data['id'] ?? null);

        $e->sale_id = self::toIntOrNull($data['sale_id'] ?? null);
        $e->customer_id = self::toIntOrNull($data['customer_id'] ?? null);

        $e->woo_order_id = self::toIntOrNull($data['woo_order_id'] ?? $data['woo_id'] ?? null);

        $e->status = self::toString($data['status'] ?? 'pending') ?: 'pending';
        $e->currency = self::toString($data['currency'] ?? 'IRR') ?: 'IRR';
        $e->amount = self::toFloatOrNull($data['amount'] ?? null);

        $e->method = self::toStringOrNull($data['method'] ?? null);
        $e->gateway = self::toStringOrNull($data['gateway'] ?? null);
        $e->reference = self::toStringOrNull($data['reference'] ?? null);
        $e->transaction_id = self::toStringOrNull($data['transaction_id'] ?? null);

        $e->paid_at = self::toDateTimeOrNull($data['paid_at'] ?? null);

        $raw = $data['raw'] ?? $data['raw_data'] ?? [];
        if (is_string($raw)) $raw = self::decodeJsonArray($raw);
        $e->raw = is_array($raw) ? $raw : [];

        $e->created_at = self::toDateTimeOrNull($data['created_at'] ?? null);
        $e->updated_at = self::toDateTimeOrNull($data['updated_at'] ?? null);

        $meta = $data['meta'] ?? [];
        $e->meta = is_array($meta) ? $meta : self::decodeJsonArray((string)$meta);

        return $e;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,

            'sale_id' => $this->sale_id,
            'customer_id' => $this->customer_id,

            'woo_order_id' => $this->woo_order_id,

            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount,

            'method' => $this->method,
            'gateway' => $this->gateway,
            'reference' => $this->reference,
            'transaction_id' => $this->transaction_id,

            'paid_at' => self::formatDateTime($this->paid_at),

            'raw' => json_encode($this->raw, JSON_UNESCAPED_UNICODE),

            'created_at' => self::formatDateTime($this->created_at),
            'updated_at' => self::formatDateTime($this->updated_at),

            'meta' => json_encode($this->meta, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid' || $this->paid_at !== null;
    }

    // ---------------------------------------------------------------------
    // Helpers
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
        if ($v instanceof \DateTimeInterface) return new DateTimeImmutable($v->format('c'));
        if (is_numeric($v)) return (new DateTimeImmutable())->setTimestamp((int)$v);

        try {
            return new DateTimeImmutable((string)$v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function formatDateTime(?DateTimeImmutable $dt): ?string
    {
        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    /**
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

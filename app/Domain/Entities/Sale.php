<?php
/**
 * File: app/Domain/Entities/Sale.php
 *
 * CRM V2 - Domain Entity: Sale (Invoice/Order)
 * ------------------------------------------------------------
 * - در CRM: فروش/فاکتور
 * - در WooCommerce: order (woo_order_id)
 * - شامل آیتم‌ها (lines) و مبالغ و وضعیت پرداخت
 */

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTimeImmutable;
use JsonSerializable;

final class Sale implements JsonSerializable
{
    public ?int $id = null;
    public ?int $customer_id = null;

    public ?int $woo_order_id = null;
    public string $source = 'crm'; // crm|woocommerce|api|import|manual

    public string $status = 'pending'; // pending|processing|completed|cancelled|refunded|failed
    public string $currency = 'IRR';

    // Totals
    public ?float $subtotal = null;
    public ?float $discount_total = null;
    public ?float $shipping_total = null;
    public ?float $tax_total = null;
    public ?float $total_amount = null;

    // Payment info
    public ?string $payment_method = null;        // e.g. 'cod', 'zarinpal'
    public ?string $payment_method_title = null;  // e.g. 'زرین‌پال'
    public ?string $gateway = null;               // internal gateway id
    public ?string $transaction_id = null;
    public ?string $paid_via = null;              // 'gateway', 'cash', 'pos', ...
    public ?DateTimeImmutable $paid_at = null;

    // Addresses (store as json)
    /** @var array<string,mixed> */
    public array $billing = [];

    /** @var array<string,mixed> */
    public array $shipping = [];

    /**
     * Sale lines (items)
     * Example:
     * [
     *   ['product_id'=>1,'variant_id'=>null,'sku'=>'X','name'=>'Item','qty'=>2,'unit_price'=>1000,'total'=>2000],
     *   ...
     * ]
     * @var array<int,array<string,mixed>>
     */
    public array $items = [];

    public ?string $customer_note = null;
    public ?string $internal_note = null;

    // AI
    public ?string $ai_summary = null;
    public ?DateTimeImmutable $ai_updated_at = null;

    // Timestamps
    public ?DateTimeImmutable $created_at = null;
    public ?DateTimeImmutable $updated_at = null;
    public ?DateTimeImmutable $deleted_at = null;

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
        $e->customer_id = self::toIntOrNull($data['customer_id'] ?? null);

        $e->woo_order_id = self::toIntOrNull($data['woo_order_id'] ?? $data['woo_id'] ?? null);
        $e->source = self::toString($data['source'] ?? 'crm') ?: 'crm';

        $e->status = self::toString($data['status'] ?? 'pending') ?: 'pending';
        $e->currency = self::toString($data['currency'] ?? 'IRR') ?: 'IRR';

        $e->subtotal = self::toFloatOrNull($data['subtotal'] ?? null);
        $e->discount_total = self::toFloatOrNull($data['discount_total'] ?? null);
        $e->shipping_total = self::toFloatOrNull($data['shipping_total'] ?? null);
        $e->tax_total = self::toFloatOrNull($data['tax_total'] ?? null);
        $e->total_amount = self::toFloatOrNull($data['total_amount'] ?? $data['total'] ?? null);

        $e->payment_method = self::toStringOrNull($data['payment_method'] ?? null);
        $e->payment_method_title = self::toStringOrNull($data['payment_method_title'] ?? null);
        $e->gateway = self::toStringOrNull($data['gateway'] ?? null);
        $e->transaction_id = self::toStringOrNull($data['transaction_id'] ?? null);
        $e->paid_via = self::toStringOrNull($data['paid_via'] ?? null);
        $e->paid_at = self::toDateTimeOrNull($data['paid_at'] ?? null);

        $billing = $data['billing'] ?? [];
        if (is_string($billing)) $billing = self::decodeJsonArray($billing);
        $e->billing = is_array($billing) ? $billing : [];

        $shipping = $data['shipping'] ?? [];
        if (is_string($shipping)) $shipping = self::decodeJsonArray($shipping);
        $e->shipping = is_array($shipping) ? $shipping : [];

        $items = $data['items'] ?? [];
        if (is_string($items)) $items = self::decodeJsonArray($items);
        $e->items = is_array($items) ? array_values($items) : [];

        $e->customer_note = self::toStringOrNull($data['customer_note'] ?? null);
        $e->internal_note = self::toStringOrNull($data['internal_note'] ?? null);

        $e->ai_summary = self::toStringOrNull($data['ai_summary'] ?? null);
        $e->ai_updated_at = self::toDateTimeOrNull($data['ai_updated_at'] ?? null);

        $e->created_at = self::toDateTimeOrNull($data['created_at'] ?? null);
        $e->updated_at = self::toDateTimeOrNull($data['updated_at'] ?? null);
        $e->deleted_at = self::toDateTimeOrNull($data['deleted_at'] ?? null);

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
            'customer_id' => $this->customer_id,

            'woo_order_id' => $this->woo_order_id,
            'source' => $this->source,

            'status' => $this->status,
            'currency' => $this->currency,

            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'shipping_total' => $this->shipping_total,
            'tax_total' => $this->tax_total,
            'total_amount' => $this->total_amount,

            'payment_method' => $this->payment_method,
            'payment_method_title' => $this->payment_method_title,
            'gateway' => $this->gateway,
            'transaction_id' => $this->transaction_id,
            'paid_via' => $this->paid_via,
            'paid_at' => self::formatDateTime($this->paid_at),

            'billing' => json_encode($this->billing, JSON_UNESCAPED_UNICODE),
            'shipping' => json_encode($this->shipping, JSON_UNESCAPED_UNICODE),
            'items' => json_encode($this->items, JSON_UNESCAPED_UNICODE),

            'customer_note' => $this->customer_note,
            'internal_note' => $this->internal_note,

            'ai_summary' => $this->ai_summary,
            'ai_updated_at' => self::formatDateTime($this->ai_updated_at),

            'created_at' => self::formatDateTime($this->created_at),
            'updated_at' => self::formatDateTime($this->updated_at),
            'deleted_at' => self::formatDateTime($this->deleted_at),

            'meta' => json_encode($this->meta, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null || in_array($this->status, ['completed','processing'], true);
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

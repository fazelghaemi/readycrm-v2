<?php
/**
 * File: app/Domain/Entities/ProductVariant.php
 *
 * CRM V2 - Domain Entity: ProductVariant (Woo "variation")
 * ------------------------------------------------------------
 * برای محصولات متغیر.
 */

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTimeImmutable;
use JsonSerializable;

final class ProductVariant implements JsonSerializable
{
    public ?int $id = null;
    public ?int $product_id = null;

    public ?int $woo_variation_id = null;
    public ?int $woo_product_id = null; // parent woo product id (optional)

    public ?string $sku = null;

    public ?float $price = null;
    public ?float $regular_price = null;
    public ?float $sale_price = null;

    public bool $manage_stock = false;
    public ?int $stock_quantity = null;
    public string $stock_status = 'instock';

    /**
     * Attributes (variation attributes)
     * Example: ['Color'=>'Red','Size'=>'XL']
     * @var array<string,string>
     */
    public array $attributes = [];

    public string $status = 'publish';

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
        $e->product_id = self::toIntOrNull($data['product_id'] ?? null);

        $e->woo_variation_id = self::toIntOrNull($data['woo_variation_id'] ?? $data['woo_id'] ?? null);
        $e->woo_product_id = self::toIntOrNull($data['woo_product_id'] ?? null);

        $e->sku = self::toStringOrNull($data['sku'] ?? null);

        $e->price = self::toFloatOrNull($data['price'] ?? null);
        $e->regular_price = self::toFloatOrNull($data['regular_price'] ?? null);
        $e->sale_price = self::toFloatOrNull($data['sale_price'] ?? null);

        $e->manage_stock = self::toBool($data['manage_stock'] ?? false);
        $e->stock_quantity = self::toIntOrNull($data['stock_quantity'] ?? null);
        $e->stock_status = self::toString($data['stock_status'] ?? 'instock') ?: 'instock';

        $attrs = $data['attributes'] ?? [];
        if (is_string($attrs)) $attrs = self::decodeJsonArray($attrs);
        $e->attributes = self::normalizeAttributes($attrs);

        $e->status = self::toString($data['status'] ?? 'publish') ?: 'publish';

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
            'product_id' => $this->product_id,

            'woo_variation_id' => $this->woo_variation_id,
            'woo_product_id' => $this->woo_product_id,

            'sku' => $this->sku,

            'price' => $this->price,
            'regular_price' => $this->regular_price,
            'sale_price' => $this->sale_price,

            'manage_stock' => $this->manage_stock ? 1 : 0,
            'stock_quantity' => $this->stock_quantity,
            'stock_status' => $this->stock_status,

            'attributes' => json_encode($this->attributes, JSON_UNESCAPED_UNICODE),

            'status' => $this->status,

            'created_at' => self::formatDateTime($this->created_at),
            'updated_at' => self::formatDateTime($this->updated_at),

            'meta' => json_encode($this->meta, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
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

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        if (is_string($v)) {
            $t = strtolower(trim($v));
            return in_array($t, ['1','true','yes','on'], true);
        }
        return (bool)$v;
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
     * @param mixed $attrs
     * @return array<string,string>
     */
    private static function normalizeAttributes(mixed $attrs): array
    {
        if (!is_array($attrs)) return [];

        // If attrs is list of objects like Woo: [ ['name'=>'Color','option'=>'Red'], ...]
        $isList = array_keys($attrs) === range(0, count($attrs) - 1);
        if ($isList) {
            $out = [];
            foreach ($attrs as $row) {
                if (!is_array($row)) continue;
                $name = trim((string)($row['name'] ?? ''));
                $opt  = trim((string)($row['option'] ?? $row['value'] ?? ''));
                if ($name !== '' && $opt !== '') $out[$name] = $opt;
            }
            return $out;
        }

        // Else: associative already
        $out = [];
        foreach ($attrs as $k => $v) {
            $kk = trim((string)$k);
            $vv = trim((string)$v);
            if ($kk !== '' && $vv !== '') $out[$kk] = $vv;
        }
        return $out;
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

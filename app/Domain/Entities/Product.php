<?php
/**
 * File: app/Domain/Entities/Product.php
 *
 * CRM V2 - Domain Entity: Product
 * ------------------------------------------------------------
 * پوشش:
 *  - محصول ساده (simple)
 *  - محصول متغیر (variable) (با Variantها در ProductVariant)
 *  - نگهداری فیلدهای کلیدی WooCommerce برای sync
 */

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTimeImmutable;
use JsonSerializable;

final class Product implements JsonSerializable
{
    public ?int $id = null;
    public ?int $woo_product_id = null;

    public string $type = 'simple'; // simple|variable
    public string $status = 'publish'; // publish|draft|private|trash

    public string $name = '';
    public ?string $slug = null;

    public ?string $sku = null;

    public ?string $short_description = null;
    public ?string $description = null;

    // Pricing (store in minor unit? Here float for simplicity; repositories can convert to DECIMAL)
    public ?float $price = null;          // current price
    public ?float $regular_price = null;
    public ?float $sale_price = null;
    public string $currency = 'IRR';

    // Stock
    public bool $manage_stock = false;
    public ?int $stock_quantity = null;
    public string $stock_status = 'instock'; // instock|outofstock|onbackorder

    // Media / taxonomy
    /** @var array<int,string> */
    public array $images = []; // URLs or stored paths

    /** @var array<int,string> */
    public array $categories = []; // category names or IDs as string

    /**
     * Attributes: e.g. size/color
     * Example:
     *  [
     *    ['name'=>'Color','options'=>['Red','Blue'],'variation'=>true],
     *    ...
     *  ]
     * @var array<int,array<string,mixed>>
     */
    public array $attributes = [];

    // Variants (for variable products)
    /** @var array<int,ProductVariant> */
    public array $variants = [];

    // AI fields
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
        $e->woo_product_id = self::toIntOrNull($data['woo_product_id'] ?? $data['woo_id'] ?? null);

        $e->type = self::toString($data['type'] ?? 'simple') ?: 'simple';
        $e->status = self::toString($data['status'] ?? 'publish') ?: 'publish';

        $e->name = self::toString($data['name'] ?? '');
        $e->slug = self::toStringOrNull($data['slug'] ?? null);

        $e->sku = self::toStringOrNull($data['sku'] ?? null);

        $e->short_description = self::toStringOrNull($data['short_description'] ?? null);
        $e->description = self::toStringOrNull($data['description'] ?? null);

        $e->price = self::toFloatOrNull($data['price'] ?? null);
        $e->regular_price = self::toFloatOrNull($data['regular_price'] ?? null);
        $e->sale_price = self::toFloatOrNull($data['sale_price'] ?? null);
        $e->currency = self::toString($data['currency'] ?? 'IRR') ?: 'IRR';

        $e->manage_stock = self::toBool($data['manage_stock'] ?? false);
        $e->stock_quantity = self::toIntOrNull($data['stock_quantity'] ?? null);
        $e->stock_status = self::toString($data['stock_status'] ?? 'instock') ?: 'instock';

        $e->images = self::normalizeStringList($data['images'] ?? []);
        $e->categories = self::normalizeStringList($data['categories'] ?? []);

        $attrs = $data['attributes'] ?? [];
        if (is_string($attrs)) $attrs = self::decodeJsonArray($attrs);
        $e->attributes = is_array($attrs) ? array_values($attrs) : [];

        // Variants
        $variants = $data['variants'] ?? [];
        if (is_string($variants)) $variants = self::decodeJsonArray($variants);
        if (is_array($variants)) {
            $out = [];
            foreach ($variants as $v) {
                if (is_array($v)) $out[] = ProductVariant::fromArray($v);
            }
            $e->variants = $out;
        }

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
            'woo_product_id' => $this->woo_product_id,

            'type' => $this->type,
            'status' => $this->status,

            'name' => $this->name,
            'slug' => $this->slug,

            'sku' => $this->sku,

            'short_description' => $this->short_description,
            'description' => $this->description,

            'price' => $this->price,
            'regular_price' => $this->regular_price,
            'sale_price' => $this->sale_price,
            'currency' => $this->currency,

            'manage_stock' => $this->manage_stock ? 1 : 0,
            'stock_quantity' => $this->stock_quantity,
            'stock_status' => $this->stock_status,

            'images' => json_encode(array_values($this->images), JSON_UNESCAPED_UNICODE),
            'categories' => json_encode(array_values($this->categories), JSON_UNESCAPED_UNICODE),
            'attributes' => json_encode($this->attributes, JSON_UNESCAPED_UNICODE),

            // variants typically are in separate table, but keeping as json is ok for cache
            'variants' => json_encode(array_map(fn($v) => $v->toArray(), $this->variants), JSON_UNESCAPED_UNICODE),

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

    public function isVariable(): bool
    {
        return $this->type === 'variable';
    }

    // ---------------------------------------------------------------------
    // Internal helpers
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
     * @param mixed $v
     * @return array<int,string>
     */
    private static function normalizeStringList(mixed $v): array
    {
        if (is_string($v)) $v = self::decodeJsonArray($v);
        if (!is_array($v)) return [];

        $out = [];
        foreach ($v as $x) {
            $s = trim((string)$x);
            if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
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

<?php
/**
 * File: app/Integrations/WooCommerce/WooMapper.php
 *
 * CRM V2 - WooCommerce Mapper
 * -----------------------------------------------------------------------------
 * نقش این کلاس:
 *  - تبدیل ساختار داده‌ی WooCommerce <-> ساختار داده‌ی CRM
 *  - برای ادغام دوطرفه، باید mapping استاندارد داشته باشیم تا:
 *      1) واردسازی Woo -> CRM درست انجام شود
 *      2) ارسال/به‌روزرسانی CRM -> Woo با payload معتبر انجام شود
 *
 * این Mapper عمداً "انعطاف‌پذیر" نوشته شده چون:
 *  - جداول CRM شما هنوز در حال تکمیل است
 *  - ممکن است ستون‌ها/نام‌ها تغییر کنند
 *
 * خروجی‌هایی که می‌دهد:
 *  - mapWooProductToCrmProduct()
 *  - mapWooVariationToCrmVariant()
 *  - mapWooCustomerToCrmCustomer()
 *  - mapWooOrderToCrmSale()
 *
 *  - mapCrmProductToWooCreatePayload()
 *  - mapCrmProductToWooUpdatePayload()
 *  - mapCrmVariantToWooVariationPayload()
 *
 * نکته:
 *  - این کلاس DB نمی‌زند. فقط Mapping می‌کند.
 *  - Controller/Service/Repository از خروجی این استفاده می‌کند تا insert/update انجام دهد.
 */

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

final class WooMapper
{
    /**
     * Woo Product -> CRM Product (برای upsert در جدول products)
     *
     * @param array<string,mixed> $woo
     * @return array<string,mixed>  // فیلدهایی که CRM باید ذخیره کند
     */
    public function mapWooProductToCrmProduct(array $woo): array
    {
        $wooId = (int)($woo['id'] ?? 0);

        $type = $this->s($woo['type'] ?? 'simple') ?? 'simple';     // simple|variable|grouped|external
        $status = $this->s($woo['status'] ?? 'publish') ?? 'publish';
        $sku = $this->s($woo['sku'] ?? null);

        $name = $this->s($woo['name'] ?? null);
        if (!$name) $name = $wooId > 0 ? ("Woo Product #{$wooId}") : "Woo Product";

        $description = $this->s($woo['description'] ?? null);
        $shortDescription = $this->s($woo['short_description'] ?? null);

        // Prices are strings in Woo API
        $price = $this->decimalStr($woo['price'] ?? null);
        $regularPrice = $this->decimalStr($woo['regular_price'] ?? null);
        $salePrice = $this->decimalStr($woo['sale_price'] ?? null);

        $manageStock = $this->bool01($woo['manage_stock'] ?? false);
        $stockQty = $this->intOrNull($woo['stock_quantity'] ?? null);
        $stockStatus = $this->s($woo['stock_status'] ?? null); // instock|outofstock|onbackorder

        $categoriesJson = $this->json($woo['categories'] ?? null);
        $imagesJson = $this->json($woo['images'] ?? null);
        $attributesJson = $this->json($woo['attributes'] ?? null);

        $meta = [
            'permalink' => $woo['permalink'] ?? null,
            'slug' => $woo['slug'] ?? null,
            'catalog_visibility' => $woo['catalog_visibility'] ?? null,
            'tax_status' => $woo['tax_status'] ?? null,
            'tax_class' => $woo['tax_class'] ?? null,
            'weight' => $woo['weight'] ?? null,
            'dimensions' => $woo['dimensions'] ?? null,
            'shipping_required' => $woo['shipping_required'] ?? null,
            'shipping_taxable' => $woo['shipping_taxable'] ?? null,
            'shipping_class' => $woo['shipping_class'] ?? null,
            'shipping_class_id' => $woo['shipping_class_id'] ?? null,
            'reviews_allowed' => $woo['reviews_allowed'] ?? null,
            'average_rating' => $woo['average_rating'] ?? null,
            'rating_count' => $woo['rating_count'] ?? null,
            'date_created' => $woo['date_created'] ?? null,
            'date_modified' => $woo['date_modified'] ?? null,
        ];

        return [
            // Identifiers
            'woo_product_id' => $wooId,
            'type' => $type,
            'status' => $status,

            // Product core
            'sku' => $sku,
            'name' => $name,
            'description' => $description,
            'short_description' => $shortDescription,

            // Prices
            'price' => $price,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,

            // Stock
            'manage_stock' => $manageStock,
            'stock_quantity' => $stockQty,
            'stock_status' => $stockStatus,

            // JSON blobs
            'categories_json' => $categoriesJson,
            'images_json' => $imagesJson,
            'attributes_json' => $attributesJson,

            // meta
            'meta_json' => $this->json($meta),
        ];
    }

    /**
     * Woo Variation -> CRM Variant (برای upsert در جدول product_variants)
     *
     * @param array<string,mixed> $wooVar
     * @param int $crmProductId
     * @return array<string,mixed>
     */
    public function mapWooVariationToCrmVariant(array $wooVar, int $crmProductId): array
    {
        $wooVarId = (int)($wooVar['id'] ?? 0);

        $sku = $this->s($wooVar['sku'] ?? null);

        $price = $this->decimalStr($wooVar['price'] ?? null);
        $regularPrice = $this->decimalStr($wooVar['regular_price'] ?? null);
        $salePrice = $this->decimalStr($wooVar['sale_price'] ?? null);

        $manageStock = $this->bool01($wooVar['manage_stock'] ?? false);
        $stockQty = $this->intOrNull($wooVar['stock_quantity'] ?? null);
        $stockStatus = $this->s($wooVar['stock_status'] ?? null);

        $attributesJson = $this->json($wooVar['attributes'] ?? null);
        $imageJson = $this->json($wooVar['image'] ?? null);

        $meta = [
            'date_created' => $wooVar['date_created'] ?? null,
            'date_modified' => $wooVar['date_modified'] ?? null,
            'on_sale' => $wooVar['on_sale'] ?? null,
            'purchasable' => $wooVar['purchasable'] ?? null,
            'virtual' => $wooVar['virtual'] ?? null,
            'downloadable' => $wooVar['downloadable'] ?? null,
            'downloads' => $wooVar['downloads'] ?? null,
            'download_limit' => $wooVar['download_limit'] ?? null,
            'download_expiry' => $wooVar['download_expiry'] ?? null,
        ];

        return [
            'product_id' => $crmProductId,
            'woo_variation_id' => $wooVarId,
            'sku' => $sku,

            'price' => $price,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,

            'manage_stock' => $manageStock,
            'stock_quantity' => $stockQty,
            'stock_status' => $stockStatus,

            'attributes_json' => $attributesJson,
            'image_json' => $imageJson,
            'meta_json' => $this->json($meta),
        ];
    }

    /**
     * Woo Customer -> CRM Customer
     *
     * @param array<string,mixed> $woo
     * @return array<string,mixed>
     */
    public function mapWooCustomerToCrmCustomer(array $woo): array
    {
        $wooId = (int)($woo['id'] ?? 0);

        $email = $this->s($woo['email'] ?? null);
        $first = $this->s($woo['first_name'] ?? null);
        $last = $this->s($woo['last_name'] ?? null);

        $fullName = trim(($first ?? '') . ' ' . ($last ?? ''));
        if ($fullName === '') {
            $fullName = $email ? $email : ($wooId > 0 ? "Woo Customer #{$wooId}" : "Woo Customer");
        }

        $billing = $woo['billing'] ?? null;
        $shipping = $woo['shipping'] ?? null;

        $meta = [
            'username' => $woo['username'] ?? null,
            'date_created' => $woo['date_created'] ?? null,
            'date_modified' => $woo['date_modified'] ?? null,
            'role' => $woo['role'] ?? null,
            'is_paying_customer' => $woo['is_paying_customer'] ?? null,
            'orders_count' => $woo['orders_count'] ?? null,
            'total_spent' => $woo['total_spent'] ?? null,
            'billing' => $billing,
            'shipping' => $shipping,
        ];

        return [
            'woo_customer_id' => $wooId,
            'full_name' => $fullName,
            'email' => $email,
            'meta_json' => $this->json($meta),
        ];
    }

    /**
     * Woo Order -> CRM Sale (حداقل ساختار)
     *
     * @param array<string,mixed> $woo
     * @return array<string,mixed>
     */
    public function mapWooOrderToCrmSale(array $woo): array
    {
        $wooId = (int)($woo['id'] ?? 0);

        $status = $this->s($woo['status'] ?? null) ?? 'pending';
        $currency = $this->s($woo['currency'] ?? null) ?? 'IRR';
        $total = $this->decimalStr($woo['total'] ?? null);

        $customerId = (int)($woo['customer_id'] ?? 0);
        $paymentMethod = $this->s($woo['payment_method'] ?? null);
        $paymentTitle = $this->s($woo['payment_method_title'] ?? null);

        $meta = [
            'date_created' => $woo['date_created'] ?? null,
            'date_modified' => $woo['date_modified'] ?? null,
            'date_paid' => $woo['date_paid'] ?? null,
            'payment_method' => $paymentMethod,
            'payment_method_title' => $paymentTitle,
            'billing' => $woo['billing'] ?? null,
            'shipping' => $woo['shipping'] ?? null,
            'line_items' => $woo['line_items'] ?? null,
            'shipping_lines' => $woo['shipping_lines'] ?? null,
            'fee_lines' => $woo['fee_lines'] ?? null,
            'coupon_lines' => $woo['coupon_lines'] ?? null,
            'refunds' => $woo['refunds'] ?? null,
        ];

        return [
            'woo_order_id' => $wooId,
            'status' => $status,
            'currency' => $currency,
            'total' => $total,
            'woo_customer_id' => $customerId > 0 ? $customerId : null,
            'meta_json' => $this->json($meta),
        ];
    }

    // -----------------------------------------------------------------------------
    // CRM -> Woo payloads (برای sync دوطرفه)
    // -----------------------------------------------------------------------------

    /**
     * CRM Product -> Woo Create Payload
     * @param array<string,mixed> $crm
     * @return array<string,mixed>
     */
    public function mapCrmProductToWooCreatePayload(array $crm): array
    {
        $type = $this->s($crm['type'] ?? 'simple') ?? 'simple';
        $name = $this->s($crm['name'] ?? null) ?? 'CRM Product';
        $sku = $this->s($crm['sku'] ?? null);

        $payload = [
            'name' => $name,
            'type' => $type, // simple|variable
        ];

        if ($sku) $payload['sku'] = $sku;

        $desc = $this->s($crm['description'] ?? null);
        $short = $this->s($crm['short_description'] ?? null);
        if ($desc !== null) $payload['description'] = $desc;
        if ($short !== null) $payload['short_description'] = $short;

        // قیمت‌ها
        $regular = $this->decimalStr($crm['regular_price'] ?? null);
        $sale = $this->decimalStr($crm['sale_price'] ?? null);
        $price = $this->decimalStr($crm['price'] ?? null);

        // توصیه: برای ایجاد محصول، regular_price معتبرتر است
        if ($regular !== null) {
            $payload['regular_price'] = $regular;
        } elseif ($price !== null) {
            // اگر regular نبود، price را به regular منتقل کن
            $payload['regular_price'] = $price;
        }

        if ($sale !== null) $payload['sale_price'] = $sale;

        // موجودی
        $manageStock = $this->boolNative($crm['manage_stock'] ?? false);
        $payload['manage_stock'] = $manageStock;
        if ($manageStock) {
            $qty = $this->intOrNull($crm['stock_quantity'] ?? null);
            if ($qty !== null) $payload['stock_quantity'] = $qty;
        }

        // وضعیت موجودی (اختیاری)
        $stockStatus = $this->s($crm['stock_status'] ?? null);
        if ($stockStatus) $payload['stock_status'] = $stockStatus;

        // دسته‌ها (اگر CRM ذخیره کرده)
        $cats = $this->tryDecodeJson($crm['categories_json'] ?? null);
        if (is_array($cats)) {
            // Woo expects: [ ['id'=>123], ... ] or ['name'=>...]
            $payload['categories'] = $this->normalizeWooCategories($cats);
        }

        // تصاویر
        $imgs = $this->tryDecodeJson($crm['images_json'] ?? null);
        if (is_array($imgs)) {
            $payload['images'] = $this->normalizeWooImages($imgs);
        }

        // attributes (برای variable محصول بسیار مهم است)
        $attrs = $this->tryDecodeJson($crm['attributes_json'] ?? null);
        if (is_array($attrs)) {
            $payload['attributes'] = $this->normalizeWooAttributes($attrs);
        }

        // status (publish|draft|private)
        $status = $this->s($crm['status'] ?? null);
        if ($status) $payload['status'] = $status;

        return $payload;
    }

    /**
     * CRM Product -> Woo Update Payload
     * برای update بهتره فقط فیلدهای تغییر یافته ارسال شوند،
     * ولی چون شما سیستم diff ندارید، اینجا همان create payload را می‌دهیم.
     *
     * @param array<string,mixed> $crm
     * @return array<string,mixed>
     */
    public function mapCrmProductToWooUpdatePayload(array $crm): array
    {
        // فعلاً همان create payload
        return $this->mapCrmProductToWooCreatePayload($crm);
    }

    /**
     * CRM Variant -> Woo Variation Payload (Create/Update)
     *
     * @param array<string,mixed> $crmVar
     * @return array<string,mixed>
     */
    public function mapCrmVariantToWooVariationPayload(array $crmVar): array
    {
        $payload = [];

        $sku = $this->s($crmVar['sku'] ?? null);
        if ($sku) $payload['sku'] = $sku;

        $regular = $this->decimalStr($crmVar['regular_price'] ?? null);
        $sale = $this->decimalStr($crmVar['sale_price'] ?? null);
        $price = $this->decimalStr($crmVar['price'] ?? null);

        if ($regular !== null) $payload['regular_price'] = $regular;
        elseif ($price !== null) $payload['regular_price'] = $price;

        if ($sale !== null) $payload['sale_price'] = $sale;

        $manageStock = $this->boolNative($crmVar['manage_stock'] ?? false);
        $payload['manage_stock'] = $manageStock;
        if ($manageStock) {
            $qty = $this->intOrNull($crmVar['stock_quantity'] ?? null);
            if ($qty !== null) $payload['stock_quantity'] = $qty;
        }

        $stockStatus = $this->s($crmVar['stock_status'] ?? null);
        if ($stockStatus) $payload['stock_status'] = $stockStatus;

        // attributes for variation: required
        $attrs = $this->tryDecodeJson($crmVar['attributes_json'] ?? null);
        if (is_array($attrs)) {
            $payload['attributes'] = $this->normalizeWooVariationAttributes($attrs);
        }

        // image (optional)
        $img = $this->tryDecodeJson($crmVar['image_json'] ?? null);
        if (is_array($img)) {
            $payload['image'] = $this->normalizeWooImageSingle($img);
        }

        return $payload;
    }

    // -----------------------------------------------------------------------------
    // Normalizers (sanitize/shape data)
    // -----------------------------------------------------------------------------

    /**
     * @param array<int,mixed> $cats
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWooCategories(array $cats): array
    {
        $out = [];
        foreach ($cats as $c) {
            if (is_array($c)) {
                if (isset($c['id']) && is_numeric($c['id'])) {
                    $out[] = ['id' => (int)$c['id']];
                    continue;
                }
                if (isset($c['name']) && is_string($c['name']) && trim($c['name']) !== '') {
                    $out[] = ['name' => trim($c['name'])];
                    continue;
                }
            }
            if (is_numeric($c)) {
                $out[] = ['id' => (int)$c];
            }
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $imgs
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWooImages(array $imgs): array
    {
        $out = [];
        foreach ($imgs as $img) {
            if (!is_array($img)) continue;

            // Woo image can be {id, src, name, alt, position}
            $item = [];

            if (isset($img['id']) && is_numeric($img['id'])) $item['id'] = (int)$img['id'];
            if (isset($img['src']) && is_string($img['src']) && trim($img['src']) !== '') $item['src'] = trim($img['src']);
            if (isset($img['name']) && is_string($img['name'])) $item['name'] = $img['name'];
            if (isset($img['alt']) && is_string($img['alt'])) $item['alt'] = $img['alt'];
            if (isset($img['position']) && is_numeric($img['position'])) $item['position'] = (int)$img['position'];

            if (!empty($item)) $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $attrs
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWooAttributes(array $attrs): array
    {
        // Woo product attributes shape:
        // [
        //   { id, name, visible, variation, options: [..] }
        // ]
        $out = [];
        foreach ($attrs as $a) {
            if (!is_array($a)) continue;
            $item = [];

            if (isset($a['id']) && is_numeric($a['id'])) $item['id'] = (int)$a['id'];

            $name = $this->s($a['name'] ?? null);
            if ($name) $item['name'] = $name;

            $item['visible'] = isset($a['visible']) ? $this->boolNative($a['visible']) : true;
            $item['variation'] = isset($a['variation']) ? $this->boolNative($a['variation']) : false;

            $options = $a['options'] ?? null;
            if (is_array($options)) {
                $clean = [];
                foreach ($options as $opt) {
                    if (is_string($opt) && trim($opt) !== '') $clean[] = trim($opt);
                }
                $item['options'] = $clean;
            } else {
                $item['options'] = [];
            }

            if (isset($item['name']) || isset($item['id'])) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Variation attributes shape:
     * Woo expects: [ { "name":"Size", "option":"XL" }, ... ]
     *
     * @param array<int,mixed> $attrs
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWooVariationAttributes(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $a) {
            if (!is_array($a)) continue;
            $name = $this->s($a['name'] ?? null);
            $option = $this->s($a['option'] ?? null);

            // sometimes stored as {name, value} or {attribute, value}
            if (!$option && isset($a['value'])) $option = $this->s($a['value']);
            if (!$name && isset($a['attribute'])) $name = $this->s($a['attribute']);

            if ($name && $option) {
                $out[] = ['name' => $name, 'option' => $option];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $img
     * @return array<string,mixed>
     */
    private function normalizeWooImageSingle(array $img): array
    {
        $item = [];
        if (isset($img['id']) && is_numeric($img['id'])) $item['id'] = (int)$img['id'];
        if (isset($img['src']) && is_string($img['src']) && trim($img['src']) !== '') $item['src'] = trim($img['src']);
        if (isset($img['name']) && is_string($img['name'])) $item['name'] = $img['name'];
        if (isset($img['alt']) && is_string($img['alt'])) $item['alt'] = $img['alt'];
        return $item;
    }

    // -----------------------------------------------------------------------------
    // Primitive helpers
    // -----------------------------------------------------------------------------

    private function s(mixed $v): ?string
    {
        if ($v === null) return null;
        if (!is_string($v) && !is_numeric($v)) return null;
        $t = trim((string)$v);
        return $t === '' ? null : $t;
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        return (int)$v;
    }

    private function decimalStr(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_string($v)) $v = trim($v);
        if ($v === '') return null;
        if (!is_numeric($v)) return null;
        return (string)$v;
    }

    private function bool01(mixed $v): int
    {
        return filter_var($v, FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    private function boolNative(mixed $v): bool
    {
        return (bool)filter_var($v, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param mixed $value
     */
    private function json(mixed $value): ?string
    {
        if ($value === null) return null;

        if (is_string($value)) {
            $t = trim($value);
            if ($t === '') return null;

            $decoded = json_decode($t, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }

            // fallback: store as string wrapper
            return json_encode(['value' => $t], JSON_UNESCAPED_UNICODE);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        // for other types
        return json_encode(['value' => $value], JSON_UNESCAPED_UNICODE);
    }

    /**
     * تلاش برای decode json
     * @return mixed
     */
    private function tryDecodeJson(mixed $v)
    {
        if ($v === null) return null;
        if (is_array($v)) return $v;

        if (!is_string($v)) return null;
        $t = trim($v);
        if ($t === '') return null;

        $decoded = json_decode($t, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;
        return $decoded;
    }
}

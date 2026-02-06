<?php
/**
 * File: app/Http/Controllers/Api/ProductsApiController.php
 *
 * CRM V2 - API Controller: ProductsApiController
 * -------------------------------------------------------------------------
 * این کنترلر API برای مدیریت محصولات داخل CRM است + همگام‌سازی با ووکامرس.
 *
 * چرا این فایل مهم است؟
 * - شما گفتی «محصولات باید درون‌ریزی شوند، سپس داخل CRM مثل حسابداری مدیریت شوند»
 * - محصولات ساده و متغیر (variable + variations) باید کامل پشتیبانی شوند
 * - همگام‌سازی دوطرفه: Woo -> CRM و CRM -> Woo
 *
 * -------------------------------------------------------------------------
 * ⚠️ نکته مهم برای پروژه شما:
 * این پروژه فریم‌ورک ندارد، پس این کنترلر خودش:
 * - احراز هویت (Session یا Bearer Token)
 * - Rate-limit سبک (اختیاری)
 * - خروجی JSON استاندارد
 * - اعتبارسنجی داده‌ها
 * - مدیریت جدول‌ها (اگر وجود نداشت crash نکند)
 * را انجام می‌دهد.
 *
 * -------------------------------------------------------------------------
 * Routes پیشنهادی (در app/Bootstrap/routes.php باید اضافه کنید)
 *
 *   ['GET',  '/api/products',                 [ProductsApiController::class, 'index']],
 *   ['GET',  '/api/products/{id}',            [ProductsApiController::class, 'show']],
 *   ['POST', '/api/products',                 [ProductsApiController::class, 'store']],
 *   ['POST', '/api/products/{id}/update',     [ProductsApiController::class, 'update']],
 *   ['POST', '/api/products/{id}/delete',     [ProductsApiController::class, 'delete']],
 *
 *   // Variants (برای محصول متغیر)
 *   ['GET',  '/api/products/{id}/variants',               [ProductsApiController::class, 'variants']],
 *   ['POST', '/api/products/{id}/variants/create',        [ProductsApiController::class, 'createVariant']],
 *   ['POST', '/api/products/{id}/variants/{vid}/update',  [ProductsApiController::class, 'updateVariant']],
 *   ['POST', '/api/products/{id}/variants/{vid}/delete',  [ProductsApiController::class, 'deleteVariant']],
 *
 *   // Woo: Import / Push / Reconcile
 *   ['POST', '/api/woocommerce/products/import',          [ProductsApiController::class, 'wooImportProducts']],
 *   ['POST', '/api/woocommerce/products/push/{id}',       [ProductsApiController::class, 'wooPushProduct']],
 *   ['POST', '/api/woocommerce/products/reconcile',       [ProductsApiController::class, 'wooReconcileProducts']],
 *
 * -------------------------------------------------------------------------
 * Config پیشنهادی (private/config.php)
 *
 * 'features' => [
 *   'enable_woocommerce' => true,
 * ],
 * 'security' => [
 *   'api' => [
 *     'require_auth' => true,
 *     'rate_limit' => [
 *       'enabled' => true,
 *       'window_sec' => 60,
 *       'max_requests' => 60,
 *       'key' => 'ip', // ip | token | ip+token
 *     ],
 *   ],
 * ],
 * 'woocommerce' => [
 *   'enabled' => true,
 *   'base_url' => 'https://example.com',
 *   'consumer_key' => 'ck_...',
 *   'consumer_secret' => 'cs_...',
 *   'api_version' => 'wc/v3',
 *   'timeout_sec' => 30,
 *   'verify_ssl' => true,
 *   'webhook_secret' => 'optional',
 * ]
 *
 * -------------------------------------------------------------------------
 * DB Tables (پیشنهادی)
 *
 * 1) products:
 *   id BIGINT PK AI
 *   type VARCHAR(20) NOT NULL DEFAULT 'simple'  (simple|variable)
 *   sku VARCHAR(100) NULL UNIQUE
 *   name VARCHAR(255) NOT NULL
 *   description LONGTEXT NULL
 *   short_description TEXT NULL
 *   status VARCHAR(20) NOT NULL DEFAULT 'publish' (publish|draft|private|trash)
 *   price DECIMAL(18,4) NULL
 *   regular_price DECIMAL(18,4) NULL
 *   sale_price DECIMAL(18,4) NULL
 *   currency VARCHAR(10) NULL DEFAULT 'IRR'
 *   stock_status VARCHAR(20) NULL (instock|outofstock|onbackorder)
 *   stock_quantity INT NULL
 *   manage_stock TINYINT(1) NOT NULL DEFAULT 0
 *   categories_json JSON NULL
 *   images_json JSON NULL
 *   attributes_json JSON NULL
 *   meta_json JSON NULL
 *   woo_product_id BIGINT NULL
 *   woo_synced_at DATETIME NULL
 *   created_at DATETIME NOT NULL
 *   updated_at DATETIME NOT NULL
 *
 * 2) product_variants:
 *   id BIGINT PK AI
 *   product_id BIGINT NOT NULL
 *   sku VARCHAR(100) NULL
 *   name VARCHAR(255) NULL
 *   price DECIMAL(18,4) NULL
 *   regular_price DECIMAL(18,4) NULL
 *   sale_price DECIMAL(18,4) NULL
 *   stock_status VARCHAR(20) NULL
 *   stock_quantity INT NULL
 *   manage_stock TINYINT(1) NOT NULL DEFAULT 0
 *   attributes_json JSON NULL  (variation attributes)
 *   image_json JSON NULL
 *   meta_json JSON NULL
 *   woo_variation_id BIGINT NULL
 *   woo_synced_at DATETIME NULL
 *   created_at DATETIME NOT NULL
 *   updated_at DATETIME NOT NULL
 *
 * 3) api_tokens (برای Bearer Auth)
 * 4) api_rate_limits (برای Rate limit)
 *
 * -------------------------------------------------------------------------
 * نکته: این کنترلر طوری نوشته شده که اگر جدول/ستون‌ها هنوز کامل نیستند،
 *       crash نکند و خطای دقیق به شما بدهد تا در migration درستش کنید.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Database\Connection;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class ProductsApiController
{
    /** @var array<string,mixed> */
    private array $config;

    private PDO $pdo;
    private Logger $logger;

    public function __construct(array $config)
    {
        $this->config = $config;

        $logDir = (defined('CRM_PRIVATE_DIR') ? CRM_PRIVATE_DIR : (dirname(__DIR__, 5) . '/private')) . '/storage/logs';
        $isDev = strtolower((string)($config['app']['env'] ?? 'production')) !== 'production';
        $this->logger = new Logger($logDir, $isDev);

        $conn = new Connection($config);
        $this->pdo = $conn->pdo();
    }

    // =========================================================================
    // API: Products CRUD
    // =========================================================================

    /**
     * GET /api/products
     * Query params:
     *  - q (search in name/sku)
     *  - type (simple|variable)
     *  - status
     *  - page, per_page
     *  - sort (id|name|updated_at)
     *  - dir  (asc|desc)
     */
    public function index(): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        if (!$this->tableExists('products')) {
            $this->json([
                'ok' => false,
                'error' => 'Table products does not exist. Run migrations.',
                'hint' => 'Go to /settings/db/migrate or create products table.',
            ], 500);
            return;
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $type = trim((string)($_GET['type'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));

        $page = $this->clampInt($_GET['page'] ?? 1, 1, 1_000_000);
        $perPage = $this->clampInt($_GET['per_page'] ?? 20, 1, 200);

        $sort = strtolower(trim((string)($_GET['sort'] ?? 'id')));
        $dir = strtolower(trim((string)($_GET['dir'] ?? 'desc')));
        if (!in_array($sort, ['id', 'name', 'updated_at', 'created_at'], true)) $sort = 'id';
        if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'desc';

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = "(name LIKE :q OR sku LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if ($type !== '') {
            $where[] = "type = :type";
            $params[':type'] = $type;
        }
        if ($status !== '') {
            $where[] = "status = :status";
            $params[':status'] = $status;
        }

        $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
        $offset = ($page - 1) * $perPage;

        // total count
        $total = 0;
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM products {$whereSql}");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)($row['c'] ?? 0);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'DB query failed.', 'details' => $e->getMessage()], 500);
            return;
        }

        // list
        try {
            $sql = "SELECT * FROM products {$whereSql} ORDER BY {$sort} {$dir} LIMIT :lim OFFSET :off";
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // decode json fields
            foreach ($items as &$it) {
                $it = $this->normalizeProductRow($it);
            }

            $this->logger->info('API_PRODUCTS_INDEX', [
                'user_id' => $auth['user_id'] ?? null,
                'q' => $q,
                'count' => count($items),
            ]);

            $this->json([
                'ok' => true,
                'data' => $items,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / max(1, $perPage)),
                ],
            ], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'DB query failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/products/{id}
     */
    public function show(int $id): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        $product = $this->findProductById($id);
        if (!$product) {
            $this->json(['ok' => false, 'error' => 'Product not found.'], 404);
            return;
        }

        // include variants if variable
        $variants = [];
        if (($product['type'] ?? 'simple') === 'variable') {
            $variants = $this->listVariants($id);
        }

        $this->logger->info('API_PRODUCTS_SHOW', [
            'user_id' => $auth['user_id'] ?? null,
            'product_id' => $id,
        ]);

        $this->json([
            'ok' => true,
            'data' => [
                'product' => $product,
                'variants' => $variants,
            ],
        ], 200);
    }

    /**
     * POST /api/products
     * Body JSON:
     * {
     *   "type":"simple|variable",
     *   "sku":"...",
     *   "name":"...",
     *   "description":"...",
     *   "short_description":"...",
     *   "status":"publish|draft|...",
     *   "price":123,
     *   "regular_price":123,
     *   "sale_price":100,
     *   "currency":"IRR",
     *   "manage_stock":true,
     *   "stock_quantity":10,
     *   "stock_status":"instock",
     *   "categories":[...],
     *   "images":[...],
     *   "attributes":[...],
     *   "meta":{...}
     * }
     */
    public function store(): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        if (!$this->tableExists('products')) {
            $this->json(['ok' => false, 'error' => 'Table products does not exist. Run migrations.'], 500);
            return;
        }

        $body = $this->readJsonBody();

        $type = $this->normalizeEnum((string)($body['type'] ?? 'simple'), ['simple','variable'], 'simple');
        $name = trim((string)($body['name'] ?? ''));
        $sku = $this->nullableTrim($body['sku'] ?? null);
        $status = $this->normalizeEnum((string)($body['status'] ?? 'publish'), ['publish','draft','private','trash'], 'publish');

        if ($name === '') {
            $this->json(['ok' => false, 'error' => 'name is required.'], 422);
            return;
        }

        $desc = $this->nullableTrim($body['description'] ?? null);
        $shortDesc = $this->nullableTrim($body['short_description'] ?? null);

        $price = $this->nullableDecimal($body['price'] ?? null);
        $regular = $this->nullableDecimal($body['regular_price'] ?? null);
        $sale = $this->nullableDecimal($body['sale_price'] ?? null);

        $currency = $this->nullableTrim($body['currency'] ?? null) ?? 'IRR';

        $manageStock = $this->bool01($body['manage_stock'] ?? false);
        $stockQty = $this->nullableInt($body['stock_quantity'] ?? null);
        $stockStatus = $this->nullableTrim($body['stock_status'] ?? null);

        $categoriesJson = $this->nullableJson($body['categories'] ?? null);
        $imagesJson = $this->nullableJson($body['images'] ?? null);
        $attributesJson = $this->nullableJson($body['attributes'] ?? null);
        $metaJson = $this->nullableJson($body['meta'] ?? null);

        // If price not provided, attempt derive from regular/sale
        if ($price === null) {
            if ($sale !== null) $price = $sale;
            elseif ($regular !== null) $price = $regular;
        }

        try {
            $sql = "INSERT INTO products
                (type, sku, name, description, short_description, status, price, regular_price, sale_price, currency,
                 stock_status, stock_quantity, manage_stock,
                 categories_json, images_json, attributes_json, meta_json,
                 created_at, updated_at)
                VALUES
                (:type, :sku, :name, :description, :short_description, :status, :price, :regular_price, :sale_price, :currency,
                 :stock_status, :stock_quantity, :manage_stock,
                 :categories_json, :images_json, :attributes_json, :meta_json,
                 NOW(), NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':type' => $type,
                ':sku' => $sku,
                ':name' => $name,
                ':description' => $desc,
                ':short_description' => $shortDesc,
                ':status' => $status,
                ':price' => $price,
                ':regular_price' => $regular,
                ':sale_price' => $sale,
                ':currency' => $currency,
                ':stock_status' => $stockStatus,
                ':stock_quantity' => $stockQty,
                ':manage_stock' => $manageStock,
                ':categories_json' => $categoriesJson,
                ':images_json' => $imagesJson,
                ':attributes_json' => $attributesJson,
                ':meta_json' => $metaJson,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $created = $this->findProductById($newId);

            $this->logger->info('API_PRODUCTS_CREATE', [
                'user_id' => $auth['user_id'] ?? null,
                'product_id' => $newId,
                'type' => $type,
                'sku' => $sku,
            ]);

            $this->json(['ok' => true, 'data' => $created], 201);
        } catch (Throwable $e) {
            $this->json([
                'ok' => false,
                'error' => 'Create failed.',
                'details' => $e->getMessage(),
                'hint' => 'If error mentions unknown column, update migrations for products table.',
            ], 500);
        }
    }

    /**
     * POST /api/products/{id}/update
     * Body JSON: same as store, but partial allowed
     */
    public function update(int $id): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        $product = $this->findProductById($id);
        if (!$product) {
            $this->json(['ok' => false, 'error' => 'Product not found.'], 404);
            return;
        }

        $body = $this->readJsonBody();

        // Partial update: keep old values if not present
        $type = array_key_exists('type', $body)
            ? $this->normalizeEnum((string)$body['type'], ['simple','variable'], (string)($product['type'] ?? 'simple'))
            : (string)($product['type'] ?? 'simple');

        $name = array_key_exists('name', $body) ? trim((string)$body['name']) : (string)($product['name'] ?? '');
        if ($name === '') {
            $this->json(['ok' => false, 'error' => 'name cannot be empty.'], 422);
            return;
        }

        $sku = array_key_exists('sku', $body) ? $this->nullableTrim($body['sku']) : ($product['sku'] ?? null);
        $status = array_key_exists('status', $body)
            ? $this->normalizeEnum((string)$body['status'], ['publish','draft','private','trash'], (string)($product['status'] ?? 'publish'))
            : (string)($product['status'] ?? 'publish');

        $desc = array_key_exists('description', $body) ? $this->nullableTrim($body['description']) : ($product['description'] ?? null);
        $shortDesc = array_key_exists('short_description', $body) ? $this->nullableTrim($body['short_description']) : ($product['short_description'] ?? null);

        $price = array_key_exists('price', $body) ? $this->nullableDecimal($body['price']) : ($product['price'] ?? null);
        $regular = array_key_exists('regular_price', $body) ? $this->nullableDecimal($body['regular_price']) : ($product['regular_price'] ?? null);
        $sale = array_key_exists('sale_price', $body) ? $this->nullableDecimal($body['sale_price']) : ($product['sale_price'] ?? null);

        $currency = array_key_exists('currency', $body) ? ($this->nullableTrim($body['currency']) ?? 'IRR') : (string)($product['currency'] ?? 'IRR');

        $manageStock = array_key_exists('manage_stock', $body) ? $this->bool01($body['manage_stock']) : (int)($product['manage_stock'] ?? 0);
        $stockQty = array_key_exists('stock_quantity', $body) ? $this->nullableInt($body['stock_quantity']) : ($product['stock_quantity'] ?? null);
        $stockStatus = array_key_exists('stock_status', $body) ? $this->nullableTrim($body['stock_status']) : ($product['stock_status'] ?? null);

        $categoriesJson = array_key_exists('categories', $body) ? $this->nullableJson($body['categories']) : ($product['categories_json'] ?? null);
        $imagesJson = array_key_exists('images', $body) ? $this->nullableJson($body['images']) : ($product['images_json'] ?? null);
        $attributesJson = array_key_exists('attributes', $body) ? $this->nullableJson($body['attributes']) : ($product['attributes_json'] ?? null);
        $metaJson = array_key_exists('meta', $body) ? $this->nullableJson($body['meta']) : ($product['meta_json'] ?? null);

        try {
            $sql = "UPDATE products SET
                type = :type,
                sku = :sku,
                name = :name,
                description = :description,
                short_description = :short_description,
                status = :status,
                price = :price,
                regular_price = :regular_price,
                sale_price = :sale_price,
                currency = :currency,
                stock_status = :stock_status,
                stock_quantity = :stock_quantity,
                manage_stock = :manage_stock,
                categories_json = :categories_json,
                images_json = :images_json,
                attributes_json = :attributes_json,
                meta_json = :meta_json,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':type' => $type,
                ':sku' => $sku,
                ':name' => $name,
                ':description' => $desc,
                ':short_description' => $shortDesc,
                ':status' => $status,
                ':price' => $price,
                ':regular_price' => $regular,
                ':sale_price' => $sale,
                ':currency' => $currency,
                ':stock_status' => $stockStatus,
                ':stock_quantity' => $stockQty,
                ':manage_stock' => $manageStock,
                ':categories_json' => $categoriesJson,
                ':images_json' => $imagesJson,
                ':attributes_json' => $attributesJson,
                ':meta_json' => $metaJson,
                ':id' => $id,
            ]);

            $updated = $this->findProductById($id);

            $this->logger->info('API_PRODUCTS_UPDATE', [
                'user_id' => $auth['user_id'] ?? null,
                'product_id' => $id,
            ]);

            $this->json(['ok' => true, 'data' => $updated], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Update failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/products/{id}/delete
     * Soft delete (status=trash) if status column exists; else hard delete.
     */
    public function delete(int $id): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        $product = $this->findProductById($id);
        if (!$product) {
            $this->json(['ok' => false, 'error' => 'Product not found.'], 404);
            return;
        }

        try {
            if ($this->columnExists('products', 'status')) {
                $stmt = $this->pdo->prepare("UPDATE products SET status='trash', updated_at=NOW() WHERE id=:id LIMIT 1");
                $stmt->execute([':id' => $id]);
            } else {
                $stmt = $this->pdo->prepare("DELETE FROM products WHERE id=:id LIMIT 1");
                $stmt->execute([':id' => $id]);
            }

            // delete variants too (best-effort)
            if ($this->tableExists('product_variants')) {
                $stmt2 = $this->pdo->prepare("DELETE FROM product_variants WHERE product_id=:id");
                $stmt2->execute([':id' => $id]);
            }

            $this->logger->info('API_PRODUCTS_DELETE', [
                'user_id' => $auth['user_id'] ?? null,
                'product_id' => $id,
            ]);

            $this->json(['ok' => true, 'deleted' => true, 'product_id' => $id], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Delete failed.', 'details' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // API: Variants
    // =========================================================================

    /**
     * GET /api/products/{id}/variants
     */
    public function variants(int $id): void
    {
        $this->apiGuard();
        $this->ensureJsonHeaders();

        if (!$this->tableExists('product_variants')) {
            $this->json(['ok' => false, 'error' => 'Table product_variants does not exist. Run migrations.'], 500);
            return;
        }

        $product = $this->findProductById($id);
        if (!$product) {
            $this->json(['ok' => false, 'error' => 'Product not found.'], 404);
            return;
        }

        $items = $this->listVariants($id);
        $this->json(['ok' => true, 'data' => $items], 200);
    }

    /**
     * POST /api/products/{id}/variants/create
     * Body:
     * {
     *   "sku":"...",
     *   "name":"...",
     *   "price":123,
     *   "regular_price":123,
     *   "sale_price":100,
     *   "manage_stock":true,
     *   "stock_quantity":10,
     *   "stock_status":"instock",
     *   "attributes": [{"name":"Size","option":"XL"}, ...],
     *   "image": {...},
     *   "meta": {...}
     * }
     */
    public function createVariant(int $id): void
    {
        $this->apiGuard();
        $this->ensureJsonHeaders();

        if (!$this->tableExists('product_variants')) {
            $this->json(['ok' => false, 'error' => 'Table product_variants does not exist. Run migrations.'], 500);
            return;
        }

        $product = $this->findProductById($id);
        if (!$product) {
            $this->json(['ok' => false, 'error' => 'Product not found.'], 404);
            return;
        }
        if (($product['type'] ?? 'simple') !== 'variable') {
            $this->json(['ok' => false, 'error' => 'Variants allowed only for variable products.'], 422);
            return;
        }

        $body = $this->readJsonBody();

        $sku = $this->nullableTrim($body['sku'] ?? null);
        $name = $this->nullableTrim($body['name'] ?? null);
        $price = $this->nullableDecimal($body['price'] ?? null);
        $regular = $this->nullableDecimal($body['regular_price'] ?? null);
        $sale = $this->nullableDecimal($body['sale_price'] ?? null);

        $manageStock = $this->bool01($body['manage_stock'] ?? false);
        $stockQty = $this->nullableInt($body['stock_quantity'] ?? null);
        $stockStatus = $this->nullableTrim($body['stock_status'] ?? null);

        $attributesJson = $this->nullableJson($body['attributes'] ?? null);
        $imageJson = $this->nullableJson($body['image'] ?? null);
        $metaJson = $this->nullableJson($body['meta'] ?? null);

        // auto price
        if ($price === null) {
            if ($sale !== null) $price = $sale;
            elseif ($regular !== null) $price = $regular;
        }

        try {
            $sql = "INSERT INTO product_variants
                (product_id, sku, name, price, regular_price, sale_price,
                 stock_status, stock_quantity, manage_stock,
                 attributes_json, image_json, meta_json,
                 created_at, updated_at)
                VALUES
                (:pid, :sku, :name, :price, :regular, :sale,
                 :stock_status, :stock_qty, :manage_stock,
                 :attrs, :img, :meta,
                 NOW(), NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':pid' => $id,
                ':sku' => $sku,
                ':name' => $name,
                ':price' => $price,
                ':regular' => $regular,
                ':sale' => $sale,
                ':stock_status' => $stockStatus,
                ':stock_qty' => $stockQty,
                ':manage_stock' => $manageStock,
                ':attrs' => $attributesJson,
                ':img' => $imageJson,
                ':meta' => $metaJson,
            ]);

            $vid = (int)$this->pdo->lastInsertId();
            $created = $this->findVariantById($vid);

            $this->json(['ok' => true, 'data' => $created], 201);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Create variant failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/products/{id}/variants/{vid}/update
     */
    public function updateVariant(int $id, int $vid): void
    {
        $this->apiGuard();
        $this->ensureJsonHeaders();

        $variant = $this->findVariantById($vid);
        if (!$variant || (int)($variant['product_id'] ?? 0) !== $id) {
            $this->json(['ok' => false, 'error' => 'Variant not found for this product.'], 404);
            return;
        }

        $body = $this->readJsonBody();

        $sku = array_key_exists('sku', $body) ? $this->nullableTrim($body['sku']) : ($variant['sku'] ?? null);
        $name = array_key_exists('name', $body) ? $this->nullableTrim($body['name']) : ($variant['name'] ?? null);

        $price = array_key_exists('price', $body) ? $this->nullableDecimal($body['price']) : ($variant['price'] ?? null);
        $regular = array_key_exists('regular_price', $body) ? $this->nullableDecimal($body['regular_price']) : ($variant['regular_price'] ?? null);
        $sale = array_key_exists('sale_price', $body) ? $this->nullableDecimal($body['sale_price']) : ($variant['sale_price'] ?? null);

        $manageStock = array_key_exists('manage_stock', $body) ? $this->bool01($body['manage_stock']) : (int)($variant['manage_stock'] ?? 0);
        $stockQty = array_key_exists('stock_quantity', $body) ? $this->nullableInt($body['stock_quantity']) : ($variant['stock_quantity'] ?? null);
        $stockStatus = array_key_exists('stock_status', $body) ? $this->nullableTrim($body['stock_status']) : ($variant['stock_status'] ?? null);

        $attrs = array_key_exists('attributes', $body) ? $this->nullableJson($body['attributes']) : ($variant['attributes_json'] ?? null);
        $img = array_key_exists('image', $body) ? $this->nullableJson($body['image']) : ($variant['image_json'] ?? null);
        $meta = array_key_exists('meta', $body) ? $this->nullableJson($body['meta']) : ($variant['meta_json'] ?? null);

        if ($price === null) {
            if ($sale !== null) $price = $sale;
            elseif ($regular !== null) $price = $regular;
        }

        try {
            $sql = "UPDATE product_variants SET
                sku = :sku,
                name = :name,
                price = :price,
                regular_price = :regular,
                sale_price = :sale,
                stock_status = :stock_status,
                stock_quantity = :stock_qty,
                manage_stock = :manage_stock,
                attributes_json = :attrs,
                image_json = :img,
                meta_json = :meta,
                updated_at = NOW()
            WHERE id = :id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':sku' => $sku,
                ':name' => $name,
                ':price' => $price,
                ':regular' => $regular,
                ':sale' => $sale,
                ':stock_status' => $stockStatus,
                ':stock_qty' => $stockQty,
                ':manage_stock' => $manageStock,
                ':attrs' => $attrs,
                ':img' => $img,
                ':meta' => $meta,
                ':id' => $vid,
            ]);

            $updated = $this->findVariantById($vid);
            $this->json(['ok' => true, 'data' => $updated], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Update variant failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/products/{id}/variants/{vid}/delete
     */
    public function deleteVariant(int $id, int $vid): void
    {
        $this->apiGuard();
        $this->ensureJsonHeaders();

        $variant = $this->findVariantById($vid);
        if (!$variant || (int)($variant['product_id'] ?? 0) !== $id) {
            $this->json(['ok' => false, 'error' => 'Variant not found for this product.'], 404);
            return;
        }

        try {
            $stmt = $this->pdo->prepare("DELETE FROM product_variants WHERE id=:id LIMIT 1");
            $stmt->execute([':id' => $vid]);
            $this->json(['ok' => true, 'deleted' => true, 'variant_id' => $vid], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Delete variant failed.', 'details' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // WooCommerce: Import / Push / Reconcile
    // =========================================================================

    /**
     * POST /api/woocommerce/products/import
     * Body:
     * {
     *   "per_page": 50,
     *   "page": 1,
     *   "status": "publish",
     *   "type": "simple|variable|any",
     *   "since": "2025-01-01T00:00:00",
     *   "full_sync": true
     * }
     *
     * Behavior:
     * - محصولات را از WooCommerce می‌گیرد و داخل CRM upsert می‌کند.
     * - اگر محصول variable باشد، variations را هم می‌گیرد و جدول product_variants را sync می‌کند.
     */
    public function wooImportProducts(): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        if (!$this->wooEnabled()) {
            $this->json(['ok' => false, 'error' => 'WooCommerce integration disabled.'], 503);
            return;
        }

        if (!$this->tableExists('products')) {
            $this->json(['ok' => false, 'error' => 'Table products does not exist. Run migrations first.'], 500);
            return;
        }

        $body = $this->readJsonBody();

        $perPage = $this->clampInt($body['per_page'] ?? 50, 1, 100);
        $page = $this->clampInt($body['page'] ?? 1, 1, 1_000_000);
        $status = $this->nullableTrim($body['status'] ?? null);
        $type = $this->nullableTrim($body['type'] ?? null);
        $since = $this->nullableTrim($body['since'] ?? null);
        $fullSync = (bool)($body['full_sync'] ?? false);

        try {
            $params = [
                'per_page' => $perPage,
                'page' => $page,
            ];
            if ($status) $params['status'] = $status;
            if ($type && $type !== 'any') $params['type'] = $type;
            if ($since) $params['after'] = $since; // Woo uses 'after' for modified date in some contexts

            // Fetch products page
            $products = $this->wooRequest('GET', '/products', $params);

            if (!is_array($products)) {
                throw new RuntimeException('Invalid Woo response: products is not array.');
            }

            $imported = 0;
            $updated = 0;
            $variantsImported = 0;

            foreach ($products as $wp) {
                if (!is_array($wp)) continue;

                $res = $this->upsertProductFromWoo($wp);
                if ($res === 'insert') $imported++;
                if ($res === 'update') $updated++;

                $wooType = (string)($wp['type'] ?? 'simple');
                $wooId = (int)($wp['id'] ?? 0);

                // Variations for variable products
                if ($wooType === 'variable' && $wooId > 0) {
                    if ($this->tableExists('product_variants')) {
                        $variantsImported += $this->importWooVariationsForProduct($wooId);
                    }
                }
            }

            // full sync cleanup (optional)
            if ($fullSync) {
                // در sync کامل می‌تونیم محصولات CRM که در Woo حذف شدند را "trash" کنیم
                // ولی چون ریسک داره، فقط اگر شما دوست داشتی فعال کن:
                // $this->markMissingWooProductsAsTrashed();
            }

            $this->logger->info('API_WOO_IMPORT_PRODUCTS', [
                'user_id' => $auth['user_id'] ?? null,
                'page' => $page,
                'per_page' => $perPage,
                'imported' => $imported,
                'updated' => $updated,
                'variants' => $variantsImported,
            ]);

            $this->json([
                'ok' => true,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'count' => count($products),
                ],
                'result' => [
                    'products_inserted' => $imported,
                    'products_updated' => $updated,
                    'variants_upserted' => $variantsImported,
                ],
            ], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Woo import failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/woocommerce/products/push/{id}
     * این متد یک محصول CRM را به WooCommerce push می‌کند (create/update).
     */
    public function wooPushProduct(int $id): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        if (!$this->wooEnabled()) {
            $this->json(['ok' => false, 'error' => 'WooCommerce integration disabled.'], 503);
            return;
        }

        $product = $this->findProductById($id);
        if (!$product) {
            $this->json(['ok' => false, 'error' => 'Product not found.'], 404);
            return;
        }

        try {
            $payload = $this->mapCrmProductToWooPayload($product);

            $wooId = (int)($product['woo_product_id'] ?? 0);
            if ($wooId > 0) {
                $resp = $this->wooRequest('PUT', "/products/{$wooId}", [], $payload);
            } else {
                $resp = $this->wooRequest('POST', "/products", [], $payload);
                if (is_array($resp) && isset($resp['id'])) {
                    $wooId = (int)$resp['id'];
                    $this->setCrmWooProductId($id, $wooId);
                }
            }

            // If variable: push variants too (best-effort)
            $variantResults = [];
            if (($product['type'] ?? 'simple') === 'variable' && $wooId > 0 && $this->tableExists('product_variants')) {
                $variantResults = $this->pushCrmVariantsToWoo($id, $wooId);
            }

            $this->touchCrmWooSyncedAt($id);

            $this->logger->info('API_WOO_PUSH_PRODUCT', [
                'user_id' => $auth['user_id'] ?? null,
                'product_id' => $id,
                'woo_product_id' => $wooId,
            ]);

            $this->json([
                'ok' => true,
                'woo_product_id' => $wooId,
                'woo_response' => $resp,
                'variants' => $variantResults,
            ], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Woo push failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/woocommerce/products/reconcile
     * هدف: reconcile اختلافات CRM و Woo برای محصولات.
     * body:
     * {
     *   "strategy":"woo_wins|crm_wins|merge",
     *   "limit":100
     * }
     */
    public function wooReconcileProducts(): void
    {
        $auth = $this->apiGuard();
        $this->ensureJsonHeaders();

        if (!$this->wooEnabled()) {
            $this->json(['ok' => false, 'error' => 'WooCommerce integration disabled.'], 503);
            return;
        }

        $body = $this->readJsonBody();
        $strategy = strtolower(trim((string)($body['strategy'] ?? 'merge')));
        if (!in_array($strategy, ['woo_wins','crm_wins','merge'], true)) $strategy = 'merge';
        $limit = $this->clampInt($body['limit'] ?? 100, 1, 1000);

        if (!$this->tableExists('products')) {
            $this->json(['ok' => false, 'error' => 'Table products does not exist.'], 500);
            return;
        }

        // این بخش را "امن" و "غیرمخرب" می‌نویسیم:
        // - فقط محصولات دارای woo_product_id را بررسی می‌کنیم
        // - اختلافات را گزارش می‌کنیم
        // - اگر strategy=woo_wins یا crm_wins باشد، آپدیت انجام می‌دهیم (best-effort)
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM products WHERE woo_product_id IS NOT NULL ORDER BY id DESC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $crmProducts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $report = [];
            $fixed = 0;

            foreach ($crmProducts as $row) {
                $row = $this->normalizeProductRow($row);
                $wooId = (int)($row['woo_product_id'] ?? 0);
                if ($wooId <= 0) continue;

                $woo = $this->wooRequest('GET', "/products/{$wooId}", []);
                if (!is_array($woo)) continue;

                $diff = $this->diffCrmWooProduct($row, $woo);

                $item = [
                    'crm_id' => (int)$row['id'],
                    'woo_id' => $wooId,
                    'diff' => $diff,
                ];

                if (!empty($diff) && $strategy !== 'merge') {
                    if ($strategy === 'woo_wins') {
                        $this->upsertProductFromWoo($woo); // update CRM based on Woo
                        $fixed++;
                        $item['action'] = 'crm_updated_from_woo';
                    } elseif ($strategy === 'crm_wins') {
                        $payload = $this->mapCrmProductToWooPayload($row);
                        $this->wooRequest('PUT', "/products/{$wooId}", [], $payload);
                        $fixed++;
                        $item['action'] = 'woo_updated_from_crm';
                    }
                }

                $report[] = $item;
            }

            $this->logger->info('API_WOO_RECONCILE_PRODUCTS', [
                'user_id' => $auth['user_id'] ?? null,
                'strategy' => $strategy,
                'checked' => count($crmProducts),
                'fixed' => $fixed,
            ]);

            $this->json([
                'ok' => true,
                'strategy' => $strategy,
                'checked' => count($crmProducts),
                'fixed' => $fixed,
                'report' => $report,
            ], 200);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Reconcile failed.', 'details' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Internal: Auth + Rate limit
    // =========================================================================

    /**
     * Auth strategy:
     * - اگر session فعال باشد و user_id داشته باشد -> OK
     * - اگر Authorization: Bearer داشته باشد -> api_tokens validate -> OK
     * - وگرنه 401
     *
     * @return array{user_id:?int, ip:string, user_agent:string, auth_mode:string}
     */
    private function apiGuard(): array
    {
        $this->ensureJsonHeaders();
        $this->rateLimitOrFail();

        $requireAuth = (bool)($this->config['security']['api']['require_auth'] ?? true);

        // If auth not required, still return client info
        if (!$requireAuth) {
            return [
                'user_id' => null,
                'ip' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
                'auth_mode' => 'none',
            ];
        }

        // Session auth
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            return [
                'user_id' => (int)$_SESSION['user_id'],
                'ip' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
                'auth_mode' => 'session',
            ];
        }

        // Bearer token auth
        $token = $this->readBearerToken();
        if ($token) {
            $uid = $this->validateApiToken($token);
            if ($uid) {
                return [
                    'user_id' => $uid,
                    'ip' => $this->clientIp(),
                    'user_agent' => $this->userAgent(),
                    'auth_mode' => 'token',
                ];
            }
        }

        $this->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        exit;
    }

    private function rateLimitOrFail(): void
    {
        $rlCfg = $this->config['security']['api']['rate_limit'] ?? [];
        $enabled = (bool)($rlCfg['enabled'] ?? true);
        if (!$enabled) return;

        $windowSec = (int)($rlCfg['window_sec'] ?? 60);
        $maxReq = (int)($rlCfg['max_requests'] ?? 60);
        $keyMode = (string)($rlCfg['key'] ?? 'ip'); // ip|token|ip+token

        $ip = $this->clientIp();
        $token = $this->readBearerToken();
        $tokenHashShort = $token ? substr(hash('sha256', $token), 0, 16) : 'none';

        $key = match ($keyMode) {
            'token' => "token:{$tokenHashShort}",
            'ip+token' => "ip:{$ip}|token:{$tokenHashShort}",
            default => "ip:{$ip}",
        };

        $now = time();
        $bucket = (int)floor($now / max(1, $windowSec));
        $rlKey = 'products_api:' . $key;

        $this->ensureRateLimitTable();

        $sql = "INSERT INTO api_rate_limits (rl_key, window_start, counter, updated_at)
                VALUES (:k, :ws, 1, NOW())
                ON DUPLICATE KEY UPDATE counter = counter + 1, updated_at = NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':k' => $rlKey, ':ws' => $bucket]);

        $stmt2 = $this->pdo->prepare("SELECT counter FROM api_rate_limits WHERE rl_key=:k AND window_start=:ws LIMIT 1");
        $stmt2->execute([':k' => $rlKey, ':ws' => $bucket]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['counter'] ?? 1);

        if ($count > $maxReq) {
            $retryAfter = $windowSec - ($now % $windowSec);
            header('Retry-After: ' . $retryAfter);
            $this->json(['ok' => false, 'error' => 'Rate limit exceeded.', 'retry_after_sec' => $retryAfter], 429);
            exit;
        }
    }

    private function ensureRateLimitTable(): void
    {
        static $done = false;
        if ($done) return;

        try {
            $this->pdo->query("SELECT 1 FROM api_rate_limits LIMIT 1");
            $done = true;
            return;
        } catch (Throwable $e) {
            // create below
        }

        $sql = "
CREATE TABLE IF NOT EXISTS api_rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rl_key VARCHAR(190) NOT NULL,
  window_start INT NOT NULL,
  counter INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_rl (rl_key, window_start),
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        try { $this->pdo->exec($sql); } catch (Throwable $e) {}
        $done = true;
    }

    private function readBearerToken(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!$h || !is_string($h)) return null;
        if (stripos($h, 'Bearer ') !== 0) return null;
        $t = trim(substr($h, 7));
        return $t !== '' ? $t : null;
    }

    private function validateApiToken(string $plainToken): ?int
    {
        $this->ensureApiTokensTable();
        $hash = hash('sha256', $plainToken);

        try {
            $stmt = $this->pdo->prepare("SELECT id,user_id,revoked_at,expires_at FROM api_tokens WHERE token_hash=:h LIMIT 1");
            $stmt->execute([':h' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            if (!empty($row['revoked_at'])) return null;
            if (!empty($row['expires_at'])) {
                $exp = strtotime((string)$row['expires_at']);
                if ($exp !== false && $exp < time()) return null;
            }

            $uid = (int)($row['user_id'] ?? 0);
            if ($uid <= 0) return null;

            // update last_used_at (best-effort)
            try {
                $stmt2 = $this->pdo->prepare("UPDATE api_tokens SET last_used_at=NOW() WHERE id=:id");
                $stmt2->execute([':id' => (int)$row['id']]);
            } catch (Throwable $e) {}

            return $uid;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function ensureApiTokensTable(): void
    {
        static $done = false;
        if ($done) return;

        try {
            $this->pdo->query("SELECT 1 FROM api_tokens LIMIT 1");
            $done = true;
            return;
        } catch (Throwable $e) {
            // create
        }

        $sql = "
CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  name VARCHAR(120) NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_token_hash (token_hash),
  KEY idx_user (user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        try { $this->pdo->exec($sql); } catch (Throwable $e) {}
        $done = true;
    }

    // =========================================================================
    // Internal: DB helpers
    // =========================================================================

    /**
     * @return array<string,mixed>|null
     */
    private function findProductById(int $id): ?array
    {
        if (!$this->tableExists('products')) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id=:id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            return $this->normalizeProductRow($row);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeProductRow(array $row): array
    {
        // decode json columns if exist
        foreach (['categories_json','images_json','attributes_json','meta_json'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $this->tryJsonDecode($row[$k]);
            }
        }
        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listVariants(int $productId): array
    {
        if (!$this->tableExists('product_variants')) return [];

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM product_variants WHERE product_id=:id ORDER BY id ASC");
            $stmt->execute([':id' => $productId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                foreach (['attributes_json','image_json','meta_json'] as $k) {
                    if (array_key_exists($k, $r)) $r[$k] = $this->tryJsonDecode($r[$k]);
                }
            }
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findVariantById(int $variantId): ?array
    {
        if (!$this->tableExists('product_variants')) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM product_variants WHERE id=:id LIMIT 1");
            $stmt->execute([':id' => $variantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            foreach (['attributes_json','image_json','meta_json'] as $k) {
                if (array_key_exists($k, $row)) $row[$k] = $this->tryJsonDecode($row[$k]);
            }
            return $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) AS c
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :t
            ");
            $stmt->execute([':t' => $table]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) AS c
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c
            ");
            $stmt->execute([':t' => $table, ':c' => $column]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // =========================================================================
    // Internal: WooCommerce integration
    // =========================================================================

    private function wooEnabled(): bool
    {
        $feature = (bool)($this->config['features']['enable_woocommerce'] ?? false);
        $cfgEnabled = (bool)($this->config['woocommerce']['enabled'] ?? false);
        return $feature && $cfgEnabled;
    }

    /**
     * Woo REST call helper using Basic Auth query params (consumer_key/secret)
     * - Many Woo setups allow ck/cs via query string for simplicity.
     * - If your server blocks query auth, switch to Authorization header (OAuth1) later.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $jsonBody
     * @return mixed
     */
    private function wooRequest(string $method, string $path, array $query = [], ?array $jsonBody = null)
    {
        $base = rtrim((string)($this->config['woocommerce']['base_url'] ?? ''), '/');
        if ($base === '') throw new RuntimeException('Woo base_url not configured.');

        $ck = (string)($this->config['woocommerce']['consumer_key'] ?? '');
        $cs = (string)($this->config['woocommerce']['consumer_secret'] ?? '');
        if ($ck === '' || $cs === '') throw new RuntimeException('Woo consumer_key/consumer_secret not configured.');

        $ver = (string)($this->config['woocommerce']['api_version'] ?? 'wc/v3');
        $timeout = (int)($this->config['woocommerce']['timeout_sec'] ?? 30);
        $verifySsl = (bool)($this->config['woocommerce']['verify_ssl'] ?? true);

        $path = '/' . ltrim($path, '/');
        $url = $base . '/wp-json/' . $ver . $path;

        // auth in query
        $query['consumer_key'] = $ck;
        $query['consumer_secret'] = $cs;

        // build query string
        $qs = http_build_query($query);
        if ($qs !== '') $url .= '?' . $qs;

        $headers = [
            'Accept: application/json',
        ];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        return $this->httpJson($method, $url, $headers, $jsonBody, $timeout, $verifySsl);
    }

    /**
     * @return mixed
     */
    private function httpJson(string $method, string $url, array $headers, ?array $jsonBody, int $timeoutSec, bool $verifySsl)
    {
        $ch = curl_init();
        if (!$ch) throw new RuntimeException('cURL init failed.');

        $method = strtoupper($method);

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(1, $timeoutSec),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeoutSec)),
        ];

        if (!$verifySsl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $body = substr($raw, $headerSize);
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $msg = "Woo HTTP {$status}.";
            if (is_array($decoded) && isset($decoded['message'])) {
                $msg .= " " . (string)$decoded['message'];
            }
            // include snippet for debugging
            $msg .= " Body: " . $this->truncateString($body, 500);
            throw new RuntimeException($msg);
        }

        return $decoded;
    }

    /**
     * Upsert CRM product based on Woo payload.
     * @param array<string,mixed> $wp
     * @return 'insert'|'update'|'skip'
     */
    private function upsertProductFromWoo(array $wp): string
    {
        $wooId = (int)($wp['id'] ?? 0);
        if ($wooId <= 0) return 'skip';

        $type = (string)($wp['type'] ?? 'simple');
        $status = (string)($wp['status'] ?? 'publish');

        $sku = $this->nullableTrim($wp['sku'] ?? null);
        $name = trim((string)($wp['name'] ?? ''));
        if ($name === '') $name = "Woo Product #{$wooId}";

        $desc = $this->nullableTrim($wp['description'] ?? null);
        $shortDesc = $this->nullableTrim($wp['short_description'] ?? null);

        // Woo returns prices as strings
        $price = $this->nullableDecimal($wp['price'] ?? null);
        $regular = $this->nullableDecimal($wp['regular_price'] ?? null);
        $sale = $this->nullableDecimal($wp['sale_price'] ?? null);

        $manageStock = $this->bool01($wp['manage_stock'] ?? false);
        $stockQty = $this->nullableInt($wp['stock_quantity'] ?? null);
        $stockStatus = $this->nullableTrim($wp['stock_status'] ?? null);

        $categoriesJson = $this->nullableJson($wp['categories'] ?? null);
        $imagesJson = $this->nullableJson($wp['images'] ?? null);
        $attributesJson = $this->nullableJson($wp['attributes'] ?? null);

        // meta (keep only safe subset)
        $metaJson = $this->nullableJson([
            'permalink' => $wp['permalink'] ?? null,
            'date_created' => $wp['date_created'] ?? null,
            'date_modified' => $wp['date_modified'] ?? null,
            'woocommerce_raw' => null, // to avoid huge payload by default
        ]);

        // Find by woo_product_id first
        $existingId = null;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM products WHERE woo_product_id=:wid LIMIT 1");
            $stmt->execute([':wid' => $wooId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $existingId = $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            $existingId = null;
        }

        if ($existingId === null && $sku) {
            // Try match by SKU if woo_id not present
            try {
                $stmt = $this->pdo->prepare("SELECT id FROM products WHERE sku=:sku LIMIT 1");
                $stmt->execute([':sku' => $sku]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $existingId = $row ? (int)$row['id'] : null;
            } catch (Throwable $e) {
                $existingId = null;
            }
        }

        if ($existingId) {
            $sql = "UPDATE products SET
                type=:type,
                sku=:sku,
                name=:name,
                description=:description,
                short_description=:short_description,
                status=:status,
                price=:price,
                regular_price=:regular,
                sale_price=:sale,
                stock_status=:stock_status,
                stock_quantity=:stock_qty,
                manage_stock=:manage_stock,
                categories_json=:categories,
                images_json=:images,
                attributes_json=:attrs,
                meta_json=:meta,
                woo_product_id=:wid,
                woo_synced_at=NOW(),
                updated_at=NOW()
            WHERE id=:id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':type' => $type,
                ':sku' => $sku,
                ':name' => $name,
                ':description' => $desc,
                ':short_description' => $shortDesc,
                ':status' => $status,
                ':price' => $price,
                ':regular' => $regular,
                ':sale' => $sale,
                ':stock_status' => $stockStatus,
                ':stock_qty' => $stockQty,
                ':manage_stock' => $manageStock,
                ':categories' => $categoriesJson,
                ':images' => $imagesJson,
                ':attrs' => $attributesJson,
                ':meta' => $metaJson,
                ':wid' => $wooId,
                ':id' => $existingId,
            ]);
            return 'update';
        }

        $sql = "INSERT INTO products
            (type, sku, name, description, short_description, status,
             price, regular_price, sale_price,
             stock_status, stock_quantity, manage_stock,
             categories_json, images_json, attributes_json, meta_json,
             woo_product_id, woo_synced_at,
             created_at, updated_at)
            VALUES
            (:type, :sku, :name, :description, :short_description, :status,
             :price, :regular, :sale,
             :stock_status, :stock_qty, :manage_stock,
             :categories, :images, :attrs, :meta,
             :wid, NOW(),
             NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type' => $type,
            ':sku' => $sku,
            ':name' => $name,
            ':description' => $desc,
            ':short_description' => $shortDesc,
            ':status' => $status,
            ':price' => $price,
            ':regular' => $regular,
            ':sale' => $sale,
            ':stock_status' => $stockStatus,
            ':stock_qty' => $stockQty,
            ':manage_stock' => $manageStock,
            ':categories' => $categoriesJson,
            ':images' => $imagesJson,
            ':attrs' => $attributesJson,
            ':meta' => $metaJson,
            ':wid' => $wooId,
        ]);

        return 'insert';
    }

    private function importWooVariationsForProduct(int $wooProductId): int
    {
        // find CRM product id by woo_product_id
        $crmId = null;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM products WHERE woo_product_id=:wid LIMIT 1");
            $stmt->execute([':wid' => $wooProductId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $crmId = $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            $crmId = null;
        }
        if (!$crmId) return 0;

        $countUpsert = 0;
        $page = 1;
        $perPage = 100;

        while (true) {
            $vars = $this->wooRequest('GET', "/products/{$wooProductId}/variations", [
                'per_page' => $perPage,
                'page' => $page,
            ]);

            if (!is_array($vars) || count($vars) === 0) break;

            foreach ($vars as $v) {
                if (!is_array($v)) continue;
                $this->upsertVariantFromWoo($crmId, $v);
                $countUpsert++;
            }

            if (count($vars) < $perPage) break;
            $page++;
            if ($page > 200) break; // safety
        }

        return $countUpsert;
    }

    /**
     * @param array<string,mixed> $wv
     */
    private function upsertVariantFromWoo(int $crmProductId, array $wv): void
    {
        if (!$this->tableExists('product_variants')) return;

        $wooVarId = (int)($wv['id'] ?? 0);
        if ($wooVarId <= 0) return;

        $sku = $this->nullableTrim($wv['sku'] ?? null);
        $price = $this->nullableDecimal($wv['price'] ?? null);
        $regular = $this->nullableDecimal($wv['regular_price'] ?? null);
        $sale = $this->nullableDecimal($wv['sale_price'] ?? null);

        $manageStock = $this->bool01($wv['manage_stock'] ?? false);
        $stockQty = $this->nullableInt($wv['stock_quantity'] ?? null);
        $stockStatus = $this->nullableTrim($wv['stock_status'] ?? null);

        $attrs = $this->nullableJson($wv['attributes'] ?? null);
        $img = $this->nullableJson($wv['image'] ?? null);
        $meta = $this->nullableJson([
            'date_created' => $wv['date_created'] ?? null,
            'date_modified' => $wv['date_modified'] ?? null,
        ]);

        // find existing by woo_variation_id
        $existing = null;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM product_variants WHERE woo_variation_id=:vid LIMIT 1");
            $stmt->execute([':vid' => $wooVarId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $existing = $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            $existing = null;
        }

        if (!$existing && $sku) {
            // fallback match by SKU within product
            try {
                $stmt = $this->pdo->prepare("SELECT id FROM product_variants WHERE product_id=:pid AND sku=:sku LIMIT 1");
                $stmt->execute([':pid' => $crmProductId, ':sku' => $sku]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $existing = $row ? (int)$row['id'] : null;
            } catch (Throwable $e) {
                $existing = null;
            }
        }

        if ($existing) {
            $sql = "UPDATE product_variants SET
                product_id=:pid,
                sku=:sku,
                price=:price,
                regular_price=:regular,
                sale_price=:sale,
                stock_status=:stock_status,
                stock_quantity=:stock_qty,
                manage_stock=:manage_stock,
                attributes_json=:attrs,
                image_json=:img,
                meta_json=:meta,
                woo_variation_id=:wvid,
                woo_synced_at=NOW(),
                updated_at=NOW()
            WHERE id=:id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':pid' => $crmProductId,
                ':sku' => $sku,
                ':price' => $price,
                ':regular' => $regular,
                ':sale' => $sale,
                ':stock_status' => $stockStatus,
                ':stock_qty' => $stockQty,
                ':manage_stock' => $manageStock,
                ':attrs' => $attrs,
                ':img' => $img,
                ':meta' => $meta,
                ':wvid' => $wooVarId,
                ':id' => $existing,
            ]);
            return;
        }

        $sql = "INSERT INTO product_variants
            (product_id, sku, price, regular_price, sale_price,
             stock_status, stock_quantity, manage_stock,
             attributes_json, image_json, meta_json,
             woo_variation_id, woo_synced_at,
             created_at, updated_at)
            VALUES
            (:pid, :sku, :price, :regular, :sale,
             :stock_status, :stock_qty, :manage_stock,
             :attrs, :img, :meta,
             :wvid, NOW(),
             NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':pid' => $crmProductId,
            ':sku' => $sku,
            ':price' => $price,
            ':regular' => $regular,
            ':sale' => $sale,
            ':stock_status' => $stockStatus,
            ':stock_qty' => $stockQty,
            ':manage_stock' => $manageStock,
            ':attrs' => $attrs,
            ':img' => $img,
            ':meta' => $meta,
            ':wvid' => $wooVarId,
        ]);
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    private function mapCrmProductToWooPayload(array $product): array
    {
        // Woo expects strings for prices
        $type = (string)($product['type'] ?? 'simple');

        $payload = [
            'name' => (string)($product['name'] ?? ''),
            'type' => $type,
            'status' => (string)($product['status'] ?? 'publish'),
            'sku' => $product['sku'] ?? null,
            'description' => $product['description'] ?? '',
            'short_description' => $product['short_description'] ?? '',
        ];

        // prices
        if ($type === 'simple') {
            if (isset($product['regular_price']) && $product['regular_price'] !== null) {
                $payload['regular_price'] = (string)$product['regular_price'];
            }
            if (isset($product['sale_price']) && $product['sale_price'] !== null) {
                $payload['sale_price'] = (string)$product['sale_price'];
            }
            if (isset($product['price']) && $product['price'] !== null) {
                // Woo calculates price from regular/sale, but we can also send regular/sale only
                // keep as fallback
            }
        }

        // stock
        $payload['manage_stock'] = (bool)($product['manage_stock'] ?? false);
        if ($payload['manage_stock']) {
            $payload['stock_quantity'] = $product['stock_quantity'] ?? null;
        }
        if (!empty($product['stock_status'])) {
            $payload['stock_status'] = (string)$product['stock_status'];
        }

        // categories/images/attributes
        if (isset($product['categories_json']) && is_array($product['categories_json'])) {
            // Woo needs array of {id} or {name}; we pass through
            $payload['categories'] = $product['categories_json'];
        }
        if (isset($product['images_json']) && is_array($product['images_json'])) {
            $payload['images'] = $product['images_json'];
        }
        if (isset($product['attributes_json']) && is_array($product['attributes_json'])) {
            $payload['attributes'] = $product['attributes_json'];
        }

        return $this->removeNulls($payload);
    }

    /**
     * Push CRM variants to Woo variations endpoint (best-effort).
     * @return array<int,array<string,mixed>>
     */
    private function pushCrmVariantsToWoo(int $crmProductId, int $wooProductId): array
    {
        $rows = $this->listVariants($crmProductId);
        $results = [];

        foreach ($rows as $v) {
            $payload = $this->mapCrmVariantToWooPayload($v);

            $wooVarId = (int)($v['woo_variation_id'] ?? 0);
            if ($wooVarId > 0) {
                $resp = $this->wooRequest('PUT', "/products/{$wooProductId}/variations/{$wooVarId}", [], $payload);
                $results[] = ['crm_variant_id' => (int)$v['id'], 'woo_variation_id' => $wooVarId, 'mode' => 'update', 'resp' => $resp];
            } else {
                $resp = $this->wooRequest('POST', "/products/{$wooProductId}/variations", [], $payload);
                $newVarId = is_array($resp) && isset($resp['id']) ? (int)$resp['id'] : 0;
                if ($newVarId > 0) {
                    $this->setCrmWooVariationId((int)$v['id'], $newVarId);
                }
                $results[] = ['crm_variant_id' => (int)$v['id'], 'woo_variation_id' => $newVarId, 'mode' => 'create', 'resp' => $resp];
            }

            // touch synced
            $this->touchCrmWooVariantSyncedAt((int)$v['id']);
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $variant
     * @return array<string,mixed>
     */
    private function mapCrmVariantToWooPayload(array $variant): array
    {
        $payload = [
            'sku' => $variant['sku'] ?? null,
            'regular_price' => isset($variant['regular_price']) && $variant['regular_price'] !== null ? (string)$variant['regular_price'] : null,
            'sale_price' => isset($variant['sale_price']) && $variant['sale_price'] !== null ? (string)$variant['sale_price'] : null,
            'manage_stock' => (bool)($variant['manage_stock'] ?? false),
            'stock_quantity' => $variant['stock_quantity'] ?? null,
            'stock_status' => $variant['stock_status'] ?? null,
        ];

        // attributes_json expected as array of {name, option}
        if (isset($variant['attributes_json']) && is_array($variant['attributes_json'])) {
            $payload['attributes'] = $variant['attributes_json'];
        }

        return $this->removeNulls($payload);
    }

    private function setCrmWooProductId(int $crmId, int $wooId): void
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE products SET woo_product_id=:wid, updated_at=NOW() WHERE id=:id LIMIT 1");
            $stmt->execute([':wid' => $wooId, ':id' => $crmId]);
        } catch (Throwable $e) {}
    }

    private function touchCrmWooSyncedAt(int $crmId): void
    {
        if (!$this->columnExists('products', 'woo_synced_at')) return;
        try {
            $stmt = $this->pdo->prepare("UPDATE products SET woo_synced_at=NOW(), updated_at=NOW() WHERE id=:id LIMIT 1");
            $stmt->execute([':id' => $crmId]);
        } catch (Throwable $e) {}
    }

    private function setCrmWooVariationId(int $crmVariantId, int $wooVariationId): void
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE product_variants SET woo_variation_id=:wvid, updated_at=NOW() WHERE id=:id LIMIT 1");
            $stmt->execute([':wvid' => $wooVariationId, ':id' => $crmVariantId]);
        } catch (Throwable $e) {}
    }

    private function touchCrmWooVariantSyncedAt(int $crmVariantId): void
    {
        if (!$this->columnExists('product_variants', 'woo_synced_at')) return;
        try {
            $stmt = $this->pdo->prepare("UPDATE product_variants SET woo_synced_at=NOW(), updated_at=NOW() WHERE id=:id LIMIT 1");
            $stmt->execute([':id' => $crmVariantId]);
        } catch (Throwable $e) {}
    }

    /**
     * Diff minimal fields between CRM product row and Woo product object.
     * @param array<string,mixed> $crm
     * @param array<string,mixed> $woo
     * @return array<string,array{crm:mixed,woo:mixed}>
     */
    private function diffCrmWooProduct(array $crm, array $woo): array
    {
        $diff = [];

        $map = [
            'name' => ['crm' => $crm['name'] ?? null, 'woo' => $woo['name'] ?? null],
            'sku' => ['crm' => $crm['sku'] ?? null, 'woo' => $woo['sku'] ?? null],
            'type' => ['crm' => $crm['type'] ?? null, 'woo' => $woo['type'] ?? null],
            'status' => ['crm' => $crm['status'] ?? null, 'woo' => $woo['status'] ?? null],
            'regular_price' => ['crm' => $crm['regular_price'] ?? null, 'woo' => $woo['regular_price'] ?? null],
            'sale_price' => ['crm' => $crm['sale_price'] ?? null, 'woo' => $woo['sale_price'] ?? null],
            'stock_status' => ['crm' => $crm['stock_status'] ?? null, 'woo' => $woo['stock_status'] ?? null],
        ];

        foreach ($map as $k => $pair) {
            $c = $pair['crm'];
            $w = $pair['woo'];

            // normalize numeric strings
            if (in_array($k, ['regular_price','sale_price'], true)) {
                $c = $c === null ? null : (string)$c;
                $w = $w === null ? null : (string)$w;
            }

            if ($c !== $w) {
                $diff[$k] = ['crm' => $pair['crm'], 'woo' => $pair['woo']];
            }
        }

        return $diff;
    }

    // =========================================================================
    // Request/Response helpers
    // =========================================================================

    private function ensureJsonHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false) $raw = '';
        $raw = trim($raw);
        if ($raw === '') return [];

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->json(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
            exit;
        }
        return $data;
    }

    /**
     * @param mixed $data
     */
    private function json($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // Utility helpers
    // =========================================================================

    private function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function userAgent(): string
    {
        return $this->truncateString((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
    }

    private function clampInt(mixed $v, int $min, int $max): int
    {
        if (!is_numeric($v)) return $min;
        $n = (int)$v;
        if ($n < $min) $n = $min;
        if ($n > $max) $n = $max;
        return $n;
    }

    private function nullableTrim(mixed $v): ?string
    {
        if ($v === null) return null;
        if (!is_string($v) && !is_numeric($v)) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private function bool01(mixed $v): int
    {
        return filter_var($v, FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        return (int)$v;
    }

    private function nullableDecimal(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_string($v)) $v = trim($v);
        if ($v === '') return null;
        if (!is_numeric($v)) return null;

        // normalize to string (to avoid float precision issues)
        // keep up to 4 decimals
        $num = (string)$v;
        return $num;
    }

    private function normalizeEnum(string $value, array $allowed, string $default): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function truncateString(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
    }

    private function nullableJson(mixed $v): ?string
    {
        if ($v === null) return null;
        // allow passing JSON string or array/object
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') return null;
            $decoded = json_decode($t, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
            // if not valid JSON, store as string meta
            return json_encode(['value' => $t], JSON_UNESCAPED_UNICODE);
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return null;
    }

    private function tryJsonDecode(mixed $v): mixed
    {
        if (!is_string($v)) return $v;
        $t = trim($v);
        if ($t === '') return null;
        $d = json_decode($t, true);
        if (json_last_error() === JSON_ERROR_NONE) return $d;
        return $v;
    }

    /**
     * @param array<string,mixed> $arr
     * @return array<string,mixed>
     */
    private function removeNulls(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if ($v === null) unset($arr[$k]);
        }
        return $arr;
    }
}

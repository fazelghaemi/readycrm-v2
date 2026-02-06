<?php
/**
 * File: app/Http/Controllers/Webhooks/WooWebhookController.php
 *
 * CRM V2 - Webhooks Controller: WooWebhookController
 * -----------------------------------------------------------------------------
 * هدف این کنترلر:
 *  - دریافت وبهوک‌های ووکامرس (product/order/customer/…)
 *  - اعتبارسنجی امنیتی وبهوک (HMAC SHA256 با secret)
 *  - جلوگیری از پردازش تکراری (Idempotency) با ذخیره signature/hash
 *  - ذخیره رویدادها در DB (event log) برای ردگیری و دیباگ
 *  - Sync "نزدیک به real-time" داده‌ها از Woo -> CRM
 *
 * -----------------------------------------------------------------------------
 * مسیر پیشنهادی:
 *   POST /webhooks/woocommerce
 *
 * ووکامرس معمولاً این هدرها را می‌فرستد:
 *  - X-WC-Webhook-Source: https://site.com
 *  - X-WC-Webhook-Topic: product.created | product.updated | order.created | order.updated | customer.created | ...
 *  - X-WC-Webhook-Resource: product | order | customer
 *  - X-WC-Webhook-Event: created | updated | deleted | restored
 *  - X-WC-Webhook-Signature: base64(hmac_sha256(payload, secret))
 *  - X-WC-Webhook-ID: <numeric>
 *  - X-WC-Webhook-Delivery-ID: <uuid/num>
 *
 * -----------------------------------------------------------------------------
 * Config پیشنهادی (private/config.php):
 *
 * 'features' => [
 *   'enable_woocommerce' => true,
 * ],
 * 'woocommerce' => [
 *   'enabled' => true,
 *   'base_url' => 'https://example.com',
 *   'consumer_key' => 'ck_...',
 *   'consumer_secret' => 'cs_...',
 *   'api_version' => 'wc/v3',
 *   'timeout_sec' => 30,
 *   'verify_ssl' => true,
 *   'webhook_secret' => 'YOUR_WEBHOOK_SECRET', // دقیقا همان که داخل WooWebhook تعریف کردی
 *   'webhook' => [
 *     'require_signature' => true,
 *     'ip_whitelist' => [], // اگر خواستی محدودش کنی: ['1.2.3.4', ...]
 *     'store_raw_payload' => true,
 *     'max_payload_bytes' => 1048576, // 1MB
 *   ],
 * ],
 *
 * -----------------------------------------------------------------------------
 * DB Tables پیشنهادی (اگر هنوز ندارید این کنترلر خودش تلاش می‌کند بسازد):
 *
 * 1) woo_webhook_events:
 *   id BIGINT AI PK
 *   delivery_id VARCHAR(120) NULL
 *   webhook_id BIGINT NULL
 *   topic VARCHAR(120) NULL
 *   resource VARCHAR(60) NULL
 *   event VARCHAR(60) NULL
 *   signature VARCHAR(255) NULL
 *   payload_hash CHAR(64) NOT NULL
 *   status VARCHAR(20) NOT NULL DEFAULT 'received' (received|processed|ignored|failed)
 *   error_message TEXT NULL
 *   entity_type VARCHAR(40) NULL
 *   entity_id BIGINT NULL
 *   raw_payload MEDIUMTEXT NULL
 *   created_at DATETIME NOT NULL
 *   processed_at DATETIME NULL
 *   ip VARCHAR(45) NULL
 *   user_agent VARCHAR(255) NULL
 *
 *   UNIQUE(payload_hash)  // idempotency
 *
 * 2) (اختیاری) woo_sync_queue:
 *   id BIGINT AI PK
 *   job_type VARCHAR(60) NOT NULL   (product|order|customer|...)
 *   woo_id BIGINT NOT NULL
 *   payload_json MEDIUMTEXT NULL
 *   status VARCHAR(20) NOT NULL DEFAULT 'pending' (pending|processing|done|failed)
 *   tries INT NOT NULL DEFAULT 0
 *   last_error TEXT NULL
 *   created_at DATETIME NOT NULL
 *   updated_at DATETIME NOT NULL
 *
 * -----------------------------------------------------------------------------
 * نکته مهم:
 * این فایل برای اینکه شما راحت باشید، "همزمان دو حالت" را پشتیبانی می‌کند:
 *  A) پردازش مستقیم (همین لحظه) برای sync ساده
 *  B) صف‌بندی (Queue) برای وقتی ترافیک بالاست یا می‌خوای async انجام بدی
 *
 * اگر Queue نمی‌خوای، config: woocommerce.webhook.use_queue=false
 */

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Database\Connection;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class WooWebhookController
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

    // =============================================================================
    // Entry point
    // =============================================================================

    /**
     * POST /webhooks/woocommerce
     */
    public function handle(): void
    {
        // Webhook endpoints should respond fast.
        // We will:
        //  1) Basic checks
        //  2) Verify signature (if enabled)
        //  3) Idempotency check via payload hash
        //  4) Log event row
        //  5) Process or enqueue
        //  6) Return 200 quickly

        $this->ensurePlainTextHeaders(); // Woo doesn't require JSON response
        $ip = $this->clientIp();
        $ua = $this->userAgent();

        if (!$this->wooEnabled()) {
            $this->respond(503, "WooCommerce integration disabled");
            return;
        }

        // Optional IP whitelist
        if (!$this->ipAllowed($ip)) {
            $this->logger->warning('WOO_WEBHOOK_BLOCKED_IP', ['ip' => $ip]);
            $this->respond(403, "Forbidden");
            return;
        }

        // Read raw payload safely with size limit
        $raw = $this->readRawBodyWithLimit();
        if ($raw === null) {
            $this->respond(413, "Payload too large");
            return;
        }

        // Parse headers (case-insensitive)
        $topic = $this->header('X-WC-Webhook-Topic');
        $resource = $this->header('X-WC-Webhook-Resource');
        $event = $this->header('X-WC-Webhook-Event');
        $signature = $this->header('X-WC-Webhook-Signature');
        $webhookId = $this->header('X-WC-Webhook-ID');
        $deliveryId = $this->header('X-WC-Webhook-Delivery-ID');

        $topic = $topic ? trim($topic) : null;
        $resource = $resource ? trim($resource) : null;
        $event = $event ? trim($event) : null;
        $signature = $signature ? trim($signature) : null;

        $webhookIdNum = $webhookId && is_numeric($webhookId) ? (int)$webhookId : null;
        $deliveryId = $deliveryId ? trim($deliveryId) : null;

        // Verify signature if enabled
        $requireSig = (bool)($this->config['woocommerce']['webhook']['require_signature'] ?? true);
        if ($requireSig) {
            $secret = (string)($this->config['woocommerce']['webhook_secret'] ?? '');
            if ($secret === '') {
                $this->logger->error('WOO_WEBHOOK_NO_SECRET', []);
                $this->respond(500, "Webhook secret is not configured");
                return;
            }
            if (!$signature) {
                $this->logger->warning('WOO_WEBHOOK_MISSING_SIGNATURE', []);
                $this->respond(401, "Missing signature");
                return;
            }
            if (!$this->verifyWooSignature($raw, $secret, $signature)) {
                $this->logger->warning('WOO_WEBHOOK_INVALID_SIGNATURE', ['ip' => $ip]);
                $this->respond(401, "Invalid signature");
                return;
            }
        }

        // Hash payload for idempotency
        $payloadHash = hash('sha256', $raw);

        // Ensure event table
        $this->ensureWebhookEventsTable();

        // If already processed: respond 200 to stop retries
        if ($this->webhookHashExists($payloadHash)) {
            $this->logger->info('WOO_WEBHOOK_DUPLICATE', ['hash' => $payloadHash, 'topic' => $topic]);
            $this->respond(200, "OK (duplicate ignored)");
            return;
        }

        // Decode JSON (Woo payload is JSON)
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $eventId = $this->insertWebhookEvent([
                'delivery_id' => $deliveryId,
                'webhook_id' => $webhookIdNum,
                'topic' => $topic,
                'resource' => $resource,
                'event' => $event,
                'signature' => $signature,
                'payload_hash' => $payloadHash,
                'status' => 'failed',
                'error_message' => 'Invalid JSON payload',
                'entity_type' => null,
                'entity_id' => null,
                'raw_payload' => $this->maybeStoreRaw($raw),
                'ip' => $ip,
                'user_agent' => $ua,
            ]);

            $this->logger->error('WOO_WEBHOOK_INVALID_JSON', ['event_id' => $eventId ?? null]);
            $this->respond(400, "Invalid JSON");
            return;
        }

        // Determine entity type & Woo ID from payload
        [$entityType, $wooEntityId] = $this->detectEntity($topic, $resource, $payload);

        // Insert event row as received
        $eventRowId = $this->insertWebhookEvent([
            'delivery_id' => $deliveryId,
            'webhook_id' => $webhookIdNum,
            'topic' => $topic,
            'resource' => $resource,
            'event' => $event,
            'signature' => $signature,
            'payload_hash' => $payloadHash,
            'status' => 'received',
            'error_message' => null,
            'entity_type' => $entityType,
            'entity_id' => $wooEntityId,
            'raw_payload' => $this->maybeStoreRaw($raw),
            'ip' => $ip,
            'user_agent' => $ua,
        ]);

        // Process or enqueue
        $useQueue = (bool)($this->config['woocommerce']['webhook']['use_queue'] ?? false);

        try {
            if ($useQueue) {
                $this->ensureQueueTable();
                $this->enqueueJob($entityType ?? 'unknown', (int)($wooEntityId ?? 0), $payload);
                $this->markWebhookEvent($eventRowId, 'ignored', null); // ignored => queued (fast)
                $this->respond(200, "OK (queued)");
                return;
            }

            $this->processWebhookPayload($topic, $resource, $event, $payload, $entityType, $wooEntityId);

            $this->markWebhookEvent($eventRowId, 'processed', null);
            $this->respond(200, "OK");
        } catch (Throwable $e) {
            $this->markWebhookEvent($eventRowId, 'failed', $e->getMessage());
            $this->logger->error('WOO_WEBHOOK_PROCESS_FAILED', [
                'event_id' => $eventRowId,
                'topic' => $topic,
                'err' => $e->getMessage(),
            ]);
            // Woo retries on non-2xx, اما برای جلوگیری از loop، بهتره 200 بدیم
            // و خطا رو داخل CRM ثبت کنیم.
            $this->respond(200, "OK (failed logged)");
        }
    }

    // =============================================================================
    // Core processing (Woo -> CRM)
    // =============================================================================

    /**
     * @param array<string,mixed> $payload
     */
    private function processWebhookPayload(
        ?string $topic,
        ?string $resource,
        ?string $event,
        array $payload,
        ?string $entityType,
        ?int $wooEntityId
    ): void {
        // Strategy:
        // - for product.* : upsert into products + variants (if variable)
        // - for order.*   : upsert into sales (و اگر نیاز دارید sale_items)
        // - for customer.*: upsert into customers
        //
        // ⚠️ چون ساختار DB شما ممکن است هنوز کامل نباشد،
        //   این متد "best-effort" است و اگر جدول/ستون نبود،
        //   خطای راهنما می‌دهد.

        $topicNorm = $topic ? strtolower($topic) : '';
        $resourceNorm = $resource ? strtolower($resource) : '';

        // If Woo sends topic like "product.updated"
        if (str_starts_with($topicNorm, 'product.') || $resourceNorm === 'product') {
            $this->syncProductFromWooPayload($payload);
            return;
        }

        if (str_starts_with($topicNorm, 'order.') || $resourceNorm === 'order') {
            $this->syncOrderFromWooPayload($payload);
            return;
        }

        if (str_starts_with($topicNorm, 'customer.') || $resourceNorm === 'customer') {
            $this->syncCustomerFromWooPayload($payload);
            return;
        }

        // Unknown topic: log only
        $this->logger->warning('WOO_WEBHOOK_UNKNOWN_TOPIC', [
            'topic' => $topic,
            'resource' => $resource,
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $wooEntityId,
        ]);
    }

    /**
     * Sync Product payload to CRM tables: products + product_variants
     * @param array<string,mixed> $p
     */
    private function syncProductFromWooPayload(array $p): void
    {
        if (!$this->tableExists('products')) {
            throw new RuntimeException("Table 'products' does not exist. Run migrations.");
        }

        $wooId = (int)($p['id'] ?? 0);
        if ($wooId <= 0) throw new RuntimeException("Product payload missing 'id'.");

        $type = (string)($p['type'] ?? 'simple');
        $status = (string)($p['status'] ?? 'publish');
        $sku = $this->nullableTrim($p['sku'] ?? null);
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') $name = "Woo Product #{$wooId}";

        $desc = $this->nullableTrim($p['description'] ?? null);
        $short = $this->nullableTrim($p['short_description'] ?? null);

        $price = $this->nullableDecimal($p['price'] ?? null);
        $regular = $this->nullableDecimal($p['regular_price'] ?? null);
        $sale = $this->nullableDecimal($p['sale_price'] ?? null);

        $manageStock = $this->bool01($p['manage_stock'] ?? false);
        $stockQty = $this->nullableInt($p['stock_quantity'] ?? null);
        $stockStatus = $this->nullableTrim($p['stock_status'] ?? null);

        $categoriesJson = $this->nullableJson($p['categories'] ?? null);
        $imagesJson = $this->nullableJson($p['images'] ?? null);
        $attributesJson = $this->nullableJson($p['attributes'] ?? null);

        $metaJson = $this->nullableJson([
            'permalink' => $p['permalink'] ?? null,
            'date_created' => $p['date_created'] ?? null,
            'date_modified' => $p['date_modified'] ?? null,
        ]);

        // Upsert by woo_product_id (or sku as fallback)
        $crmId = $this->findCrmProductIdByWooId($wooId);
        if ($crmId === null && $sku) {
            $crmId = $this->findCrmProductIdBySku($sku);
        }

        if ($crmId !== null) {
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
                woo_product_id=:woo_id,
                woo_synced_at=NOW(),
                updated_at=NOW()
            WHERE id=:id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':type' => $type,
                ':sku' => $sku,
                ':name' => $name,
                ':description' => $desc,
                ':short_description' => $short,
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
                ':woo_id' => $wooId,
                ':id' => $crmId,
            ]);
        } else {
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
                 :woo_id, NOW(),
                 NOW(), NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':type' => $type,
                ':sku' => $sku,
                ':name' => $name,
                ':description' => $desc,
                ':short_description' => $short,
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
                ':woo_id' => $wooId,
            ]);
            $crmId = (int)$this->pdo->lastInsertId();
        }

        // Variations (if variable): Woo webhook for product may not include variations.
        // We can:
        //  A) just store product, and a separate job fetch variations by API
        //  B) if payload includes "variations" IDs, fetch each variation
        //
        // Here we do best-effort:
        // - if table product_variants exists AND product is variable:
        //   fetch variations list from Woo API (recommended)
        if ($type === 'variable' && $this->tableExists('product_variants')) {
            $this->syncVariationsByWooApi($wooId, $crmId);
        }

        $this->logger->info('WOO_WEBHOOK_PRODUCT_SYNCED', [
            'woo_product_id' => $wooId,
            'crm_product_id' => $crmId,
            'type' => $type,
        ]);
    }

    /**
     * Sync variations for a Woo variable product by calling Woo API.
     * @param int $wooProductId
     * @param int $crmProductId
     */
    private function syncVariationsByWooApi(int $wooProductId, int $crmProductId): void
    {
        if (!$this->wooApiConfigured()) {
            // If no API credentials, we cannot fetch variations. Not a failure.
            $this->logger->warning('WOO_WEBHOOK_NO_API_FOR_VARIATIONS', [
                'woo_product_id' => $wooProductId,
                'crm_product_id' => $crmProductId,
            ]);
            return;
        }

        $page = 1;
        $perPage = 100;
        $totalUpsert = 0;

        while (true) {
            $vars = $this->wooRequest('GET', "/products/{$wooProductId}/variations", [
                'per_page' => $perPage,
                'page' => $page,
            ]);

            if (!is_array($vars) || count($vars) === 0) break;

            foreach ($vars as $v) {
                if (!is_array($v)) continue;
                $this->upsertVariantFromWoo($crmProductId, $v);
                $totalUpsert++;
            }

            if (count($vars) < $perPage) break;
            $page++;
            if ($page > 200) break; // safety
        }

        $this->logger->info('WOO_WEBHOOK_VARIATIONS_SYNCED', [
            'woo_product_id' => $wooProductId,
            'crm_product_id' => $crmProductId,
            'count' => $totalUpsert,
        ]);
    }

    /**
     * Upsert a variant row into product_variants by woo_variation_id.
     * @param int $crmProductId
     * @param array<string,mixed> $wv
     */
    private function upsertVariantFromWoo(int $crmProductId, array $wv): void
    {
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

        // Find by woo_variation_id
        $existing = null;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM product_variants WHERE woo_variation_id=:wvid LIMIT 1");
            $stmt->execute([':wvid' => $wooVarId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $existing = $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            $existing = null;
        }

        if (!$existing && $sku) {
            // fallback: match by SKU within product
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
     * Sync Order payload to CRM tables (sales + sale_items suggested)
     * @param array<string,mixed> $o
     */
    private function syncOrderFromWooPayload(array $o): void
    {
        // Minimum viable: store as a sale row with meta_json
        if (!$this->tableExists('sales')) {
            // If you still don't have sales table, log but don't crash entire webhook flow
            throw new RuntimeException("Table 'sales' does not exist. Create sales table for orders sync.");
        }

        $wooId = (int)($o['id'] ?? 0);
        if ($wooId <= 0) throw new RuntimeException("Order payload missing 'id'.");

        $status = $this->nullableTrim($o['status'] ?? null) ?? 'pending';
        $currency = $this->nullableTrim($o['currency'] ?? null) ?? 'IRR';
        $total = $this->nullableDecimal($o['total'] ?? null);

        $billing = $o['billing'] ?? null;
        $shipping = $o['shipping'] ?? null;

        $customerWooId = (int)($o['customer_id'] ?? 0);
        $paymentMethod = $this->nullableTrim($o['payment_method'] ?? null);
        $paymentTitle = $this->nullableTrim($o['payment_method_title'] ?? null);

        $items = $o['line_items'] ?? null;

        $meta = [
            'woo' => [
                'id' => $wooId,
                'status' => $status,
                'currency' => $currency,
                'total' => $total,
                'date_created' => $o['date_created'] ?? null,
                'date_paid' => $o['date_paid'] ?? null,
                'payment_method' => $paymentMethod,
                'payment_method_title' => $paymentTitle,
                'customer_id' => $customerWooId,
                'billing' => $billing,
                'shipping' => $shipping,
                'line_items' => $items,
            ],
        ];

        // Upsert by woo_order_id if column exists, otherwise store by reference in meta.
        $crmSaleId = $this->findCrmSaleIdByWooId($wooId);

        if ($crmSaleId !== null) {
            $sql = "UPDATE sales SET
                status=:status,
                currency=:currency,
                total=:total,
                meta_json=:meta,
                woo_synced_at=NOW(),
                updated_at=NOW()
            WHERE id=:id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':currency' => $currency,
                ':total' => $total,
                ':meta' => $this->nullableJson($meta),
                ':id' => $crmSaleId,
            ]);
        } else {
            $sql = "INSERT INTO sales
                (status, currency, total, meta_json, woo_order_id, woo_synced_at, created_at, updated_at)
            VALUES
                (:status, :currency, :total, :meta, :woo_order_id, NOW(), NOW(), NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':currency' => $currency,
                ':total' => $total,
                ':meta' => $this->nullableJson($meta),
                ':woo_order_id' => $wooId,
            ]);
            $crmSaleId = (int)$this->pdo->lastInsertId();
        }

        $this->logger->info('WOO_WEBHOOK_ORDER_SYNCED', [
            'woo_order_id' => $wooId,
            'crm_sale_id' => $crmSaleId,
            'status' => $status,
        ]);
    }

    /**
     * Sync Customer payload to CRM customers
     * @param array<string,mixed> $c
     */
    private function syncCustomerFromWooPayload(array $c): void
    {
        if (!$this->tableExists('customers')) {
            throw new RuntimeException("Table 'customers' does not exist. Create customers table for customers sync.");
        }

        $wooId = (int)($c['id'] ?? 0);
        if ($wooId <= 0) throw new RuntimeException("Customer payload missing 'id'.");

        $email = $this->nullableTrim($c['email'] ?? null);
        $first = $this->nullableTrim($c['first_name'] ?? null);
        $last = $this->nullableTrim($c['last_name'] ?? null);
        $name = trim(($first ?? '') . ' ' . ($last ?? ''));
        if ($name === '') $name = $email ?? "Woo Customer #{$wooId}";

        $billing = $c['billing'] ?? null;
        $shipping = $c['shipping'] ?? null;

        $meta = [
            'woo' => [
                'id' => $wooId,
                'username' => $c['username'] ?? null,
                'date_created' => $c['date_created'] ?? null,
                'date_modified' => $c['date_modified'] ?? null,
                'billing' => $billing,
                'shipping' => $shipping,
            ],
        ];

        // Upsert by woo_customer_id OR email
        $crmId = $this->findCrmCustomerIdByWooId($wooId);
        if ($crmId === null && $email) {
            $crmId = $this->findCrmCustomerIdByEmail($email);
        }

        if ($crmId !== null) {
            $sql = "UPDATE customers SET
                full_name=:name,
                email=:email,
                meta_json=:meta,
                woo_customer_id=:woo_id,
                woo_synced_at=NOW(),
                updated_at=NOW()
            WHERE id=:id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':meta' => $this->nullableJson($meta),
                ':woo_id' => $wooId,
                ':id' => $crmId,
            ]);
        } else {
            $sql = "INSERT INTO customers
                (full_name, email, meta_json, woo_customer_id, woo_synced_at, created_at, updated_at)
            VALUES
                (:name, :email, :meta, :woo_id, NOW(), NOW(), NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':meta' => $this->nullableJson($meta),
                ':woo_id' => $wooId,
            ]);
            $crmId = (int)$this->pdo->lastInsertId();
        }

        $this->logger->info('WOO_WEBHOOK_CUSTOMER_SYNCED', [
            'woo_customer_id' => $wooId,
            'crm_customer_id' => $crmId,
            'email' => $email,
        ]);
    }

    // =============================================================================
    // Webhook security: signature verification
    // =============================================================================

    private function verifyWooSignature(string $rawPayload, string $secret, string $signatureHeader): bool
    {
        // Woo: base64_encode(hash_hmac('sha256', $payload, $secret, true))
        $computed = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));
        // Constant-time compare
        return hash_equals($computed, $signatureHeader);
    }

    private function ipAllowed(string $ip): bool
    {
        $wl = $this->config['woocommerce']['webhook']['ip_whitelist'] ?? [];
        if (!is_array($wl) || count($wl) === 0) return true;
        return in_array($ip, $wl, true);
    }

    // =============================================================================
    // Idempotency + event log
    // =============================================================================

    private function ensureWebhookEventsTable(): void
    {
        static $done = false;
        if ($done) return;

        try {
            $this->pdo->query("SELECT 1 FROM woo_webhook_events LIMIT 1");
            $done = true;
            return;
        } catch (Throwable $e) {
            // create
        }

        $sql = "
CREATE TABLE IF NOT EXISTS woo_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  delivery_id VARCHAR(120) NULL,
  webhook_id BIGINT NULL,
  topic VARCHAR(120) NULL,
  resource VARCHAR(60) NULL,
  event VARCHAR(60) NULL,
  signature VARCHAR(255) NULL,
  payload_hash CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'received',
  error_message TEXT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id BIGINT NULL,
  raw_payload MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_payload_hash (payload_hash),
  KEY idx_created (created_at),
  KEY idx_topic (topic),
  KEY idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        $this->pdo->exec($sql);
        $done = true;
    }

    private function webhookHashExists(string $hash): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM woo_webhook_events WHERE payload_hash=:h LIMIT 1");
            $stmt->execute([':h' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (bool)$row;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertWebhookEvent(array $data): ?int
    {
        $sql = "INSERT INTO woo_webhook_events
            (delivery_id, webhook_id, topic, resource, event, signature, payload_hash, status, error_message,
             entity_type, entity_id, raw_payload, created_at, ip, user_agent)
            VALUES
            (:delivery_id, :webhook_id, :topic, :resource, :event, :signature, :payload_hash, :status, :error_message,
             :entity_type, :entity_id, :raw_payload, NOW(), :ip, :user_agent)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':delivery_id' => $data['delivery_id'] ?? null,
                ':webhook_id' => $data['webhook_id'] ?? null,
                ':topic' => $data['topic'] ?? null,
                ':resource' => $data['resource'] ?? null,
                ':event' => $data['event'] ?? null,
                ':signature' => $data['signature'] ?? null,
                ':payload_hash' => $data['payload_hash'],
                ':status' => $data['status'] ?? 'received',
                ':error_message' => $data['error_message'] ?? null,
                ':entity_type' => $data['entity_type'] ?? null,
                ':entity_id' => $data['entity_id'] ?? null,
                ':raw_payload' => $data['raw_payload'] ?? null,
                ':ip' => $data['ip'] ?? null,
                ':user_agent' => $this->truncate((string)($data['user_agent'] ?? ''), 255),
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            // if UNIQUE(payload_hash) violated, treat as duplicate
            return null;
        }
    }

    private function markWebhookEvent(?int $id, string $status, ?string $error): void
    {
        if (!$id) return;
        try {
            $stmt = $this->pdo->prepare("
                UPDATE woo_webhook_events
                SET status=:s, error_message=:e, processed_at=NOW()
                WHERE id=:id LIMIT 1
            ");
            $stmt->execute([':s' => $status, ':e' => $error, ':id' => $id]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function maybeStoreRaw(string $raw): ?string
    {
        $store = (bool)($this->config['woocommerce']['webhook']['store_raw_payload'] ?? true);
        if (!$store) return null;

        // Don't store extremely large payloads even if allowed
        $max = (int)($this->config['woocommerce']['webhook']['store_raw_max_chars'] ?? 60000);
        if ($max <= 0) $max = 60000;

        return $this->truncate($raw, $max);
    }

    // =============================================================================
    // Optional Queue
    // =============================================================================

    private function ensureQueueTable(): void
    {
        static $done = false;
        if ($done) return;

        try {
            $this->pdo->query("SELECT 1 FROM woo_sync_queue LIMIT 1");
            $done = true;
            return;
        } catch (Throwable $e) {}

        $sql = "
CREATE TABLE IF NOT EXISTS woo_sync_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(60) NOT NULL,
  woo_id BIGINT NOT NULL,
  payload_json MEDIUMTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  tries INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_created (created_at),
  KEY idx_job (job_type, woo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        $this->pdo->exec($sql);
        $done = true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function enqueueJob(string $jobType, int $wooId, array $payload): void
    {
        $sql = "INSERT INTO woo_sync_queue (job_type, woo_id, payload_json, status, tries, created_at, updated_at)
                VALUES (:t, :id, :p, 'pending', 0, NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':t' => $jobType,
            ':id' => $wooId,
            ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // =============================================================================
    // Woo API helper (for variations fetch)
    // =============================================================================

    private function wooApiConfigured(): bool
    {
        $base = (string)($this->config['woocommerce']['base_url'] ?? '');
        $ck = (string)($this->config['woocommerce']['consumer_key'] ?? '');
        $cs = (string)($this->config['woocommerce']['consumer_secret'] ?? '');
        return trim($base) !== '' && trim($ck) !== '' && trim($cs) !== '';
    }

    /**
     * @param array<string,mixed> $query
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

        // Simple auth via query (works on many Woo setups)
        $query['consumer_key'] = $ck;
        $query['consumer_secret'] = $cs;

        $qs = http_build_query($query);
        if ($qs !== '') $url .= '?' . $qs;

        $headers = ['Accept: application/json'];
        if ($jsonBody !== null) $headers[] = 'Content-Type: application/json';

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
            $msg = "Woo HTTP {$status}. " . $this->truncate($body, 400);
            throw new RuntimeException($msg);
        }

        return $decoded;
    }

    // =============================================================================
    // Entity detection
    // =============================================================================

    /**
     * @param array<string,mixed> $payload
     * @return array{0:?string,1:?int}
     */
    private function detectEntity(?string $topic, ?string $resource, array $payload): array
    {
        $topicNorm = $topic ? strtolower($topic) : '';
        $resourceNorm = $resource ? strtolower($resource) : '';

        if (str_starts_with($topicNorm, 'product.') || $resourceNorm === 'product') {
            return ['product', isset($payload['id']) ? (int)$payload['id'] : null];
        }
        if (str_starts_with($topicNorm, 'order.') || $resourceNorm === 'order') {
            return ['order', isset($payload['id']) ? (int)$payload['id'] : null];
        }
        if (str_starts_with($topicNorm, 'customer.') || $resourceNorm === 'customer') {
            return ['customer', isset($payload['id']) ? (int)$payload['id'] : null];
        }

        return [null, isset($payload['id']) ? (int)$payload['id'] : null];
    }

    // =============================================================================
    // CRM lookup helpers
    // =============================================================================

    private function findCrmProductIdByWooId(int $wooId): ?int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM products WHERE woo_product_id=:w LIMIT 1");
            $stmt->execute([':w' => $wooId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function findCrmProductIdBySku(string $sku): ?int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM products WHERE sku=:s LIMIT 1");
            $stmt->execute([':s' => $sku]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function findCrmSaleIdByWooId(int $wooOrderId): ?int
    {
        if (!$this->columnExists('sales', 'woo_order_id')) {
            // If you don't have this column yet, you should add it in migrations.
            return null;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM sales WHERE woo_order_id=:w LIMIT 1");
            $stmt->execute([':w' => $wooOrderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function findCrmCustomerIdByWooId(int $wooCustomerId): ?int
    {
        if (!$this->columnExists('customers', 'woo_customer_id')) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM customers WHERE woo_customer_id=:w LIMIT 1");
            $stmt->execute([':w' => $wooCustomerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function findCrmCustomerIdByEmail(string $email): ?int
    {
        if (!$this->columnExists('customers', 'email')) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM customers WHERE email=:e LIMIT 1");
            $stmt->execute([':e' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // =============================================================================
    // DB schema helpers
    // =============================================================================

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

    // =============================================================================
    // Basic environment helpers
    // =============================================================================

    private function wooEnabled(): bool
    {
        $feature = (bool)($this->config['features']['enable_woocommerce'] ?? false);
        $cfgEnabled = (bool)($this->config['woocommerce']['enabled'] ?? false);
        return $feature && $cfgEnabled;
    }

    private function ensurePlainTextHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
    }

    private function respond(int $status, string $message): void
    {
        http_response_code($status);
        echo $message;
    }

    private function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function userAgent(): string
    {
        return $this->truncate((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
    }

    private function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return trim((string)$_SERVER[$key]);

        // Some servers pass headers differently
        $alt = strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$alt])) return trim((string)$_SERVER[$alt]);

        return null;
    }

    private function readRawBodyWithLimit(): ?string
    {
        $maxBytes = (int)($this->config['woocommerce']['webhook']['max_payload_bytes'] ?? 1048576); // 1MB default
        if ($maxBytes <= 0) $maxBytes = 1048576;

        $raw = file_get_contents('php://input');
        if ($raw === false) $raw = '';

        // PHP already read it; check size to avoid DB storing huge
        if (strlen($raw) > $maxBytes) {
            return null;
        }
        return $raw;
    }

    private function truncate(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
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
        return (string)$v;
    }

    private function nullableJson(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') return null;
            $decoded = json_decode($t, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
            return json_encode(['value' => $t], JSON_UNESCAPED_UNICODE);
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return null;
    }
}

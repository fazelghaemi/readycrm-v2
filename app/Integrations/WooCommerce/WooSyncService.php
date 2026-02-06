<?php
/**
 * File: app/Integrations/WooCommerce/WooSyncService.php
 *
 * CRM V2 - WooCommerce Sync Service (Two-way)
 * -----------------------------------------------------------------------------
 * این سرویس، مغز ادغام دوطرفه CRM <-> WooCommerce است.
 *
 * اهداف:
 *  1) Import از Woo به CRM:
 *     - محصولات: ساده و متغیر (+ variation ها)
 *     - مشتری‌ها
 *     - سفارش‌ها (به عنوان فروش)
 *
 *  2) Export از CRM به Woo:
 *     - ایجاد/آپدیت محصول
 *     - ایجاد/آپدیت وارییشن‌ها (برای محصول متغیر)
 *
 *  3) Conflict Handling:
 *     - اگر همزمان در CRM و Woo تغییر کرده باشد، باید سیاست تعیین شود.
 *     - سیاست پیشنهادی: "آخرین تغییر wins" یا "CRM wins" یا "Woo wins"
 *
 *  4) Logging و Report:
 *     - خروجی متنی/آرایه‌ای برای نمایش در UI یا API
 *
 * -----------------------------------------------------------------------------
 * مهم: چون DB و ساختار دقیق شما در حال توسعه است، این سرویس:
 *  - از ستون‌های رایج (woo_product_id, woo_customer_id, woo_order_id, woo_synced_at, updated_at) استفاده می‌کند
 *  - اگر جدول/ستون نبود، خطای قابل فهم می‌دهد تا شما در مهاجرت‌ها اضافه کنی.
 *
 * وابستگی‌ها:
 *  - WooClient: ارتباط HTTP
 *  - WooMapper: mapping payload
 *  - PDO: insert/update در DB
 *  - Logger
 */

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class WooSyncService
{
    private PDO $pdo;
    private Logger $logger;
    private WooClient $client;
    private WooMapper $mapper;

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(PDO $pdo, Logger $logger, WooClient $client, WooMapper $mapper, array $config)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->client = $client;
        $this->mapper = $mapper;
        $this->config = $config;
    }

    // =============================================================================
    // Public: Import from Woo -> CRM
    // =============================================================================

    /**
     * Import all products from Woo (with pagination).
     * Optionally sync variations for variable products.
     *
     * @return array<string,mixed>
     */
    public function importProducts(bool $includeVariations = true, int $perPage = 50, int $maxPages = 50): array
    {
        $this->client->assertReady();
        $this->assertTable('products');

        $report = $this->newReport('importProducts');
        $report['params'] = [
            'includeVariations' => $includeVariations,
            'perPage' => $perPage,
            'maxPages' => $maxPages,
        ];

        $page = 1;

        while ($page <= $maxPages) {
            $list = $this->client->get('/products', [
                'per_page' => min(100, max(1, $perPage)),
                'page' => $page,
                'status' => 'any',
            ]);

            if (!is_array($list) || count($list) === 0) {
                break;
            }

            foreach ($list as $wooProduct) {
                if (!is_array($wooProduct)) continue;

                try {
                    $result = $this->upsertCrmProductFromWoo($wooProduct);
                    $report['counters']['products_upserted']++;

                    // variations
                    $type = strtolower((string)($wooProduct['type'] ?? 'simple'));
                    $wooId = (int)($wooProduct['id'] ?? 0);
                    $crmId = (int)($result['crm_id'] ?? 0);

                    if ($includeVariations && $type === 'variable' && $wooId > 0 && $crmId > 0) {
                        if ($this->tableExists('product_variants')) {
                            $vCount = $this->importVariationsForWooProduct($wooId, $crmId);
                            $report['counters']['variations_upserted'] += $vCount;
                        } else {
                            $report['warnings'][] = "Table product_variants not found; variations skipped for product {$wooId}.";
                        }
                    }
                } catch (Throwable $e) {
                    $report['counters']['errors']++;
                    $report['errors'][] = [
                        'type' => 'product',
                        'woo_id' => $wooProduct['id'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                    $this->logger->error('WOO_IMPORT_PRODUCT_FAILED', [
                        'woo_id' => $wooProduct['id'] ?? null,
                        'err' => $e->getMessage(),
                    ]);
                }
            }

            if (count($list) < $perPage) {
                break;
            }

            $page++;
        }

        $report['success'] = ($report['counters']['errors'] === 0);
        return $report;
    }

    /**
     * Import all customers from Woo
     * @return array<string,mixed>
     */
    public function importCustomers(int $perPage = 50, int $maxPages = 50): array
    {
        $this->client->assertReady();
        $this->assertTable('customers');

        $report = $this->newReport('importCustomers');
        $report['params'] = ['perPage' => $perPage, 'maxPages' => $maxPages];

        $page = 1;

        while ($page <= $maxPages) {
            $list = $this->client->get('/customers', [
                'per_page' => min(100, max(1, $perPage)),
                'page' => $page,
            ]);

            if (!is_array($list) || count($list) === 0) break;

            foreach ($list as $wooCustomer) {
                if (!is_array($wooCustomer)) continue;

                try {
                    $this->upsertCrmCustomerFromWoo($wooCustomer);
                    $report['counters']['customers_upserted']++;
                } catch (Throwable $e) {
                    $report['counters']['errors']++;
                    $report['errors'][] = [
                        'type' => 'customer',
                        'woo_id' => $wooCustomer['id'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                    $this->logger->error('WOO_IMPORT_CUSTOMER_FAILED', [
                        'woo_id' => $wooCustomer['id'] ?? null,
                        'err' => $e->getMessage(),
                    ]);
                }
            }

            if (count($list) < $perPage) break;
            $page++;
        }

        $report['success'] = ($report['counters']['errors'] === 0);
        return $report;
    }

    /**
     * Import orders from Woo
     * @return array<string,mixed>
     */
    public function importOrders(int $perPage = 50, int $maxPages = 50, ?string $status = null): array
    {
        $this->client->assertReady();
        $this->assertTable('sales');

        $report = $this->newReport('importOrders');
        $report['params'] = ['perPage' => $perPage, 'maxPages' => $maxPages, 'status' => $status];

        $page = 1;

        while ($page <= $maxPages) {
            $query = [
                'per_page' => min(100, max(1, $perPage)),
                'page' => $page,
            ];
            if ($status) $query['status'] = $status;

            $list = $this->client->get('/orders', $query);
            if (!is_array($list) || count($list) === 0) break;

            foreach ($list as $wooOrder) {
                if (!is_array($wooOrder)) continue;

                try {
                    $this->upsertCrmSaleFromWoo($wooOrder);
                    $report['counters']['orders_upserted']++;
                } catch (Throwable $e) {
                    $report['counters']['errors']++;
                    $report['errors'][] = [
                        'type' => 'order',
                        'woo_id' => $wooOrder['id'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                    $this->logger->error('WOO_IMPORT_ORDER_FAILED', [
                        'woo_id' => $wooOrder['id'] ?? null,
                        'err' => $e->getMessage(),
                    ]);
                }
            }

            if (count($list) < $perPage) break;
            $page++;
        }

        $report['success'] = ($report['counters']['errors'] === 0);
        return $report;
    }

    // =============================================================================
    // Public: Export from CRM -> Woo
    // =============================================================================

    /**
     * Push one CRM product to Woo (create if missing woo_product_id; else update)
     *
     * @param array<string,mixed> $crmProductRow
     * @return array<string,mixed>
     */
    public function pushProductToWoo(array $crmProductRow): array
    {
        $this->client->assertReady();

        $wooId = (int)($crmProductRow['woo_product_id'] ?? 0);
        $payload = $this->mapper->mapCrmProductToWooUpdatePayload($crmProductRow);

        if ($wooId > 0) {
            $updated = $this->client->put("/products/{$wooId}", [], $payload);
            return [
                'mode' => 'update',
                'woo_product_id' => $wooId,
                'woo_response' => $updated,
            ];
        }

        // create
        $created = $this->client->post('/products', [], $payload);
        if (!is_array($created) || !isset($created['id'])) {
            throw new RuntimeException('Woo create product returned invalid response.');
        }

        $newWooId = (int)$created['id'];

        // You should save woo_product_id back into CRM products table:
        if (isset($crmProductRow['id']) && is_numeric($crmProductRow['id'])) {
            $this->linkCrmProductToWoo((int)$crmProductRow['id'], $newWooId);
        }

        return [
            'mode' => 'create',
            'woo_product_id' => $newWooId,
            'woo_response' => $created,
        ];
    }

    /**
     * Push one CRM variant to Woo variation (needs woo_product_id in parent)
     *
     * @param int $wooProductId
     * @param array<string,mixed> $crmVariantRow
     * @return array<string,mixed>
     */
    public function pushVariantToWoo(int $wooProductId, array $crmVariantRow): array
    {
        $this->client->assertReady();

        $payload = $this->mapper->mapCrmVariantToWooVariationPayload($crmVariantRow);
        $wooVarId = (int)($crmVariantRow['woo_variation_id'] ?? 0);

        if ($wooVarId > 0) {
            $updated = $this->client->put("/products/{$wooProductId}/variations/{$wooVarId}", [], $payload);
            return [
                'mode' => 'update',
                'woo_variation_id' => $wooVarId,
                'woo_response' => $updated,
            ];
        }

        $created = $this->client->post("/products/{$wooProductId}/variations", [], $payload);
        if (!is_array($created) || !isset($created['id'])) {
            throw new RuntimeException('Woo create variation returned invalid response.');
        }

        return [
            'mode' => 'create',
            'woo_variation_id' => (int)$created['id'],
            'woo_response' => $created,
        ];
    }

    // =============================================================================
    // Internal: Upserts (Woo -> CRM)
    // =============================================================================

    /**
     * @param array<string,mixed> $wooProduct
     * @return array<string,mixed> report info
     */
    private function upsertCrmProductFromWoo(array $wooProduct): array
    {
        $mapped = $this->mapper->mapWooProductToCrmProduct($wooProduct);
        $wooId = (int)($mapped['woo_product_id'] ?? 0);

        if ($wooId <= 0) {
            throw new RuntimeException('Woo product missing id.');
        }

        // find existing by woo_product_id OR sku
        $crmId = $this->findId('products', 'woo_product_id', $wooId);
        if ($crmId === null && !empty($mapped['sku'])) {
            $crmId = $this->findIdByString('products', 'sku', (string)$mapped['sku']);
        }

        if ($crmId !== null) {
            $this->updateRow('products', $crmId, $mapped);
            return ['action' => 'update', 'crm_id' => $crmId, 'woo_id' => $wooId];
        }

        $newId = $this->insertRow('products', $mapped);
        return ['action' => 'insert', 'crm_id' => $newId, 'woo_id' => $wooId];
    }

    /**
     * @param array<string,mixed> $wooCustomer
     */
    private function upsertCrmCustomerFromWoo(array $wooCustomer): void
    {
        $mapped = $this->mapper->mapWooCustomerToCrmCustomer($wooCustomer);
        $wooId = (int)($mapped['woo_customer_id'] ?? 0);

        if ($wooId <= 0) throw new RuntimeException('Woo customer missing id.');

        $crmId = $this->findId('customers', 'woo_customer_id', $wooId);
        if ($crmId === null && !empty($mapped['email'])) {
            $crmId = $this->findIdByString('customers', 'email', (string)$mapped['email']);
        }

        if ($crmId !== null) {
            $this->updateRow('customers', $crmId, $mapped);
            return;
        }

        $this->insertRow('customers', $mapped);
    }

    /**
     * @param array<string,mixed> $wooOrder
     */
    private function upsertCrmSaleFromWoo(array $wooOrder): void
    {
        $mapped = $this->mapper->mapWooOrderToCrmSale($wooOrder);
        $wooId = (int)($mapped['woo_order_id'] ?? 0);
        if ($wooId <= 0) throw new RuntimeException('Woo order missing id.');

        // Need a column sales.woo_order_id for clean upsert
        if (!$this->columnExists('sales', 'woo_order_id')) {
            throw new RuntimeException("Column 'sales.woo_order_id' is required for order sync upsert.");
        }

        $crmId = $this->findId('sales', 'woo_order_id', $wooId);

        if ($crmId !== null) {
            $this->updateRow('sales', $crmId, $mapped);
            return;
        }

        $this->insertRow('sales', $mapped);
    }

    /**
     * Import variations for a Woo variable product (fetch via API)
     */
    private function importVariationsForWooProduct(int $wooProductId, int $crmProductId): int
    {
        $count = 0;

        $page = 1;
        $perPage = 100;
        $maxPages = 50;

        while ($page <= $maxPages) {
            $list = $this->client->get("/products/{$wooProductId}/variations", [
                'per_page' => $perPage,
                'page' => $page,
            ]);

            if (!is_array($list) || count($list) === 0) break;

            foreach ($list as $wooVar) {
                if (!is_array($wooVar)) continue;

                $mapped = $this->mapper->mapWooVariationToCrmVariant($wooVar, $crmProductId);
                $wooVarId = (int)($mapped['woo_variation_id'] ?? 0);
                if ($wooVarId <= 0) continue;

                // upsert by woo_variation_id
                $crmVarId = $this->findId('product_variants', 'woo_variation_id', $wooVarId);
                if ($crmVarId === null && !empty($mapped['sku'])) {
                    // fallback: within same product
                    $crmVarId = $this->findVariantByProductSku($crmProductId, (string)$mapped['sku']);
                }

                if ($crmVarId !== null) {
                    $this->updateRow('product_variants', $crmVarId, $mapped);
                } else {
                    $this->insertRow('product_variants', $mapped);
                }

                $count++;
            }

            if (count($list) < $perPage) break;
            $page++;
        }

        return $count;
    }

    // =============================================================================
    // Internal: DB generic helpers
    // =============================================================================

    private function assertTable(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new RuntimeException("Required table '{$table}' does not exist.");
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

    private function findId(string $table, string $col, int $value): ?int
    {
        if (!$this->columnExists($table, $col)) return null;

        $sql = "SELECT id FROM {$table} WHERE {$col}=:v LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':v' => $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['id'] : null;
    }

    private function findIdByString(string $table, string $col, string $value): ?int
    {
        if (!$this->columnExists($table, $col)) return null;

        $sql = "SELECT id FROM {$table} WHERE {$col}=:v LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':v' => $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['id'] : null;
    }

    private function findVariantByProductSku(int $productId, string $sku): ?int
    {
        if (!$this->columnExists('product_variants', 'product_id')) return null;
        if (!$this->columnExists('product_variants', 'sku')) return null;

        $sql = "SELECT id FROM product_variants WHERE product_id=:pid AND sku=:sku LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $productId, ':sku' => $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['id'] : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertRow(string $table, array $data): int
    {
        // add timestamps if exist
        if ($this->columnExists($table, 'created_at') && !isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if ($this->columnExists($table, 'updated_at') && !isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($this->columnExists($table, 'woo_synced_at') && !isset($data['woo_synced_at'])) {
            $data['woo_synced_at'] = date('Y-m-d H:i:s');
        }

        $cols = array_keys($data);
        if (count($cols) === 0) throw new RuntimeException("Insert into {$table} with empty data.");

        $fields = implode(', ', $cols);
        $ph = implode(', ', array_map(fn($c) => ':' . $c, $cols));

        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$ph})";
        $stmt = $this->pdo->prepare($sql);

        $bind = [];
        foreach ($data as $k => $v) {
            $bind[':' . $k] = $v;
        }

        $stmt->execute($bind);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    private function updateRow(string $table, int $id, array $data): void
    {
        // Do not update id
        unset($data['id']);

        if ($this->columnExists($table, 'updated_at')) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($this->columnExists($table, 'woo_synced_at')) {
            $data['woo_synced_at'] = date('Y-m-d H:i:s');
        }

        $sets = [];
        $bind = [':id' => $id];

        foreach ($data as $k => $v) {
            if (!$this->columnExists($table, $k)) {
                // اگر ستون وجود ندارد، بی‌سروصدا ignore (چون DB شما در حال تکمیل است)
                continue;
            }
            $sets[] = "{$k}=:" . $k;
            $bind[':' . $k] = $v;
        }

        if (count($sets) === 0) return;

        $setSql = implode(', ', $sets);
        $sql = "UPDATE {$table} SET {$setSql} WHERE id=:id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
    }

    private function linkCrmProductToWoo(int $crmProductId, int $wooProductId): void
    {
        if (!$this->columnExists('products', 'woo_product_id')) {
            throw new RuntimeException("Column products.woo_product_id is required to link CRM product to Woo.");
        }

        $sql = "UPDATE products SET woo_product_id=:w, woo_synced_at=NOW(), updated_at=NOW() WHERE id=:id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':w' => $wooProductId, ':id' => $crmProductId]);
    }

    // =============================================================================
    // Report helpers
    // =============================================================================

    /**
     * @return array<string,mixed>
     */
    private function newReport(string $action): array
    {
        return [
            'action' => $action,
            'success' => false,
            'params' => [],
            'counters' => [
                'products_upserted' => 0,
                'variations_upserted' => 0,
                'customers_upserted' => 0,
                'orders_upserted' => 0,
                'errors' => 0,
            ],
            'warnings' => [],
            'errors' => [],
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
        ];
    }
}

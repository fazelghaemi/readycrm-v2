<?php
/**
 * File: app/Integrations/WooCommerce/WooOutboxPublisher.php
 *
 * CRM V2 - WooCommerce Outbox Publisher (Reliable two-way sync)
 * -----------------------------------------------------------------------------
 * این کلاس برای «Sync دوطرفه قابل اتکا» لازم است.
 *
 * مشکل رایج Sync مستقیم:
 *  - اگر در لحظه‌ی push به Woo اینترنت قطع شود/Rate limit بخورید/خطای موقت داشته باشید،
 *    تغییرات از بین می‌روند یا سیستم وارد وضعیت نامعلوم می‌شود.
 *
 * راه حل صنعتی: Outbox Pattern
 *  - هر تغییری که باید به Woo ارسال شود، ابتدا در DB داخل جدول outbox ذخیره می‌شود.
 *  - سپس یک Worker / Cron / CLI این جدول را می‌خواند و ارسال را انجام می‌دهد.
 *  - اگر ارسال شکست خورد، رکورد در outbox می‌ماند و با retry دوباره تلاش می‌شود.
 *
 * -----------------------------------------------------------------------------
 * جدول پیشنهادی: woo_outbox
 *
 * CREATE TABLE IF NOT EXISTS woo_outbox (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   event_type VARCHAR(60) NOT NULL,               -- product.upsert | variant.upsert | order.status.update | ...
 *   entity_type VARCHAR(40) NOT NULL,              -- product|variant|customer|order
 *   entity_id BIGINT NOT NULL,                     -- id داخلی CRM
 *   woo_id BIGINT NULL,                            -- اگر به Woo لینک شده
 *   payload_json MEDIUMTEXT NOT NULL,              -- payload نهایی برای Woo (یا داده‌ی خام برای mapping)
 *   status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|processing|sent|failed|dead
 *   tries INT NOT NULL DEFAULT 0,
 *   last_error TEXT NULL,
 *   locked_at DATETIME NULL,
 *   available_at DATETIME NOT NULL,
 *   sent_at DATETIME NULL,
 *   created_at DATETIME NOT NULL,
 *   updated_at DATETIME NOT NULL,
 *   KEY idx_status_available (status, available_at),
 *   KEY idx_entity (entity_type, entity_id),
 *   KEY idx_event (event_type)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * -----------------------------------------------------------------------------
 * Config (private/config.php) پیشنهادی:
 *
 * 'woocommerce' => [
 *   'enabled' => true,
 *   ...
 *   'outbox' => [
 *     'enabled' => true,
 *     'batch_size' => 20,
 *     'lock_seconds' => 120,        // زمان قفل هر job برای جلوگیری از پردازش همزمان
 *     'max_tries' => 8,             // بعد از این تعداد: dead
 *     'backoff_seconds' => [        // retry delay بر حسب tries
 *        1 => 10,
 *        2 => 30,
 *        3 => 120,
 *        4 => 600,
 *        5 => 1800,
 *        6 => 3600,
 *        7 => 7200,
 *        8 => 14400,
 *     ],
 *   ],
 * ],
 *
 * -----------------------------------------------------------------------------
 * استفاده:
 *  - هنگام تغییر محصول/وارییشن در CRM:
 *      $outbox->enqueueProductUpsert($crmProductRow);
 *      $outbox->enqueueVariantUpsert($wooProductId, $crmVariantRow);
 *
 *  - در Cron/Worker:
 *      $outbox->publishPending();
 *
 * -----------------------------------------------------------------------------
 * وابستگی‌ها:
 *  - PDO
 *  - Logger
 *  - WooClient
 *  - WooMapper
 */

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class WooOutboxPublisher
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

        $this->ensureOutboxTable();
    }

    // =============================================================================
    // Enqueue helpers (CRM -> Woo)
    // =============================================================================

    /**
     * enqueue: create/update product on Woo
     * @param array<string,mixed> $crmProductRow
     */
    public function enqueueProductUpsert(array $crmProductRow, ?int $availableAtUnix = null): int
    {
        $crmId = (int)($crmProductRow['id'] ?? 0);
        if ($crmId <= 0) {
            throw new RuntimeException('enqueueProductUpsert requires CRM product row with id.');
        }

        // payload نهایی Woo
        $payload = $this->mapper->mapCrmProductToWooUpdatePayload($crmProductRow);

        $wooId = (int)($crmProductRow['woo_product_id'] ?? 0);
        return $this->enqueue([
            'event_type' => 'product.upsert',
            'entity_type' => 'product',
            'entity_id' => $crmId,
            'woo_id' => $wooId > 0 ? $wooId : null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'available_at' => $availableAtUnix ?? time(),
        ]);
    }

    /**
     * enqueue: create/update variation on Woo
     * @param int $wooProductId
     * @param array<string,mixed> $crmVariantRow
     */
    public function enqueueVariantUpsert(int $wooProductId, array $crmVariantRow, ?int $availableAtUnix = null): int
    {
        $crmVarId = (int)($crmVariantRow['id'] ?? 0);
        if ($crmVarId <= 0) {
            throw new RuntimeException('enqueueVariantUpsert requires CRM variant row with id.');
        }
        if ($wooProductId <= 0) {
            throw new RuntimeException('enqueueVariantUpsert requires valid parent wooProductId.');
        }

        $payload = $this->mapper->mapCrmVariantToWooVariationPayload($crmVariantRow);
        $wooVarId = (int)($crmVariantRow['woo_variation_id'] ?? 0);

        // برای پردازش publish باید wooProductId هم موجود باشد:
        $payloadWrapper = [
            'woo_product_id' => $wooProductId,
            'variation_payload' => $payload,
        ];

        return $this->enqueue([
            'event_type' => 'variant.upsert',
            'entity_type' => 'variant',
            'entity_id' => $crmVarId,
            'woo_id' => $wooVarId > 0 ? $wooVarId : null,
            'payload_json' => json_encode($payloadWrapper, JSON_UNESCAPED_UNICODE),
            'available_at' => $availableAtUnix ?? time(),
        ]);
    }

    // =============================================================================
    // Publisher (Worker/Cron)
    // =============================================================================

    /**
     * Publish pending outbox records to Woo.
     *
     * @return array<string,mixed> report
     */
    public function publishPending(): array
    {
        if (!$this->outboxEnabled()) {
            return [
                'ok' => true,
                'message' => 'Outbox disabled in config',
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'dead' => 0,
            ];
        }

        $this->client->assertReady();

        $batchSize = (int)($this->config['woocommerce']['outbox']['batch_size'] ?? 20);
        if ($batchSize <= 0) $batchSize = 20;

        $lockSeconds = (int)($this->config['woocommerce']['outbox']['lock_seconds'] ?? 120);
        if ($lockSeconds <= 0) $lockSeconds = 120;

        $rows = $this->lockBatch($batchSize, $lockSeconds);

        $report = [
            'ok' => true,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'dead' => 0,
            'items' => [],
        ];

        foreach ($rows as $row) {
            $report['processed']++;

            $id = (int)$row['id'];
            $eventType = (string)$row['event_type'];
            $entityType = (string)$row['entity_type'];
            $entityId = (int)$row['entity_id'];
            $tries = (int)$row['tries'];

            try {
                $payload = json_decode((string)$row['payload_json'], true);
                if (!is_array($payload)) {
                    throw new RuntimeException('Invalid JSON payload in outbox.');
                }

                $result = $this->dispatch($eventType, $entityType, $entityId, $row, $payload);

                // mark sent
                $this->markSent($id);

                $report['sent']++;
                $report['items'][] = [
                    'id' => $id,
                    'event_type' => $eventType,
                    'status' => 'sent',
                    'result' => $this->safeResult($result),
                ];
            } catch (Throwable $e) {
                $next = $this->computeNextAvailableAt($tries + 1);
                $maxTries = (int)($this->config['woocommerce']['outbox']['max_tries'] ?? 8);
                if ($maxTries <= 0) $maxTries = 8;

                if (($tries + 1) >= $maxTries) {
                    $this->markDead($id, $e->getMessage());
                    $report['dead']++;
                    $report['items'][] = [
                        'id' => $id,
                        'event_type' => $eventType,
                        'status' => 'dead',
                        'error' => $e->getMessage(),
                    ];
                } else {
                    $this->markFailed($id, $tries + 1, $e->getMessage(), $next);
                    $report['failed']++;
                    $report['items'][] = [
                        'id' => $id,
                        'event_type' => $eventType,
                        'status' => 'failed',
                        'tries' => $tries + 1,
                        'next_retry_at' => date('Y-m-d H:i:s', $next),
                        'error' => $e->getMessage(),
                    ];
                }

                $this->logger->warning('WOO_OUTBOX_SEND_FAILED', [
                    'id' => $id,
                    'event_type' => $eventType,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'tries' => $tries + 1,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        return $report;
    }

    // =============================================================================
    // Dispatchers (send to Woo by event_type)
    // =============================================================================

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     * @return mixed
     */
    private function dispatch(string $eventType, string $entityType, int $entityId, array $row, array $payload)
    {
        // eventType routing
        switch ($eventType) {
            case 'product.upsert':
                return $this->dispatchProductUpsert($row, $payload);

            case 'variant.upsert':
                return $this->dispatchVariantUpsert($row, $payload);

            default:
                throw new RuntimeException("Unknown outbox event_type: {$eventType}");
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     * @return mixed
     */
    private function dispatchProductUpsert(array $row, array $payload)
    {
        $wooId = isset($row['woo_id']) && is_numeric($row['woo_id']) ? (int)$row['woo_id'] : 0;

        if ($wooId > 0) {
            return $this->client->put("/products/{$wooId}", [], $payload);
        }

        $created = $this->client->post("/products", [], $payload);
        if (is_array($created) && isset($created['id']) && is_numeric($created['id'])) {
            // بهتر است CRM product را به woo_product_id لینک کنید.
            // اینجا فقط تلاش می‌کنیم اگر جدول products و ستون woo_product_id هست:
            $crmProductId = (int)($row['entity_id'] ?? 0);
            $this->tryLinkCrmProductToWoo($crmProductId, (int)$created['id']);
        }

        return $created;
    }

    /**
     * Payload wrapper:
     *  [
     *    'woo_product_id' => 123,
     *    'variation_payload' => {...}
     *  ]
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     * @return mixed
     */
    private function dispatchVariantUpsert(array $row, array $payload)
    {
        $wooProductId = (int)($payload['woo_product_id'] ?? 0);
        $varPayload = $payload['variation_payload'] ?? null;

        if ($wooProductId <= 0 || !is_array($varPayload)) {
            throw new RuntimeException('variant.upsert payload is invalid (missing woo_product_id or variation_payload).');
        }

        $wooVarId = isset($row['woo_id']) && is_numeric($row['woo_id']) ? (int)$row['woo_id'] : 0;

        if ($wooVarId > 0) {
            return $this->client->put("/products/{$wooProductId}/variations/{$wooVarId}", [], $varPayload);
        }

        $created = $this->client->post("/products/{$wooProductId}/variations", [], $varPayload);

        // اگر موفق شد، می‌توانید CRM variant را به woo_variation_id لینک کنید (اختیاری)
        // اینجا فقط تلاش می‌کنیم اگر جدول product_variants و ستون woo_variation_id هست:
        if (is_array($created) && isset($created['id']) && is_numeric($created['id'])) {
            $crmVarId = (int)($row['entity_id'] ?? 0);
            $this->tryLinkCrmVariantToWoo($crmVarId, (int)$created['id']);
        }

        return $created;
    }

    // =============================================================================
    // DB: Outbox persistence
    // =============================================================================

    private function outboxEnabled(): bool
    {
        return (bool)($this->config['woocommerce']['outbox']['enabled'] ?? true);
    }

    private function ensureOutboxTable(): void
    {
        // اگر DB شما هنوز آماده نیست، این کلاس خودش جدول را می‌سازد
        try {
            $this->pdo->query("SELECT 1 FROM woo_outbox LIMIT 1");
            return;
        } catch (Throwable $e) {
            // create
        }

        $sql = "
CREATE TABLE IF NOT EXISTS woo_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT NOT NULL,
  woo_id BIGINT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  tries INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  locked_at DATETIME NULL,
  available_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_status_available (status, available_at),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_event (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        $this->pdo->exec($sql);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function enqueue(array $data): int
    {
        if (!$this->outboxEnabled()) {
            // اگر Outbox خاموش است، enqueue معنی ندارد
            throw new RuntimeException('Woo outbox is disabled in config.');
        }

        $eventType = (string)($data['event_type'] ?? '');
        $entityType = (string)($data['entity_type'] ?? '');
        $entityId = (int)($data['entity_id'] ?? 0);
        $wooId = $data['woo_id'] ?? null;

        $payloadJson = (string)($data['payload_json'] ?? '');
        $availableAtUnix = (int)($data['available_at'] ?? time());
        $availableAt = date('Y-m-d H:i:s', $availableAtUnix);

        if ($eventType === '' || $entityType === '' || $entityId <= 0 || $payloadJson === '') {
            throw new RuntimeException('Invalid outbox enqueue data.');
        }

        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            INSERT INTO woo_outbox
              (event_type, entity_type, entity_id, woo_id, payload_json, status, tries, last_error,
               locked_at, available_at, sent_at, created_at, updated_at)
            VALUES
              (:event_type, :entity_type, :entity_id, :woo_id, :payload_json, 'pending', 0, NULL,
               NULL, :available_at, NULL, :now, :now)
        ");

        $stmt->execute([
            ':event_type' => $eventType,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':woo_id' => $wooId,
            ':payload_json' => $payloadJson,
            ':available_at' => $availableAt,
            ':now' => $now,
        ]);

        $id = (int)$this->pdo->lastInsertId();

        $this->logger->info('WOO_OUTBOX_ENQUEUED', [
            'id' => $id,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'woo_id' => $wooId,
        ]);

        return $id;
    }

    /**
     * Lock a batch of rows for processing.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lockBatch(int $batchSize, int $lockSeconds): array
    {
        $now = date('Y-m-d H:i:s');
        $lockExpire = date('Y-m-d H:i:s', time() - $lockSeconds);

        // Strategy:
        // 1) select ids that are pending/failed and available_at <= now
        //    and (locked_at is null OR locked_at < lockExpire)  (stale lock)
        // 2) update them to status=processing, locked_at=now
        // 3) re-select those rows
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM woo_outbox
                WHERE
                  (status = 'pending' OR status = 'failed')
                  AND available_at <= :now
                  AND (locked_at IS NULL OR locked_at <= :lock_expire)
                ORDER BY id ASC
                LIMIT {$batchSize}
                FOR UPDATE
            ");
            $stmt->execute([':now' => $now, ':lock_expire' => $lockExpire]);

            $ids = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ids[] = (int)$r['id'];
            }

            if (count($ids) === 0) {
                $this->pdo->commit();
                return [];
            }

            // update to processing
            $in = implode(',', array_fill(0, count($ids), '?'));
            $upd = $this->pdo->prepare("
                UPDATE woo_outbox
                SET status='processing', locked_at=?, updated_at=?
                WHERE id IN ({$in})
            ");
            $params = array_merge([$now, $now], $ids);
            $upd->execute($params);

            // fetch rows
            $sel = $this->pdo->prepare("SELECT * FROM woo_outbox WHERE id IN ({$in}) ORDER BY id ASC");
            $sel->execute($ids);

            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

            $this->pdo->commit();
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function markSent(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE woo_outbox
            SET status='sent', last_error=NULL, locked_at=NULL, sent_at=:now, updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':now' => $now]);
    }

    private function markFailed(int $id, int $tries, string $error, int $nextAvailableAtUnix): void
    {
        $now = date('Y-m-d H:i:s');
        $next = date('Y-m-d H:i:s', $nextAvailableAtUnix);

        $stmt = $this->pdo->prepare("
            UPDATE woo_outbox
            SET status='failed', tries=:tries, last_error=:err, locked_at=NULL, available_at=:next, updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $id,
            ':tries' => $tries,
            ':err' => $error,
            ':next' => $next,
            ':now' => $now,
        ]);
    }

    private function markDead(int $id, string $error): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE woo_outbox
            SET status='dead', last_error=:err, locked_at=NULL, updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':err' => $error, ':now' => $now]);
    }

    // =============================================================================
    // Retry Backoff
    // =============================================================================

    private function computeNextAvailableAt(int $tries): int
    {
        $map = $this->config['woocommerce']['outbox']['backoff_seconds'] ?? [];
        $delay = null;

        if (is_array($map) && isset($map[$tries]) && is_numeric($map[$tries])) {
            $delay = (int)$map[$tries];
        }

        if ($delay === null) {
            // fallback: exponential-ish (10s, 30s, 2m, 10m, 30m, 1h, 2h, 4h)
            $fallback = [1 => 10, 2 => 30, 3 => 120, 4 => 600, 5 => 1800, 6 => 3600, 7 => 7200, 8 => 14400];
            $delay = $fallback[$tries] ?? 14400;
        }

        if ($delay < 0) $delay = 0;
        return time() + $delay;
    }

    // =============================================================================
    // Linking helpers (optional, best-effort)
    // =============================================================================

    private function tryLinkCrmProductToWoo(int $crmProductId, int $wooProductId): void
    {
        if ($crmProductId <= 0 || $wooProductId <= 0) return;

        if (!$this->tableExists('products')) return;
        if (!$this->columnExists('products', 'woo_product_id')) return;

        try {
            $stmt = $this->pdo->prepare("
                UPDATE products
                SET woo_product_id=:w, woo_synced_at=NOW(), updated_at=NOW()
                WHERE id=:id
                LIMIT 1
            ");
            $stmt->execute([':w' => $wooProductId, ':id' => $crmProductId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function tryLinkCrmVariantToWoo(int $crmVariantId, int $wooVarId): void
    {
        if ($crmVariantId <= 0 || $wooVarId <= 0) return;

        if (!$this->tableExists('product_variants')) return;
        if (!$this->columnExists('product_variants', 'woo_variation_id')) return;

        try {
            $stmt = $this->pdo->prepare("
                UPDATE product_variants
                SET woo_variation_id=:w, woo_synced_at=NOW(), updated_at=NOW()
                WHERE id=:id
                LIMIT 1
            ");
            $stmt->execute([':w' => $wooVarId, ':id' => $crmVariantId]);
        } catch (Throwable $e) {
            // ignore
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
    // Result sanitization (avoid huge logs)
    // =============================================================================

    /**
     * @param mixed $result
     * @return mixed
     */
    private function safeResult($result)
    {
        // اگر پاسخ خیلی بزرگ بود، خلاصه کن
        if (is_array($result)) {
            // keep only few keys
            $keep = ['id', 'name', 'type', 'status', 'sku', 'price', 'regular_price', 'sale_price'];
            $out = [];
            foreach ($keep as $k) {
                if (array_key_exists($k, $result)) $out[$k] = $result[$k];
            }
            // also include "message/code" if exist
            if (isset($result['message'])) $out['message'] = $result['message'];
            if (isset($result['code'])) $out['code'] = $result['code'];
            return $out;
        }
        return $result;
    }
}

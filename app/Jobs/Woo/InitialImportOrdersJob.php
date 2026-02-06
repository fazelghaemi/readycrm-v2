<?php
/**
 * File: app/Jobs/Woo/InitialImportOrdersJob.php
 *
 * CRM V2 - Job: Initial Import Orders from WooCommerce (Woo -> CRM)
 * -----------------------------------------------------------------------------
 * هدف:
 * - همگام‌سازی اولیه سفارشات ووکامرس و تبدیل آن‌ها به «فروش» داخل CRM
 * - پشتیبانی از سفارشات با روش‌های پرداخت مختلف (درگاه‌های مختلف)
 * - پشتیبانی از سفارشات guest (خیلی مهم): اگر customer_id در Woo وجود نداشت، از billing.email مشتری را upsert می‌کنیم
 * - وارد کردن line_items (اقلام سفارش) و جمع‌ها و آدرس‌ها
 * - قابلیت resume (ادامه از جایی که قطع شده)
 * - قابلیت اجرای batch/pagination
 * - قابلیت enqueue صفحه بعد
 *
 * -----------------------------------------------------------------------------
 * نکته:
 * - Woo REST endpoint سفارش‌ها: /wp-json/wc/v3/orders
 * - per_page تا 100
 * - برای کارایی: بهتر است در هر run چند صفحه محدود پردازش شود (max_pages)
 *
 * -----------------------------------------------------------------------------
 * Payload ورودی:
 *  [
 *    'site_id' => 1,
 *    'per_page' => 50,                 // 1..100
 *    'page' => 1,                      // اگر ندهید از state می‌خواند
 *    'status' => 'any',                // optional: 'any' یا لیست وضعیت‌ها: processing, completed, on-hold, ...
 *    'since' => '2025-01-01T00:00:00', // optional: modified_after / after
 *    'force' => false,                 // اگر true => حتی اگر قبلاً sync شده دوباره upsert
 *    'max_pages' => 0,                 // اگر >0 => محدودیت صفحه در همین run
 *    'enqueue_next' => true,
 *    'dry_run' => false,               // دریافت/Map بدون نوشتن DB
 *
 *    // رفتار در Guest Checkout:
 *    'ensure_customer' => true,        // اگر true => قبل از upsert order، customer را هم upsert می‌کند
 *    'customer_mode' => 'email',       // 'email'|'woo_id' (پیشنهاد: email)
 *  ]
 *
 * -----------------------------------------------------------------------------
 * پیش‌نیاز Integration Layer:
 * - App\Integrations\WooCommerce\WooCommerceClient
 *     - listOrders(array $params): array
 *     - getOrder(int $id): array (اختیاری)
 *
 * - App\Integrations\WooCommerce\WooMapper
 *     - map(string $topic, array $wooData): array
 *       topic های پیشنهادی:
 *          - 'order.import'      => تبدیل سفارش به ساختار CRM
 *          - 'customer.import'   => تبدیل مشتری (اگر بخواهیم ensure_customer انجام دهیم)
 *
 * - App\Integrations\WooCommerce\WooUpserter
 *     - apply(string $topic, array $mapped, array $context): array
 *
 * - App\Jobs\Queue (اختیاری) برای enqueue صفحه بعد
 *
 * -----------------------------------------------------------------------------
 * Resume/State:
 * - از جدول woo_sync_state استفاده می‌کنیم
 * - sync_key این Job: 'initial_orders'
 */

declare(strict_types=1);

namespace App\Jobs\Woo;

use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooMapper;
use App\Integrations\WooCommerce\WooUpserter;
use App\Jobs\Queue;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class InitialImportOrdersJob
{
    private const SYNC_KEY = 'initial_orders';

    private PDO $pdo;
    private Logger $logger;

    private WooCommerceClient $woo;
    private WooMapper $mapper;
    private WooUpserter $upserter;

    private ?Queue $queue;

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        PDO $pdo,
        Logger $logger,
        WooCommerceClient $woo,
        WooMapper $mapper,
        WooUpserter $upserter,
        array $config = [],
        ?Queue $queue = null
    ) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->woo = $woo;
        $this->mapper = $mapper;
        $this->upserter = $upserter;
        $this->config = $config;
        $this->queue = $queue;

        $this->ensureStateTableBestEffort();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $siteId = isset($payload['site_id']) ? (int)$payload['site_id'] : 1;

        $perPage = isset($payload['per_page']) ? (int)$payload['per_page'] : 50;
        $perPage = $this->clampInt($perPage, 1, 100);

        $status = isset($payload['status']) && is_string($payload['status']) ? trim($payload['status']) : 'any';
        if ($status === '') $status = 'any';

        $since = isset($payload['since']) && is_string($payload['since']) ? trim($payload['since']) : '';

        $force = (bool)($payload['force'] ?? false);
        $enqueueNext = (bool)($payload['enqueue_next'] ?? true);
        $dryRun = (bool)($payload['dry_run'] ?? false);

        $maxPages = isset($payload['max_pages']) ? (int)$payload['max_pages'] : 0;
        if ($maxPages < 0) $maxPages = 0;

        $ensureCustomer = (bool)($payload['ensure_customer'] ?? true);
        $customerMode = isset($payload['customer_mode']) && is_string($payload['customer_mode']) ? trim($payload['customer_mode']) : 'email';
        if ($customerMode === '') $customerMode = 'email';
        if (!in_array($customerMode, ['email', 'woo_id'], true)) {
            $customerMode = 'email';
        }

        $page = isset($payload['page']) ? (int)$payload['page'] : 0;
        if ($page <= 0) {
            $state = $this->loadState($siteId);
            $page = (int)($state['page'] ?? 1);
            if ($page <= 0) $page = 1;

            if ($since === '' && isset($state['since']) && is_string($state['since'])) {
                $since = trim((string)$state['since']);
            }
            if ($status === 'any' && isset($state['status']) && is_string($state['status'])) {
                $status = trim((string)$state['status']);
                if ($status === '') $status = 'any';
            }
        }

        $this->logger->info('WOO_INITIAL_ORDERS_START', [
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
            'status' => $status,
            'since' => $since,
            'force' => $force,
            'enqueue_next' => $enqueueNext,
            'dry_run' => $dryRun,
            'max_pages' => $maxPages,
            'ensure_customer' => $ensureCustomer,
            'customer_mode' => $customerMode,
        ]);

        $startedAt = time();
        $this->touchState($siteId, null, null);

        $processedOrders = 0;
        $created = 0;
        $updated = 0;
        $customersCreated = 0;
        $customersUpdated = 0;
        $failedPages = 0;

        $pagesProcessedThisRun = 0;
        $hasMore = true;

        while ($hasMore) {
            $pagesProcessedThisRun++;
            if ($maxPages > 0 && $pagesProcessedThisRun > $maxPages) {
                break;
            }

            try {
                $params = [
                    'per_page' => $perPage,
                    'page' => $page,
                    'orderby' => 'id',
                    'order' => 'asc',
                ];

                // status filter
                // Woo supports status='any' یا status='processing' و ...
                $params['status'] = $status;

                // best-effort: modified_after/after
                if ($since !== '') {
                    $params['modified_after'] = $since;
                    $params['after'] = $since;
                }

                $orders = $this->woo->listOrders($params);
                if (!is_array($orders)) {
                    throw new RuntimeException('Woo listOrders returned non-array.');
                }

                if ($orders === []) {
                    $hasMore = false;
                    break;
                }

                foreach ($orders as $o) {
                    if (!is_array($o)) continue;
                    $processedOrders++;

                    $wooOrderId = isset($o['id']) ? (int)$o['id'] : 0;

                    // 1) ensure customer
                    if ($ensureCustomer) {
                        try {
                            $cRes = $this->ensureCustomerForOrder($o, $siteId, $force, $dryRun, $customerMode);
                            $customersCreated += (int)($cRes['created'] ?? 0);
                            $customersUpdated += (int)($cRes['updated'] ?? 0);

                            // اگر upserter مشتری customer_id داخلی را برگرداند، می‌توانیم به order اضافه کنیم
                            if (isset($cRes['customer_id']) && is_numeric($cRes['customer_id'])) {
                                $o['_crm_customer_id'] = (int)$cRes['customer_id'];
                            }
                        } catch (Throwable $eCust) {
                            // عدم ساخت مشتری نباید کل order import را متوقف کند
                            $this->logger->warning('WOO_INITIAL_ORDERS_ENSURE_CUSTOMER_FAILED', [
                                'woo_order_id' => $wooOrderId,
                                'err' => $eCust->getMessage(),
                            ]);
                        }
                    }

                    // 2) map order
                    $topic = 'order.import';

                    // برای Mapper اطلاعات اضافی می‌گذاریم
                    $o['_meta'] = array_merge((array)($o['_meta'] ?? []), [
                        'woo_order_id' => $wooOrderId,
                        'site_id' => $siteId,
                    ]);

                    $mapped = $this->mapper->map($topic, $o);

                    if ($dryRun) {
                        $updated++;
                        continue;
                    }

                    $res = $this->upserter->apply($topic, $mapped, [
                        'site_id' => $siteId,
                        'mode' => 'initial_import',
                        'force' => $force,
                        'woo_order_id' => $wooOrderId,
                    ]);

                    $created += (int)($res['created'] ?? 0);
                    $updated += (int)($res['updated'] ?? 0);
                }

                // next page
                $page++;

                // save cursor
                $this->saveState($siteId, [
                    'page' => $page,
                    'since' => $since,
                    'status' => $status,
                ], null, true);
            } catch (Throwable $e) {
                $failedPages++;
                $err = $e->getMessage();

                $this->saveState($siteId, [
                    'page' => $page,
                    'since' => $since,
                    'status' => $status,
                ], $err, false);

                $this->logger->error('WOO_INITIAL_ORDERS_PAGE_FAILED', [
                    'site_id' => $siteId,
                    'page' => $page,
                    'err' => $err,
                ]);

                throw $e;
            }
        }

        $nextEnqueued = false;
        if ($enqueueNext && $this->queue !== null) {
            $nextEnqueued = $this->enqueueNextRun(
                $siteId,
                $page,
                $perPage,
                $status,
                $since,
                $force,
                $dryRun,
                $ensureCustomer,
                $customerMode
            );
        }

        $this->touchState($siteId, null, true);

        $this->logger->info('WOO_INITIAL_ORDERS_DONE', [
            'site_id' => $siteId,
            'processed_orders' => $processedOrders,
            'created' => $created,
            'updated' => $updated,
            'customers_created' => $customersCreated,
            'customers_updated' => $customersUpdated,
            'failed_pages' => $failedPages,
            'next_page' => $page,
            'next_enqueued' => $nextEnqueued,
            'duration_sec' => time() - $startedAt,
        ]);

        return [
            'ok' => true,
            'site_id' => $siteId,
            'processed_orders' => $processedOrders,
            'created' => $created,
            'updated' => $updated,
            'customers_created' => $customersCreated,
            'customers_updated' => $customersUpdated,
            'failed_pages' => $failedPages,
            'next_page' => $page,
            'next_enqueued' => $nextEnqueued,
            'duration_sec' => time() - $startedAt,
        ];
    }

    // =============================================================================
    // Ensure customer for order (Guest handling)
    // =============================================================================

    /**
     * تلاش می‌کند از روی سفارش، مشتری را پیدا/بسازد.
     * در guest checkout معمولاً Woo customer_id = 0 است.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>  ['created'=>int,'updated'=>int,'customer_id'=>int|null]
     */
    private function ensureCustomerForOrder(array $order, int $siteId, bool $force, bool $dryRun, string $mode): array
    {
        // Order->customer_id در Woo
        $wooCustomerId = isset($order['customer_id']) ? (int)$order['customer_id'] : 0;

        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $email = isset($billing['email']) ? trim((string)$billing['email']) : '';

        // اگر هیچ نشانه‌ای نداریم، کاری نمی‌کنیم
        if ($wooCustomerId <= 0 && $email === '') {
            return ['created' => 0, 'updated' => 0];
        }

        // ساخت payload مشتری برای mapper
        // نکته: Woo endpoint customers ممکنه برای guest وجود نداشته باشد،
        // پس ما یک "pseudo customer payload" بر اساس billing می‌سازیم.
        $customerPayload = [
            'id' => $wooCustomerId > 0 ? $wooCustomerId : null,
            'email' => $email !== '' ? $email : null,
            'first_name' => isset($billing['first_name']) ? (string)$billing['first_name'] : '',
            'last_name' => isset($billing['last_name']) ? (string)$billing['last_name'] : '',
            'billing' => $billing,
            'shipping' => (isset($order['shipping']) && is_array($order['shipping'])) ? $order['shipping'] : [],
            '_meta' => [
                'source' => 'order',
                'woo_customer_id' => $wooCustomerId,
                'site_id' => $siteId,
                'customer_mode' => $mode,
            ],
        ];

        // اگر mode=woo_id و wooCustomerId داریم، می‌توانیم داده کامل را از Woo بگیریم
        if ($mode === 'woo_id' && $wooCustomerId > 0) {
            try {
                $full = $this->woo->getCustomer($wooCustomerId);
                if (is_array($full) && $full !== []) {
                    $customerPayload = array_merge($customerPayload, $full);
                    $customerPayload['_meta']['source'] = 'woo_api';
                }
            } catch (Throwable $e) {
                // اگر نشد، همان pseudo را نگه می‌داریم
                $this->logger->warning('WOO_INITIAL_ORDERS_FETCH_CUSTOMER_FAILED', [
                    'woo_customer_id' => $wooCustomerId,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        $topic = 'customer.import';
        $mapped = $this->mapper->map($topic, $customerPayload);

        if ($dryRun) {
            return ['created' => 0, 'updated' => 1];
        }

        $res = $this->upserter->apply($topic, $mapped, [
            'site_id' => $siteId,
            'mode' => 'initial_import',
            'force' => $force,
            'woo_customer_id' => $wooCustomerId > 0 ? $wooCustomerId : null,
            'email' => $email !== '' ? $email : null,
            'source' => 'order',
        ]);

        // اگر upserter مشتری id داخلی را برگرداند
        $out = [
            'created' => (int)($res['created'] ?? 0),
            'updated' => (int)($res['updated'] ?? 0),
        ];
        if (isset($res['customer_id']) && is_numeric($res['customer_id'])) {
            $out['customer_id'] = (int)$res['customer_id'];
        }

        return $out;
    }

    // =============================================================================
    // Enqueue next
    // =============================================================================

    private function enqueueNextRun(
        int $siteId,
        int $page,
        int $perPage,
        string $status,
        string $since,
        bool $force,
        bool $dryRun,
        bool $ensureCustomer,
        string $customerMode
    ): bool {
        try {
            if ($page > 200000) {
                $this->logger->warning('WOO_INITIAL_ORDERS_STOP_ENQUEUE', [
                    'reason' => 'page too large',
                    'page' => $page,
                ]);
                return false;
            }

            $jobPayload = [
                'site_id' => $siteId,
                'page' => $page,
                'per_page' => $perPage,
                'status' => $status,
                'since' => $since,
                'force' => $force,
                'enqueue_next' => true,
                'dry_run' => $dryRun,
                'ensure_customer' => $ensureCustomer,
                'customer_mode' => $customerMode,
            ];

            $this->queue->push(self::class, $jobPayload, [
                'queue' => $this->config['queue']['default_queue'] ?? 'default',
                'delay_sec' => 1,
                'max_attempts' => 5,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('WOO_INITIAL_ORDERS_ENQUEUE_FAILED', ['err' => $e->getMessage()]);
            return false;
        }
    }

    // =============================================================================
    // State (woo_sync_state)
    // =============================================================================

    private function ensureStateTableBestEffort(): void
    {
        try {
            $sql = "
CREATE TABLE IF NOT EXISTS woo_sync_state (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id BIGINT NOT NULL DEFAULT 1,
  sync_key VARCHAR(80) NOT NULL,
  cursor_json MEDIUMTEXT NULL,
  last_run_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_site_key (site_id, sync_key),
  KEY idx_site (site_id),
  KEY idx_key (sync_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->logger->warning('WOO_SYNC_STATE_TABLE_CREATE_FAILED', ['err' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadState(int $siteId): array
    {
        if (!$this->tableExists('woo_sync_state')) return [];

        $stmt = $this->pdo->prepare("
            SELECT cursor_json
            FROM woo_sync_state
            WHERE site_id=:sid AND sync_key=:sk
            LIMIT 1
        ");
        $stmt->execute([':sid' => $siteId, ':sk' => self::SYNC_KEY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !isset($row['cursor_json'])) return [];

        $d = json_decode((string)$row['cursor_json'], true);
        return is_array($d) ? $d : [];
    }

    /**
     * @param array<string,mixed> $cursor
     */
    private function saveState(int $siteId, array $cursor, ?string $error, bool $success): void
    {
        if (!$this->tableExists('woo_sync_state')) return;

        $now = date('Y-m-d H:i:s');
        $cursorJson = json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($cursorJson === false) $cursorJson = '{}';

        $stmt = $this->pdo->prepare("
            INSERT INTO woo_sync_state
              (site_id, sync_key, cursor_json, last_run_at, last_success_at, last_error, created_at, updated_at)
            VALUES
              (:sid, :sk, :cjson, :lrun, :lsucc, :err, :now, :now)
            ON DUPLICATE KEY UPDATE
              cursor_json=VALUES(cursor_json),
              last_run_at=VALUES(last_run_at),
              last_success_at=VALUES(last_success_at),
              last_error=VALUES(last_error),
              updated_at=VALUES(updated_at)
        ");

        $stmt->execute([
            ':sid' => $siteId,
            ':sk' => self::SYNC_KEY,
            ':cjson' => $cursorJson,
            ':lrun' => $now,
            ':lsucc' => $success ? $now : null,
            ':err' => $error !== null ? $this->truncate($error, 8000) : null,
            ':now' => $now,
        ]);
    }

    private function touchState(int $siteId, ?string $error, ?bool $success): void
    {
        if (!$this->tableExists('woo_sync_state')) return;

        $now = date('Y-m-d H:i:s');

        $this->pdo->prepare("
            INSERT IGNORE INTO woo_sync_state (site_id, sync_key, cursor_json, created_at, updated_at)
            VALUES (:sid, :sk, '{}', :now, :now)
        ")->execute([':sid' => $siteId, ':sk' => self::SYNC_KEY, ':now' => $now]);

        $sql = "UPDATE woo_sync_state SET last_run_at=:now, updated_at=:now";
        $params = [':sid' => $siteId, ':sk' => self::SYNC_KEY, ':now' => $now];

        if ($error !== null) {
            $sql .= ", last_error=:err";
            $params[':err'] = $this->truncate($error, 8000);
        }

        if ($success === true) {
            $sql .= ", last_success_at=:succ, last_error=NULL";
            $params[':succ'] = $now;
        }

        $sql .= " WHERE site_id=:sid AND sync_key=:sk LIMIT 1";

        $this->pdo->prepare($sql)->execute($params);
    }

    // =============================================================================
    // DB helpers
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

    // =============================================================================
    // Utils
    // =============================================================================

    private function clampInt(mixed $v, int $min, int $max): int
    {
        if (!is_numeric($v)) return $min;
        $n = (int)$v;
        if ($n < $min) $n = $min;
        if ($n > $max) $n = $max;
        return $n;
    }

    private function truncate(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
    }
}

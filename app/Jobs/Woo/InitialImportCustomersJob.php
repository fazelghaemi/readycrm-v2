<?php
/**
 * File: app/Jobs/Woo/InitialImportCustomersJob.php
 *
 * CRM V2 - Job: Initial Import Customers from WooCommerce (Woo -> CRM)
 * -----------------------------------------------------------------------------
 * هدف:
 * - در همگام‌سازی اولیه (Initial Sync) مشتریان WooCommerce را وارد CRM کند
 * - قابلیت Resume داشته باشد (اگر وسط کار قطع شد، از همانجا ادامه دهد)
 * - قابلیت Batch/Pagination داشته باشد تا فشار به API و DB کنترل شود
 * - قابلیت enqueue خودکار صفحه بعد (اگر Queue در Container موجود باشد)
 *
 * -----------------------------------------------------------------------------
 * نکته بسیار مهم درباره WooCommerce:
 * - endpoint مشتریان: /wp-json/wc/v3/customers
 * - معمولاً per_page تا 100
 * - برخی فروشگاه‌ها guest checkout دارند:
 *     - مشتریان مهم ممکن است فقط در orders باشند و در customers وجود نداشته باشند
 *   پس این Job فقط «registered customers» را وارد می‌کند.
 *   برای پوشش کامل، بعداً در InitialImportOrdersJob باید از billing.email هم مشتری بسازی/آپدیت کنی.
 *
 * -----------------------------------------------------------------------------
 * Payload ورودی:
 *  [
 *    'site_id' => 1,
 *    'per_page' => 50,               // Woo per_page (1..100)
 *    'page' => 1,                    // شروع صفحه (اختیاری)
 *    'since' => '2025-01-01T00:00:00', // optional: modified_after / after
 *    'force' => false,               // اگر true => حتی اگر sync شده هم upsert
 *    'max_pages' => 0,               // اگر >0 => فقط N صفحه در همین اجرا
 *    'enqueue_next' => true,         // enqueue صفحه بعد
 *    'dry_run' => false,             // فقط دریافت/Map بدون نوشتن DB
 *  ]
 *
 * -----------------------------------------------------------------------------
 * پیش‌نیاز Integration Layer:
 * - App\Integrations\WooCommerce\WooCommerceClient
 *     - listCustomers(array $params): array
 *     - getCustomer(int $id): array   (اختیاری)
 *
 * - App\Integrations\WooCommerce\WooMapper
 *     - map(string $topic, array $wooData): array
 *       topic پیشنهادی: 'customer.import'
 *
 * - App\Integrations\WooCommerce\WooUpserter
 *     - apply(string $topic, array $mapped, array $context): array
 *
 * - (اختیاری) App\Jobs\Queue برای enqueue صفحه بعد
 *
 * -----------------------------------------------------------------------------
 * Resume/State:
 * - از جدول woo_sync_state استفاده می‌کنیم (همان که در InitialImportProductsJob ساخته شد)
 * - sync_key برای این Job: 'initial_customers'
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

final class InitialImportCustomersJob
{
    private const SYNC_KEY = 'initial_customers';

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

        $force = (bool)($payload['force'] ?? false);
        $enqueueNext = (bool)($payload['enqueue_next'] ?? true);
        $dryRun = (bool)($payload['dry_run'] ?? false);

        $maxPages = isset($payload['max_pages']) ? (int)$payload['max_pages'] : 0;
        if ($maxPages < 0) $maxPages = 0;

        $since = isset($payload['since']) && is_string($payload['since']) ? trim($payload['since']) : '';

        $page = isset($payload['page']) ? (int)$payload['page'] : 0;
        if ($page <= 0) {
            $state = $this->loadState($siteId);
            $page = (int)($state['page'] ?? 1);
            if ($page <= 0) $page = 1;

            if ($since === '' && isset($state['since']) && is_string($state['since'])) {
                $since = trim((string)$state['since']);
            }
        }

        $this->logger->info('WOO_INITIAL_CUSTOMERS_START', [
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
            'since' => $since,
            'force' => $force,
            'enqueue_next' => $enqueueNext,
            'dry_run' => $dryRun,
            'max_pages' => $maxPages,
        ]);

        $startedAt = time();
        $this->touchState($siteId, null, null);

        $processedCustomers = 0;
        $created = 0;
        $updated = 0;
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

                // best-effort: modified_after/after
                if ($since !== '') {
                    $params['modified_after'] = $since;
                    $params['after'] = $since;
                }

                $customers = $this->woo->listCustomers($params);
                if (!is_array($customers)) {
                    throw new RuntimeException('Woo listCustomers returned non-array.');
                }

                if ($customers === []) {
                    $hasMore = false;
                    break;
                }

                foreach ($customers as $c) {
                    if (!is_array($c)) continue;

                    $processedCustomers++;

                    $wooCustomerId = isset($c['id']) ? (int)$c['id'] : 0;
                    $email = isset($c['email']) ? (string)$c['email'] : '';

                    // Normalization minimal (برای Mapper)
                    $c['_meta'] = [
                        'woo_customer_id' => $wooCustomerId,
                        'email' => $email,
                    ];

                    $topic = 'customer.import';
                    $mapped = $this->mapper->map($topic, $c);

                    if ($dryRun) {
                        $updated++;
                        continue;
                    }

                    $res = $this->upserter->apply($topic, $mapped, [
                        'site_id' => $siteId,
                        'mode' => 'initial_import',
                        'force' => $force,
                        'woo_customer_id' => $wooCustomerId,
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
                ], null, true);
            } catch (Throwable $e) {
                $failedPages++;
                $err = $e->getMessage();

                $this->saveState($siteId, [
                    'page' => $page,
                    'since' => $since,
                ], $err, false);

                $this->logger->error('WOO_INITIAL_CUSTOMERS_PAGE_FAILED', [
                    'site_id' => $siteId,
                    'page' => $page,
                    'err' => $err,
                ]);

                throw $e; // let worker retry
            }
        }

        $nextEnqueued = false;
        if ($enqueueNext && $this->queue !== null) {
            $nextEnqueued = $this->enqueueNextRun($siteId, $page, $perPage, $since, $force, $dryRun);
        }

        $this->touchState($siteId, null, true);

        $this->logger->info('WOO_INITIAL_CUSTOMERS_DONE', [
            'site_id' => $siteId,
            'processed_customers' => $processedCustomers,
            'created' => $created,
            'updated' => $updated,
            'failed_pages' => $failedPages,
            'next_page' => $page,
            'next_enqueued' => $nextEnqueued,
            'duration_sec' => time() - $startedAt,
        ]);

        return [
            'ok' => true,
            'site_id' => $siteId,
            'processed_customers' => $processedCustomers,
            'created' => $created,
            'updated' => $updated,
            'failed_pages' => $failedPages,
            'next_page' => $page,
            'next_enqueued' => $nextEnqueued,
            'duration_sec' => time() - $startedAt,
        ];
    }

    // =============================================================================
    // Enqueue next
    // =============================================================================

    private function enqueueNextRun(
        int $siteId,
        int $page,
        int $perPage,
        string $since,
        bool $force,
        bool $dryRun
    ): bool {
        try {
            if ($page > 200000) {
                $this->logger->warning('WOO_INITIAL_CUSTOMERS_STOP_ENQUEUE', [
                    'reason' => 'page too large',
                    'page' => $page,
                ]);
                return false;
            }

            $jobPayload = [
                'site_id' => $siteId,
                'page' => $page,
                'per_page' => $perPage,
                'since' => $since,
                'force' => $force,
                'enqueue_next' => true,
                'dry_run' => $dryRun,
            ];

            $this->queue->push(self::class, $jobPayload, [
                'queue' => $this->config['queue']['default_queue'] ?? 'default',
                'delay_sec' => 1,
                'max_attempts' => 5,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('WOO_INITIAL_CUSTOMERS_ENQUEUE_FAILED', ['err' => $e->getMessage()]);
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

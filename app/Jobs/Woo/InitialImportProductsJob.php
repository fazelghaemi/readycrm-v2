<?php
/**
 * File: app/Jobs/Woo/InitialImportProductsJob.php
 *
 * CRM V2 - Job: Initial Import Products from WooCommerce (Woo -> CRM)
 * -----------------------------------------------------------------------------
 * هدف این Job:
 * - در فاز اولیه (Initial Sync) محصولات ووکامرس را وارد CRM کند
 * - محصولات ساده و متغیر را پشتیبانی کند
 * - برای محصولات متغیر: variationها را هم به صورت کامل وارد کند
 * - قابلیت resume داشته باشد (اگر وسط کار قطع شد، دوباره از همانجا ادامه دهد)
 *
 * ایده‌ی اجرای صحیح در پروژه شما:
 * - Controller/CLI یک Job enqueue می‌کند:
 *     $queue->push(InitialImportProductsJob::class, ['site_id'=>1, 'per_page'=>50]);
 *
 * - Worker آن را اجرا می‌کند و در هر اجرا یک batch وارد می‌کند.
 * - در پایان اگر هنوز صفحات باقی مانده باشند، Job دوباره enqueue می‌شود تا ادامه بدهد.
 *
 * -----------------------------------------------------------------------------
 * Payload ورودی:
 *  [
 *    'site_id' => 1,
 *    'per_page' => 50,              // تعداد محصولات در هر درخواست (Woo per_page)
 *    'page' => 1,                   // صفحه شروع (اگر دستی خواستی)
 *    'include_variations' => true,  // گرفتن variationها برای محصولات variable
 *    'since' => '2025-01-01T00:00:00', // optional: فقط بعد از این تاریخ (ISO8601)
 *    'force' => false,              // اگر true => حتی اگر قبلاً sync شده هم دوباره upsert کند
 *    'max_pages' => 0,              // اگر >0 => محدودیت تعداد صفحه در همین run
 *    'enqueue_next' => true,        // اگر true => خودش برای صفحه بعد job می‌سازد
 *    'dry_run' => false,            // اگر true => فقط می‌گیرد و map می‌کند، در DB نمی‌نویسد
 *  ]
 *
 * -----------------------------------------------------------------------------
 * پیش‌نیازهای Integration Layer (در پروژه شما):
 * - App\Integrations\WooCommerce\WooCommerceClient
 *     - listProducts(array $params): array
 *     - listProductVariations(int $productId, array $params): array
 *
 * - App\Integrations\WooCommerce\WooMapper
 *     - map(string $topic, array $wooData): array
 *         topic پیشنهادی: 'product.import'
 *
 * - App\Integrations\WooCommerce\WooUpserter
 *     - apply(string $topic, array $mapped, array $context): array
 *
 * - App\Jobs\Queue برای enqueue صفحه بعد (اگر Container آن را inject کند)
 *
 * اگر این کلاس‌ها هنوز وجود ندارند، طبیعی است که Job fail شود تا شما آن‌ها را بسازی.
 *
 * -----------------------------------------------------------------------------
 * Resume/State:
 * - یک جدول سبک برای وضعیت sync نگه می‌داریم: woo_sync_state
 *
 * CREATE TABLE IF NOT EXISTS woo_sync_state (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   site_id BIGINT NOT NULL DEFAULT 1,
 *   sync_key VARCHAR(80) NOT NULL,          -- e.g. 'initial_products'
 *   cursor_json MEDIUMTEXT NULL,            -- {"page": 3, "since": "..."}
 *   last_run_at DATETIME NULL,
 *   last_success_at DATETIME NULL,
 *   last_error TEXT NULL,
 *   created_at DATETIME NOT NULL,
 *   updated_at DATETIME NOT NULL,
 *   UNIQUE KEY uq_site_key (site_id, sync_key)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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

final class InitialImportProductsJob
{
    private const SYNC_KEY = 'initial_products';

    private PDO $pdo;
    private Logger $logger;

    private WooCommerceClient $woo;
    private WooMapper $mapper;
    private WooUpserter $upserter;

    // Queue اختیاری است: اگر موجود باشد، خود Job می‌تواند صفحه بعد را enqueue کند
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

        $includeVariations = (bool)($payload['include_variations'] ?? true);
        $force = (bool)($payload['force'] ?? false);
        $enqueueNext = (bool)($payload['enqueue_next'] ?? true);
        $dryRun = (bool)($payload['dry_run'] ?? false);

        $maxPages = isset($payload['max_pages']) ? (int)$payload['max_pages'] : 0;
        if ($maxPages < 0) $maxPages = 0;

        // since: اگر داده شد، فقط محصولاتی که بعد از آن تغییر کرده‌اند
        $since = isset($payload['since']) && is_string($payload['since']) ? trim($payload['since']) : '';

        // page: اگر payload page دارد، اولویت با آن است؛ در غیر اینصورت از state استفاده می‌کنیم
        $page = isset($payload['page']) ? (int)$payload['page'] : 0;
        if ($page <= 0) {
            $state = $this->loadState($siteId);
            $page = (int)($state['page'] ?? 1);
            if ($page <= 0) $page = 1;

            if ($since === '' && isset($state['since']) && is_string($state['since'])) {
                $since = trim((string)$state['since']);
            }
        }

        $this->logger->info('WOO_INITIAL_PRODUCTS_START', [
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
            'since' => $since,
            'include_variations' => $includeVariations,
            'force' => $force,
            'enqueue_next' => $enqueueNext,
            'dry_run' => $dryRun,
            'max_pages' => $maxPages,
        ]);

        $startedAt = time();
        $this->touchState($siteId, null, null);

        $processedProducts = 0;
        $processedVariations = 0;
        $created = 0;
        $updated = 0;
        $failed = 0;

        $pagesProcessedThisRun = 0;
        $hasMore = true;

        while ($hasMore) {
            $pagesProcessedThisRun++;
            if ($maxPages > 0 && $pagesProcessedThisRun > $maxPages) {
                // stop loop for this run
                break;
            }

            try {
                // 1) Fetch a page from Woo
                $params = [
                    'per_page' => $perPage,
                    'page' => $page,
                    'orderby' => 'id',
                    'order' => 'asc',
                ];

                // Woo API supports "modified_after" (WC REST), but some wrappers may use different key.
                // ما به صورت best-effort دو کلید می‌گذاریم.
                if ($since !== '') {
                    $params['modified_after'] = $since;
                    $params['after'] = $since; // fallback if wrapper uses 'after'
                }

                $products = $this->woo->listProducts($params);

                if (!is_array($products)) {
                    throw new RuntimeException('Woo listProducts returned non-array.');
                }

                // اگر صفحه خالی شد یعنی تمام
                if ($products === []) {
                    $hasMore = false;
                    break;
                }

                // 2) For each product, optionally fetch variations, then upsert
                foreach ($products as $p) {
                    if (!is_array($p)) continue;

                    $processedProducts++;

                    $wooProductId = isset($p['id']) ? (int)$p['id'] : 0;
                    $type = isset($p['type']) ? (string)$p['type'] : '';

                    // variations
                    if ($includeVariations && $type === 'variable' && $wooProductId > 0) {
                        try {
                            $vars = $this->fetchAllVariationsForProduct($wooProductId);
                            $p['variations_full'] = $vars;
                            $processedVariations += count($vars);
                        } catch (Throwable $eVar) {
                            // variations fail should not stop importing product record itself
                            $this->logger->warning('WOO_INITIAL_PRODUCTS_VARIATIONS_FAILED', [
                                'woo_product_id' => $wooProductId,
                                'err' => $eVar->getMessage(),
                            ]);
                        }
                    }

                    // map -> apply
                    $topic = 'product.import';
                    $mapped = $this->mapper->map($topic, $p);

                    if ($dryRun) {
                        // فقط count می‌کنیم
                        $updated++;
                        continue;
                    }

                    $res = $this->upserter->apply($topic, $mapped, [
                        'site_id' => $siteId,
                        'mode' => 'initial_import',
                        'force' => $force,
                        'woo_product_id' => $wooProductId,
                    ]);

                    // تلاش برای جمع زدن نتیجه
                    $created += (int)($res['created'] ?? 0);
                    $updated += (int)($res['updated'] ?? 0);
                }

                // 3) Next page
                $page++;

                // Save cursor state after each successful page
                $this->saveState($siteId, [
                    'page' => $page,
                    'since' => $since,
                ], null, true);
            } catch (Throwable $e) {
                $failed++;
                $err = $e->getMessage();

                $this->saveState($siteId, [
                    'page' => $page,
                    'since' => $since,
                ], $err, false);

                $this->logger->error('WOO_INITIAL_PRODUCTS_PAGE_FAILED', [
                    'site_id' => $siteId,
                    'page' => $page,
                    'err' => $err,
                ]);

                // throw so queue retries (safer)
                throw $e;
            }
        }

        // If we stopped because maxPages, we probably have more; or if last page was non-empty, we may have more.
        // Best effort: if enqueueNext enabled and Queue is available => enqueue next run.
        $nextEnqueued = false;

        if ($enqueueNext && $this->queue !== null) {
            // enqueue next job run from current cursor state
            $nextEnqueued = $this->enqueueNextRun($siteId, $page, $perPage, $since, $includeVariations, $force, $dryRun);
        }

        $this->touchState($siteId, null, true);

        $this->logger->info('WOO_INITIAL_PRODUCTS_DONE', [
            'site_id' => $siteId,
            'processed_products' => $processedProducts,
            'processed_variations' => $processedVariations,
            'created' => $created,
            'updated' => $updated,
            'failed_pages' => $failed,
            'next_page' => $page,
            'next_enqueued' => $nextEnqueued,
            'duration_sec' => time() - $startedAt,
        ]);

        return [
            'ok' => true,
            'site_id' => $siteId,
            'processed_products' => $processedProducts,
            'processed_variations' => $processedVariations,
            'created' => $created,
            'updated' => $updated,
            'failed_pages' => $failed,
            'next_page' => $page,
            'next_enqueued' => $nextEnqueued,
            'duration_sec' => time() - $startedAt,
        ];
    }

    // =============================================================================
    // Variations fetch
    // =============================================================================

    /**
     * Fetch variations for a variable product. Handles pagination (per_page=100).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchAllVariationsForProduct(int $wooProductId): array
    {
        $all = [];
        $page = 1;
        $perPage = 100;

        while (true) {
            $vars = $this->woo->listProductVariations($wooProductId, [
                'per_page' => $perPage,
                'page' => $page,
                'orderby' => 'id',
                'order' => 'asc',
            ]);

            if (!is_array($vars) || $vars === []) {
                break;
            }

            foreach ($vars as $v) {
                if (is_array($v)) $all[] = $v;
            }

            if (count($vars) < $perPage) {
                break;
            }

            $page++;
            if ($page > 200) {
                // safety guard
                break;
            }
        }

        return $all;
    }

    // =============================================================================
    // Enqueue next
    // =============================================================================

    private function enqueueNextRun(
        int $siteId,
        int $page,
        int $perPage,
        string $since,
        bool $includeVariations,
        bool $force,
        bool $dryRun
    ): bool {
        try {
            // اگر صفحه خیلی بزرگ شد، بهتر است stop کنیم مگر اینکه شما واقعاً فروشگاه عظیم دارید.
            if ($page > 200000) {
                $this->logger->warning('WOO_INITIAL_PRODUCTS_STOP_ENQUEUE', [
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
                'include_variations' => $includeVariations,
                'force' => $force,
                'enqueue_next' => true,
                'dry_run' => $dryRun,
                // برای اینکه هر run خیلی طول نکشد، می‌توانید max_pages بگذارید:
                // 'max_pages' => 5
            ];

            $this->queue->push(self::class, $jobPayload, [
                'queue' => $this->config['queue']['default_queue'] ?? 'default',
                'delay_sec' => 1,        // خیلی کم تا پشت سر هم اجرا شوند
                'max_attempts' => 5,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('WOO_INITIAL_PRODUCTS_ENQUEUE_FAILED', ['err' => $e->getMessage()]);
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
            // اگر نتوانست بسازد، باز هم Job کار می‌کند ولی resume state نداریم
            $this->logger->warning('WOO_SYNC_STATE_TABLE_CREATE_FAILED', ['err' => $e->getMessage()]);
        }
    }

    /**
     * Load cursor state.
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
     * Save cursor state + last_error.
     *
     * @param array<string,mixed> $cursor
     */
    private function saveState(int $siteId, array $cursor, ?string $error, bool $success): void
    {
        if (!$this->tableExists('woo_sync_state')) return;

        $now = date('Y-m-d H:i:s');
        $cursorJson = json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($cursorJson === false) $cursorJson = '{}';

        // Upsert
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

    /**
     * Update last_run_at / last_success_at quickly.
     */
    private function touchState(int $siteId, ?string $error, ?bool $success): void
    {
        if (!$this->tableExists('woo_sync_state')) return;

        $now = date('Y-m-d H:i:s');

        // Ensure row exists
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

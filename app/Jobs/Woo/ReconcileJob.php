<?php
/**
 * File: app/Jobs/Woo/ReconcileJob.php
 *
 * CRM V2 - Job: Reconcile WooCommerce <-> CRM (Consistency Check & Repair)
 * -----------------------------------------------------------------------------
 * این Job وظیفه دارد به صورت دوره‌ای یا دستی:
 * - اختلاف بین CRM و WooCommerce را تشخیص دهد
 * - عملیات اصلاحی انجام دهد (Repair)
 *
 * چرا لازم است؟
 * - ممکن است وبهوک‌ها از دست بروند
 * - ممکن است Outbox به دلیل خطا/Rate limit عقب بماند
 * - ممکن است تغییرات دستی در Woo انجام شود
 * - ممکن است CRM در حال refactor باشد و داده‌ها inconsistent شوند
 *
 * -----------------------------------------------------------------------------
 * این Job "بهترین جای ممکن" برای هوشمندسازی با AI هم هست:
 * - تحلیل اختلاف‌ها
 * - پیشنهاد علت اختلاف
 * - ساخت گزارش مدیریتی
 *
 * -----------------------------------------------------------------------------
 * Payload ورودی:
 *  [
 *    'site_id' => 1,
 *    'mode' => 'products'|'customers'|'orders'|'all',
 *    'limit' => 100,                   // تعداد رکوردهایی که بررسی می‌کند
 *    'repair' => true,                 // اگر true => اصلاح انجام می‌دهد
 *    'dry_run' => false,               // اگر true => فقط گزارش، بدون تغییر
 *    'strategy' => 'woo_wins'|'crm_wins'|'merge',
 *    'scan_window_days' => 30,         // فقط رکوردهای 30 روز اخیر بررسی شوند (برای کارایی)
 *
 *    // اختیاری: اگر خواستی فقط یک entity خاص را بررسی کند
 *    'entity_type' => 'product'|'customer'|'order',
 *    'entity_id' => 123,
 *  ]
 *
 * -----------------------------------------------------------------------------
 * پیش‌نیاز Integration Layer:
 * - WooCommerceClient:
 *     - getProduct(id), getCustomer(id), getOrder(id)
 *     - listProducts/listCustomers/listOrders (اختیاری)
 *
 * - WooMapper:
 *     - map('product.import'/'customer.import'/'order.import', $wooData)
 *
 * - WooUpserter:
 *     - apply('product.import'/'customer.import'/'order.import', $mapped, $context)
 *
 * - WooOutboxPublisher (اختیاری):
 *     - برای سناریو crm_wins: ارسال تغییر CRM به Woo
 *
 * -----------------------------------------------------------------------------
 * جدول کمکی پیشنهادی برای گزارش reconcile:
 *
 * CREATE TABLE IF NOT EXISTS woo_reconcile_reports (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   site_id BIGINT NOT NULL DEFAULT 1,
 *   mode VARCHAR(30) NOT NULL,
 *   strategy VARCHAR(30) NOT NULL,
 *   repair TINYINT(1) NOT NULL DEFAULT 0,
 *   dry_run TINYINT(1) NOT NULL DEFAULT 0,
 *   summary_json MEDIUMTEXT NULL,
 *   created_at DATETIME NOT NULL,
 *   KEY idx_site (site_id),
 *   KEY idx_mode (mode),
 *   KEY idx_created (created_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

declare(strict_types=1);

namespace App\Jobs\Woo;

use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooMapper;
use App\Integrations\WooCommerce\WooOutboxPublisher;
use App\Integrations\WooCommerce\WooUpserter;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class ReconcileJob
{
    private PDO $pdo;
    private Logger $logger;

    private WooCommerceClient $woo;
    private WooMapper $mapper;
    private WooUpserter $upserter;

    // اختیاری: اگر strategy=crm_wins باشد، نیاز به publisher داریم
    private ?WooOutboxPublisher $publisher;

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
        ?WooOutboxPublisher $publisher = null
    ) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->woo = $woo;
        $this->mapper = $mapper;
        $this->upserter = $upserter;
        $this->config = $config;
        $this->publisher = $publisher;

        $this->ensureReportTableBestEffort();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $siteId = isset($payload['site_id']) ? (int)$payload['site_id'] : 1;

        $mode = isset($payload['mode']) && is_string($payload['mode']) ? trim($payload['mode']) : 'all';
        if ($mode === '') $mode = 'all';

        $limit = isset($payload['limit']) ? (int)$payload['limit'] : 100;
        $limit = $this->clampInt($limit, 1, 2000);

        $repair = (bool)($payload['repair'] ?? true);
        $dryRun = (bool)($payload['dry_run'] ?? false);

        $strategy = isset($payload['strategy']) && is_string($payload['strategy']) ? trim($payload['strategy']) : 'woo_wins';
        if ($strategy === '') $strategy = 'woo_wins';
        if (!in_array($strategy, ['woo_wins', 'crm_wins', 'merge'], true)) {
            $strategy = 'woo_wins';
        }

        $scanWindowDays = isset($payload['scan_window_days']) ? (int)$payload['scan_window_days'] : 30;
        $scanWindowDays = $this->clampInt($scanWindowDays, 1, 3650);

        $entityType = isset($payload['entity_type']) && is_string($payload['entity_type']) ? trim($payload['entity_type']) : '';
        $entityId = isset($payload['entity_id']) ? (int)$payload['entity_id'] : 0;

        // اگر entity خاص داده شد، mode را override می‌کنیم
        if ($entityType !== '' && $entityId > 0) {
            $mode = $entityType . 's';
        }

        $this->logger->info('WOO_RECONCILE_START', [
            'site_id' => $siteId,
            'mode' => $mode,
            'limit' => $limit,
            'strategy' => $strategy,
            'repair' => $repair,
            'dry_run' => $dryRun,
            'scan_window_days' => $scanWindowDays,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        $startedAt = time();

        $summary = [
            'site_id' => $siteId,
            'mode' => $mode,
            'strategy' => $strategy,
            'repair' => $repair,
            'dry_run' => $dryRun,
            'scan_window_days' => $scanWindowDays,
            'checked' => 0,
            'fixed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        try {
            if ($entityType !== '' && $entityId > 0) {
                $res = $this->reconcileSingle($siteId, $entityType, $entityId, $strategy, $repair, $dryRun);
                $summary['checked'] = 1;
                $summary['fixed'] = (int)($res['fixed'] ?? 0);
                $summary['skipped'] = (int)($res['skipped'] ?? 0);
                $summary['errors'] = (int)($res['errors'] ?? 0);
                $summary['details'][] = $res;
            } else {
                // reconcile by mode
                if ($mode === 'all' || $mode === 'products') {
                    $r = $this->reconcileProducts($siteId, $limit, $scanWindowDays, $strategy, $repair, $dryRun);
                    $summary = $this->mergeSummary($summary, $r);
                }
                if ($mode === 'all' || $mode === 'customers') {
                    $r = $this->reconcileCustomers($siteId, $limit, $scanWindowDays, $strategy, $repair, $dryRun);
                    $summary = $this->mergeSummary($summary, $r);
                }
                if ($mode === 'all' || $mode === 'orders') {
                    $r = $this->reconcileOrders($siteId, $limit, $scanWindowDays, $strategy, $repair, $dryRun);
                    $summary = $this->mergeSummary($summary, $r);
                }
            }

            $summary['duration_sec'] = time() - $startedAt;

            $this->saveReport($siteId, $mode, $strategy, $repair, $dryRun, $summary);

            $this->logger->info('WOO_RECONCILE_DONE', [
                'site_id' => $siteId,
                'checked' => $summary['checked'],
                'fixed' => $summary['fixed'],
                'errors' => $summary['errors'],
                'duration_sec' => $summary['duration_sec'],
            ]);

            return [
                'ok' => true,
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            $summary['duration_sec'] = time() - $startedAt;
            $summary['fatal_error'] = $e->getMessage();

            $this->saveReport($siteId, $mode, $strategy, $repair, $dryRun, $summary);

            $this->logger->error('WOO_RECONCILE_FATAL', [
                'site_id' => $siteId,
                'err' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // =============================================================================
    // Reconcile single entity
    // =============================================================================

    /**
     * @return array<string,mixed>
     */
    private function reconcileSingle(int $siteId, string $entityType, int $entityId, string $strategy, bool $repair, bool $dryRun): array
    {
        switch ($entityType) {
            case 'product':
                return $this->reconcileByWooId('product', $entityId, $siteId, $strategy, $repair, $dryRun);

            case 'customer':
                return $this->reconcileByWooId('customer', $entityId, $siteId, $strategy, $repair, $dryRun);

            case 'order':
                return $this->reconcileByWooId('order', $entityId, $siteId, $strategy, $repair, $dryRun);

            default:
                throw new RuntimeException("Unknown entity_type: {$entityType}");
        }
    }

    /**
     * Reconcile by Woo ID: fetch from Woo and upsert to CRM (woo_wins/merge),
     * or publish CRM changes to Woo (crm_wins).
     *
     * @return array<string,mixed>
     */
    private function reconcileByWooId(string $resource, int $wooId, int $siteId, string $strategy, bool $repair, bool $dryRun): array
    {
        $fixed = 0;
        $skipped = 0;
        $errors = 0;
        $notes = [];

        try {
            if ($strategy === 'crm_wins') {
                // CRM -> Woo
                if ($this->publisher === null) {
                    throw new RuntimeException('publisher not available for crm_wins strategy');
                }

                // برای crm_wins باید entity داخلی را پیدا کنیم (مثلاً product by woo_product_id)
                $crmEntity = $this->findCrmEntityByWooId($resource, $wooId);
                if (!$crmEntity) {
                    $skipped++;
                    $notes[] = "CRM {$resource} not found for woo_id={$wooId}";
                } else {
                    if ($dryRun) {
                        $skipped++;
                        $notes[] = "dry_run: would publish CRM->{$resource} to Woo";
                    } else {
                        $pub = $this->publisher->publish($resource, (int)$crmEntity['id'], 'upsert', [], [
                            'site_id' => $siteId,
                            'reason' => 'reconcile_crm_wins',
                            'woo_id' => $wooId,
                        ]);
                        if ((bool)($pub['ok'] ?? false)) {
                            $fixed++;
                            $notes[] = "published CRM->{$resource} to Woo";
                        } else {
                            throw new RuntimeException((string)($pub['message'] ?? 'publish failed'));
                        }
                    }
                }

                return [
                    'resource' => $resource,
                    'woo_id' => $wooId,
                    'strategy' => $strategy,
                    'fixed' => $fixed,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'notes' => $notes,
                ];
            }

            // woo_wins یا merge: Woo -> CRM (upsert)
            $wooData = $this->fetchWooResource($resource, $wooId);
            if (!$wooData) {
                $skipped++;
                $notes[] = "Woo {$resource} not found: id={$wooId}";
                return [
                    'resource' => $resource,
                    'woo_id' => $wooId,
                    'strategy' => $strategy,
                    'fixed' => 0,
                    'skipped' => 1,
                    'errors' => 0,
                    'notes' => $notes,
                ];
            }

            $topic = $resource . '.import';
            $mapped = $this->mapper->map($topic, $wooData);

            if (!$repair || $dryRun) {
                $skipped++;
                $notes[] = ($dryRun ? 'dry_run: ' : '') . "would upsert Woo->{$resource} into CRM";
            } else {
                $res = $this->upserter->apply($topic, $mapped, [
                    'site_id' => $siteId,
                    'mode' => 'reconcile',
                    'force' => true,
                    'woo_' . $resource . '_id' => $wooId,
                ]);

                $delta = (int)($res['created'] ?? 0) + (int)($res['updated'] ?? 0);
                if ($delta > 0) {
                    $fixed++;
                    $notes[] = "upserted Woo->{$resource} into CRM (delta={$delta})";
                } else {
                    $skipped++;
                    $notes[] = "no changes detected for {$resource} woo_id={$wooId}";
                }
            }
        } catch (Throwable $e) {
            $errors++;
            $notes[] = "error: " . $e->getMessage();
        }

        return [
            'resource' => $resource,
            'woo_id' => $wooId,
            'strategy' => $strategy,
            'fixed' => $fixed,
            'skipped' => $skipped,
            'errors' => $errors,
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchWooResource(string $resource, int $wooId): ?array
    {
        switch ($resource) {
            case 'product':
                return $this->woo->getProduct($wooId);

            case 'customer':
                return $this->woo->getCustomer($wooId);

            case 'order':
                return $this->woo->getOrder($wooId);

            default:
                return null;
        }
    }

    // =============================================================================
    // Reconcile bulk by scanning CRM (best-effort)
    // =============================================================================

    /**
     * @return array<string,mixed>
     */
    private function reconcileProducts(int $siteId, int $limit, int $windowDays, string $strategy, bool $repair, bool $dryRun): array
    {
        $rows = $this->selectRecentCrmRows('products', $limit, $windowDays);

        return $this->reconcileByCrmRows('product', $siteId, $rows, $strategy, $repair, $dryRun);
    }

    /**
     * @return array<string,mixed>
     */
    private function reconcileCustomers(int $siteId, int $limit, int $windowDays, string $strategy, bool $repair, bool $dryRun): array
    {
        $rows = $this->selectRecentCrmRows('customers', $limit, $windowDays);

        return $this->reconcileByCrmRows('customer', $siteId, $rows, $strategy, $repair, $dryRun);
    }

    /**
     * @return array<string,mixed>
     */
    private function reconcileOrders(int $siteId, int $limit, int $windowDays, string $strategy, bool $repair, bool $dryRun): array
    {
        $rows = $this->selectRecentCrmRows('orders', $limit, $windowDays);

        return $this->reconcileByCrmRows('order', $siteId, $rows, $strategy, $repair, $dryRun);
    }

    /**
     * Generic reconcile scan:
     * - for each CRM row find woo_id column
     * - fetch from Woo
     * - compare quickly (hash) and optionally repair
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function reconcileByCrmRows(string $resource, int $siteId, array $rows, string $strategy, bool $repair, bool $dryRun): array
    {
        $checked = 0;
        $fixed = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        $wooIdCol = 'woo_' . $resource . '_id';

        foreach ($rows as $r) {
            $checked++;
            $crmId = (int)($r['id'] ?? 0);
            $wooId = isset($r[$wooIdCol]) ? (int)$r[$wooIdCol] : 0;

            if ($crmId <= 0 || $wooId <= 0) {
                $skipped++;
                continue;
            }

            $one = $this->reconcileByWooId($resource, $wooId, $siteId, $strategy, $repair, $dryRun);

            // count fixed/errors
            $fixed += (int)($one['fixed'] ?? 0);
            $skipped += (int)($one['skipped'] ?? 0);
            $errors += (int)($one['errors'] ?? 0);

            if (count($details) < 50) {
                $details[] = array_merge($one, ['crm_id' => $crmId]);
            }
        }

        return [
            'checked' => $checked,
            'fixed' => $fixed,
            'skipped' => $skipped,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    /**
     * Best-effort select recent rows from CRM table
     *
     * @return array<int,array<string,mixed>>
     */
    private function selectRecentCrmRows(string $table, int $limit, int $windowDays): array
    {
        if (!$this->tableExists($table)) return [];

        $limit = $this->clampInt($limit, 1, 2000);
        $since = date('Y-m-d H:i:s', time() - ($windowDays * 86400));

        $col = $this->columnExists($table, 'updated_at') ? 'updated_at' : 'created_at';

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM {$table}
            WHERE {$col} >= :since
            ORDER BY {$col} DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':since' => $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    // =============================================================================
    // CRM lookup (for crm_wins)
    // =============================================================================

    /**
     * Find CRM entity row by woo_id.
     * @return array<string,mixed>|null
     */
    private function findCrmEntityByWooId(string $resource, int $wooId): ?array
    {
        $table = $resource . 's'; // products/customers/orders
        $wooIdCol = 'woo_' . $resource . '_id';

        if (!$this->tableExists($table)) return null;
        if (!$this->columnExists($table, $wooIdCol)) return null;

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM {$table}
            WHERE {$wooIdCol} = :wid
            LIMIT 1
        ");
        $stmt->execute([':wid' => $wooId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // =============================================================================
    // Report persistence
    // =============================================================================

    private function ensureReportTableBestEffort(): void
    {
        try {
            $sql = "
CREATE TABLE IF NOT EXISTS woo_reconcile_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id BIGINT NOT NULL DEFAULT 1,
  mode VARCHAR(30) NOT NULL,
  strategy VARCHAR(30) NOT NULL,
  repair TINYINT(1) NOT NULL DEFAULT 0,
  dry_run TINYINT(1) NOT NULL DEFAULT 0,
  summary_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_site (site_id),
  KEY idx_mode (mode),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->logger->warning('WOO_RECONCILE_REPORT_TABLE_CREATE_FAILED', ['err' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function saveReport(int $siteId, string $mode, string $strategy, bool $repair, bool $dryRun, array $summary): void
    {
        if (!$this->tableExists('woo_reconcile_reports')) return;

        $now = date('Y-m-d H:i:s');
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) $json = '{}';

        $stmt = $this->pdo->prepare("
            INSERT INTO woo_reconcile_reports
              (site_id, mode, strategy, repair, dry_run, summary_json, created_at)
            VALUES
              (:sid, :mode, :strategy, :repair, :dry, :json, :now)
        ");
        $stmt->execute([
            ':sid' => $siteId,
            ':mode' => $mode,
            ':strategy' => $strategy,
            ':repair' => $repair ? 1 : 0,
            ':dry' => $dryRun ? 1 : 0,
            ':json' => $json,
            ':now' => $now,
        ]);
    }

    // =============================================================================
    // Summary utils
    // =============================================================================

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $add
     * @return array<string,mixed>
     */
    private function mergeSummary(array $base, array $add): array
    {
        $base['checked'] += (int)($add['checked'] ?? 0);
        $base['fixed'] += (int)($add['fixed'] ?? 0);
        $base['skipped'] += (int)($add['skipped'] ?? 0);
        $base['errors'] += (int)($add['errors'] ?? 0);

        if (isset($add['details']) && is_array($add['details'])) {
            foreach ($add['details'] as $d) {
                if (count($base['details']) >= 80) break;
                $base['details'][] = $d;
            }
        }

        return $base;
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
}

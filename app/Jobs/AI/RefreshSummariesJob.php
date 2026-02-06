<?php
/**
 * File: app/Jobs/AI/RefreshSummariesJob.php
 *
 * CRM V2 - Job: Refresh AI Summaries
 * -----------------------------------------------------------------------------
 * این Job برای ساخت/به‌روزرسانی «خلاصه‌های هوشمند» به صورت دوره‌ای است:
 *
 * مثال‌ها:
 * - خلاصه پروفایل مشتری (Customer 360 Summary)
 * - خلاصه محصول (Product Summary)
 * - خلاصه وضعیت فروش/سفارش (Sales Snapshot)
 *
 * چرا مهم است؟
 * - شما می‌خوای CRM شبیه حسابداری/مدیریت فروش باشد.
 * - AI باید خروجی «عملیاتی» بدهد، نه اینکه هر بار از صفر همه چیز را تحلیل کند.
 * - پس خلاصه‌های آماده در DB نگه می‌داریم و UI سریع نمایش می‌دهد.
 *
 * -----------------------------------------------------------------------------
 * اجرای نمونه:
 *  - enqueue این job روزانه/هر چند ساعت:
 *      $queue->push(RefreshSummariesJob::class, ['mode'=>'customers','limit'=>50]);
 *
 * payload:
 *  - mode: 'customers'|'products'|'orders'
 *  - limit: تعداد رکوردها
 *  - since_days: فقط رکوردهای تغییر کرده در N روز اخیر
 *  - force: اگر true باشد حتی اگر تازه هست هم دوباره بسازد
 *
 * -----------------------------------------------------------------------------
 * جدول پیشنهادی ai_summaries:
 *
 * CREATE TABLE IF NOT EXISTS ai_summaries (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   subject_type VARCHAR(40) NOT NULL,     -- customer|product|order
 *   subject_id BIGINT NOT NULL,
 *   summary_key VARCHAR(120) NOT NULL,     -- مثلا customer.profile.summarize
 *   summary_text MEDIUMTEXT NULL,
 *   summary_json MEDIUMTEXT NULL,
 *   warnings_json MEDIUMTEXT NULL,
 *   provider VARCHAR(80) NULL,
 *   model VARCHAR(120) NULL,
 *   refreshed_at DATETIME NULL,
 *   created_at DATETIME NOT NULL,
 *   updated_at DATETIME NOT NULL,
 *   UNIQUE KEY uq_subject_key (subject_type, subject_id, summary_key),
 *   KEY idx_subject (subject_type, subject_id),
 *   KEY idx_refreshed (refreshed_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * -----------------------------------------------------------------------------
 * نکته:
 * - این Job طوری نوشته شده که اگر جدول‌های CRM شما هنوز کامل نیست، crash نکند:
 *   - best-effort برای خواندن customers/products/orders
 *   - اگر جدول وجود نداشت، warning و ادامه
 */

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Integrations\GapGPT\AIScenarios;
use App\Integrations\GapGPT\GapGPTClient;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class RefreshSummariesJob
{
    private PDO $pdo;
    private Logger $logger;
    private GapGPTClient $gapgpt;

    /** @var array<string,mixed> */
    private array $config;

    public function __construct(PDO $pdo, Logger $logger, GapGPTClient $gapgpt, array $config = [])
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->gapgpt = $gapgpt;
        $this->config = $config;

        $this->ensureTablesBestEffort();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $mode = isset($payload['mode']) ? (string)$payload['mode'] : 'customers';
        $limit = isset($payload['limit']) ? (int)$payload['limit'] : 50;
        $sinceDays = isset($payload['since_days']) ? (int)$payload['since_days'] : 7;
        $force = (bool)($payload['force'] ?? false);

        if ($limit <= 0) $limit = 50;
        if ($sinceDays <= 0) $sinceDays = 7;

        $this->logger->info('AI_REFRESH_SUMMARIES_START', [
            'mode' => $mode,
            'limit' => $limit,
            'since_days' => $sinceDays,
            'force' => $force,
        ]);

        $startedAt = time();

        $processed = 0;
        $ok = 0;
        $failed = 0;
        $items = [];

        try {
            switch ($mode) {
                case 'customers':
                    [$processed, $ok, $failed, $items] = $this->processCustomers($limit, $sinceDays, $force);
                    break;

                case 'products':
                    [$processed, $ok, $failed, $items] = $this->processProducts($limit, $sinceDays, $force);
                    break;

                case 'orders':
                    [$processed, $ok, $failed, $items] = $this->processOrders($limit, $sinceDays, $force);
                    break;

                default:
                    throw new RuntimeException("Unknown mode: {$mode}");
            }

            $this->logger->info('AI_REFRESH_SUMMARIES_DONE', [
                'mode' => $mode,
                'processed' => $processed,
                'ok' => $ok,
                'failed' => $failed,
                'duration_sec' => time() - $startedAt,
            ]);

            return [
                'ok' => true,
                'mode' => $mode,
                'processed' => $processed,
                'ok_count' => $ok,
                'failed_count' => $failed,
                'duration_sec' => time() - $startedAt,
                'items' => $items,
            ];
        } catch (Throwable $e) {
            $this->logger->error('AI_REFRESH_SUMMARIES_FATAL', [
                'mode' => $mode,
                'err' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // =============================================================================
    // Customers
    // =============================================================================

    /**
     * @return array{0:int,1:int,2:int,3:array<int,array<string,mixed>>}
     */
    private function processCustomers(int $limit, int $sinceDays, bool $force): array
    {
        $scenarioKey = 'customer.profile.summarize';
        $subjectType = 'customer';

        if (!$this->tableExists('customers')) {
            $this->logger->warning('AI_REFRESH_SUMMARIES_SKIP', ['reason' => 'customers table missing']);
            return [0, 0, 0, []];
        }

        $rows = $this->selectRecentRows('customers', $limit, $sinceDays);

        $processed = 0;
        $ok = 0;
        $failed = 0;
        $items = [];

        foreach ($rows as $c) {
            $processed++;
            $id = (int)($c['id'] ?? 0);
            if ($id <= 0) continue;

            // اگر خلاصه تازه است و force نیست، skip
            if (!$force && $this->isSummaryFresh($subjectType, $id, $scenarioKey, 24)) {
                $items[] = ['id' => $id, 'status' => 'skipped_fresh'];
                continue;
            }

            try {
                // build input: customer + orders + notes (best-effort)
                $orders = $this->fetchOrdersForCustomer($id, 20);
                $notes  = $this->fetchNotesForCustomer($id, 30);

                $input = [
                    'customer' => $this->pickFields($c, ['id','name','email','phone','created_at','updated_at','woo_customer_id']),
                    'orders' => $orders,
                    'notes' => $notes,
                ];

                $saved = $this->runScenarioAndSaveSummary($scenarioKey, $subjectType, $id, $input);

                $ok++;
                $items[] = ['id' => $id, 'status' => 'ok', 'ai_ok' => $saved['ai_ok']];
            } catch (Throwable $e) {
                $failed++;
                $items[] = ['id' => $id, 'status' => 'failed', 'error' => $e->getMessage()];
                $this->logger->warning('AI_REFRESH_CUSTOMER_FAILED', ['customer_id' => $id, 'err' => $e->getMessage()]);
            }
        }

        return [$processed, $ok, $failed, $items];
    }

    // =============================================================================
    // Products
    // =============================================================================

    /**
     * @return array{0:int,1:int,2:int,3:array<int,array<string,mixed>>}
     */
    private function processProducts(int $limit, int $sinceDays, bool $force): array
    {
        // برای محصولات می‌تونیم سناریوهای مختلف داشته باشیم:
        // - description.generate
        // - attributes.extract
        // ولی Summary عمومی را با description.generate می‌سازیم (و JSON خروجی را نگه می‌داریم)
        $scenarioKey = 'product.description.generate';
        $subjectType = 'product';

        if (!$this->tableExists('products')) {
            $this->logger->warning('AI_REFRESH_SUMMARIES_SKIP', ['reason' => 'products table missing']);
            return [0, 0, 0, []];
        }

        $rows = $this->selectRecentRows('products', $limit, $sinceDays);

        $processed = 0;
        $ok = 0;
        $failed = 0;
        $items = [];

        foreach ($rows as $p) {
            $processed++;
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0) continue;

            if (!$force && $this->isSummaryFresh($subjectType, $id, $scenarioKey, 72)) {
                $items[] = ['id' => $id, 'status' => 'skipped_fresh'];
                continue;
            }

            try {
                // best-effort attributes/variants
                $variants = $this->fetchVariantsForProduct($id, 50);

                $input = [
                    'name' => (string)($p['name'] ?? ''),
                    'sku' => (string)($p['sku'] ?? ''),
                    'type' => (string)($p['type'] ?? ''),
                    'price' => $p['price'] ?? null,
                    'categories' => $this->safeJsonDecodeArray($p['categories_json'] ?? null),
                    'attributes' => $this->safeJsonDecodeArray($p['attributes_json'] ?? null),
                    'variants' => $variants,
                    'tone' => 'professional',
                    'language' => 'fa',
                ];

                $saved = $this->runScenarioAndSaveSummary($scenarioKey, $subjectType, $id, $input);

                $ok++;
                $items[] = ['id' => $id, 'status' => 'ok', 'ai_ok' => $saved['ai_ok']];
            } catch (Throwable $e) {
                $failed++;
                $items[] = ['id' => $id, 'status' => 'failed', 'error' => $e->getMessage()];
                $this->logger->warning('AI_REFRESH_PRODUCT_FAILED', ['product_id' => $id, 'err' => $e->getMessage()]);
            }
        }

        return [$processed, $ok, $failed, $items];
    }

    // =============================================================================
    // Orders
    // =============================================================================

    /**
     * @return array{0:int,1:int,2:int,3:array<int,array<string,mixed>>}
     */
    private function processOrders(int $limit, int $sinceDays, bool $force): array
    {
        $scenarioKey = 'order.risk.detect';
        $subjectType = 'order';

        if (!$this->tableExists('orders')) {
            $this->logger->warning('AI_REFRESH_SUMMARIES_SKIP', ['reason' => 'orders table missing']);
            return [0, 0, 0, []];
        }

        $rows = $this->selectRecentRows('orders', $limit, $sinceDays);

        $processed = 0;
        $ok = 0;
        $failed = 0;
        $items = [];

        foreach ($rows as $o) {
            $processed++;
            $id = (int)($o['id'] ?? 0);
            if ($id <= 0) continue;

            if (!$force && $this->isSummaryFresh($subjectType, $id, $scenarioKey, 12)) {
                $items[] = ['id' => $id, 'status' => 'skipped_fresh'];
                continue;
            }

            try {
                $customerId = (int)($o['customer_id'] ?? 0);
                $customer = $customerId > 0 ? $this->fetchCustomer($customerId) : null;

                $input = [
                    'order' => $this->pickFields($o, [
                        'id','status','total','currency','payment_method','created_at','updated_at',
                        'billing_json','shipping_json','items_json','woo_order_id'
                    ]),
                    'customer' => $customer ? $this->pickFields($customer, ['id','name','email','phone','woo_customer_id']) : null,
                ];

                $saved = $this->runScenarioAndSaveSummary($scenarioKey, $subjectType, $id, $input);

                $ok++;
                $items[] = ['id' => $id, 'status' => 'ok', 'ai_ok' => $saved['ai_ok']];
            } catch (Throwable $e) {
                $failed++;
                $items[] = ['id' => $id, 'status' => 'failed', 'error' => $e->getMessage()];
                $this->logger->warning('AI_REFRESH_ORDER_FAILED', ['order_id' => $id, 'err' => $e->getMessage()]);
            }
        }

        return [$processed, $ok, $failed, $items];
    }

    // =============================================================================
    // Run scenario and save summary
    // =============================================================================

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function runScenarioAndSaveSummary(string $scenarioKey, string $subjectType, int $subjectId, array $input): array
    {
        $scenario = AIScenarios::get($scenarioKey);
        $messages = AIScenarios::buildMessages($scenario, $input);

        // Allow override defaults by config (optional)
        $override = $this->config['gapgpt']['summaries_override'] ?? [];
        $override = is_array($override) ? $override : [];

        $options = AIScenarios::buildOptions($scenario, $override);

        $resp = $this->gapgpt->chat($messages, $options);
        $parsed = AIScenarios::parseOutput($scenario, (string)($resp['text'] ?? ''), is_array($resp['raw'] ?? null) ? $resp['raw'] : null);

        $provider = (string)($resp['provider'] ?? ($options['provider'] ?? ''));
        $model = (string)($resp['model'] ?? ($options['model'] ?? ''));
        $this->saveSummary($subjectType, $subjectId, $scenarioKey, $parsed, $provider, $model);

        return [
            'ai_ok' => (bool)($parsed['ok'] ?? false),
            'warnings' => $parsed['warnings'] ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function saveSummary(string $subjectType, int $subjectId, string $scenarioKey, array $parsed, string $provider, string $model): void
    {
        if (!$this->tableExists('ai_summaries')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $summaryText = (string)($parsed['text'] ?? '');
        $summaryJson = null;
        if (($parsed['format'] ?? '') === 'json' && is_array($parsed['data'] ?? null)) {
            $summaryJson = json_encode($parsed['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $warningsJson = json_encode($parsed['warnings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Upsert by unique key
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_summaries
              (subject_type, subject_id, summary_key, summary_text, summary_json, warnings_json, provider, model, refreshed_at, created_at, updated_at)
            VALUES
              (:st, :sid, :sk, :stxt, :sjson, :wjson, :prov, :model, :ref, :now, :now)
            ON DUPLICATE KEY UPDATE
              summary_text=VALUES(summary_text),
              summary_json=VALUES(summary_json),
              warnings_json=VALUES(warnings_json),
              provider=VALUES(provider),
              model=VALUES(model),
              refreshed_at=VALUES(refreshed_at),
              updated_at=VALUES(updated_at)
        ");
        $stmt->execute([
            ':st' => $subjectType,
            ':sid' => $subjectId,
            ':sk' => $scenarioKey,
            ':stxt' => $this->truncate($summaryText, 120000),
            ':sjson' => $summaryJson,
            ':wjson' => $warningsJson ?: '[]',
            ':prov' => $this->truncate($provider, 80),
            ':model' => $this->truncate($model, 120),
            ':ref' => $now,
            ':now' => $now,
        ]);
    }

    // =============================================================================
    // Best-effort data fetchers
    // =============================================================================

    /**
     * @return array<int,array<string,mixed>>
     */
    private function selectRecentRows(string $table, int $limit, int $sinceDays): array
    {
        $limit = max(1, min(500, $limit));
        $since = date('Y-m-d H:i:s', time() - ($sinceDays * 86400));

        // اگر updated_at ندارید، از created_at استفاده می‌کنیم.
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchOrdersForCustomer(int $customerId, int $limit): array
    {
        if (!$this->tableExists('orders')) return [];
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo->prepare("
            SELECT id, status, total, currency, payment_method, created_at, woo_order_id
            FROM orders
            WHERE customer_id = :cid
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':cid' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchNotesForCustomer(int $customerId, int $limit): array
    {
        if (!$this->tableExists('customer_notes')) return [];
        $limit = max(1, min(200, $limit));

        $stmt = $this->pdo->prepare("
            SELECT id, note, created_at
            FROM customer_notes
            WHERE customer_id = :cid
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':cid' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchVariantsForProduct(int $productId, int $limit): array
    {
        if (!$this->tableExists('product_variants')) return [];
        $limit = max(1, min(500, $limit));

        $stmt = $this->pdo->prepare("
            SELECT id, sku, price, attributes_json, woo_variation_id, created_at, updated_at
            FROM product_variants
            WHERE product_id = :pid
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([':pid' => $productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // normalize JSON fields if exist
        $out = [];
        foreach (($rows ?: []) as $r) {
            $r['attributes'] = $this->safeJsonDecodeArray($r['attributes_json'] ?? null);
            unset($r['attributes_json']);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchCustomer(int $customerId): ?array
    {
        if (!$this->tableExists('customers')) return null;

        $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE id=:id LIMIT 1");
        $stmt->execute([':id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param mixed $json
     * @return array<int,mixed>|array<string,mixed>
     */
    private function safeJsonDecodeArray($json): array
    {
        if (!is_string($json) || trim($json) === '') return [];
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    // =============================================================================
    // Freshness
    // =============================================================================

    private function isSummaryFresh(string $subjectType, int $subjectId, string $summaryKey, int $freshHours): bool
    {
        if (!$this->tableExists('ai_summaries')) return false;

        $since = date('Y-m-d H:i:s', time() - ($freshHours * 3600));

        $stmt = $this->pdo->prepare("
            SELECT refreshed_at
            FROM ai_summaries
            WHERE subject_type=:st AND subject_id=:sid AND summary_key=:sk
            LIMIT 1
        ");
        $stmt->execute([':st' => $subjectType, ':sid' => $subjectId, ':sk' => $summaryKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['refreshed_at'])) return false;

        return (string)$row['refreshed_at'] >= $since;
    }

    // =============================================================================
    // Tables
    // =============================================================================

    private function ensureTablesBestEffort(): void
    {
        try {
            $sql = "
CREATE TABLE IF NOT EXISTS ai_summaries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_type VARCHAR(40) NOT NULL,
  subject_id BIGINT NOT NULL,
  summary_key VARCHAR(120) NOT NULL,
  summary_text MEDIUMTEXT NULL,
  summary_json MEDIUMTEXT NULL,
  warnings_json MEDIUMTEXT NULL,
  provider VARCHAR(80) NULL,
  model VARCHAR(120) NULL,
  refreshed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subject_key (subject_type, subject_id, summary_key),
  KEY idx_subject (subject_type, subject_id),
  KEY idx_refreshed (refreshed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->logger->warning('AI_SUMMARIES_TABLE_CREATE_FAILED', ['err' => $e->getMessage()]);
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

    // =============================================================================
    // Utils
    // =============================================================================

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return array<string,mixed>
     */
    private function pickFields(array $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $out[$k] = $row[$k];
            }
        }
        return $out;
    }

    private function truncate(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
    }
}

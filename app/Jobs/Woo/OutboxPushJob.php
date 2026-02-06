<?php
/**
 * File: app/Jobs/Woo/OutboxPushJob.php
 *
 * CRM V2 - Job: Woo Outbox Push (Two-way sync: CRM -> WooCommerce)
 * -----------------------------------------------------------------------------
 * این Job برای «همگام‌سازی دوطرفه» طراحی شده: هر تغییری که داخل CRM انجام می‌دهی،
 * به جای اینکه همان لحظه مستقیم به WooCommerce API بزنی (که ممکن است کند/خطاپذیر باشد)،
 * یک رکورد در جدول Outbox ثبت می‌شود و این Job آن‌ها را:
 *  - دسته‌ای برمی‌دارد
 *  - به ووکامرس ارسال می‌کند
 *  - در صورت موفقیت mark sent
 *  - در صورت خطا retry/backoff و نهایتاً dead
 *
 * چرا Outbox؟
 * - قابلیت اطمینان بالاتر (Reliable)
 * - جلوگیری از از دست رفتن سینک
 * - کنترل Rate Limit / API errors
 * - امکان مشاهده وضعیت سینک در پنل ادمین
 *
 * -----------------------------------------------------------------------------
 * جدول پیشنهادی: woo_outbox
 *
 * CREATE TABLE IF NOT EXISTS woo_outbox (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   site_id BIGINT NOT NULL DEFAULT 1,
 *   entity_type VARCHAR(40) NOT NULL,          -- product|variant|customer|order
 *   entity_id BIGINT NOT NULL,                 -- id داخلی CRM
 *   action VARCHAR(40) NOT NULL,               -- upsert|delete|sync_stock|sync_price|sync_status|...
 *   payload_json MEDIUMTEXT NULL,              -- داده لازم برای ارسال (اختیاری)
 *   idempotency_key VARCHAR(120) NOT NULL,     -- برای ارسال امن و جلوگیری از دوباره‌کاری
 *   status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|sending|sent|failed|dead
 *   attempts INT NOT NULL DEFAULT 0,
 *   max_attempts INT NOT NULL DEFAULT 5,
 *   last_error TEXT NULL,
 *   locked_at DATETIME NULL,
 *   available_at DATETIME NOT NULL,
 *   sent_at DATETIME NULL,
 *   created_at DATETIME NOT NULL,
 *   updated_at DATETIME NOT NULL,
 *   KEY idx_status_available (status, available_at),
 *   KEY idx_entity (entity_type, entity_id),
 *   KEY idx_site (site_id),
 *   UNIQUE KEY uq_idem (idempotency_key)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * -----------------------------------------------------------------------------
 * Payload ورودی Job:
 *  [
 *    'site_id' => 1,
 *    'limit' => 50,                 // تعداد رکوردهای پردازش در هر اجرا
 *    'only_types' => ['product'],   // اختیاری: فقط این نوع‌ها
 *    'dry_run' => false,            // اگر true => ارسال واقعی به ووکامرس انجام نشود
 *    'force' => false,              // اگر true => حتی failedها هم دوباره تلاش شود
 *  ]
 *
 * -----------------------------------------------------------------------------
 * این Job از کلاس موجود شما استفاده می‌کند:
 *   App\Integrations\WooCommerce\WooOutboxPublisher
 *
 * انتظار می‌رود WooOutboxPublisher حداقل این را داشته باشد:
 *   publish(string $entityType, int $entityId, string $action, array $payload, array $context): array
 * خروجی publish:
 *   [
 *     'ok' => bool,
 *     'remote_id' => int|null,
 *     'message' => string|null,
 *     'raw' => mixed|null
 *   ]
 *
 * اگر امضای متد متفاوت بود، فقط همین فایل را تنظیم می‌کنی.
 */

declare(strict_types=1);

namespace App\Jobs\Woo;

use App\Integrations\WooCommerce\WooOutboxPublisher;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class OutboxPushJob
{
    private PDO $pdo;
    private Logger $logger;
    private WooOutboxPublisher $publisher;

    /** @var array<string,mixed> */
    private array $config;

    public function __construct(PDO $pdo, Logger $logger, WooOutboxPublisher $publisher, array $config = [])
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->publisher = $publisher;
        $this->config = $config;

        $this->ensureTableBestEffort();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $siteId = isset($payload['site_id']) ? (int)$payload['site_id'] : 1;
        $limit = isset($payload['limit']) ? (int)$payload['limit'] : 50;

        $onlyTypes = $payload['only_types'] ?? null;
        if (!is_array($onlyTypes)) $onlyTypes = null;

        $dryRun = (bool)($payload['dry_run'] ?? false);
        $force  = (bool)($payload['force'] ?? false);

        $limit = $this->clampInt($limit, 1, 500);

        $this->logger->info('WOO_OUTBOX_PUSH_START', [
            'site_id' => $siteId,
            'limit' => $limit,
            'only_types' => $onlyTypes,
            'dry_run' => $dryRun,
            'force' => $force,
        ]);

        $startedAt = time();

        // 1) Lock a batch
        $batch = $this->lockBatch($siteId, $limit, $onlyTypes, $force);

        if ($batch === []) {
            $this->logger->info('WOO_OUTBOX_PUSH_EMPTY', ['site_id' => $siteId]);
            return [
                'ok' => true,
                'site_id' => $siteId,
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'duration_sec' => time() - $startedAt,
                'message' => 'No outbox items available.',
            ];
        }

        $processed = 0;
        $sent = 0;
        $failed = 0;
        $items = [];

        foreach ($batch as $row) {
            $processed++;
            $id = (int)$row['id'];

            $entityType = (string)$row['entity_type'];
            $entityId = (int)$row['entity_id'];
            $action = (string)$row['action'];

            $payloadJson = (string)($row['payload_json'] ?? '');
            $payloadArr = $this->decodeJsonArray($payloadJson);

            $attempts = (int)$row['attempts'];
            $maxAttempts = (int)$row['max_attempts'];
            $idemKey = (string)$row['idempotency_key'];

            try {
                if ($dryRun) {
                    $this->markSent($id, 'dry_run: not sent');
                    $sent++;
                    $items[] = [
                        'id' => $id,
                        'status' => 'sent(dry_run)',
                        'entity' => "{$entityType}#{$entityId}",
                        'action' => $action,
                    ];
                    continue;
                }

                // 2) Publish via integration layer
                $result = $this->publisher->publish($entityType, $entityId, $action, $payloadArr, [
                    'site_id' => $siteId,
                    'outbox_id' => $id,
                    'idempotency_key' => $idemKey,
                    'attempts' => $attempts,
                ]);

                $okPublish = (bool)($result['ok'] ?? false);
                $message = (string)($result['message'] ?? '');

                if ($okPublish) {
                    $this->markSent($id, $message);
                    $sent++;
                    $items[] = [
                        'id' => $id,
                        'status' => 'sent',
                        'entity' => "{$entityType}#{$entityId}",
                        'action' => $action,
                        'message' => $this->truncate($message, 240),
                    ];
                } else {
                    throw new RuntimeException($message !== '' ? $message : 'Publish failed without message.');
                }
            } catch (Throwable $e) {
                $failed++;
                $err = $e->getMessage();

                // 3) Retry policy
                $attempts2 = $attempts + 1;

                if ($attempts2 >= $maxAttempts) {
                    $this->markDead($id, $err, $attempts2);
                    $items[] = [
                        'id' => $id,
                        'status' => 'dead',
                        'entity' => "{$entityType}#{$entityId}",
                        'action' => $action,
                        'error' => $this->truncate($err, 240),
                    ];
                } else {
                    $delaySec = $this->computeBackoffDelaySec($attempts2);
                    $this->releaseForRetry($id, $delaySec, $err, $attempts2);
                    $items[] = [
                        'id' => $id,
                        'status' => 'retry',
                        'entity' => "{$entityType}#{$entityId}",
                        'action' => $action,
                        'delay_sec' => $delaySec,
                        'error' => $this->truncate($err, 240),
                    ];
                }

                $this->logger->warning('WOO_OUTBOX_ITEM_FAILED', [
                    'id' => $id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'action' => $action,
                    'attempts' => $attempts2,
                    'max_attempts' => $maxAttempts,
                    'err' => $err,
                ]);
            }
        }

        $this->logger->info('WOO_OUTBOX_PUSH_DONE', [
            'site_id' => $siteId,
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'duration_sec' => time() - $startedAt,
        ]);

        return [
            'ok' => true,
            'site_id' => $siteId,
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'duration_sec' => time() - $startedAt,
            'items' => $items,
        ];
    }

    // =============================================================================
    // Locking / Batch selection
    // =============================================================================

    /**
     * Lock items for sending (atomic-ish):
     * - select IDs that are pending and available
     * - update them to sending + locked_at
     *
     * @return array<int,array<string,mixed>>
     */
    private function lockBatch(int $siteId, int $limit, ?array $onlyTypes, bool $force): array
    {
        if (!$this->tableExists('woo_outbox')) {
            return [];
        }

        $now = date('Y-m-d H:i:s');

        // آزادسازی lockهای قدیمی (اگر worker crash کرده باشد)
        $this->releaseStaleLocks();

        $this->pdo->beginTransaction();

        try {
            $where = "site_id = :site_id AND available_at <= :now";
            $params = [
                ':site_id' => $siteId,
                ':now' => $now,
            ];

            if ($force) {
                // pending و failed
                $where .= " AND status IN ('pending','failed')";
            } else {
                $where .= " AND status = 'pending'";
            }

            if (is_array($onlyTypes) && $onlyTypes !== []) {
                $in = [];
                foreach ($onlyTypes as $i => $t) {
                    if (!is_string($t) || trim($t) === '') continue;
                    $k = ':t' . $i;
                    $in[] = $k;
                    $params[$k] = trim($t);
                }
                if ($in !== []) {
                    $where .= " AND entity_type IN (" . implode(',', $in) . ")";
                }
            }

            // Select for update
            $sqlSel = "
                SELECT *
                FROM woo_outbox
                WHERE {$where}
                ORDER BY id ASC
                LIMIT {$limit}
                FOR UPDATE
            ";
            $stmt = $this->pdo->prepare($sqlSel);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || $rows === []) {
                $this->pdo->commit();
                return [];
            }

            // Lock them
            $ids = array_map(fn($r) => (int)$r['id'], $rows);
            $ids = array_values(array_filter($ids, fn($x) => $x > 0));
            if ($ids === []) {
                $this->pdo->commit();
                return [];
            }

            $inSql = implode(',', array_fill(0, count($ids), '?'));
            $sqlUpd = "
                UPDATE woo_outbox
                SET status='sending', locked_at=?, updated_at=?
                WHERE id IN ({$inSql})
            ";
            $upd = $this->pdo->prepare($sqlUpd);

            $bind = array_merge([$now, $now], $ids);
            $upd->execute($bind);

            $this->pdo->commit();

            return $rows;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function releaseStaleLocks(): void
    {
        // اگر locked_at خیلی قدیمی است => آزاد شود
        $timeoutSec = (int)($this->config['woo']['outbox_lock_timeout_sec'] ?? 180);
        $timeoutSec = $this->clampInt($timeoutSec, 30, 3600);

        $cutoff = date('Y-m-d H:i:s', time() - $timeoutSec);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            UPDATE woo_outbox
            SET status='pending', locked_at=NULL, updated_at=:now
            WHERE status='sending' AND locked_at IS NOT NULL AND locked_at <= :cutoff
        ");
        $stmt->execute([':now' => $now, ':cutoff' => $cutoff]);
    }

    // =============================================================================
    // State transitions
    // =============================================================================

    private function markSent(int $id, ?string $message = null): void
    {
        $now = date('Y-m-d H:i:s');

        $sql = "
            UPDATE woo_outbox
            SET status='sent',
                sent_at=:now,
                locked_at=NULL,
                last_error=NULL,
                updated_at=:now
        ";
        $params = [':id' => $id, ':now' => $now];

        if ($message !== null && trim($message) !== '') {
            // message را در last_error نگه نمی‌داریم؛
            // اگر خواستی ستون last_message بساز و اینجا قرار بده.
            // فعلاً فقط log می‌کنیم.
            $this->logger->info('WOO_OUTBOX_SENT_MESSAGE', ['id' => $id, 'message' => $this->truncate($message, 500)]);
        }

        $sql .= " WHERE id=:id LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function releaseForRetry(int $id, int $delaySec, string $error, int $attempts): void
    {
        $now = date('Y-m-d H:i:s');
        $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySec));

        $stmt = $this->pdo->prepare("
            UPDATE woo_outbox
            SET status='failed',
                attempts=:attempts,
                last_error=:err,
                locked_at=NULL,
                available_at=:available_at,
                updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $id,
            ':attempts' => $attempts,
            ':err' => $this->truncate($error, 8000),
            ':available_at' => $availableAt,
            ':now' => $now,
        ]);
    }

    private function markDead(int $id, string $error, int $attempts): void
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            UPDATE woo_outbox
            SET status='dead',
                attempts=:attempts,
                last_error=:err,
                locked_at=NULL,
                updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $id,
            ':attempts' => $attempts,
            ':err' => $this->truncate($error, 8000),
            ':now' => $now,
        ]);
    }

    // =============================================================================
    // Table ensure
    // =============================================================================

    private function ensureTableBestEffort(): void
    {
        try {
            $sql = "
CREATE TABLE IF NOT EXISTS woo_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id BIGINT NOT NULL DEFAULT 1,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT NOT NULL,
  action VARCHAR(40) NOT NULL,
  payload_json MEDIUMTEXT NULL,
  idempotency_key VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 5,
  last_error TEXT NULL,
  locked_at DATETIME NULL,
  available_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_status_available (status, available_at),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_site (site_id),
  UNIQUE KEY uq_idem (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->logger->warning('WOO_OUTBOX_TABLE_CREATE_FAILED', ['err' => $e->getMessage()]);
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

    // =============================================================================
    // Helpers
    // =============================================================================

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonArray(string $json): array
    {
        $json = trim($json);
        if ($json === '') return [];
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    private function computeBackoffDelaySec(int $attempt): int
    {
        // Backoff قابل فهم: 10s, 30s, 90s, 5m, 15m, 45m, ...
        $map = [1 => 10, 2 => 30, 3 => 90, 4 => 300, 5 => 900, 6 => 2700];
        return $map[$attempt] ?? 3600;
    }

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

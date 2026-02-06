<?php
/**
 * File: app/Jobs/Queue.php
 *
 * CRM V2 - Database Queue (Simple, Reliable)
 * -----------------------------------------------------------------------------
 * این کلاس یک سیستم صف (Queue) دیتابیس‌محور برای اجرای Job هاست.
 *
 * چرا DB Queue؟
 * - شما کدنویسی بلد نیستی و می‌خوای با وایب‌کدینگ جلو بری => ساده، قابل دیباگ، قابل توسعه
 * - بدون نیاز به Redis یا سرویس خارجی
 * - مقاوم در برابر قطع شدن worker (با reserve/timeout)
 *
 * طراحی:
 * - جدول پیشنهادی: jobs_queue
 * - متدهای اصلی:
 *   - push(): قرار دادن job در صف
 *   - reserve(): گرفتن یک job برای اجرا (قفل نرم)
 *   - ack(): ثبت موفقیت
 *   - fail(): ثبت خطا
 *   - release(): برگرداندن به صف با تاخیر
 *
 * -----------------------------------------------------------------------------
 * جدول پیشنهادی (MySQL):
 *
 * CREATE TABLE IF NOT EXISTS jobs_queue (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   queue VARCHAR(40) NOT NULL DEFAULT 'default',
 *   job VARCHAR(120) NOT NULL,                      -- نام job class یا handler key
 *   payload_json MEDIUMTEXT NOT NULL,               -- داده job (JSON)
 *   status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending|reserved|done|failed|dead
 *   attempts INT NOT NULL DEFAULT 0,
 *   max_attempts INT NOT NULL DEFAULT 3,
 *   reserved_at DATETIME NULL,
 *   available_at DATETIME NOT NULL,
 *   finished_at DATETIME NULL,
 *   last_error TEXT NULL,
 *   created_at DATETIME NOT NULL,
 *   updated_at DATETIME NOT NULL,
 *   KEY idx_queue_status_available (queue, status, available_at),
 *   KEY idx_status_reserved (status, reserved_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * -----------------------------------------------------------------------------
 * Config پیشنهادی (private/config.php):
 *
 * 'queue' => [
 *   'enabled' => true,
 *   'default_queue' => 'default',
 *   'reserve_timeout_sec' => 120, // اگر worker وسط اجرا مرد، بعد از این مدت job آزاد شود
 *   'sleep_when_empty_ms' => 500,
 *   'dead_after_attempts' => 8,   // سقف سخت برای dead
 * ],
 *
 * -----------------------------------------------------------------------------
 * نکته امنیتی:
 * - payload_json در DB ذخیره می‌شود، پس فقط داده لازم را ذخیره کن (نه پسورد!)
 */

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class Queue
{
    private PDO $pdo;
    private Logger $logger;

    /** @var array<string,mixed> */
    private array $config;

    private bool $enabled;
    private string $defaultQueue;
    private int $reserveTimeoutSec;
    private int $sleepWhenEmptyMs;
    private int $deadAfterAttempts;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(PDO $pdo, Logger $logger, array $config)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->config = $config;

        $q = $config['queue'] ?? [];

        $this->enabled = (bool)($q['enabled'] ?? true);
        $this->defaultQueue = (string)($q['default_queue'] ?? 'default');
        $this->reserveTimeoutSec = $this->clampInt($q['reserve_timeout_sec'] ?? 120, 10, 3600);
        $this->sleepWhenEmptyMs = $this->clampInt($q['sleep_when_empty_ms'] ?? 500, 0, 10000);
        $this->deadAfterAttempts = $this->clampInt($q['dead_after_attempts'] ?? 8, 1, 50);

        $this->ensureTable();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // =============================================================================
    // Push
    // =============================================================================

    /**
     * Push a job into queue.
     *
     * @param string $job   Example: 'App\\Jobs\\Woo\\ProcessWebhookJob'
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     *   - queue: string
     *   - delay_sec: int
     *   - max_attempts: int
     */
    public function push(string $job, array $payload = [], array $options = []): int
    {
        $this->assertEnabled();

        $queue = (string)($options['queue'] ?? $this->defaultQueue);
        $delaySec = (int)($options['delay_sec'] ?? 0);
        $maxAttempts = (int)($options['max_attempts'] ?? 3);

        if ($queue === '') $queue = $this->defaultQueue;
        if ($job === '') throw new RuntimeException('Job name cannot be empty.');
        if ($maxAttempts <= 0) $maxAttempts = 3;

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new RuntimeException('Failed to encode job payload as JSON.');
        }

        $now = date('Y-m-d H:i:s');
        $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySec));

        $stmt = $this->pdo->prepare("
            INSERT INTO jobs_queue
              (queue, job, payload_json, status, attempts, max_attempts,
               reserved_at, available_at, finished_at, last_error, created_at, updated_at)
            VALUES
              (:queue, :job, :payload, 'pending', 0, :max_attempts,
               NULL, :available_at, NULL, NULL, :now, :now)
        ");

        $stmt->execute([
            ':queue' => $queue,
            ':job' => $job,
            ':payload' => $payloadJson,
            ':max_attempts' => $maxAttempts,
            ':available_at' => $availableAt,
            ':now' => $now,
        ]);

        $id = (int)$this->pdo->lastInsertId();

        $this->logger->info('QUEUE_PUSH', [
            'id' => $id,
            'queue' => $queue,
            'job' => $job,
            'delay_sec' => $delaySec,
            'max_attempts' => $maxAttempts,
        ]);

        return $id;
    }

    // =============================================================================
    // Reserve
    // =============================================================================

    /**
     * Reserve one job for processing.
     *
     * @param string|null $queue
     * @return array<string,mixed>|null
     *   [
     *     'id' => int,
     *     'queue' => string,
     *     'job' => string,
     *     'payload' => array,
     *     'attempts' => int,
     *     'max_attempts' => int,
     *   ]
     */
    public function reserve(?string $queue = null): ?array
    {
        $this->assertEnabled();

        $queue = $queue ?: $this->defaultQueue;

        // Release stale reserved jobs (اگر worker مرده باشد)
        $this->releaseStaleReservations();

        $this->pdo->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');

            // SELECT ... FOR UPDATE برای جلوگیری از رزرو همزمان
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM jobs_queue
                WHERE
                  queue = :queue
                  AND status = 'pending'
                  AND available_at <= :now
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':queue' => $queue, ':now' => $now]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->pdo->commit();
                return null;
            }

            $id = (int)$row['id'];
            $attempts = (int)$row['attempts'];
            $maxAttempts = (int)$row['max_attempts'];

            // اگر attempts خیلی زیاد شد => dead
            if ($attempts >= $this->deadAfterAttempts) {
                $this->markDead($id, "Exceeded hard attempts limit ({$this->deadAfterAttempts})");
                $this->pdo->commit();
                return null;
            }

            // Reserve it
            $upd = $this->pdo->prepare("
                UPDATE jobs_queue
                SET status='reserved', reserved_at=:now, updated_at=:now
                WHERE id=:id
                LIMIT 1
            ");
            $upd->execute([':id' => $id, ':now' => $now]);

            $this->pdo->commit();

            // decode payload
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) $payload = [];

            return [
                'id' => $id,
                'queue' => (string)$row['queue'],
                'job' => (string)$row['job'],
                'payload' => $payload,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // =============================================================================
    // ACK / FAIL / RELEASE
    // =============================================================================

    public function ack(int $id): void
    {
        $this->assertEnabled();

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE jobs_queue
            SET status='done', finished_at=:now, last_error=NULL, reserved_at=NULL, updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':now' => $now]);

        $this->logger->info('QUEUE_ACK', ['id' => $id]);
    }

    public function fail(int $id, string $error, bool $retry = true): void
    {
        $this->assertEnabled();

        $row = $this->getRow($id);
        if (!$row) return;

        $attempts = (int)$row['attempts'] + 1;
        $maxAttempts = (int)$row['max_attempts'];

        // اگر retry نخواهیم یا از سقف گذشت => dead
        if (!$retry || $attempts >= $maxAttempts || $attempts >= $this->deadAfterAttempts) {
            $this->markDead($id, $error, $attempts);
            return;
        }

        // retry with backoff
        $delay = $this->computeBackoffDelaySec($attempts);
        $this->release($id, $delay, $error, $attempts);
    }

    public function release(int $id, int $delaySec = 0, ?string $error = null, ?int $attempts = null): void
    {
        $this->assertEnabled();

        $now = date('Y-m-d H:i:s');
        $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySec));

        $sql = "
            UPDATE jobs_queue
            SET
              status='pending',
              reserved_at=NULL,
              available_at=:available_at,
              updated_at=:now
        ";

        $params = [
            ':id' => $id,
            ':available_at' => $availableAt,
            ':now' => $now,
        ];

        if ($error !== null) {
            $sql .= ", last_error=:err";
            $params[':err'] = $this->truncate($error, 8000);
        }

        if ($attempts !== null) {
            $sql .= ", attempts=:attempts";
            $params[':attempts'] = (int)$attempts;
        }

        $sql .= " WHERE id=:id LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->logger->warning('QUEUE_RELEASE', [
            'id' => $id,
            'delay_sec' => $delaySec,
            'attempts' => $attempts,
        ]);
    }

    // =============================================================================
    // Worker helpers
    // =============================================================================

    public function sleepWhenEmpty(): void
    {
        if ($this->sleepWhenEmptyMs > 0) {
            usleep($this->sleepWhenEmptyMs * 1000);
        }
    }

    // =============================================================================
    // Internal
    // =============================================================================

    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new RuntimeException('Queue is disabled in config.');
        }
    }

    private function ensureTable(): void
    {
        // create table if not exists
        $sql = "
CREATE TABLE IF NOT EXISTS jobs_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue VARCHAR(40) NOT NULL DEFAULT 'default',
  job VARCHAR(120) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  reserved_at DATETIME NULL,
  available_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_queue_status_available (queue, status, available_at),
  KEY idx_status_reserved (status, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        $this->pdo->exec($sql);
    }

    private function releaseStaleReservations(): void
    {
        $timeout = $this->reserveTimeoutSec;
        $cutoff = date('Y-m-d H:i:s', time() - $timeout);
        $now = date('Y-m-d H:i:s');

        // هر jobی که reserved بوده و خیلی وقت گذشته، آزاد شود
        $stmt = $this->pdo->prepare("
            UPDATE jobs_queue
            SET status='pending', reserved_at=NULL, updated_at=:now
            WHERE status='reserved' AND reserved_at IS NOT NULL AND reserved_at <= :cutoff
        ");
        $stmt->execute([':now' => $now, ':cutoff' => $cutoff]);
    }

    private function markDead(int $id, string $error, ?int $attempts = null): void
    {
        $now = date('Y-m-d H:i:s');

        $sql = "
            UPDATE jobs_queue
            SET status='dead', last_error=:err, reserved_at=NULL, finished_at=:now, updated_at=:now
        ";
        $params = [':id' => $id, ':err' => $this->truncate($error, 8000), ':now' => $now];

        if ($attempts !== null) {
            $sql .= ", attempts=:attempts";
            $params[':attempts'] = (int)$attempts;
        }

        $sql .= " WHERE id=:id LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $this->logger->error('QUEUE_DEAD', ['id' => $id, 'error' => $error]);
    }

    private function computeBackoffDelaySec(int $attempt): int
    {
        // Backoff ساده و قابل فهم: 5s, 20s, 60s, 180s, 600s ...
        $map = [1 => 5, 2 => 20, 3 => 60, 4 => 180, 5 => 600, 6 => 1800];
        return $map[$attempt] ?? 3600;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM jobs_queue WHERE id=:id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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

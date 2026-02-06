<?php
/**
 * File: app/Jobs/Woo/ProcessWebhookJob.php
 *
 * CRM V2 - Job: Process WooCommerce Webhook
 * -----------------------------------------------------------------------------
 * این Job «قلب همگام‌سازی لحظه‌ای با ووکامرس» است.
 *
 * جریان پیشنهادی:
 * 1) ووکامرس وبهوک می‌زند (order.created, order.updated, product.updated, customer.created, ...)
 * 2) WooWebhookController.php وبهوک را دریافت می‌کند و raw payload را در DB (woo_webhook_events) ذخیره می‌کند
 * 3) سپس یک job enqueue می‌کند: ProcessWebhookJob با payload:
 *    [
 *      'event_id' => 123,                 // id در جدول woo_webhook_events
 *      'topic' => 'order.updated',        // topic وبهوک
 *      'resource' => 'order',             // order|product|customer
 *      'resource_id' => 987,              // id در ووکامرس
 *      'site_id' => 1,                    // اگر چند سایت دارید
 *      'force_fetch' => true|false,       // اگر خواستید از API دوباره بگیرد
 *    ]
 *
 * 4) این Job:
 *   - payload را validate می‌کند
 *   - اگر force_fetch باشد، از Woo API نسخه CRM داده کامل را می‌گیرد
 *   - سپس آن را به عملیات داخلی CRM تبدیل می‌کند:
 *       - upsert customer
 *       - upsert product (+ variations)
 *       - upsert order (+ items/payments/shipping)
 *   - و نهایتاً status event را "processed" می‌کند یا در صورت خطا retry.
 *
 * -----------------------------------------------------------------------------
 * فرضیات:
 * - شما یک Integration layer دارید در app/Integrations/WooCommerce مثل:
 *     - WooCommerceClient (REST)
 *     - WooMapper (map woo payload to CRM schema)
 *     - WooUpserters/Repositories (write to DB)
 *
 * اگر هنوز کامل نیست، این Job طوری نوشته شده که:
 * - در نبود بعضی کلاس‌ها، fail واضح بدهد و شما همانجا تکمیلش کنید.
 *
 * -----------------------------------------------------------------------------
 * جدول پیشنهادی woo_webhook_events:
 *
 * CREATE TABLE IF NOT EXISTS woo_webhook_events (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   site_id BIGINT NOT NULL DEFAULT 1,
 *   topic VARCHAR(120) NOT NULL,
 *   resource VARCHAR(40) NULL,
 *   resource_id BIGINT NULL,
 *   signature_ok TINYINT(1) NOT NULL DEFAULT 0,
 *   payload_json MEDIUMTEXT NOT NULL,
 *   headers_json MEDIUMTEXT NULL,
 *   status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending|processing|processed|failed|dead
 *   attempts INT NOT NULL DEFAULT 0,
 *   last_error TEXT NULL,
 *   received_at DATETIME NOT NULL,
 *   processed_at DATETIME NULL,
 *   created_at DATETIME NOT NULL,
 *   updated_at DATETIME NOT NULL,
 *   KEY idx_status (status),
 *   KEY idx_topic (topic),
 *   KEY idx_resource (resource, resource_id),
 *   KEY idx_site (site_id)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

declare(strict_types=1);

namespace App\Jobs\Woo;

use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

// این‌ها وابسته به ساختار Integrations/WooCommerce شماست.
// اگر کلاس‌ها هنوز وجود ندارند، job fail می‌شود و شما باید آن‌ها را بسازید.
use App\Integrations\WooCommerce\WooCommerceClient;
use App\Integrations\WooCommerce\WooMapper;
use App\Integrations\WooCommerce\WooUpserter;

final class ProcessWebhookJob
{
    private PDO $pdo;
    private Logger $logger;

    private WooCommerceClient $woo;
    private WooMapper $mapper;
    private WooUpserter $upserter;

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
        array $config = []
    ) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->woo = $woo;
        $this->mapper = $mapper;
        $this->upserter = $upserter;
        $this->config = $config;

        $this->ensureTableBestEffort();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $eventId = isset($payload['event_id']) ? (int)$payload['event_id'] : 0;
        $topic = isset($payload['topic']) ? (string)$payload['topic'] : '';
        $resource = isset($payload['resource']) ? (string)$payload['resource'] : '';
        $resourceId = isset($payload['resource_id']) ? (int)$payload['resource_id'] : 0;
        $siteId = isset($payload['site_id']) ? (int)$payload['site_id'] : 1;
        $forceFetch = (bool)($payload['force_fetch'] ?? false);

        if ($eventId <= 0) {
            throw new RuntimeException('ProcessWebhookJob: event_id is required.');
        }

        // Load event
        $event = $this->loadEvent($eventId);
        if (!$event) {
            throw new RuntimeException("Webhook event not found: {$eventId}");
        }

        $topic = $topic !== '' ? $topic : (string)($event['topic'] ?? '');
        $resource = $resource !== '' ? $resource : (string)($event['resource'] ?? '');
        $resourceId = $resourceId > 0 ? $resourceId : (int)($event['resource_id'] ?? 0);
        $siteId = $siteId > 0 ? $siteId : (int)($event['site_id'] ?? 1);

        $this->markEventProcessing($eventId);

        $this->logger->info('WOO_WEBHOOK_PROCESS_START', [
            'event_id' => $eventId,
            'topic' => $topic,
            'resource' => $resource,
            'resource_id' => $resourceId,
            'site_id' => $siteId,
            'force_fetch' => $forceFetch,
        ]);

        $startedAt = time();

        try {
            $payloadData = $this->decodeJsonArray((string)($event['payload_json'] ?? ''));

            // اگر force_fetch یا payload ناقص باشد، از Woo API دریافت می‌کنیم.
            $wooData = $payloadData;
            if ($forceFetch || $this->payloadLikelyIncomplete($topic, $payloadData)) {
                $wooData = $this->fetchFromWoo($topic, $resource, $resourceId);
            }

            // Map Woo => CRM schema
            $mapped = $this->mapper->map($topic, $wooData);

            // Upsert into CRM DB
            $result = $this->upserter->apply($topic, $mapped, [
                'site_id' => $siteId,
                'event_id' => $eventId,
                'resource' => $resource,
                'resource_id' => $resourceId,
            ]);

            $this->markEventProcessed($eventId);

            $this->logger->info('WOO_WEBHOOK_PROCESS_DONE', [
                'event_id' => $eventId,
                'topic' => $topic,
                'duration_sec' => time() - $startedAt,
                'result' => $this->summarizeResult($result),
            ]);

            return [
                'ok' => true,
                'event_id' => $eventId,
                'topic' => $topic,
                'processed' => true,
                'duration_sec' => time() - $startedAt,
                'result' => $result,
            ];
        } catch (Throwable $e) {
            $this->markEventFailed($eventId, $e->getMessage());

            $this->logger->error('WOO_WEBHOOK_PROCESS_FAILED', [
                'event_id' => $eventId,
                'topic' => $topic,
                'err' => $e->getMessage(),
                'duration_sec' => time() - $startedAt,
            ]);

            // throw => worker will retry
            throw $e;
        }
    }

    // =============================================================================
    // Woo API fetch
    // =============================================================================

    /**
     * @param array<string,mixed> $payloadData
     */
    private function payloadLikelyIncomplete(string $topic, array $payloadData): bool
    {
        // خیلی از وبهوک‌ها داده کامل می‌دهند، ولی بعضی‌ها نه.
        // همچنین اگر شما در ووکامرس وبهوک را طوری تنظیم کرده باشید که فقط ID بدهد،
        // اینجا تشخیص می‌دهیم.
        if (empty($payloadData)) return true;

        // common: if has "id" only and few keys -> incomplete
        if (count($payloadData) <= 3 && isset($payloadData['id'])) {
            return true;
        }

        // for orders: should have line_items typically
        if (str_starts_with($topic, 'order.') || str_contains($topic, 'order')) {
            if (!isset($payloadData['line_items']) || !is_array($payloadData['line_items'])) {
                return true;
            }
        }

        // for products: should have name/type
        if (str_starts_with($topic, 'product.') || str_contains($topic, 'product')) {
            if (!isset($payloadData['name'])) return true;
        }

        // for customer: should have email
        if (str_starts_with($topic, 'customer.') || str_contains($topic, 'customer')) {
            if (!isset($payloadData['email'])) return true;
        }

        return false;
    }

    /**
     * Fetch full resource payload from WooCommerce.
     *
     * @return array<string,mixed>
     */
    private function fetchFromWoo(string $topic, string $resource, int $resourceId): array
    {
        if ($resourceId <= 0) {
            // اگر id نداریم، از payload استفاده می‌کنیم
            return [];
        }

        // resource can be derived from topic
        if ($resource === '') {
            if (str_contains($topic, 'order')) $resource = 'order';
            elseif (str_contains($topic, 'product')) $resource = 'product';
            elseif (str_contains($topic, 'customer')) $resource = 'customer';
        }

        switch ($resource) {
            case 'order':
                return $this->woo->getOrder($resourceId);

            case 'product':
                // محصول ممکنه متغیر باشد؛ بهتر است variations هم بگیریم
                $product = $this->woo->getProduct($resourceId);

                if (isset($product['type']) && $product['type'] === 'variable') {
                    $vars = $this->woo->listProductVariations($resourceId, ['per_page' => 100]);
                    $product['variations_full'] = $vars;
                }

                return $product;

            case 'customer':
                return $this->woo->getCustomer($resourceId);

            default:
                // اگر resource ناشناخته بود، حداقل raw payload را برگردان
                return [];
        }
    }

    // =============================================================================
    // DB operations (events)
    // =============================================================================

    /**
     * @return array<string,mixed>|null
     */
    private function loadEvent(int $id): ?array
    {
        if (!$this->tableExists('woo_webhook_events')) {
            throw new RuntimeException('woo_webhook_events table missing.');
        }

        $stmt = $this->pdo->prepare("SELECT * FROM woo_webhook_events WHERE id=:id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function markEventProcessing(int $id): void
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            UPDATE woo_webhook_events
            SET status='processing', attempts=attempts+1, updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':now' => $now]);
    }

    private function markEventProcessed(int $id): void
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            UPDATE woo_webhook_events
            SET status='processed', processed_at=:now, last_error=NULL, updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':now' => $now]);
    }

    private function markEventFailed(int $id, string $error): void
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            UPDATE woo_webhook_events
            SET status='failed', last_error=:err, updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $id,
            ':err' => $this->truncate($error, 8000),
            ':now' => $now
        ]);
    }

    // =============================================================================
    // Table ensure
    // =============================================================================

    private function ensureTableBestEffort(): void
    {
        try {
            $sql = "
CREATE TABLE IF NOT EXISTS woo_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id BIGINT NOT NULL DEFAULT 1,
  topic VARCHAR(120) NOT NULL,
  resource VARCHAR(40) NULL,
  resource_id BIGINT NULL,
  signature_ok TINYINT(1) NOT NULL DEFAULT 0,
  payload_json MEDIUMTEXT NOT NULL,
  headers_json MEDIUMTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  received_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_topic (topic),
  KEY idx_resource (resource, resource_id),
  KEY idx_site (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->logger->warning('WOO_WEBHOOK_EVENTS_TABLE_CREATE_FAILED', ['err' => $e->getMessage()]);
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

    private function truncate(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    private function summarizeResult($result)
    {
        if (is_array($result)) {
            $keep = ['ok','created','updated','deleted','synced','errors','warnings'];
            $out = [];
            foreach ($keep as $k) {
                if (array_key_exists($k, $result)) $out[$k] = $result[$k];
            }
            return $out !== [] ? $out : ['keys' => array_slice(array_keys($result), 0, 12)];
        }
        return $result;
    }
}

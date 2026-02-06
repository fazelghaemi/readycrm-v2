<?php
/**
 * File: app/Integrations/WooCommerce/WooWebhookVerifier.php
 *
 * CRM V2 - WooCommerce Webhook Verifier
 * -----------------------------------------------------------------------------
 * این کلاس مسئول «اعتبارسنجی امنیتی» وبهوک‌های ووکامرس است:
 *
 * ✅ 1) بررسی فعال بودن ووکامرس و وجود webhook_secret
 * ✅ 2) بررسی IP whitelist (اختیاری)
 * ✅ 3) محدودسازی حجم payload (برای جلوگیری از DoS)
 * ✅ 4) اعتبارسنجی امضای وبهوک Woo (HMAC SHA256 / base64)
 * ✅ 5) استخراج متادیتا از هدرها و نرمال‌سازی آن‌ها
 * ✅ 6) ساختن hash استاندارد برای idempotency (sha256(payload))
 *
 * WooCommerce signature format:
 *   X-WC-Webhook-Signature = base64_encode( hash_hmac('sha256', payload, secret, true) )
 *
 * -----------------------------------------------------------------------------
 * Config پیشنهادی (private/config.php):
 *
 * 'woocommerce' => [
 *   'enabled' => true,
 *   'webhook_secret' => 'YOUR_SECRET',
 *   'webhook' => [
 *     'require_signature' => true,
 *     'ip_whitelist' => [],                // ['1.2.3.4', '5.6.7.8']
 *     'max_payload_bytes' => 1048576,      // 1MB
 *     'allow_missing_topic' => true,       // اگر برخی هاست‌ها هدر topic رو حذف کردند
 *   ],
 * ],
 *
 * -----------------------------------------------------------------------------
 * استفاده نمونه:
 *
 * $verifier = new WooWebhookVerifier($config, $logger);
 * $raw = file_get_contents('php://input');
 * $meta = $verifier->extractMetaFromServer($_SERVER);
 * $result = $verifier->verify($raw, $meta, $_SERVER);
 *
 * if (!$result['ok']) { ... }
 */

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Support\Logger;

final class WooWebhookVerifier
{
    /** @var array<string,mixed> */
    private array $config;

    private Logger $logger;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // Main API
    // -------------------------------------------------------------------------

    /**
     * Verify webhook request.
     *
     * @param string $rawPayload
     * @param array<string,mixed> $meta  (headers normalized)
     * @param array<string,mixed> $server ($_SERVER)
     * @return array<string,mixed>
     *   [
     *     'ok' => bool,
     *     'http_status' => int,
     *     'message' => string,
     *     'payload_hash' => string,
     *     'meta' => array,
     *   ]
     */
    public function verify(string $rawPayload, array $meta, array $server): array
    {
        if (!$this->wooEnabled()) {
            return $this->fail(503, 'WooCommerce integration disabled', $rawPayload, $meta);
        }

        // Payload size limit
        $maxBytes = (int)($this->config['woocommerce']['webhook']['max_payload_bytes'] ?? 1048576);
        if ($maxBytes <= 0) $maxBytes = 1048576;

        if (strlen($rawPayload) > $maxBytes) {
            $this->logger->warning('WOO_WEBHOOK_PAYLOAD_TOO_LARGE', [
                'len' => strlen($rawPayload),
                'max' => $maxBytes,
            ]);
            return $this->fail(413, 'Payload too large', $rawPayload, $meta);
        }

        // Optional IP whitelist
        $ip = $this->clientIp($server);
        if (!$this->ipAllowed($ip)) {
            $this->logger->warning('WOO_WEBHOOK_BLOCKED_IP', ['ip' => $ip]);
            return $this->fail(403, 'Forbidden', $rawPayload, $meta);
        }

        $requireSig = (bool)($this->config['woocommerce']['webhook']['require_signature'] ?? true);
        $secret = (string)($this->config['woocommerce']['webhook_secret'] ?? '');

        if ($requireSig) {
            if ($secret === '') {
                $this->logger->error('WOO_WEBHOOK_SECRET_MISSING', []);
                return $this->fail(500, 'Webhook secret is not configured', $rawPayload, $meta);
            }

            $sig = $this->s($meta['signature'] ?? null);
            if (!$sig) {
                $this->logger->warning('WOO_WEBHOOK_SIGNATURE_MISSING', ['ip' => $ip]);
                return $this->fail(401, 'Missing signature', $rawPayload, $meta);
            }

            if (!$this->verifySignature($rawPayload, $secret, $sig)) {
                $this->logger->warning('WOO_WEBHOOK_SIGNATURE_INVALID', ['ip' => $ip]);
                return $this->fail(401, 'Invalid signature', $rawPayload, $meta);
            }
        }

        // Topic/resource can be missing on some server configs; optional
        $allowMissingTopic = (bool)($this->config['woocommerce']['webhook']['allow_missing_topic'] ?? true);
        if (!$allowMissingTopic) {
            $topic = $this->s($meta['topic'] ?? null);
            if (!$topic) {
                return $this->fail(400, 'Missing topic header', $rawPayload, $meta);
            }
        }

        $payloadHash = hash('sha256', $rawPayload);

        return [
            'ok' => true,
            'http_status' => 200,
            'message' => 'OK',
            'payload_hash' => $payloadHash,
            'meta' => $meta,
        ];
    }

    // -------------------------------------------------------------------------
    // Meta extractors
    // -------------------------------------------------------------------------

    /**
     * Extract Woo headers from $_SERVER and normalize keys.
     *
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public function extractMetaFromServer(array $server): array
    {
        // Woo headers (case-insensitive) typically appear as HTTP_X_WC_...
        $meta = [
            'source' => $this->serverHeader($server, 'X-WC-Webhook-Source'),
            'topic' => $this->serverHeader($server, 'X-WC-Webhook-Topic'),
            'resource' => $this->serverHeader($server, 'X-WC-Webhook-Resource'),
            'event' => $this->serverHeader($server, 'X-WC-Webhook-Event'),
            'signature' => $this->serverHeader($server, 'X-WC-Webhook-Signature'),
            'webhook_id' => $this->serverHeader($server, 'X-WC-Webhook-ID'),
            'delivery_id' => $this->serverHeader($server, 'X-WC-Webhook-Delivery-ID'),
            'user_agent' => $this->s($server['HTTP_USER_AGENT'] ?? null),
            'ip' => $this->clientIp($server),
        ];

        // Normalize numeric ids
        $meta['webhook_id'] = $this->intOrNull($meta['webhook_id']);
        $meta['delivery_id'] = $this->s($meta['delivery_id']);

        // Normalize strings
        $meta['source'] = $this->s($meta['source']);
        $meta['topic'] = $this->s($meta['topic']);
        $meta['resource'] = $this->s($meta['resource']);
        $meta['event'] = $this->s($meta['event']);
        $meta['signature'] = $this->s($meta['signature']);
        $meta['user_agent'] = $this->truncate((string)($meta['user_agent'] ?? ''), 255);

        // Derive entity type from topic/resource
        $meta['entity_type'] = $this->detectEntityType($meta['topic'], $meta['resource']);

        return $meta;
    }

    // -------------------------------------------------------------------------
    // Signature verification
    // -------------------------------------------------------------------------

    public function verifySignature(string $rawPayload, string $secret, string $signatureHeader): bool
    {
        // Woo uses base64(hmac_sha256(payload, secret, raw=true))
        $computed = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));
        return hash_equals($computed, $signatureHeader);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function wooEnabled(): bool
    {
        $enabled = (bool)($this->config['woocommerce']['enabled'] ?? false);
        $feature = (bool)($this->config['features']['enable_woocommerce'] ?? true);
        return $enabled && $feature;
    }

    private function ipAllowed(string $ip): bool
    {
        $wl = $this->config['woocommerce']['webhook']['ip_whitelist'] ?? [];
        if (!is_array($wl) || count($wl) === 0) return true;
        return in_array($ip, $wl, true);
    }

    private function clientIp(array $server): string
    {
        // اگر پشت reverse proxy هستید، می‌توانید X-Forwarded-For را هم در آینده اضافه کنید
        return (string)($server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Read header from $_SERVER.
     */
    private function serverHeader(array $server, string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($server[$key])) return trim((string)$server[$key]);

        $alt = strtoupper(str_replace('-', '_', $name));
        if (isset($server[$alt])) return trim((string)$server[$alt]);

        return null;
    }

    private function detectEntityType(?string $topic, ?string $resource): ?string
    {
        $t = $topic ? strtolower($topic) : '';
        $r = $resource ? strtolower($resource) : '';

        if (str_starts_with($t, 'product.') || $r === 'product') return 'product';
        if (str_starts_with($t, 'order.') || $r === 'order') return 'order';
        if (str_starts_with($t, 'customer.') || $r === 'customer') return 'customer';

        return null;
    }

    private function fail(int $status, string $message, string $raw, array $meta): array
    {
        return [
            'ok' => false,
            'http_status' => $status,
            'message' => $message,
            'payload_hash' => hash('sha256', $raw),
            'meta' => $meta,
        ];
    }

    private function s(mixed $v): ?string
    {
        if ($v === null) return null;
        if (!is_string($v) && !is_numeric($v)) return null;
        $t = trim((string)$v);
        return $t === '' ? null : $t;
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        return (int)$v;
    }

    private function truncate(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
    }
}

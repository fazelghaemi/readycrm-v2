<?php
/**
 * File: app/Integrations/WooCommerce/WooClient.php
 *
 * CRM V2 - WooCommerce Integration Client
 * -----------------------------------------------------------------------------
 * این کلاس «هسته‌ی ارتباط با WooCommerce REST API (wc/v3)» است.
 *
 * چرا وجودش ضروری است؟
 * - کدهای ProductsApiController و WooWebhookController نباید تکراری cURL و auth و error handling داشته باشند.
 * - شما گفتی «ووکامرس باید کامل و دقیق و دوطرفه ادغام شود»، پس باید یک کلاینت قابل اتکا داشته باشیم.
 *
 * قابلیت‌های این WooClient:
 * 1) پشتیبانی از REST مسیرها: GET/POST/PUT/DELETE
 * 2) پشتیبانی از احراز هویت ساده با consumer_key/secret (Query Auth)
 *    - قابل تغییر به Basic Auth/Headers در آینده
 * 3) Timeout/SSL verify قابل تنظیم
 * 4) مدیریت Pagination (صفحه‌بندی) برای endpointهایی که لیست بزرگ دارند
 * 5) Error Handling استاندارد با Exception های دقیق
 * 6) Log سبک و قابل دیباگ
 * 7) Guard: چک فعال بودن و تنظیم بودن config
 *
 * -----------------------------------------------------------------------------
 * پیش‌نیاز config (private/config.php):
 *
 * 'woocommerce' => [
 *   'enabled' => true,
 *   'base_url' => 'https://your-site.com',
 *   'consumer_key' => 'ck_...',
 *   'consumer_secret' => 'cs_...',
 *   'api_version' => 'wc/v3',
 *   'timeout_sec' => 30,
 *   'verify_ssl' => true,
 *   'auth_mode' => 'query', // query | basic (آینده) | header (آینده)
 *
 *   // برای درخواست‌های لیستی
 *   'pagination' => [
 *      'default_per_page' => 50,
 *      'max_per_page' => 100,
 *      'max_pages' => 200,
 *   ],
 *
 *   // retry ساده برای خطاهای موقت شبکه
 *   'retry' => [
 *      'enabled' => true,
 *      'max_attempts' => 2,
 *      'sleep_ms' => 200,
 *   ],
 * ],
 *
 * -----------------------------------------------------------------------------
 * استفاده نمونه:
 *
 * $woo = new WooClient($config, $logger);
 * $products = $woo->get('/products', ['per_page'=>50,'page'=>1]);
 * $created = $woo->post('/products', [], ['name'=>'Test', 'type'=>'simple']);
 *
 * // لیست کامل با paginate:
 * $all = $woo->getAllPages('/products', ['status'=>'publish']);
 */

declare(strict_types=1);

namespace App\Integrations\WooCommerce;

use App\Support\Logger;
use RuntimeException;
use Throwable;

final class WooClient
{
    /** @var array<string,mixed> */
    private array $config;

    private Logger $logger;

    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $apiVersion;

    private int $timeoutSec;
    private bool $verifySsl;

    private string $authMode;

    private int $defaultPerPage;
    private int $maxPerPage;
    private int $maxPages;

    private bool $retryEnabled;
    private int $retryMaxAttempts;
    private int $retrySleepMs;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        $wc = $config['woocommerce'] ?? [];

        $this->baseUrl = rtrim((string)($wc['base_url'] ?? ''), '/');
        $this->consumerKey = (string)($wc['consumer_key'] ?? '');
        $this->consumerSecret = (string)($wc['consumer_secret'] ?? '');
        $this->apiVersion = (string)($wc['api_version'] ?? 'wc/v3');

        $this->timeoutSec = (int)($wc['timeout_sec'] ?? 30);
        $this->verifySsl = (bool)($wc['verify_ssl'] ?? true);

        $this->authMode = strtolower(trim((string)($wc['auth_mode'] ?? 'query')));
        if (!in_array($this->authMode, ['query', 'basic', 'header'], true)) {
            $this->authMode = 'query';
        }

        $pg = $wc['pagination'] ?? [];
        $this->defaultPerPage = $this->clampInt($pg['default_per_page'] ?? 50, 1, 100);
        $this->maxPerPage = $this->clampInt($pg['max_per_page'] ?? 100, 1, 100);
        $this->maxPages = $this->clampInt($pg['max_pages'] ?? 200, 1, 5000);

        $rt = $wc['retry'] ?? [];
        $this->retryEnabled = (bool)($rt['enabled'] ?? true);
        $this->retryMaxAttempts = $this->clampInt($rt['max_attempts'] ?? 2, 1, 10);
        $this->retrySleepMs = $this->clampInt($rt['sleep_ms'] ?? 200, 0, 10_000);
    }

    // -----------------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------------

    public function isEnabled(): bool
    {
        $enabled = (bool)($this->config['woocommerce']['enabled'] ?? false);
        return $enabled;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->consumerKey !== '' && $this->consumerSecret !== '';
    }

    /**
     * اگر ووکامرس فعال/تنظیم نشده باشد، این متد exception می‌دهد.
     */
    public function assertReady(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('WooCommerce integration is disabled in config.');
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('WooCommerce is not configured: base_url / consumer_key / consumer_secret missing.');
        }
    }

    // -----------------------------------------------------------------------------
    // Public REST Methods
    // -----------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     * @return mixed
     */
    public function get(string $path, array $query = [])
    {
        return $this->request('GET', $path, $query, null);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @return mixed
     */
    public function post(string $path, array $query = [], array $body = [])
    {
        return $this->request('POST', $path, $query, $body);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @return mixed
     */
    public function put(string $path, array $query = [], array $body = [])
    {
        return $this->request('PUT', $path, $query, $body);
    }

    /**
     * @param array<string,mixed> $query
     * @return mixed
     */
    public function delete(string $path, array $query = [])
    {
        return $this->request('DELETE', $path, $query, null);
    }

    // -----------------------------------------------------------------------------
    // Pagination helpers
    // -----------------------------------------------------------------------------

    /**
     * گرفتن تمام صفحات یک endpoint لیستی (مثل /products, /orders, /customers)
     * تا زمانی که صفحه خالی شود یا per_page کمتر از max باشد یا max_pages تمام شود.
     *
     * @param array<string,mixed> $baseQuery
     * @return array<int,mixed>
     */
    public function getAllPages(string $path, array $baseQuery = [], ?int $perPage = null, ?int $maxPages = null): array
    {
        $this->assertReady();

        $pp = $perPage ?? $this->defaultPerPage;
        $pp = $this->clampInt($pp, 1, $this->maxPerPage);

        $mp = $maxPages ?? $this->maxPages;
        $mp = $this->clampInt($mp, 1, $this->maxPages);

        $all = [];
        $page = 1;

        while ($page <= $mp) {
            $query = $baseQuery;
            $query['per_page'] = $pp;
            $query['page'] = $page;

            $data = $this->get($path, $query);

            if (!is_array($data)) {
                // اگر پاسخ array نباشد، یعنی endpoint لیستی نیست یا خطای ساختاری است
                throw new RuntimeException("Woo pagination expected array response for {$path} page {$page}");
            }

            if (count($data) === 0) {
                break;
            }

            foreach ($data as $item) {
                $all[] = $item;
            }

            // اگر تعداد کمتر از per_page است، یعنی صفحه آخر بوده
            if (count($data) < $pp) {
                break;
            }

            $page++;
        }

        return $all;
    }

    // -----------------------------------------------------------------------------
    // Core request
    // -----------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $jsonBody
     * @return mixed
     */
    public function request(string $method, string $path, array $query = [], ?array $jsonBody = null)
    {
        $this->assertReady();

        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new RuntimeException("Unsupported HTTP method: {$method}");
        }

        $url = $this->buildUrl($path, $query);
        $headers = $this->buildHeaders($jsonBody);

        $attempt = 0;
        $lastErr = null;

        $maxAttempts = $this->retryEnabled ? $this->retryMaxAttempts : 1;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $resp = $this->httpJson($method, $url, $headers, $jsonBody);

                // log success (minimal)
                $this->logger->info('WOO_HTTP_OK', [
                    'method' => $method,
                    'path' => $this->safePathForLog($path),
                    'attempt' => $attempt,
                ]);

                return $resp;
            } catch (Throwable $e) {
                $lastErr = $e;

                $this->logger->warning('WOO_HTTP_FAIL', [
                    'method' => $method,
                    'path' => $this->safePathForLog($path),
                    'attempt' => $attempt,
                    'err' => $e->getMessage(),
                ]);

                // اگر آخرین تلاش است، پرتاب کن
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                // sleep کوتاه برای retry
                if ($this->retrySleepMs > 0) {
                    usleep($this->retrySleepMs * 1000);
                }
            }
        }

        // should not reach
        throw new RuntimeException('Woo request failed unexpectedly.' . ($lastErr ? (' ' . $lastErr->getMessage()) : ''));
    }

    // -----------------------------------------------------------------------------
    // URL / Headers / Auth
    // -----------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $path = '/' . ltrim($path, '/');

        // base: https://site.com/wp-json/wc/v3/...
        $url = $this->baseUrl . '/wp-json/' . $this->apiVersion . $path;

        // auth
        $queryWithAuth = $query;
        $queryWithAuth = $this->applyAuthToQuery($queryWithAuth);

        $qs = http_build_query($queryWithAuth);
        if ($qs !== '') {
            $url .= '?' . $qs;
        }

        return $url;
    }

    /**
     * @param array<string,mixed>|null $jsonBody
     * @return array<int,string>
     */
    private function buildHeaders(?array $jsonBody): array
    {
        $headers = [
            'Accept: application/json',
            'User-Agent: CRM-V2-WooClient/1.0',
        ];

        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        // future: Basic / Header auth
        if ($this->authMode === 'basic') {
            // Woo over HTTPS can accept Basic auth for API keys on some setups/plugins
            $basic = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
            $headers[] = 'Authorization: Basic ' . $basic;
        } elseif ($this->authMode === 'header') {
            // Placeholder: if later you implement OAuth1 signing or custom headers, do here
            // $headers[] = 'Authorization: ...';
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function applyAuthToQuery(array $query): array
    {
        // Default: query auth (consumer_key, consumer_secret)
        if ($this->authMode === 'query') {
            $query['consumer_key'] = $this->consumerKey;
            $query['consumer_secret'] = $this->consumerSecret;
        }

        // If basic/header mode, do not put secrets in URL
        return $query;
    }

    private function safePathForLog(string $path): string
    {
        // فقط path را لاگ می‌کنیم نه url کامل (که ممکن است query secrets داشته باشد)
        return '/' . ltrim($path, '/');
    }

    // -----------------------------------------------------------------------------
    // Low-level HTTP
    // -----------------------------------------------------------------------------

    /**
     * اجرای درخواست HTTP و برگرداندن JSON decode شده.
     * اگر HTTP status غیر 2xx باشد، Exception دقیق می‌دهد.
     *
     * @param array<int,string> $headers
     * @param array<string,mixed>|null $jsonBody
     * @return mixed
     */
    private function httpJson(string $method, string $url, array $headers, ?array $jsonBody)
    {
        $ch = curl_init();
        if (!$ch) {
            throw new RuntimeException('cURL init failed.');
        }

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(1, $this->timeoutSec),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $this->timeoutSec)),
        ];

        if (!$this->verifySsl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                curl_close($ch);
                throw new RuntimeException('Failed to encode JSON body.');
            }
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        $respHeadersRaw = substr($raw, 0, $headerSize);
        $bodyRaw = substr($raw, $headerSize);

        // Decode JSON
        $decoded = json_decode($bodyRaw, true);

        // Woo sometimes returns empty body for delete; handle gracefully
        if ($decoded === null && trim($bodyRaw) !== '' && json_last_error() !== JSON_ERROR_NONE) {
            // not valid JSON but body exists -> treat as text
            $decoded = ['raw' => $this->truncate($bodyRaw, 2000)];
        }

        if ($status < 200 || $status >= 300) {
            // try to extract meaningful Woo error
            $message = "Woo HTTP {$status}";

            if (is_array($decoded)) {
                if (isset($decoded['message']) && is_string($decoded['message'])) {
                    $message .= " - " . $decoded['message'];
                } elseif (isset($decoded['code']) && is_string($decoded['code'])) {
                    $message .= " - code: " . $decoded['code'];
                }
            }

            // Include small debug snippet (no secrets)
            $message .= " | body: " . $this->truncate($bodyRaw, 700);

            // Make response headers available for debugging if needed
            $hint = $this->extractWooRateLimitHint($respHeadersRaw);

            if ($hint !== null) {
                $message .= " | hint: " . $hint;
            }

            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function extractWooRateLimitHint(string $rawHeaders): ?string
    {
        // Woo/host may include rate-limit headers; we parse lightly
        $h = strtolower($rawHeaders);
        if (strpos($h, 'retry-after:') !== false) {
            return 'Server asked to retry later (Retry-After present).';
        }
        return null;
    }

    // -----------------------------------------------------------------------------
    // Utils
    // -----------------------------------------------------------------------------

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

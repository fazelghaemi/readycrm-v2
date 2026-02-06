<?php
/**
 * File: app/Http/Controllers/Api/AIController.php
 *
 * CRM V2 - API Controller: AIController
 * ------------------------------------------------------------------
 * هدف این کنترلر:
 *  1) ارائه API برای قابلیت‌های هوش مصنوعی داخل CRM
 *  2) اتصال به GapGPT.app (به عنوان بک‌هاب مدل‌ها) از طریق API
 *  3) مدیریت امنیت، نرخ درخواست (Rate Limit)، ثبت لاگ، ذخیره تاریخچه، و هندل خطاها
 *
 * نکته برای شما (چون کدنویسی بلد نیستی):
 *  - این فایل طوری نوشته شده که تقریبا "همه چیز" را خودش انجام دهد.
 *  - فقط کافی است در config، کلیدهای GapGPT را وارد کنید و DB migrations را داشته باشید.
 *
 * ------------------------------------------------------------------
 * Endpoints پیشنهادی (routes):
 *  - POST /api/ai/chat
 *      بدنه: {messages:[{role,content}], model?, temperature?, max_tokens?, stream?}
 *  - POST /api/ai/customer/{id}/summarize
 *  - POST /api/ai/customer/{id}/tag
 *  - POST /api/ai/sale/{id}/summarize
 *  - GET  /api/ai/health
 *
 * ------------------------------------------------------------------
 * وابستگی‌ها:
 *  - Container باید شامل:
 *      - 'db'     => PDO
 *      - 'config' => array
 *      - 'logger' => callable(string $line):void  (اختیاری)
 *
 * ------------------------------------------------------------------
 * Config پیشنهادی (نمونه):
 *
 * return [
 *   'security' => [
 *     'api' => [
 *       'require_auth' => true,
 *       'rate_limit' => [
 *         'enabled' => true,
 *         'window_sec' => 60,
 *         'max_requests' => 30,
 *         'key' => 'ip', // ip | token | ip+token
 *       ],
 *     ],
 *   ],
 *   'ai' => [
 *     'enabled' => true,
 *     'provider' => 'gapgpt',
 *     'gapgpt' => [
 *       'base_url' => 'https://gapgpt.app/api/v1',  // اگر فرق دارد عوض کنید
 *       'api_key' => 'YOUR_GAPGPT_KEY',
 *       'timeout_sec' => 45,
 *       'default_model' => 'gpt-4.1-mini', // مثال
 *       'default_temperature' => 0.2,
 *       'default_max_tokens' => 800,
 *       'verify_ssl' => true,
 *     ],
 *     'limits' => [
 *       'max_messages' => 30,
 *       'max_input_chars' => 12000,
 *       'max_system_chars' => 4000,
 *     ],
 *     'templates' => [
 *       'customer_summary' => "You are a CRM assistant... خلاصه مشتری: ...",
 *       'customer_tags' => "You are a CRM assistant... برچسب‌ها را فقط JSON بده...",
 *       'sale_summary' => "You are a CRM assistant... خلاصه فروش/سفارش: ...",
 *     ],
 *     'storage' => [
 *       'log_requests' => true,
 *       'log_responses' => true,
 *       'truncate_chars' => 6000,
 *     ],
 *   ],
 * ];
 *
 * ------------------------------------------------------------------
 * جداول DB پیشنهادی (اگر هنوز ندارید):
 *  - ai_logs:
 *      id BIGINT AI PK
 *      user_id BIGINT NULL
 *      scenario VARCHAR(80) NULL
 *      entity_type VARCHAR(30) NULL  (customer|sale|none)
 *      entity_id BIGINT NULL
 *      model VARCHAR(80) NULL
 *      request_json MEDIUMTEXT NULL
 *      response_json MEDIUMTEXT NULL
 *      status VARCHAR(20) NOT NULL DEFAULT 'ok' (ok|error)
 *      error_message TEXT NULL
 *      created_at DATETIME NOT NULL
 *      ip VARCHAR(45) NULL
 *      user_agent VARCHAR(255) NULL
 *
 *  - api_tokens:
 *      id BIGINT AI PK
 *      user_id BIGINT NOT NULL
 *      token_hash CHAR(64) NOT NULL UNIQUE (sha256)
 *      name VARCHAR(120) NULL
 *      last_used_at DATETIME NULL
 *      expires_at DATETIME NULL
 *      revoked_at DATETIME NULL
 *      created_at DATETIME NOT NULL
 *      ip VARCHAR(45) NULL
 *      user_agent VARCHAR(255) NULL
 *
 *  - api_rate_limits:
 *      id BIGINT AI PK
 *      rl_key VARCHAR(190) NOT NULL
 *      window_start INT NOT NULL
 *      counter INT NOT NULL DEFAULT 0
 *      updated_at DATETIME NOT NULL
 *      UNIQUE(rl_key, window_start)
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Bootstrap\Container;
use PDO;
use RuntimeException;
use Throwable;

final class AIController
{
    private Container $c;
    private PDO $db;

    /** @var array<string,mixed> */
    private array $config;

    /** @var callable|null */
    private $logger;

    public function __construct(Container $container)
    {
        $this->c = $container;

        $db = $this->c->get('db') ?? null;
        if (!$db instanceof PDO) {
            throw new RuntimeException("AIController نیاز به سرویس 'db' از نوع PDO در Container دارد.");
        }
        $this->db = $db;

        $cfg = $this->c->get('config') ?? [];
        $this->config = is_array($cfg) ? $cfg : [];

        $logger = null;
        if ($this->c->has('logger')) {
            $logger = $this->c->get('logger');
        }
        $this->logger = is_callable($logger) ? $logger : null;
    }

    // =========================================================
    // Public endpoints
    // =========================================================

    /**
     * GET /api/ai/health
     */
    public function health(): void
    {
        $this->bootstrapApiGuard(true); // allow without auth
        $enabled = (bool)($this->config['ai']['enabled'] ?? false);
        $provider = (string)($this->config['ai']['provider'] ?? 'gapgpt');
        $hasKey = !empty($this->config['ai']['gapgpt']['api_key'] ?? '');

        $this->json([
            'ok' => true,
            'ai_enabled' => $enabled,
            'provider' => $provider,
            'gapgpt_key_configured' => $hasKey,
            'time' => date('c'),
        ], 200);
    }

    /**
     * POST /api/ai/chat
     * بدنه:
     * {
     *   "messages":[{"role":"system|user|assistant","content":"..."}],
     *   "model":"optional",
     *   "temperature":0.2,
     *   "max_tokens":800
     * }
     */
    public function chat(): void
    {
        $auth = $this->bootstrapApiGuard(false);

        if (!$this->aiEnabled()) {
            $this->json(['ok' => false, 'error' => 'AI is disabled in config.'], 503);
            return;
        }

        $body = $this->readJsonBody();

        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || count($messages) === 0) {
            $this->json(['ok' => false, 'error' => 'messages is required and must be an array.'], 422);
            return;
        }

        $model = $this->coalesceString($body['model'] ?? null, $this->gapgptDefaultModel());
        $temperature = $this->clampFloat($body['temperature'] ?? null, $this->gapgptDefaultTemperature(), 0.0, 1.5);
        $maxTokens = $this->clampInt($body['max_tokens'] ?? null, $this->gapgptDefaultMaxTokens(), 16, 8192);

        // Validate & normalize messages
        $normalized = $this->normalizeMessages($messages);
        $this->enforceMessageLimits($normalized);

        $scenario = (string)($body['scenario'] ?? 'chat');

        $reqPayload = [
            'model' => $model,
            'messages' => $normalized,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        $logId = $this->aiLogStart([
            'user_id' => $auth['user_id'] ?? null,
            'scenario' => $scenario,
            'entity_type' => null,
            'entity_id' => null,
            'model' => $model,
            'request_json' => $reqPayload,
            'ip' => $auth['ip'] ?? null,
            'user_agent' => $auth['user_agent'] ?? null,
        ]);

        try {
            $resp = $this->callGapGPTChatCompletions($reqPayload);

            $this->aiLogFinish($logId, [
                'status' => 'ok',
                'response_json' => $resp,
                'error_message' => null,
            ]);

            $this->json(['ok' => true, 'data' => $resp], 200);
        } catch (Throwable $e) {
            $this->aiLogFinish($logId, [
                'status' => 'error',
                'response_json' => null,
                'error_message' => $e->getMessage(),
            ]);

            $this->json([
                'ok' => false,
                'error' => 'AI request failed.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/ai/customer/{id}/summarize
     */
    public function summarizeCustomer(int $id): void
    {
        $auth = $this->bootstrapApiGuard(false);

        if (!$this->aiEnabled()) {
            $this->json(['ok' => false, 'error' => 'AI is disabled in config.'], 503);
            return;
        }

        $customer = $this->fetchCustomer($id);
        if (!$customer) {
            $this->json(['ok' => false, 'error' => 'Customer not found.'], 404);
            return;
        }

        $template = (string)($this->config['ai']['templates']['customer_summary'] ?? '');
        if (trim($template) === '') {
            $template = $this->defaultCustomerSummaryTemplate();
        }

        $userPrompt = $this->buildCustomerPrompt($customer, $template);

        $model = $this->gapgptDefaultModel();
        $temperature = $this->gapgptDefaultTemperature();
        $maxTokens = $this->gapgptDefaultMaxTokens();

        $reqPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful CRM assistant. Reply in Persian.'],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        $logId = $this->aiLogStart([
            'user_id' => $auth['user_id'] ?? null,
            'scenario' => 'customer_summary',
            'entity_type' => 'customer',
            'entity_id' => $id,
            'model' => $model,
            'request_json' => $reqPayload,
            'ip' => $auth['ip'] ?? null,
            'user_agent' => $auth['user_agent'] ?? null,
        ]);

        try {
            $resp = $this->callGapGPTChatCompletions($reqPayload);

            $text = $this->extractAssistantText($resp);

            // Save into customer table if you have these columns (ai_summary, ai_updated_at)
            $this->tryUpdateCustomerAISummary($id, $text);

            $this->aiLogFinish($logId, [
                'status' => 'ok',
                'response_json' => $resp,
                'error_message' => null,
            ]);

            $this->json(['ok' => true, 'customer_id' => $id, 'summary' => $text, 'raw' => $resp], 200);
        } catch (Throwable $e) {
            $this->aiLogFinish($logId, [
                'status' => 'error',
                'response_json' => null,
                'error_message' => $e->getMessage(),
            ]);

            $this->json(['ok' => false, 'error' => 'AI summarize failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/ai/customer/{id}/tag
     * خروجی باید JSON array باشد: ["VIP","همکار","خریدار عمده"]
     */
    public function tagCustomer(int $id): void
    {
        $auth = $this->bootstrapApiGuard(false);

        if (!$this->aiEnabled()) {
            $this->json(['ok' => false, 'error' => 'AI is disabled in config.'], 503);
            return;
        }

        $customer = $this->fetchCustomer($id);
        if (!$customer) {
            $this->json(['ok' => false, 'error' => 'Customer not found.'], 404);
            return;
        }

        $template = (string)($this->config['ai']['templates']['customer_tags'] ?? '');
        if (trim($template) === '') {
            $template = $this->defaultCustomerTagsTemplate();
        }

        $prompt = $this->buildCustomerPrompt($customer, $template);

        $model = $this->gapgptDefaultModel();
        $reqPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => "You are a CRM assistant. Output ONLY valid JSON array of tags in Persian. No extra text."],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => 300,
        ];

        $logId = $this->aiLogStart([
            'user_id' => $auth['user_id'] ?? null,
            'scenario' => 'customer_tags',
            'entity_type' => 'customer',
            'entity_id' => $id,
            'model' => $model,
            'request_json' => $reqPayload,
            'ip' => $auth['ip'] ?? null,
            'user_agent' => $auth['user_agent'] ?? null,
        ]);

        try {
            $resp = $this->callGapGPTChatCompletions($reqPayload);
            $text = $this->extractAssistantText($resp);

            $tags = $this->parseJsonArrayOfStrings($text);
            if ($tags === null) {
                // مدل ممکن است متن اضافی داده باشد؛ تلاش دوم: استخراج JSON از داخل متن
                $tags = $this->extractJsonArrayFromText($text);
            }
            if ($tags === null) {
                throw new RuntimeException("AI output is not a valid JSON array of strings.");
            }

            // Save tags to customer if you have tags column as JSON
            $this->tryUpdateCustomerTags($id, $tags);

            $this->aiLogFinish($logId, [
                'status' => 'ok',
                'response_json' => $resp,
                'error_message' => null,
            ]);

            $this->json(['ok' => true, 'customer_id' => $id, 'tags' => $tags, 'raw' => $resp], 200);
        } catch (Throwable $e) {
            $this->aiLogFinish($logId, [
                'status' => 'error',
                'response_json' => null,
                'error_message' => $e->getMessage(),
            ]);

            $this->json(['ok' => false, 'error' => 'AI tag failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/ai/sale/{id}/summarize
     */
    public function summarizeSale(int $id): void
    {
        $auth = $this->bootstrapApiGuard(false);

        if (!$this->aiEnabled()) {
            $this->json(['ok' => false, 'error' => 'AI is disabled in config.'], 503);
            return;
        }

        $sale = $this->fetchSale($id);
        if (!$sale) {
            $this->json(['ok' => false, 'error' => 'Sale not found.'], 404);
            return;
        }

        $template = (string)($this->config['ai']['templates']['sale_summary'] ?? '');
        if (trim($template) === '') {
            $template = $this->defaultSaleSummaryTemplate();
        }

        $prompt = $this->buildSalePrompt($sale, $template);

        $model = $this->gapgptDefaultModel();
        $reqPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful CRM assistant. Reply in Persian.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => 900,
        ];

        $logId = $this->aiLogStart([
            'user_id' => $auth['user_id'] ?? null,
            'scenario' => 'sale_summary',
            'entity_type' => 'sale',
            'entity_id' => $id,
            'model' => $model,
            'request_json' => $reqPayload,
            'ip' => $auth['ip'] ?? null,
            'user_agent' => $auth['user_agent'] ?? null,
        ]);

        try {
            $resp = $this->callGapGPTChatCompletions($reqPayload);
            $text = $this->extractAssistantText($resp);

            $this->tryUpdateSaleAISummary($id, $text);

            $this->aiLogFinish($logId, [
                'status' => 'ok',
                'response_json' => $resp,
                'error_message' => null,
            ]);

            $this->json(['ok' => true, 'sale_id' => $id, 'summary' => $text, 'raw' => $resp], 200);
        } catch (Throwable $e) {
            $this->aiLogFinish($logId, [
                'status' => 'error',
                'response_json' => null,
                'error_message' => $e->getMessage(),
            ]);

            $this->json(['ok' => false, 'error' => 'AI summarize failed.', 'details' => $e->getMessage()], 500);
        }
    }

    // =========================================================
    // Guards: auth + rate limit
    // =========================================================

    /**
     * @return array<string,mixed> auth info
     */
    private function bootstrapApiGuard(bool $allowUnauthed = false): array
    {
        $this->ensureJsonResponseHeaders();

        // Rate limit first (before heavy ops)
        $this->rateLimitOrFail();

        $requireAuth = (bool)($this->config['security']['api']['require_auth'] ?? true);
        if ($allowUnauthed) {
            // health endpoint can be open
            return $this->basicClientInfo(null);
        }

        if (!$requireAuth) {
            return $this->basicClientInfo(null);
        }

        $token = $this->readBearerToken();
        if (!$token) {
            $this->json(['ok' => false, 'error' => 'Unauthorized. Missing Bearer token.'], 401);
            exit;
        }

        $userId = $this->validateApiToken($token);
        if (!$userId) {
            $this->json(['ok' => false, 'error' => 'Unauthorized. Invalid token.'], 401);
            exit;
        }

        return $this->basicClientInfo($userId);
    }

    /**
     * @return array<string,mixed>
     */
    private function basicClientInfo(?int $userId): array
    {
        return [
            'user_id' => $userId,
            'ip' => $this->clientIp(),
            'user_agent' => $this->userAgent(),
        ];
    }

    private function rateLimitOrFail(): void
    {
        $rlCfg = $this->config['security']['api']['rate_limit'] ?? [];
        $enabled = (bool)($rlCfg['enabled'] ?? true);
        if (!$enabled) return;

        $windowSec = (int)($rlCfg['window_sec'] ?? 60);
        $maxReq = (int)($rlCfg['max_requests'] ?? 30);
        $keyMode = (string)($rlCfg['key'] ?? 'ip'); // ip|token|ip+token

        $ip = $this->clientIp();
        $token = $this->readBearerToken();
        $tokenHashShort = $token ? substr(hash('sha256', $token), 0, 16) : 'none';

        $key = match ($keyMode) {
            'token' => "token:{$tokenHashShort}",
            'ip+token' => "ip:{$ip}|token:{$tokenHashShort}",
            default => "ip:{$ip}",
        };

        $now = time();
        $windowStart = (int)floor($now / max(1, $windowSec)); // bucket
        $rlKey = 'ai:' . $key;

        // Ensure table exists (best-effort)
        $this->ensureRateLimitTable();

        // Atomic upsert:
        // Unique(rl_key, window_start)
        $sql = "INSERT INTO api_rate_limits (rl_key, window_start, counter, updated_at)
                VALUES (:k, :ws, 1, NOW())
                ON DUPLICATE KEY UPDATE counter = counter + 1, updated_at = NOW()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':k' => $rlKey, ':ws' => $windowStart]);

        $sql2 = "SELECT counter FROM api_rate_limits WHERE rl_key = :k AND window_start = :ws LIMIT 1";
        $stmt2 = $this->db->prepare($sql2);
        $stmt2->execute([':k' => $rlKey, ':ws' => $windowStart]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['counter'] ?? 1);

        if ($count > $maxReq) {
            $retryAfter = $windowSec - ($now % $windowSec);
            header('Retry-After: ' . $retryAfter);
            $this->json([
                'ok' => false,
                'error' => 'Rate limit exceeded.',
                'retry_after_sec' => $retryAfter,
            ], 429);
            exit;
        }
    }

    private function ensureRateLimitTable(): void
    {
        // If already exists, do nothing
        static $done = false;
        if ($done) return;

        try {
            $this->db->query("SELECT 1 FROM api_rate_limits LIMIT 1");
            $done = true;
            return;
        } catch (Throwable $e) {
            // Create
        }

        $sql = "
CREATE TABLE IF NOT EXISTS api_rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rl_key VARCHAR(190) NOT NULL,
  window_start INT NOT NULL,
  counter INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_rl (rl_key, window_start),
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        try {
            $this->db->exec($sql);
        } catch (Throwable $e) {
            // If cannot create, silently ignore (rate limit becomes best-effort)
        }

        $done = true;
    }

    // =========================================================
    // GapGPT integration
    // =========================================================

    /**
     * Calls GapGPT chat completions endpoint.
     * IMPORTANT:
     *  - base_url might differ depending on GapGPT docs.
     *  - If your endpoint differs, change buildGapGPTUrl() or this method.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function callGapGPTChatCompletions(array $payload): array
    {
        $baseUrl = $this->gapgptBaseUrl();
        $url = $this->buildGapGPTUrl($baseUrl, '/chat/completions');

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->gapgptApiKey(),
        ];

        $timeout = $this->gapgptTimeoutSec();
        $verifySsl = (bool)($this->config['ai']['gapgpt']['verify_ssl'] ?? true);

        $this->log("[AI] Calling GapGPT: {$url}");

        $resp = $this->httpJson('POST', $url, $headers, $payload, $timeout, $verifySsl);

        // Validate structure lightly
        if (!is_array($resp)) {
            throw new RuntimeException("Invalid AI response (not JSON array).");
        }

        // If API returns error format
        if (isset($resp['error'])) {
            $msg = is_array($resp['error']) ? ($resp['error']['message'] ?? 'AI error') : (string)$resp['error'];
            throw new RuntimeException("AI provider error: " . $msg);
        }

        return $resp;
    }

    private function buildGapGPTUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $path = '/' . ltrim($path, '/');
        return $baseUrl . $path;
    }

    private function gapgptBaseUrl(): string
    {
        $v = (string)($this->config['ai']['gapgpt']['base_url'] ?? 'https://gapgpt.app/api/v1');
        $v = trim($v);
        return $v !== '' ? $v : 'https://gapgpt.app/api/v1';
    }

    private function gapgptApiKey(): string
    {
        $k = (string)($this->config['ai']['gapgpt']['api_key'] ?? '');
        if (trim($k) === '') {
            throw new RuntimeException("GapGPT api_key is not configured.");
        }
        return $k;
    }

    private function gapgptTimeoutSec(): int
    {
        return (int)($this->config['ai']['gapgpt']['timeout_sec'] ?? 45);
    }

    private function gapgptDefaultModel(): string
    {
        return (string)($this->config['ai']['gapgpt']['default_model'] ?? 'gpt-4.1-mini');
    }

    private function gapgptDefaultTemperature(): float
    {
        return (float)($this->config['ai']['gapgpt']['default_temperature'] ?? 0.2);
    }

    private function gapgptDefaultMaxTokens(): int
    {
        return (int)($this->config['ai']['gapgpt']['default_max_tokens'] ?? 800);
    }

    private function aiEnabled(): bool
    {
        return (bool)($this->config['ai']['enabled'] ?? false);
    }

    // =========================================================
    // Data fetching (customer / sale)
    // =========================================================

    /**
     * @return array<string,mixed>|null
     */
    private function fetchCustomer(int $id): ?array
    {
        // NOTE: اگر نام جدول شما فرق دارد، همینجا تغییر بده.
        // پیشنهاد: customers
        $sql = "SELECT * FROM customers WHERE id = :id LIMIT 1";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            // اگر جدول هنوز وجود ندارد
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchSale(int $id): ?array
    {
        // NOTE: اگر نام جدول شما فرق دارد، همینجا تغییر بده.
        // پیشنهاد: sales
        $sql = "SELECT * FROM sales WHERE id = :id LIMIT 1";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function tryUpdateCustomerAISummary(int $id, string $summary): void
    {
        // If columns do not exist, fail silently.
        $sql = "UPDATE customers SET ai_summary = :s, ai_updated_at = NOW() WHERE id = :id";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':s' => $summary, ':id' => $id]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * @param array<int,string> $tags
     */
    private function tryUpdateCustomerTags(int $id, array $tags): void
    {
        $json = json_encode(array_values($tags), JSON_UNESCAPED_UNICODE);
        $sql = "UPDATE customers SET tags = :t, updated_at = NOW() WHERE id = :id";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':t' => $json, ':id' => $id]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function tryUpdateSaleAISummary(int $id, string $summary): void
    {
        $sql = "UPDATE sales SET ai_summary = :s, ai_updated_at = NOW() WHERE id = :id";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':s' => $summary, ':id' => $id]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    // =========================================================
    // Prompt builders
    // =========================================================

    /**
     * @param array<string,mixed> $customer
     */
    private function buildCustomerPrompt(array $customer, string $template): string
    {
        // شما بعداً می‌توانید template را حرفه‌ای‌تر کنید.
        // اینجا اطلاعات مشتری را به شکل readable قرار می‌دهیم.
        $profile = [
            'id' => $customer['id'] ?? null,
            'full_name' => $customer['full_name'] ?? ($customer['name'] ?? null),
            'email' => $customer['email'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'status' => $customer['status'] ?? null,
            'company' => $customer['company'] ?? null,
            'tags' => $customer['tags'] ?? null,
            'notes' => $customer['notes'] ?? null,
            'created_at' => $customer['created_at'] ?? null,
            'updated_at' => $customer['updated_at'] ?? null,
        ];

        $json = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $template . "\n\n---\nCustomer JSON:\n" . $json;
    }

    /**
     * @param array<string,mixed> $sale
     */
    private function buildSalePrompt(array $sale, string $template): string
    {
        // اگر شما items را به صورت JSON در sales ذخیره کردید، همینجا اضافه می‌کنیم.
        $payload = $sale;
        // برای جلوگیری از payload خیلی بزرگ:
        if (isset($payload['items']) && is_string($payload['items']) && strlen($payload['items']) > 8000) {
            $payload['items'] = substr($payload['items'], 0, 8000) . '...[truncated]';
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $template . "\n\n---\nSale JSON:\n" . $json;
    }

    private function defaultCustomerSummaryTemplate(): string
    {
        return
"وظیفه تو این است که با توجه به اطلاعات مشتری، یک خلاصه حرفه‌ای برای CRM بنویسی.
خلاصه باید شامل این موارد باشد:
1) معرفی کوتاه مشتری
2) سطح اهمیت/پتانسیل (با دلیل)
3) پیشنهاد اقدام بعدی (next step)
4) نکات حساس (اگر وجود دارد)
خروجی را فقط به زبان فارسی و در 6 تا 10 خط بنویس.";
    }

    private function defaultCustomerTagsTemplate(): string
    {
        return
"وظیفه تو این است که با توجه به اطلاعات مشتری، فقط و فقط یک JSON Array از برچسب‌های پیشنهادی بسازی.
قوانین:
- خروجی فقط JSON معتبر باشد. هیچ متن اضافه‌ای ننویس.
- برچسب‌ها کوتاه و کاربردی باشند.
- حداکثر 8 برچسب بده.
- برچسب‌ها فارسی باشند.
مثال خروجی:
[\"VIP\",\"خریدار عمده\",\"پیگیری فوری\"]";
    }

    private function defaultSaleSummaryTemplate(): string
    {
        return
"وظیفه تو این است که با توجه به اطلاعات فروش/سفارش، یک خلاصه مدیریتی برای CRM بنویسی.
خلاصه باید شامل:
1) وضعیت سفارش و پرداخت
2) موارد مهم آیتم‌ها (اگر موجود است)
3) ریسک‌ها/مشکلات احتمالی
4) پیشنهاد اقدام بعدی
خروجی فارسی، شفاف، 6 تا 12 خط.";
    }

    // =========================================================
    // AI logging
    // =========================================================

    /**
     * @param array<string,mixed> $data
     */
    private function aiLogStart(array $data): ?int
    {
        $storeCfg = $this->config['ai']['storage'] ?? [];
        $logRequests = (bool)($storeCfg['log_requests'] ?? true);
        $truncate = (int)($storeCfg['truncate_chars'] ?? 6000);

        $this->ensureAiLogsTable();

        $reqJson = $logRequests ? $this->truncateJson($data['request_json'] ?? null, $truncate) : null;

        $sql = "INSERT INTO ai_logs
            (user_id, scenario, entity_type, entity_id, model, request_json, status, created_at, ip, user_agent)
            VALUES
            (:user_id, :scenario, :entity_type, :entity_id, :model, :request_json, 'ok', NOW(), :ip, :ua)";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':user_id' => $data['user_id'] ?? null,
                ':scenario' => $data['scenario'] ?? null,
                ':entity_type' => $data['entity_type'] ?? null,
                ':entity_id' => $data['entity_id'] ?? null,
                ':model' => $data['model'] ?? null,
                ':request_json' => $reqJson,
                ':ip' => $data['ip'] ?? null,
                ':ua' => $this->truncateString((string)($data['user_agent'] ?? ''), 255),
            ]);
            return (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            // Logging should never break the app
            return null;
        }
    }

    /**
     * @param array{status:string,response_json:mixed,error_message:?string} $data
     */
    private function aiLogFinish(?int $logId, array $data): void
    {
        if (!$logId) return;

        $storeCfg = $this->config['ai']['storage'] ?? [];
        $logResponses = (bool)($storeCfg['log_responses'] ?? true);
        $truncate = (int)($storeCfg['truncate_chars'] ?? 6000);

        $respJson = $logResponses ? $this->truncateJson($data['response_json'] ?? null, $truncate) : null;

        $sql = "UPDATE ai_logs
                SET status = :status,
                    response_json = :resp,
                    error_message = :err
                WHERE id = :id
                LIMIT 1";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':status' => $data['status'],
                ':resp' => $respJson,
                ':err' => $data['error_message'],
                ':id' => $logId,
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function ensureAiLogsTable(): void
    {
        static $done = false;
        if ($done) return;

        try {
            $this->db->query("SELECT 1 FROM ai_logs LIMIT 1");
            $done = true;
            return;
        } catch (Throwable $e) {
            // create
        }

        $sql = "
CREATE TABLE IF NOT EXISTS ai_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NULL,
  scenario VARCHAR(80) NULL,
  entity_type VARCHAR(30) NULL,
  entity_id BIGINT NULL,
  model VARCHAR(80) NULL,
  request_json MEDIUMTEXT NULL,
  response_json MEDIUMTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ok',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_created (created_at),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        try {
            $this->db->exec($sql);
        } catch (Throwable $e) {
            // ignore
        }

        $done = true;
    }

    // =========================================================
    // Auth (Bearer Token)
    // =========================================================

    private function readBearerToken(): ?string
    {
        $h = $this->header('Authorization');
        if (!$h) return null;

        if (stripos($h, 'Bearer ') === 0) {
            $token = trim(substr($h, 7));
            return $token !== '' ? $token : null;
        }
        return null;
    }

    private function validateApiToken(string $plainToken): ?int
    {
        // Ensure table exists (best-effort)
        $this->ensureApiTokensTable();

        $hash = hash('sha256', $plainToken);

        $sql = "SELECT id, user_id, revoked_at, expires_at FROM api_tokens WHERE token_hash = :h LIMIT 1";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':h' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            if (!empty($row['revoked_at'])) return null;

            if (!empty($row['expires_at'])) {
                $exp = strtotime((string)$row['expires_at']);
                if ($exp !== false && $exp < time()) return null;
            }

            $userId = (int)($row['user_id'] ?? 0);
            if ($userId <= 0) return null;

            // Update last_used_at
            try {
                $stmt2 = $this->db->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id");
                $stmt2->execute([':id' => (int)$row['id']]);
            } catch (Throwable $e) {
                // ignore
            }

            return $userId;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function ensureApiTokensTable(): void
    {
        static $done = false;
        if ($done) return;

        try {
            $this->db->query("SELECT 1 FROM api_tokens LIMIT 1");
            $done = true;
            return;
        } catch (Throwable $e) {
            // create
        }

        $sql = "
CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  name VARCHAR(120) NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_token_hash (token_hash),
  KEY idx_user (user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        try {
            $this->db->exec($sql);
        } catch (Throwable $e) {
            // ignore
        }

        $done = true;
    }

    // =========================================================
    // Request/Response helpers
    // =========================================================

    private function ensureJsonResponseHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false) $raw = '';

        $raw = trim($raw);
        if ($raw === '') return [];

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->json(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
            exit;
        }
        return $data;
    }

    /**
     * @param mixed $data
     */
    private function json($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function header(string $name): ?string
    {
        $nameLower = strtolower($name);

        // Fast-path common Authorization header
        if ($nameLower === 'authorization') {
            $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            return $h ? trim((string)$h) : null;
        }

        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return trim((string)$_SERVER[$key]);
        }
        return null;
    }

    private function clientIp(): string
    {
        // Simple and safe approach
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return (string)$ip;
    }

    private function userAgent(): string
    {
        return $this->truncateString((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
    }

    private function log(string $line): void
    {
        if ($this->logger) {
            try { ($this->logger)($line); } catch (Throwable $e) {}
        }
    }

    // =========================================================
    // Normalization & limits
    // =========================================================

    /**
     * @param array<int,mixed> $messages
     * @return array<int,array{role:string,content:string}>
     */
    private function normalizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $role = (string)($m['role'] ?? 'user');
            $content = (string)($m['content'] ?? '');
            $role = strtolower(trim($role));
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }
            $content = trim($content);
            if ($content === '') continue;
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     */
    private function enforceMessageLimits(array $messages): void
    {
        $limits = $this->config['ai']['limits'] ?? [];
        $maxMessages = (int)($limits['max_messages'] ?? 30);
        $maxInputChars = (int)($limits['max_input_chars'] ?? 12000);
        $maxSystemChars = (int)($limits['max_system_chars'] ?? 4000);

        if (count($messages) > $maxMessages) {
            $this->json(['ok' => false, 'error' => "Too many messages. max={$maxMessages}"], 422);
            exit;
        }

        $total = 0;
        foreach ($messages as $m) {
            $len = mb_strlen($m['content']);
            $total += $len;

            if ($m['role'] === 'system' && $len > $maxSystemChars) {
                $this->json(['ok' => false, 'error' => "System message too long. max={$maxSystemChars} chars"], 422);
                exit;
            }
        }

        if ($total > $maxInputChars) {
            $this->json(['ok' => false, 'error' => "Input too long. max={$maxInputChars} chars"], 422);
            exit;
        }
    }

    // =========================================================
    // Parsing AI response
    // =========================================================

    /**
     * @param array<string,mixed> $resp
     */
    private function extractAssistantText(array $resp): string
    {
        // Typical OpenAI-like format:
        // choices[0].message.content
        $choices = $resp['choices'] ?? null;
        if (is_array($choices) && isset($choices[0]) && is_array($choices[0])) {
            $msg = $choices[0]['message'] ?? null;
            if (is_array($msg)) {
                $content = $msg['content'] ?? '';
                if (is_string($content)) return trim($content);
            }
            // Some providers may use text
            if (isset($choices[0]['text']) && is_string($choices[0]['text'])) {
                return trim($choices[0]['text']);
            }
        }

        // Fallback: if response has direct content
        if (isset($resp['content']) && is_string($resp['content'])) {
            return trim($resp['content']);
        }

        return '';
    }

    /**
     * @return array<int,string>|null
     */
    private function parseJsonArrayOfStrings(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) return null;

        $out = [];
        foreach ($decoded as $v) {
            if (!is_string($v)) return null;
            $s = trim($v);
            if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
        }
        return $out;
    }

    /**
     * Try extract JSON array from a text like:
     * "Here are tags:\n[...]\nThanks"
     * @return array<int,string>|null
     */
    private function extractJsonArrayFromText(string $text): ?array
    {
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) return null;

        $candidate = substr($text, $start, $end - $start + 1);
        return $this->parseJsonArrayOfStrings($candidate);
    }

    // =========================================================
    // HTTP client helper (cURL)
    // =========================================================

    /**
     * @param array<int,string> $headers
     * @param array<string,mixed> $jsonPayload
     * @return array<string,mixed>
     */
    private function httpJson(
        string $method,
        string $url,
        array $headers,
        array $jsonPayload,
        int $timeoutSec,
        bool $verifySsl
    ): array {
        $ch = curl_init();
        if (!$ch) {
            throw new RuntimeException("cURL init failed.");
        }

        $method = strtoupper($method);
        $payloadStr = json_encode($jsonPayload, JSON_UNESCAPED_UNICODE);

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(1, $timeoutSec),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeoutSec)),
            CURLOPT_POSTFIELDS => $payloadStr,
        ];

        if (!$verifySsl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: " . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        $this->log("[AI] HTTP status={$status}");

        $decoded = json_decode($rawBody, true);

        if ($status < 200 || $status >= 300) {
            $msg = "Upstream returned HTTP {$status}.";
            if (is_array($decoded) && isset($decoded['error'])) {
                $err = $decoded['error'];
                if (is_array($err) && isset($err['message'])) $msg .= " " . $err['message'];
                if (is_string($err)) $msg .= " " . $err;
            }
            throw new RuntimeException($msg);
        }

        if (!is_array($decoded)) {
            // sometimes body might not be json; show excerpt
            throw new RuntimeException("Invalid JSON from upstream. Body: " . $this->truncateString($rawBody, 500));
        }

        return $decoded;
    }

    // =========================================================
    // Utility helpers
    // =========================================================

    private function coalesceString(mixed $v, string $default): string
    {
        if (is_string($v)) {
            $s = trim($v);
            return $s !== '' ? $s : $default;
        }
        return $default;
    }

    private function clampInt(mixed $v, int $default, int $min, int $max): int
    {
        if ($v === null || $v === '') return $default;
        if (is_numeric($v)) {
            $n = (int)$v;
            if ($n < $min) $n = $min;
            if ($n > $max) $n = $max;
            return $n;
        }
        return $default;
    }

    private function clampFloat(mixed $v, float $default, float $min, float $max): float
    {
        if ($v === null || $v === '') return $default;
        if (is_numeric($v)) {
            $n = (float)$v;
            if ($n < $min) $n = $min;
            if ($n > $max) $n = $max;
            return $n;
        }
        return $default;
    }

    private function truncateString(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max) . '...[truncated]';
    }

    private function truncateJson(mixed $value, int $maxChars): ?string
    {
        if ($value === null) return null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return null;
        return $this->truncateString($json, $maxChars);
    }
}

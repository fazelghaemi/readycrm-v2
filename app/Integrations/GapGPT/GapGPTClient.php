<?php
/**
 * File: app/Integrations/GapGPT/GapGPTClient.php
 *
 * CRM V2 - GapGPT Client
 * -----------------------------------------------------------------------------
 * این کلاس «کلاینت رسمی» ارتباط با gapgpt.app است.
 *
 * هدف:
 * - یک نقطه‌ی واحد برای ارسال درخواست‌های هوش مصنوعی (چت/تکمیل/ابزارها) به GapGPT
 * - مدیریت:
 *   1) Authentication
 *   2) Timeout/Retry
 *   3) Logging
 *   4) Error handling استاندارد
 *   5) Rate-limit hints
 *   6) پشتیبانی از چند Provider/Model (چون GapGPT بک‌هاب است)
 *
 * -----------------------------------------------------------------------------
 * ✅ ساختار پیشنهادی config (private/config.php):
 *
 * 'gapgpt' => [
 *   'enabled' => true,
 *
 *   // آدرس پایه سرویس GapGPT
 *   'base_url' => 'https://gapgpt.app',
 *
 *   // مسیر API (ممکن است بسته به مستندات شما تغییر کند)
 *   // اینجا طوری طراحی شده که سریع قابل تغییر باشد.
 *   'api_path' => '/api/v1',
 *
 *   // کلید API (توکن)
 *   'api_key' => 'YOUR_GAPGPT_API_KEY',
 *
 *   // هدر Auth: Bearer یا X-API-KEY
 *   'auth_mode' => 'bearer', // bearer | x-api-key
 *
 *   // تنظیمات شبکه
 *   'timeout_sec' => 60,
 *   'connect_timeout_sec' => 10,
 *   'verify_ssl' => true,
 *
 *   // Retry برای خطاهای موقت
 *   'retry' => [
 *     'enabled' => true,
 *     'max_attempts' => 2,
 *     'sleep_ms' => 250
 *   ],
 *
 *   // پیش‌فرض‌های مدل/ارائه‌دهنده
 *   'defaults' => [
 *     'provider' => 'openai',       // مثال: openai, anthropic, google, ...
 *     'model' => 'gpt-4o-mini',     // مثال
 *     'temperature' => 0.2,
 *     'max_tokens' => 1200,
 *   ],
 *
 *   // محدودیت‌های داخلی
 *   'limits' => [
 *     'max_prompt_chars' => 60000,
 *     'max_response_chars' => 120000,
 *   ],
 *
 *   // مسیر ذخیره‌ی cache (اختیاری)
 *   'cache' => [
 *     'enabled' => true,
 *     'dir' => __DIR__ . '/../../../private/storage/cache',
 *     'ttl_sec' => 900, // 15 minutes
 *   ],
 * ],
 *
 * -----------------------------------------------------------------------------
 * API Contract:
 * - چون GapGPT ممکن است endpoints متفاوت داشته باشد، این کلاینت 2 سطح دارد:
 *   1) requestRaw($method, $endpoint, $query, $body)  => خروجی آرایه decode شده
 *   2) chat($messages, $options) => خروجی استاندارد (text + meta)
 *
 * - اگر endpoint واقعی شما متفاوت است:
 *   فقط متد endpointForChat() را اصلاح می‌کنی.
 */

declare(strict_types=1);

namespace App\Integrations\GapGPT;

use App\Support\Logger;
use RuntimeException;
use Throwable;

final class GapGPTClient
{
    /** @var array<string,mixed> */
    private array $config;

    private Logger $logger;

    private string $baseUrl;
    private string $apiPath;
    private string $apiKey;
    private string $authMode;

    private int $timeoutSec;
    private int $connectTimeoutSec;
    private bool $verifySsl;

    private bool $retryEnabled;
    private int $retryMaxAttempts;
    private int $retrySleepMs;

    private string $defaultProvider;
    private string $defaultModel;
    private float $defaultTemperature;
    private int $defaultMaxTokens;

    private int $maxPromptChars;
    private int $maxResponseChars;

    private bool $cacheEnabled;
    private string $cacheDir;
    private int $cacheTtl;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        $g = $config['gapgpt'] ?? [];

        $this->baseUrl = rtrim((string)($g['base_url'] ?? 'https://gapgpt.app'), '/');
        $this->apiPath = '/' . ltrim((string)($g['api_path'] ?? '/api/v1'), '/');

        $this->apiKey = (string)($g['api_key'] ?? '');
        $this->authMode = strtolower(trim((string)($g['auth_mode'] ?? 'bearer')));
        if (!in_array($this->authMode, ['bearer', 'x-api-key'], true)) {
            $this->authMode = 'bearer';
        }

        $this->timeoutSec = $this->clampInt($g['timeout_sec'] ?? 60, 5, 300);
        $this->connectTimeoutSec = $this->clampInt($g['connect_timeout_sec'] ?? 10, 1, 60);
        $this->verifySsl = (bool)($g['verify_ssl'] ?? true);

        $rt = $g['retry'] ?? [];
        $this->retryEnabled = (bool)($rt['enabled'] ?? true);
        $this->retryMaxAttempts = $this->clampInt($rt['max_attempts'] ?? 2, 1, 8);
        $this->retrySleepMs = $this->clampInt($rt['sleep_ms'] ?? 250, 0, 10000);

        $d = $g['defaults'] ?? [];
        $this->defaultProvider = (string)($d['provider'] ?? 'openai');
        $this->defaultModel = (string)($d['model'] ?? 'gpt-4o-mini');
        $this->defaultTemperature = (float)($d['temperature'] ?? 0.2);
        $this->defaultMaxTokens = $this->clampInt($d['max_tokens'] ?? 1200, 16, 32000);

        $lim = $g['limits'] ?? [];
        $this->maxPromptChars = $this->clampInt($lim['max_prompt_chars'] ?? 60000, 1000, 200000);
        $this->maxResponseChars = $this->clampInt($lim['max_response_chars'] ?? 120000, 1000, 400000);

        $cache = $g['cache'] ?? [];
        $this->cacheEnabled = (bool)($cache['enabled'] ?? false);
        $this->cacheDir = (string)($cache['dir'] ?? (__DIR__ . '/../../../private/storage/cache'));
        $this->cacheTtl = $this->clampInt($cache['ttl_sec'] ?? 900, 30, 86400);

        if ($this->cacheEnabled) {
            $this->ensureCacheDir();
        }
    }

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    public function isEnabled(): bool
    {
        return (bool)($this->config['gapgpt']['enabled'] ?? false);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiPath !== '' && $this->apiKey !== '';
    }

    public function assertReady(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('GapGPT integration is disabled in config.');
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('GapGPT is not configured: base_url/api_path/api_key missing.');
        }
    }

    // -------------------------------------------------------------------------
    // High-level API: Chat
    // -------------------------------------------------------------------------

    /**
     * Chat request (messages array: [{role:'system'|'user'|'assistant', content:'...'}])
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     * @return array<string,mixed> standardized response:
     *  [
     *    'ok' => bool,
     *    'text' => string,
     *    'raw' => array|null,
     *    'provider' => string,
     *    'model' => string,
     *    'usage' => array|null,
     *    'request_id' => string|null
     *  ]
     */
    public function chat(array $messages, array $options = []): array
    {
        $this->assertReady();

        $provider = (string)($options['provider'] ?? $this->defaultProvider);
        $model = (string)($options['model'] ?? $this->defaultModel);
        $temperature = isset($options['temperature']) ? (float)$options['temperature'] : $this->defaultTemperature;
        $maxTokens = isset($options['max_tokens']) ? (int)$options['max_tokens'] : $this->defaultMaxTokens;

        $temperature = max(0.0, min(2.0, $temperature));
        $maxTokens = max(16, min(32000, $maxTokens));

        $messages = $this->normalizeMessages($messages);
        $promptStr = $this->messagesToCompactString($messages);

        if (mb_strlen($promptStr) > $this->maxPromptChars) {
            // اگر پرامپت خیلی بزرگ است، بهترین کار truncation + هشدار
            $messages = $this->truncateMessagesToFit($messages, $this->maxPromptChars);
            $promptStr = $this->messagesToCompactString($messages);
        }

        // caching (optional) - only for deterministic-ish requests
        $cacheKey = null;
        if ($this->cacheEnabled && (bool)($options['cache'] ?? true)) {
            $cacheKey = $this->cacheKey('chat', [
                'provider' => $provider,
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ]);

            $hit = $this->cacheGet($cacheKey);
            if ($hit !== null) {
                $this->logger->info('GAPGPT_CACHE_HIT', ['key' => $cacheKey]);
                return $hit;
            }
        }

        $endpoint = $this->endpointForChat();

        $body = [
            // این payload را مطابق الگوی رایج aggregatorها نوشتیم:
            // اگر GapGPT شما فیلد متفاوت دارد، همینجا تغییر می‌دهی.
            'provider' => $provider,
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        // optional: tools/function calling
        if (isset($options['tools']) && is_array($options['tools'])) {
            $body['tools'] = $options['tools'];
        }
        if (isset($options['tool_choice'])) {
            $body['tool_choice'] = $options['tool_choice'];
        }

        // optional: response format
        if (isset($options['response_format']) && is_array($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        // optional: metadata
        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $body['metadata'] = $options['metadata'];
        }

        $raw = $this->requestRaw('POST', $endpoint, [], $body);

        $std = $this->standardizeChatResponse($raw, $provider, $model);

        if ($this->cacheEnabled && $cacheKey !== null) {
            $this->cacheSet($cacheKey, $std);
        }

        return $std;
    }

    // -------------------------------------------------------------------------
    // Low-level API
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $jsonBody
     * @return array<string,mixed>
     */
    public function requestRaw(string $method, string $endpoint, array $query = [], ?array $jsonBody = null): array
    {
        $this->assertReady();

        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new RuntimeException("Unsupported HTTP method: {$method}");
        }

        $url = $this->buildUrl($endpoint, $query);
        $headers = $this->buildHeaders($jsonBody);

        $attempt = 0;
        $maxAttempts = $this->retryEnabled ? $this->retryMaxAttempts : 1;
        $last = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $resp = $this->httpJson($method, $url, $headers, $jsonBody);

                $this->logger->info('GAPGPT_HTTP_OK', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                ]);

                return $resp;
            } catch (Throwable $e) {
                $last = $e;

                $this->logger->warning('GAPGPT_HTTP_FAIL', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'err' => $e->getMessage(),
                ]);

                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                if ($this->retrySleepMs > 0) {
                    usleep($this->retrySleepMs * 1000);
                }
            }
        }

        throw new RuntimeException('GapGPT request failed.' . ($last ? (' ' . $last->getMessage()) : ''));
    }

    // -------------------------------------------------------------------------
    // Endpoint mapping (customize here if GapGPT differs)
    // -------------------------------------------------------------------------

    /**
     * Endpoint for chat-completions style.
     * اگر GapGPT شما مثلاً /chat یا /ai/chat دارد، همین را تغییر بده.
     */
    private function endpointForChat(): string
    {
        // common style: /chat/completions OR /chat
        // ما پیش‌فرض را /chat/completions گذاشتیم چون با اکوسیستم رایج سازگار است
        return '/chat/completions';
    }

    // -------------------------------------------------------------------------
    // Response normalization
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function standardizeChatResponse(array $raw, string $provider, string $model): array
    {
        // سعی می‌کنیم از چند شکل متداول پاسخ متن را استخراج کنیم:
        // 1) OpenAI-like: choices[0].message.content
        // 2) Alternative: output.text / data.text / result.text / message
        $text = '';

        // OpenAI-like
        if (isset($raw['choices'][0]['message']['content']) && is_string($raw['choices'][0]['message']['content'])) {
            $text = (string)$raw['choices'][0]['message']['content'];
        } elseif (isset($raw['choices'][0]['text']) && is_string($raw['choices'][0]['text'])) {
            $text = (string)$raw['choices'][0]['text'];
        } elseif (isset($raw['output']['text']) && is_string($raw['output']['text'])) {
            $text = (string)$raw['output']['text'];
        } elseif (isset($raw['data']['text']) && is_string($raw['data']['text'])) {
            $text = (string)$raw['data']['text'];
        } elseif (isset($raw['result']['text']) && is_string($raw['result']['text'])) {
            $text = (string)$raw['result']['text'];
        } elseif (isset($raw['message']) && is_string($raw['message'])) {
            $text = (string)$raw['message'];
        }

        $text = trim($text);

        if (mb_strlen($text) > $this->maxResponseChars) {
            $text = mb_substr($text, 0, $this->maxResponseChars) . '...[truncated]';
        }

        $usage = null;
        if (isset($raw['usage']) && is_array($raw['usage'])) {
            $usage = $raw['usage'];
        }

        $requestId = null;
        if (isset($raw['id']) && is_string($raw['id'])) {
            $requestId = $raw['id'];
        } elseif (isset($raw['request_id']) && is_string($raw['request_id'])) {
            $requestId = $raw['request_id'];
        }

        return [
            'ok' => ($text !== ''),
            'text' => $text,
            'raw' => $raw,
            'provider' => $provider,
            'model' => $model,
            'usage' => $usage,
            'request_id' => $requestId,
        ];
    }

    // -------------------------------------------------------------------------
    // HTTP + Auth
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     */
    private function buildUrl(string $endpoint, array $query): string
    {
        $endpoint = '/' . ltrim($endpoint, '/');
        $url = $this->baseUrl . $this->apiPath . $endpoint;

        if (!empty($query)) {
            $qs = http_build_query($query);
            if ($qs !== '') {
                $url .= '?' . $qs;
            }
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
            'User-Agent: CRM-V2-GapGPTClient/1.0',
        ];

        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        if ($this->authMode === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        } else {
            $headers[] = 'X-API-KEY: ' . $this->apiKey;
        }

        return $headers;
    }

    /**
     * @param array<int,string> $headers
     * @param array<string,mixed>|null $jsonBody
     * @return array<string,mixed>
     */
    private function httpJson(string $method, string $url, array $headers, ?array $jsonBody): array
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
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSec,
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

        $rawHeaders = substr($raw, 0, $headerSize);
        $bodyRaw = substr($raw, $headerSize);

        $decoded = json_decode($bodyRaw, true);
        if (!is_array($decoded)) {
            // اگر پاسخ JSON نبود، یک wrapper بده تا خطاها قابل فهم شود
            $decoded = [
                'raw' => $this->truncate($bodyRaw, 4000),
            ];
        }

        if ($status < 200 || $status >= 300) {
            $msg = "GapGPT HTTP {$status}";

            if (isset($decoded['message']) && is_string($decoded['message'])) {
                $msg .= " - " . $decoded['message'];
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $msg .= " - " . $decoded['error'];
            }

            $hint = $this->extractRateLimitHint($rawHeaders);
            if ($hint) $msg .= " | hint: {$hint}";

            $msg .= " | body: " . $this->truncate($bodyRaw, 800);

            throw new RuntimeException($msg);
        }

        return $decoded;
    }

    private function extractRateLimitHint(string $rawHeaders): ?string
    {
        $h = strtolower($rawHeaders);

        if (strpos($h, 'retry-after:') !== false) {
            return 'Server asked to retry later (Retry-After present).';
        }
        if (strpos($h, 'x-ratelimit-remaining') !== false) {
            return 'Rate-limit headers present.';
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Message utilities
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @return array<int,array{role:string,content:string}>
     */
    private function normalizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $role = isset($m['role']) ? strtolower(trim((string)$m['role'])) : 'user';
            if (!in_array($role, ['system', 'user', 'assistant'], true)) $role = 'user';

            $content = isset($m['content']) ? (string)$m['content'] : '';
            $content = trim($content);

            if ($content === '') continue;

            $out[] = ['role' => $role, 'content' => $content];
        }

        if (count($out) === 0) {
            $out[] = ['role' => 'user', 'content' => 'سلام'];
        }

        return $out;
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     */
    private function messagesToCompactString(array $messages): string
    {
        $parts = [];
        foreach ($messages as $m) {
            $parts[] = '[' . $m['role'] . "] " . $m['content'];
        }
        return implode("\n", $parts);
    }

    /**
     * اگر پرامپت طولانی است، از ابتدای history کم می‌کند و system+آخر user را نگه می‌دارد.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return array<int,array{role:string,content:string}>
     */
    private function truncateMessagesToFit(array $messages, int $maxChars): array
    {
        if ($maxChars <= 0) return $messages;

        // Keep system messages
        $system = [];
        $rest = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') $system[] = $m;
            else $rest[] = $m;
        }

        // Keep last N of rest until fits
        $kept = [];
        for ($i = count($rest) - 1; $i >= 0; $i--) {
            array_unshift($kept, $rest[$i]);
            $candidate = array_merge($system, $kept);
            if (mb_strlen($this->messagesToCompactString($candidate)) > $maxChars) {
                array_shift($kept);
                break;
            }
        }

        $out = array_merge($system, $kept);
        if (count($out) === 0) {
            return [['role' => 'user', 'content' => '...']];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Cache (file-based)
    // -------------------------------------------------------------------------

    private function ensureCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function cacheKey(string $prefix, array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $prefix . '_' . hash('sha256', $json ?: '');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cacheGet(string $key): ?array
    {
        $file = rtrim($this->cacheDir, '/') . '/' . $key . '.json';
        if (!is_file($file)) return null;

        $mtime = @filemtime($file);
        if ($mtime === false) return null;

        if (time() - $mtime > $this->cacheTtl) {
            @unlink($file);
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') return null;

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return null;

        return $decoded;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function cacheSet(string $key, array $value): void
    {
        $file = rtrim($this->cacheDir, '/') . '/' . $key . '.json';
        $raw = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($raw === false) return;
        @file_put_contents($file, $raw);
    }

    // -------------------------------------------------------------------------
    // Utils
    // -------------------------------------------------------------------------

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

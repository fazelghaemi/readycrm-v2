<?php
/**
 * File: app/Support/Logger.php
 *
 * CRM V2 - Structured Logger (File-based)
 * ------------------------------------------------------------
 * هدف:
 *  - تولید لاگ‌های قابل اعتماد و قابل خواندن برای Debug/Monitoring
 *  - ذخیره لاگ در فایل با چرخش روزانه (daily rotation)
 *  - ثبت context استاندارد (request_id, ip, url, user_agent, user_id ...)
 *  - ماسک کردن اطلاعات حساس
 *
 * ویژگی‌ها:
 *  - JSON lines format (هر خط یک JSON)
 *  - سطوح: debug, info, warn, error, critical
 *  - قابلیت خاموش/روشن کردن debug بر اساس env
 *  - جلوگیری از رشد بی‌نهایت فایل با rotation روزانه و optional pruning
 *
 * مسیر پیشنهادی:
 *  private/storage/logs/
 *    app-YYYY-MM-DD.log
 *    migrations.log (توسط Migrator)
 *
 * نکته:
 *  - این logger برای سادگی از هیچ پکیج خارجی استفاده نمی‌کند.
 */

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use Throwable;

final class Logger
{
    private string $logDir;
    private bool $debugEnabled;

    /**
     * اگر true باشد، علاوه بر فایل، در CLI هم echo می‌کند (اختیاری).
     */
    private bool $echoInCli = false;

    /**
     * کلیدهایی که باید ماسک شوند
     * @var array<int,string>
     */
    private array $sensitiveKeys = [
        'password', 'pass', 'pwd',
        'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'secret', 'client_secret',
        'authorization', 'auth', 'cookie', 'set-cookie',
        'ck', 'cs', // Woo keys
    ];

    /**
     * حداکثر طول message/context برای جلوگیری از انفجار لاگ
     */
    private int $maxStringLen = 5000;

    /**
     * حداکثر عمق آرایه برای serialize
     */
    private int $maxDepth = 8;

    /**
     * @param string $logDir مسیر پوشه لاگ
     * @param bool $debugEnabled اگر true، debug هم نوشته می‌شود
     */
    public function __construct(string $logDir, bool $debugEnabled = false)
    {
        $this->logDir = rtrim($logDir, DIRECTORY_SEPARATOR);
        $this->debugEnabled = $debugEnabled;

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
    }

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    public function setEchoInCli(bool $enabled): void
    {
        $this->echoInCli = $enabled;
    }

    public function debug(string $event, array $context = []): void
    {
        if (!$this->debugEnabled) return;
        $this->write('debug', $event, $context);
    }

    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    public function warn(string $event, array $context = []): void
    {
        $this->write('warn', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    public function critical(string $event, array $context = []): void
    {
        $this->write('critical', $event, $context);
    }

    /**
     * ثبت Exception با جزئیات کامل (به‌صورت امن)
     */
    public function exception(string $event, Throwable $e, array $context = []): void
    {
        $context['exception'] = $this->formatException($e);
        $this->write('error', $event, $context);
    }

    /**
     * حذف لاگ‌های قدیمی‌تر از N روز (اختیاری)
     * @param int $daysKeep تعداد روز نگهداری
     */
    public function prune(int $daysKeep = 30): void
    {
        if ($daysKeep < 1) $daysKeep = 1;

        $files = glob($this->logDir . DIRECTORY_SEPARATOR . '*.log') ?: [];
        $cutoff = time() - ($daysKeep * 86400);

        foreach ($files as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($f);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function write(string $level, string $event, array $context): void
    {
        $now = new DateTimeImmutable();

        $record = [
            'ts' => $now->format('c'),
            'level' => $level,
            'event' => $event,
            'context' => $this->sanitize($context),
            'meta' => $this->defaultMeta(),
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            // fallback minimal
            $line = '{"ts":"' . $now->format('c') . '","level":"error","event":"LOGGER_JSON_ENCODE_FAILED"}';
        }

        $file = $this->dailyFilePath('app');

        // best-effort write
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);

        // optional cli echo
        if ($this->echoInCli && php_sapi_name() === 'cli') {
            echo $line . PHP_EOL;
        }
    }

    /**
     * ساخت مسیر فایل روزانه مثل: app-2026-02-06.log
     */
    private function dailyFilePath(string $prefix): string
    {
        $date = (new DateTimeImmutable())->format('Y-m-d');
        return $this->logDir . DIRECTORY_SEPARATOR . $prefix . '-' . $date . '.log';
    }

    /**
     * اطلاعات عمومی درخواست/محیط
     * @return array<string,mixed>
     */
    private function defaultMeta(): array
    {
        $meta = [
            'php_sapi' => php_sapi_name(),
            'request_id' => $this->getRequestId(),
        ];

        if (php_sapi_name() !== 'cli') {
            $meta['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            $meta['method'] = $_SERVER['REQUEST_METHOD'] ?? null;
            $meta['uri'] = $_SERVER['REQUEST_URI'] ?? null;
            $meta['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // user_id اگر سیستم auth آن را داخل session گذاشته باشد
            $meta['user_id'] = $_SESSION['user_id'] ?? null;
        }

        return $meta;
    }

    /**
     * تولید یا دریافت request_id برای trace کردن
     */
    private function getRequestId(): string
    {
        // اگر در runtime قبلاً ساخته شده
        if (isset($GLOBALS['crm_request_id']) && is_string($GLOBALS['crm_request_id'])) {
            return $GLOBALS['crm_request_id'];
        }

        // اگر header وجود داشته باشد (در reverse proxy)
        $hdr = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        if (is_string($hdr) && $hdr !== '') {
            $GLOBALS['crm_request_id'] = $hdr;
            return $hdr;
        }

        // تولید تصادفی
        $id = bin2hex(random_bytes(8)) . '-' . dechex(time());
        $GLOBALS['crm_request_id'] = $id;
        return $id;
    }

    /**
     * پاکسازی context:
     * - ماسک اطلاعات حساس
     * - محدود کردن طول
     * - جلوگیری از recursion/عمق زیاد
     */
    private function sanitize(array $context): array
    {
        return $this->sanitizeValue($context, 0);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeValue($value, int $depth)
    {
        if ($depth > $this->maxDepth) {
            return '[max_depth_reached]';
        }

        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if ($value instanceof Throwable) {
            return $this->formatException($value);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? $k : (string)$k;

                if ($this->isSensitiveKey($key)) {
                    $out[$key] = '[masked]';
                    continue;
                }

                $out[$key] = $this->sanitizeValue($v, $depth + 1);
            }
            return $out;
        }

        if (is_object($value)) {
            // اگر object قابل jsonSerialize باشد
            if ($value instanceof \JsonSerializable) {
                try {
                    $data = $value->jsonSerialize();
                    return $this->sanitizeValue($data, $depth + 1);
                } catch (Throwable $e) {
                    return '[object_jsonserialize_failed]';
                }
            }

            // اگر __toString داشت
            if (method_exists($value, '__toString')) {
                try {
                    return $this->truncate((string)$value);
                } catch (Throwable $e) {
                    return '[object_tostring_failed]';
                }
            }

            // fallback
            return '[object:' . get_class($value) . ']';
        }

        // resource یا سایر موارد
        return '[unserializable]';
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower(trim($key));
        if ($key === '') return false;

        // exact matches
        foreach ($this->sensitiveKeys as $s) {
            if ($key === $s) return true;
        }

        // partial matches (مثلاً apiKey, user_password)
        foreach ($this->sensitiveKeys as $s) {
            if ($s !== '' && str_contains($key, $s)) return true;
        }

        // Authorization header pattern
        if (str_contains($key, 'authorization')) return true;

        return false;
    }

    private function truncate(string $s): string
    {
        if (mb_strlen($s, 'UTF-8') <= $this->maxStringLen) {
            return $s;
        }
        return mb_substr($s, 0, $this->maxStringLen, 'UTF-8') . '...[truncated]';
    }

    /**
     * Exception formatter
     * @return array<string,mixed>
     */
    private function formatException(Throwable $e): array
    {
        return [
            'type' => get_class($e),
            'message' => $this->truncate($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
            'trace' => $this->truncate($e->getTraceAsString()),
        ];
    }
}

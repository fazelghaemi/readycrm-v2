<?php
/**
 * File: app/Jobs/Workers/worker.php
 *
 * CRM V2 - Queue Worker (CLI)
 * -----------------------------------------------------------------------------
 * این فایل «ورکر صف» است؛ یعنی یک اسکریپت CLI که دائماً اجرا می‌شود و:
 *  1) از جدول jobs_queue یک job را reserve می‌کند
 *  2) job handler مربوطه را پیدا می‌کند
 *  3) اجرا می‌کند
 *  4) در صورت موفقیت ack، در صورت خطا fail/retry
 *
 * -----------------------------------------------------------------------------
 * اجرا:
 *   php app/Jobs/Workers/worker.php
 *
 * گزینه‌ها:
 *   --queue=default          نام صف
 *   --once=1                 فقط یک job را اجرا کند و تمام
 *   --max-jobs=100           بعد از N job خارج شود
 *   --max-seconds=3600       بعد از N ثانیه خارج شود
 *   --sleep-ms=500           وقتی صف خالی است چقدر صبر کند
 *   --verbose=1              لاگ بیشتر
 *
 * -----------------------------------------------------------------------------
 * پیش‌نیاز:
 * - Queue.php
 * - DI Container (app/Bootstrap/container.php)
 * - Logger
 * - Job classes (در app/Jobs/AI و app/Jobs/Woo ...)
 *
 * -----------------------------------------------------------------------------
 * نکته:
 * - برای سادگی و قابلیت دیباگ، job dispatch اینجا به 2 روش پشتیبانی می‌کند:
 *   1) payload['handler'] = 'FQCN' و آن کلاس method handle($payload) داشته باشد
 *   2) خود ستون job در DB، FQCN باشد و method handle داشته باشد
 *
 * - اگر job class از Container نیاز دارد، آن را از container resolve می‌کنیم.
 */

declare(strict_types=1);

use App\Support\Logger;
use App\Jobs\Queue;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------

$root = realpath(__DIR__ . '/../../..');
if ($root === false) {
    echo "Failed to locate project root.\n";
    exit(1);
}

require_once $root . '/vendor/autoload.php';

// اگر شما autoload سفارشی دارید (مثلاً app/Bootstrap/autoload.php) می‌توانید اینجا اضافه کنید.
// require_once $root . '/app/Bootstrap/autoload.php';

$containerFile = $root . '/app/Bootstrap/container.php';
if (!is_file($containerFile)) {
    echo "Missing container bootstrap: {$containerFile}\n";
    exit(1);
}

/** @var mixed $container */
$container = require $containerFile;

// -----------------------------------------------------------------------------
// Helper: safely resolve from container
// -----------------------------------------------------------------------------
/**
 * @param mixed $container
 * @param string $id
 * @return mixed
 */
$resolve = function ($container, string $id) {
    // سناریوهای رایج:
    // - container->get($id)
    // - container[$id]
    // - function container($id)
    if (is_object($container)) {
        if (method_exists($container, 'get')) {
            return $container->get($id);
        }
        if (method_exists($container, 'resolve')) {
            return $container->resolve($id);
        }
    }
    if (is_array($container) && array_key_exists($id, $container)) {
        return $container[$id];
    }
    throw new RuntimeException("Container cannot resolve: {$id}");
};

// -----------------------------------------------------------------------------
// Resolve services
// -----------------------------------------------------------------------------
/** @var Logger $logger */
$logger = $resolve($container, Logger::class);

/** @var Queue $queue */
$queue = $resolve($container, Queue::class);

// -----------------------------------------------------------------------------
// CLI args
// -----------------------------------------------------------------------------
$args = parseArgs($argv);

$queueName  = (string)($args['queue'] ?? 'default');
$once       = (bool)intValSafe($args['once'] ?? 0);
$maxJobs    = (int)intValSafe($args['max-jobs'] ?? 0);
$maxSeconds = (int)intValSafe($args['max-seconds'] ?? 0);
$sleepMs    = (int)intValSafe($args['sleep-ms'] ?? 0);
$verbose    = (bool)intValSafe($args['verbose'] ?? 0);

if ($sleepMs > 0) {
    // اگر کاربر sleep را مشخص کرد، بر config پیش‌فرض Queue ترجیح دارد
    // (بدون دستکاری config داخلی، اینجا استفاده می‌کنیم)
}

$logger->info('WORKER_START', [
    'queue' => $queueName,
    'once' => $once,
    'max_jobs' => $maxJobs,
    'max_seconds' => $maxSeconds,
    'sleep_ms' => $sleepMs,
    'pid' => getmypid(),
]);

// -----------------------------------------------------------------------------
// Graceful shutdown (signals)
// -----------------------------------------------------------------------------
$stop = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () use (&$stop, $logger) {
        $stop = true;
        $logger->warning('WORKER_SIGNAL', ['signal' => 'SIGINT']);
    });
    pcntl_signal(SIGTERM, function () use (&$stop, $logger) {
        $stop = true;
        $logger->warning('WORKER_SIGNAL', ['signal' => 'SIGTERM']);
    });
}

// -----------------------------------------------------------------------------
// Main loop
// -----------------------------------------------------------------------------
$startedAt = time();
$processed = 0;

while (true) {
    if ($stop) {
        $logger->warning('WORKER_STOP_REQUESTED', []);
        break;
    }

    if ($maxSeconds > 0 && (time() - $startedAt) >= $maxSeconds) {
        $logger->info('WORKER_MAX_SECONDS_REACHED', ['max_seconds' => $maxSeconds]);
        break;
    }

    if ($maxJobs > 0 && $processed >= $maxJobs) {
        $logger->info('WORKER_MAX_JOBS_REACHED', ['max_jobs' => $maxJobs]);
        break;
    }

    // Reserve a job
    try {
        $job = $queue->reserve($queueName);
    } catch (Throwable $e) {
        $logger->error('WORKER_RESERVE_ERROR', ['err' => $e->getMessage()]);
        // اگر DB مشکل داشت، کمی صبر
        sleepMs(500);
        continue;
    }

    if ($job === null) {
        // no job
        if ($once) {
            $logger->info('WORKER_ONCE_EMPTY', []);
            break;
        }

        if ($sleepMs > 0) {
            sleepMs($sleepMs);
        } else {
            $queue->sleepWhenEmpty();
        }
        continue;
    }

    $processed++;
    $jobId = (int)$job['id'];
    $jobClass = (string)$job['job'];
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $attempts = (int)($job['attempts'] ?? 0);
    $maxAttempts = (int)($job['max_attempts'] ?? 3);

    $logger->info('WORKER_JOB_RESERVED', [
        'id' => $jobId,
        'job' => $jobClass,
        'attempts' => $attempts,
        'max_attempts' => $maxAttempts,
    ]);

    // Run job
    try {
        $result = dispatchJob($container, $resolve, $jobClass, $payload, $logger, $verbose);

        // ack
        $queue->ack($jobId);

        $logger->info('WORKER_JOB_DONE', [
            'id' => $jobId,
            'job' => $jobClass,
            'result' => summarizeResult($result),
        ]);
    } catch (Throwable $e) {
        // fail with retry policy
        $err = $e->getMessage();

        // اگر خود job گفت retry نکن (مثلاً payload['no_retry']=true)
        $retry = !(bool)($payload['no_retry'] ?? false);

        try {
            $queue->fail($jobId, $err, $retry);
        } catch (Throwable $e2) {
            $logger->error('WORKER_FAIL_RECORDING_ERROR', [
                'id' => $jobId,
                'job' => $jobClass,
                'err' => $e2->getMessage(),
            ]);
        }

        $logger->error('WORKER_JOB_FAILED', [
            'id' => $jobId,
            'job' => $jobClass,
            'err' => $err,
            'retry' => $retry,
        ]);
    }

    if ($once) {
        $logger->info('WORKER_ONCE_DONE', ['processed' => $processed]);
        break;
    }
}

$logger->info('WORKER_EXIT', [
    'processed' => $processed,
    'uptime_sec' => time() - $startedAt,
    'queue' => $queueName,
]);

exit(0);

// =============================================================================
// Functions
// =============================================================================

/**
 * Parse CLI arguments like:
 *   --queue=default --once=1
 *
 * @param array<int,string> $argv
 * @return array<string,string>
 */
function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if (!str_starts_with($arg, '--')) continue;

        $arg = substr($arg, 2);
        if ($arg === '') continue;

        if (strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k !== '') $out[$k] = $v;
        } else {
            // flags like --verbose
            $out[$arg] = '1';
        }
    }
    return $out;
}

function intValSafe(mixed $v): int
{
    if (is_bool($v)) return $v ? 1 : 0;
    if (!is_numeric($v)) return 0;
    return (int)$v;
}

function sleepMs(int $ms): void
{
    if ($ms <= 0) return;
    usleep($ms * 1000);
}

/**
 * Dispatches job to a handler.
 *
 * Supported:
 *  1) $jobClass is FQCN and has handle(array $payload): mixed
 *  2) payload['handler'] is FQCN and has handle($payload)
 *  3) handler implements __invoke($payload)
 *
 * @param mixed $container
 * @param callable $resolve
 * @return mixed
 */
function dispatchJob($container, callable $resolve, string $jobClass, array $payload, Logger $logger, bool $verbose)
{
    $handlerClass = $jobClass;

    if (isset($payload['handler']) && is_string($payload['handler']) && trim($payload['handler']) !== '') {
        $handlerClass = trim($payload['handler']);
    }

    if (!class_exists($handlerClass)) {
        // برای پروژه‌هایی که autoload سفارشی دارند، class_exists ممکن است false بدهد
        // اما فایل هنوز موجود باشد. اینجا خطای واضح می‌دهیم.
        throw new RuntimeException("Job handler class not found: {$handlerClass}");
    }

    // Resolve handler instance from container if possible
    $handler = null;

    try {
        $handler = $resolve($container, $handlerClass);
    } catch (Throwable $e) {
        // fallback: direct instantiate
        $handler = new $handlerClass();
    }

    if ($verbose) {
        $logger->info('WORKER_HANDLER_RESOLVED', [
            'handler' => $handlerClass,
            'resolved_via_container' => is_object($handler),
        ]);
    }

    // Provide context to payload (optional)
    // اینجا می‌تواند برای jobها مفید باشد: job_id، زمان، ...
    $payload['_meta'] = array_merge((array)($payload['_meta'] ?? []), [
        'dispatched_at' => date('c'),
        'handler' => $handlerClass,
    ]);

    // Execute
    if (is_object($handler) && method_exists($handler, 'handle')) {
        return $handler->handle($payload);
    }

    if (is_callable($handler)) {
        return $handler($payload);
    }

    throw new RuntimeException("Job handler is not executable: {$handlerClass} (no handle() / not callable)");
}

/**
 * Keep logs smaller.
 * @param mixed $result
 * @return mixed
 */
function summarizeResult($result)
{
    if (is_array($result)) {
        // keep first-level only, and only a few keys
        $keep = ['ok', 'id', 'status', 'message', 'count', 'processed', 'sent', 'failed', 'dead'];
        $out = [];
        foreach ($keep as $k) {
            if (array_key_exists($k, $result)) $out[$k] = $result[$k];
        }
        if ($out !== []) return $out;

        // otherwise just size hint
        return ['type' => 'array', 'keys' => array_slice(array_keys($result), 0, 12)];
    }

    if (is_string($result)) {
        $len = mb_strlen($result);
        return $len > 200 ? (mb_substr($result, 0, 200) . '...[truncated]') : $result;
    }

    if (is_bool($result) || is_int($result) || is_float($result) || $result === null) {
        return $result;
    }

    return ['type' => gettype($result)];
}

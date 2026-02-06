<?php
/**
 * File: app/Jobs/AI/RunAIRequestJob.php
 *
 * CRM V2 - Job: Run AI Request (GapGPT)
 * -----------------------------------------------------------------------------
 * این Job برای اجرای درخواست‌های AI به صورت Async (صف) طراحی شده است.
 *
 * سناریو پیشنهادی:
 * - UI/Controller یک AI Request ثبت می‌کند (در DB یا هر storage)
 * - سپس یک job در jobs_queue enqueue می‌کند با payload:
 *     [
 *       'request_id' => 123,
 *       'scenario' => 'product.description.generate',
 *       'input' => [...],
 *       'options' => [...],      // override provider/model/temperature/...
 *       'actor' => [...],        // اطلاعات کاربر/اپراتور (اختیاری)
 *     ]
 * - Worker این job را اجرا می‌کند و:
 *   1) سناریو را از AIScenarios می‌گیرد
 *   2) messages + options را می‌سازد
 *   3) GapGPTClient::chat را صدا می‌زند
 *   4) parseOutput + post-process را اجرا می‌کند
 *   5) نتیجه را در DB ذخیره می‌کند (ai_requests table)
 *   6) لاگ را در DB ذخیره می‌کند (ai_logs table)
 *
 * -----------------------------------------------------------------------------
 * جدول‌های پیشنهادی (در فاز دیتابیس کامل):
 *
 * ai_requests:
 *  - id
 *  - status: pending|running|done|failed
 *  - scenario_key
 *  - input_json
 *  - options_json
 *  - output_text
 *  - output_json
 *  - warnings_json
 *  - provider, model
 *  - tokens_prompt, tokens_completion, tokens_total (اگر usage برگشت)
 *  - error_message
 *  - started_at, finished_at
 *  - created_at, updated_at
 *
 * ai_logs:
 *  - id
 *  - request_id
 *  - level: info|warning|error
 *  - event
 *  - meta_json
 *  - created_at
 *
 * -----------------------------------------------------------------------------
 * نکته:
 * - این Job طوری نوشته شده که اگر جدول‌ها هنوز وجود ندارند،
 *   باز هم سیستم crash نکند (best-effort persistence).
 */

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Integrations\GapGPT\AIScenarios;
use App\Integrations\GapGPT\GapGPTClient;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class RunAIRequestJob
{
    private PDO $pdo;
    private Logger $logger;
    private GapGPTClient $gapgpt;

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(PDO $pdo, Logger $logger, GapGPTClient $gapgpt, array $config = [])
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->gapgpt = $gapgpt;
        $this->config = $config;

        $this->ensureTablesBestEffort();
    }

    /**
     * Main handler for queue worker.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $requestId = isset($payload['request_id']) ? (int)$payload['request_id'] : 0;
        $scenarioKey = isset($payload['scenario']) ? (string)$payload['scenario'] : '';
        $input = isset($payload['input']) && is_array($payload['input']) ? $payload['input'] : [];
        $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];
        $actor = isset($payload['actor']) && is_array($payload['actor']) ? $payload['actor'] : [];

        if ($scenarioKey === '') {
            throw new RuntimeException('RunAIRequestJob: scenario is required.');
        }

        $this->logger->info('AI_JOB_START', [
            'request_id' => $requestId,
            'scenario' => $scenarioKey,
        ]);

        $this->logToDb($requestId, 'info', 'AI_JOB_START', [
            'scenario' => $scenarioKey,
            'actor' => $this->safeActor($actor),
        ]);

        $startedAt = time();

        // mark request running
        $this->markRequestRunning($requestId, $scenarioKey, $input, $options);

        try {
            // 1) Scenario
            $scenario = AIScenarios::get($scenarioKey);

            // 2) Build messages & options
            $messages = AIScenarios::buildMessages($scenario, $input);
            $chatOptions = AIScenarios::buildOptions($scenario, $options);

            // 3) Call GapGPT
            $resp = $this->gapgpt->chat($messages, $chatOptions);

            // 4) Parse output
            $parsed = AIScenarios::parseOutput($scenario, (string)($resp['text'] ?? ''), is_array($resp['raw'] ?? null) ? $resp['raw'] : null);

            // 5) Save result
            $provider = (string)($resp['provider'] ?? ($chatOptions['provider'] ?? ''));
            $model = (string)($resp['model'] ?? ($chatOptions['model'] ?? ''));

            $usage = is_array($resp['usage'] ?? null) ? $resp['usage'] : null;

            $this->markRequestDone(
                $requestId,
                $scenarioKey,
                $parsed,
                $provider,
                $model,
                $usage,
                $startedAt
            );

            $this->logToDb($requestId, 'info', 'AI_JOB_DONE', [
                'scenario' => $scenarioKey,
                'ok' => (bool)($parsed['ok'] ?? false),
                'warnings' => $parsed['warnings'] ?? [],
                'provider' => $provider,
                'model' => $model,
                'usage' => $usage,
                'duration_sec' => time() - $startedAt,
            ]);

            $this->logger->info('AI_JOB_DONE', [
                'request_id' => $requestId,
                'scenario' => $scenarioKey,
                'ok' => (bool)($parsed['ok'] ?? false),
                'duration_sec' => time() - $startedAt,
            ]);

            return [
                'ok' => true,
                'request_id' => $requestId,
                'scenario' => $scenarioKey,
                'ai_ok' => (bool)($parsed['ok'] ?? false),
                'warnings' => $parsed['warnings'] ?? [],
                'duration_sec' => time() - $startedAt,
            ];
        } catch (Throwable $e) {
            $this->markRequestFailed($requestId, $scenarioKey, $e->getMessage(), $startedAt);

            $this->logToDb($requestId, 'error', 'AI_JOB_FAILED', [
                'scenario' => $scenarioKey,
                'error' => $e->getMessage(),
                'duration_sec' => time() - $startedAt,
            ]);

            $this->logger->error('AI_JOB_FAILED', [
                'request_id' => $requestId,
                'scenario' => $scenarioKey,
                'err' => $e->getMessage(),
            ]);

            // rethrow so worker marks queue job failed/retry
            throw $e;
        }
    }

    // =============================================================================
    // Persistence (best-effort)
    // =============================================================================

    private function markRequestRunning(int $requestId, string $scenarioKey, array $input, array $options): void
    {
        if ($requestId <= 0) {
            return; // request_id optional
        }

        if (!$this->tableExists('ai_requests')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $inputJson = $this->json($input);
        $optionsJson = $this->json($options);

        // اگر رکورد وجود دارد => update، اگر ندارد => insert
        if ($this->rowExists('ai_requests', $requestId)) {
            $stmt = $this->pdo->prepare("
                UPDATE ai_requests
                SET status='running',
                    scenario_key=:scenario,
                    input_json=:input_json,
                    options_json=:options_json,
                    error_message=NULL,
                    started_at=:started_at,
                    updated_at=:now
                WHERE id=:id
                LIMIT 1
            ");
            $stmt->execute([
                ':id' => $requestId,
                ':scenario' => $scenarioKey,
                ':input_json' => $inputJson,
                ':options_json' => $optionsJson,
                ':started_at' => $now,
                ':now' => $now,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_requests
                  (id, status, scenario_key, input_json, options_json, created_at, updated_at, started_at)
                VALUES
                  (:id, 'running', :scenario, :input_json, :options_json, :now, :now, :started_at)
            ");
            $stmt->execute([
                ':id' => $requestId,
                ':scenario' => $scenarioKey,
                ':input_json' => $inputJson,
                ':options_json' => $optionsJson,
                ':now' => $now,
                ':started_at' => $now,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $parsed
     * @param array<string,mixed>|null $usage
     */
    private function markRequestDone(
        int $requestId,
        string $scenarioKey,
        array $parsed,
        string $provider,
        string $model,
        ?array $usage,
        int $startedAtUnix
    ): void {
        if ($requestId <= 0 || !$this->tableExists('ai_requests')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $finishedAt = $now;

        $outputText = (string)($parsed['text'] ?? '');
        $outputJson = null;

        if (($parsed['format'] ?? '') === 'json' && is_array($parsed['data'] ?? null)) {
            $outputJson = $this->json($parsed['data']);
        }

        $warningsJson = $this->json($parsed['warnings'] ?? []);

        $tokensPrompt = null;
        $tokensCompletion = null;
        $tokensTotal = null;

        if (is_array($usage)) {
            // OpenAI-like usage
            if (isset($usage['prompt_tokens']) && is_numeric($usage['prompt_tokens'])) {
                $tokensPrompt = (int)$usage['prompt_tokens'];
            }
            if (isset($usage['completion_tokens']) && is_numeric($usage['completion_tokens'])) {
                $tokensCompletion = (int)$usage['completion_tokens'];
            }
            if (isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])) {
                $tokensTotal = (int)$usage['total_tokens'];
            }
        }

        $durationSec = max(0, time() - $startedAtUnix);

        $stmt = $this->pdo->prepare("
            UPDATE ai_requests
            SET status='done',
                scenario_key=:scenario,
                output_text=:output_text,
                output_json=:output_json,
                warnings_json=:warnings_json,
                provider=:provider,
                model=:model,
                tokens_prompt=:tp,
                tokens_completion=:tc,
                tokens_total=:tt,
                duration_sec=:dur,
                finished_at=:finished_at,
                updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $requestId,
            ':scenario' => $scenarioKey,
            ':output_text' => $this->truncate($outputText, 120000),
            ':output_json' => $outputJson,
            ':warnings_json' => $warningsJson,
            ':provider' => $this->truncate($provider, 80),
            ':model' => $this->truncate($model, 120),
            ':tp' => $tokensPrompt,
            ':tc' => $tokensCompletion,
            ':tt' => $tokensTotal,
            ':dur' => $durationSec,
            ':finished_at' => $finishedAt,
            ':now' => $now,
        ]);
    }

    private function markRequestFailed(int $requestId, string $scenarioKey, string $error, int $startedAtUnix): void
    {
        if ($requestId <= 0 || !$this->tableExists('ai_requests')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $durationSec = max(0, time() - $startedAtUnix);

        $stmt = $this->pdo->prepare("
            UPDATE ai_requests
            SET status='failed',
                scenario_key=:scenario,
                error_message=:err,
                duration_sec=:dur,
                finished_at=:finished_at,
                updated_at=:now
            WHERE id=:id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $requestId,
            ':scenario' => $scenarioKey,
            ':err' => $this->truncate($error, 8000),
            ':dur' => $durationSec,
            ':finished_at' => $now,
            ':now' => $now,
        ]);
    }

    /**
     * DB log (best-effort).
     * @param array<string,mixed> $meta
     */
    private function logToDb(int $requestId, string $level, string $event, array $meta): void
    {
        if (!$this->tableExists('ai_logs')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            INSERT INTO ai_logs
              (request_id, level, event, meta_json, created_at)
            VALUES
              (:request_id, :level, :event, :meta_json, :created_at)
        ");

        $stmt->execute([
            ':request_id' => $requestId > 0 ? $requestId : null,
            ':level' => $this->truncate($level, 20),
            ':event' => $this->truncate($event, 80),
            ':meta_json' => $this->json($meta),
            ':created_at' => $now,
        ]);
    }

    // =============================================================================
    // Best-effort table creation
    // =============================================================================

    private function ensureTablesBestEffort(): void
    {
        // اگر DB permission ندهد، نباید سیستم fail کند
        try {
            $this->ensureAiRequestsTable();
            $this->ensureAiLogsTable();
        } catch (Throwable $e) {
            // silent (but log)
            $this->logger->warning('AI_JOB_TABLES_CREATE_FAILED', ['err' => $e->getMessage()]);
        }
    }

    private function ensureAiRequestsTable(): void
    {
        $sql = "
CREATE TABLE IF NOT EXISTS ai_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  scenario_key VARCHAR(120) NOT NULL,
  input_json MEDIUMTEXT NULL,
  options_json MEDIUMTEXT NULL,
  output_text MEDIUMTEXT NULL,
  output_json MEDIUMTEXT NULL,
  warnings_json MEDIUMTEXT NULL,
  provider VARCHAR(80) NULL,
  model VARCHAR(120) NULL,
  tokens_prompt INT NULL,
  tokens_completion INT NULL,
  tokens_total INT NULL,
  duration_sec INT NULL,
  error_message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_scenario (scenario_key),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        $this->pdo->exec($sql);
    }

    private function ensureAiLogsTable(): void
    {
        $sql = "
CREATE TABLE IF NOT EXISTS ai_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT NULL,
  level VARCHAR(20) NOT NULL,
  event VARCHAR(80) NOT NULL,
  meta_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_request (request_id),
  KEY idx_level (level),
  KEY idx_event (event),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        $this->pdo->exec($sql);
    }

    // =============================================================================
    // Schema helpers
    // =============================================================================

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

    private function rowExists(string $table, int $id): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE id=:id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (bool)$row;
        } catch (Throwable $e) {
            return false;
        }
    }

    // =============================================================================
    // Utils
    // =============================================================================

    private function json(mixed $v): string
    {
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($j === false) return '{}';
        return $j;
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function safeActor(array $actor): array
    {
        // اطلاعات حساس را حذف کن
        $deny = ['password', 'pass', 'token', 'api_key', 'secret'];
        foreach ($deny as $k) {
            if (array_key_exists($k, $actor)) {
                unset($actor[$k]);
            }
        }

        // فقط چند فیلد معقول
        $allow = ['id', 'name', 'email', 'role'];
        $out = [];
        foreach ($allow as $k) {
            if (array_key_exists($k, $actor)) {
                $out[$k] = $actor[$k];
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

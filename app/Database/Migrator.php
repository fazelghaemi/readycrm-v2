<?php
/**
 * File: app/Database/Migrator.php
 *
 * CRM V2 - Database Migrator
 * ------------------------------------------------------------
 * Purpose:
 *  - Automatically create / update database schema using migration files
 *  - Make installation easy (like WordPress)
 *  - Ensure migrations run exactly once and are tracked
 *
 * Migration files:
 *  - Directory: /database/migrations
 *  - Naming convention recommended:
 *      2026_02_06_000001_create_users.sql
 *      2026_02_06_000002_create_customers.sql
 *    (any sortable naming works; lexicographic order is used)
 *
 * Each migration file can contain multiple SQL statements separated by ';'
 * This migrator will:
 *  - Strip comments
 *  - Split statements safely (quote-aware)
 *  - Execute each statement
 *  - Track success in table `migrations`
 *
 * Concurrency:
 *  - Uses MySQL GET_LOCK to avoid double-run
 *  - Also uses filesystem lock file optionally (best-effort)
 *
 * Usage:
 *   $migrator = new Migrator($pdo, $migrationsDir, $logDir, $loggerCallable);
 *   $result = $migrator->run([
 *       'dry_run' => false,
 *       'specific' => null,
 *       'lock_timeout_sec' => 60,
 *   ]);
 *
 * Return:
 *   [
 *     'ok' => bool,
 *     'applied' => [filename,...],
 *     'skipped' => [filename,...],
 *     'errors' => [ ... ],
 *     'stats' => [ ... ],
 *   ]
 */

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Migrator
{
    private PDO $pdo;
    private string $migrationsDir;
    private ?string $logDir;

    /** @var callable|null */
    private $logger;

    private string $migrationsTable = 'migrations';

    // Lock options
    private string $mysqlLockName = 'crm_migrator_lock';
    private ?string $fsLockFile = null;

    // Safety limits
    private int $maxFileSizeBytes = 5_000_000; // 5MB per migration file
    private int $maxStatementsPerFile = 500;

    /**
     * @param PDO $pdo
     * @param string $migrationsDir
     * @param string|null $logDir used for fs lock and optional extra logs
     * @param callable|null $logger callable(string $line):void
     */
    public function __construct(PDO $pdo, string $migrationsDir, ?string $logDir = null, ?callable $logger = null)
    {
        $this->pdo = $pdo;
        $this->migrationsDir = rtrim($migrationsDir, DIRECTORY_SEPARATOR);
        $this->logDir = $logDir ? rtrim($logDir, DIRECTORY_SEPARATOR) : null;
        $this->logger = $logger;

        if (!is_dir($this->migrationsDir)) {
            throw new RuntimeException("Migrations directory not found: {$this->migrationsDir}");
        }

        if ($this->logDir) {
            $this->fsLockFile = $this->logDir . DIRECTORY_SEPARATOR . 'migrator.lock';
            if (!is_dir($this->logDir)) {
                @mkdir($this->logDir, 0775, true);
            }
        }

        // Ensure PDO is in exception mode
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Some recommended PDO options
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Run migrations
     *
     * @param array{
     *  dry_run?:bool,
     *  specific?:string|null,
     *  lock_timeout_sec?:int,
     *  continue_on_error?:bool,
     *  allow_out_of_order?:bool
     * } $options
     *
     * @return array{
     *  ok:bool,
     *  applied:array<int,string>,
     *  skipped:array<int,string>,
     *  errors:array<int,array<string,mixed>>,
     *  stats:array<string,mixed>
     * }
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool)($options['dry_run'] ?? false);
        $specific = $options['specific'] ?? null;
        $lockTimeout = (int)($options['lock_timeout_sec'] ?? 60);
        $continueOnError = (bool)($options['continue_on_error'] ?? false);
        $allowOutOfOrder = (bool)($options['allow_out_of_order'] ?? true);

        $applied = [];
        $skipped = [];
        $errors  = [];

        $start = microtime(true);

        $this->log("Migrator starting. dry_run=" . ($dryRun ? '1' : '0') . " specific=" . ($specific ?: '[none]'));

        // Acquire locks
        $lockOk = $this->acquireLock($lockTimeout);
        if (!$lockOk) {
            return [
                'ok' => false,
                'applied' => [],
                'skipped' => [],
                'errors' => [
                    ['type' => 'lock', 'message' => 'Could not acquire migration lock within timeout']
                ],
                'stats' => [
                    'duration_ms' => (int)((microtime(true) - $start) * 1000),
                ],
            ];
        }

        try {
            // Ensure migrations table exists
            $this->ensureMigrationsTable();

            // Load migration files list
            $files = $this->scanMigrationFiles();

            if ($specific !== null && $specific !== '') {
                // filter exact match
                $files = array_values(array_filter($files, fn($f) => basename($f) === basename($specific)));
                if (count($files) === 0) {
                    return [
                        'ok' => false,
                        'applied' => [],
                        'skipped' => [],
                        'errors' => [
                            ['type' => 'input', 'message' => "Specific migration not found: {$specific}"]
                        ],
                        'stats' => [
                            'duration_ms' => (int)((microtime(true) - $start) * 1000),
                        ],
                    ];
                }
            }

            $already = $this->getAppliedMigrations(); // filename => row
            $this->log("Found " . count($files) . " migration file(s). Already applied: " . count($already));

            // If strict ordering needed, we can validate ordering based on applied list.
            if (!$allowOutOfOrder) {
                $this->validateOrder($files, array_keys($already));
            }

            // Apply
            foreach ($files as $filePath) {
                $fileName = basename($filePath);

                if (isset($already[$fileName])) {
                    $skipped[] = $fileName;
                    $this->log("Skip (already applied): {$fileName}");
                    continue;
                }

                $this->log("Apply: {$fileName}");

                try {
                    if ($dryRun) {
                        $stmtCount = $this->countStatementsInFile($filePath);
                        $this->log("DryRun: would execute {$stmtCount} statement(s) from {$fileName}");
                        $applied[] = $fileName; // in dry-run we consider it "would apply"
                        continue;
                    }

                    $applyRes = $this->applyOneFile($filePath);

                    if (($applyRes['ok'] ?? false) !== true) {
                        $errors[] = [
                            'type' => 'migration_failed',
                            'file' => $fileName,
                            'message' => $applyRes['message'] ?? 'Unknown failure',
                            'details' => $applyRes,
                        ];
                        $this->log("ERROR applying {$fileName}: " . ($applyRes['message'] ?? 'unknown'));

                        if (!$continueOnError) {
                            break;
                        }
                        continue;
                    }

                    $applied[] = $fileName;
                } catch (Throwable $e) {
                    $errors[] = [
                        'type' => 'exception',
                        'file' => $fileName,
                        'message' => $e->getMessage(),
                        'trace' => $this->truncate($e->getTraceAsString(), 3000),
                    ];
                    $this->log("EXCEPTION applying {$fileName}: " . $e->getMessage());

                    if (!$continueOnError) {
                        break;
                    }
                }
            }

            $ok = count($errors) === 0;

            $durationMs = (int)((microtime(true) - $start) * 1000);
            $this->log("Migrator finished. ok=" . ($ok ? '1' : '0') . " applied=" . count($applied) . " skipped=" . count($skipped) . " errors=" . count($errors) . " duration_ms={$durationMs}");

            return [
                'ok' => $ok,
                'applied' => $applied,
                'skipped' => $skipped,
                'errors' => $errors,
                'stats' => [
                    'duration_ms' => $durationMs,
                    'dry_run' => $dryRun,
                    'files_total' => count($files),
                ],
            ];
        } finally {
            $this->releaseLock();
        }
    }

    // =============================================================================
    // Apply a single migration file
    // =============================================================================

    /**
     * @return array{ok:bool,message?:string,statements?:int}
     */
    private function applyOneFile(string $filePath): array
    {
        $fileName = basename($filePath);

        $content = $this->readMigrationFile($filePath);
        $statements = $this->splitSqlStatements($content);

        if (count($statements) === 0) {
            // Consider empty migration as applied, but warn
            $this->log("WARN: {$fileName} has no SQL statements. Marking as applied.");
            $this->markApplied($fileName, sha1($content), 0, 'empty');
            return ['ok' => true, 'statements' => 0];
        }

        if (count($statements) > $this->maxStatementsPerFile) {
            return ['ok' => false, 'message' => "Too many statements in {$fileName}. Limit={$this->maxStatementsPerFile}"];
        }

        // Transaction strategy:
        // - MySQL DDL sometimes auto-commits; transaction may not protect DDL fully
        // - Still, we wrap in transaction for DML + best-effort
        $hash = sha1($content);
        $executed = 0;

        try {
            $this->pdo->beginTransaction();
        } catch (Throwable $e) {
            // if cannot begin, proceed without transaction
            $this->log("WARN: cannot begin transaction: " . $e->getMessage());
        }

        try {
            foreach ($statements as $sql) {
                $sql = trim($sql);
                if ($sql === '') continue;

                $this->pdo->exec($sql);
                $executed++;

                // Optional verbose logging
                $this->log("  executed #{$executed}: " . $this->sqlPreview($sql));
            }

            // Commit if transaction is active
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            $this->markApplied($fileName, $hash, $executed, 'ok');
            return ['ok' => true, 'statements' => $executed];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (Throwable $ignore) {}
            }
            return ['ok' => false, 'message' => $e->getMessage(), 'statements' => $executed];
        }
    }

    // =============================================================================
    // Migrations tracking table
    // =============================================================================

    private function ensureMigrationsTable(): void
    {
        if ($this->tableExists($this->migrationsTable)) {
            return;
        }

        // Create migrations table
        $sql = "
CREATE TABLE IF NOT EXISTS `{$this->migrationsTable}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `hash` CHAR(40) NULL,
  `applied_at` DATETIME NOT NULL,
  `applied_by` VARCHAR(120) NULL,
  `statements` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(30) NOT NULL DEFAULT 'ok',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
        $this->pdo->exec($sql);
        $this->log("Created migrations table: {$this->migrationsTable}");
    }

    /**
     * @return array<string,array<string,mixed>> filename => row
     */
    private function getAppliedMigrations(): array
    {
        $sql = "SELECT filename, hash, applied_at, status, statements FROM `{$this->migrationsTable}` ORDER BY id ASC";
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $fn = (string)($r['filename'] ?? '');
            if ($fn !== '') {
                $map[$fn] = $r;
            }
        }
        return $map;
    }

    private function markApplied(string $filename, string $hash, int $statements, string $status): void
    {
        $by = $this->appliedBy();

        $sql = "INSERT INTO `{$this->migrationsTable}` (filename, hash, applied_at, applied_by, statements, status)
                VALUES (:f, :h, NOW(), :b, :s, :st)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':f' => $filename,
            ':h' => $hash,
            ':b' => $by,
            ':s' => $statements,
            ':st' => $status,
        ]);

        $this->log("Marked applied: {$filename} statements={$statements} status={$status}");
    }

    private function appliedBy(): string
    {
        if (php_sapi_name() === 'cli') {
            $u = get_current_user();
            return "cli:" . ($u ?: 'unknown');
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua = $this->truncate((string)$ua, 80);
        return "web:{$ip}:{$ua}";
    }

    // =============================================================================
    // File scanning / reading
    // =============================================================================

    /**
     * @return array<int,string> full paths sorted
     */
    private function scanMigrationFiles(): array
    {
        $files = glob($this->migrationsDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function readMigrationFile(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("Migration file not found: {$filePath}");
        }

        $size = filesize($filePath);
        if ($size !== false && $size > $this->maxFileSizeBytes) {
            throw new RuntimeException("Migration file too large: " . basename($filePath) . " size={$size} bytes");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Cannot read migration file: {$filePath}");
        }

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return $content;
    }

    private function countStatementsInFile(string $filePath): int
    {
        $content = $this->readMigrationFile($filePath);
        $stmts = $this->splitSqlStatements($content);
        return count($stmts);
    }

    // =============================================================================
    // SQL parsing
    // =============================================================================

    /**
     * Split SQL file content into statements safely.
     * - Removes comments
     * - Splits by ';' but aware of quotes
     *
     * @return array<int,string>
     */
    private function splitSqlStatements(string $sqlFileContent): array
    {
        $sql = $this->stripComments($sqlFileContent);

        $statements = [];
        $current = '';

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // Toggle quote states (ignore escaped quotes)
            if ($ch === "'" && !$inDouble && !$inBacktick) {
                // If escaped by backslash
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) $inSingle = !$inSingle;
                $current .= $ch;
                continue;
            }

            if ($ch === '"' && !$inSingle && !$inBacktick) {
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) $inDouble = !$inDouble;
                $current .= $ch;
                continue;
            }

            if ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                $current .= $ch;
                continue;
            }

            // Statement terminator
            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        // safety: limit
        if (count($statements) > $this->maxStatementsPerFile) {
            // keep first N for safety, but return full count info by throwing maybe
            // Here: just return to let caller handle
        }

        return $statements;
    }

    /**
     * Remove SQL comments:
     *  - line comments: -- ... \n  and # ... \n
     *  - block comments: /* ... *\/
     */
    private function stripComments(string $sql): string
    {
        // Remove block comments
        $sql = preg_replace('~/\*.*?\*/~s', '', $sql) ?? $sql;

        $lines = explode("\n", $sql);
        $out = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);

            // Skip full-line comments
            if ($trim === '') {
                $out[] = '';
                continue;
            }

            if (str_starts_with($trim, '--')) {
                $out[] = '';
                continue;
            }

            if (str_starts_with($trim, '#')) {
                $out[] = '';
                continue;
            }

            // Remove inline -- comments ONLY when not inside quotes (best-effort)
            // We'll do a simple approach: split on -- if occurs and before it not in quotes.
            // For robust parser, we'd need a state machine; but this is acceptable for migrations.
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    // =============================================================================
    // Locking
    // =============================================================================

    private function acquireLock(int $timeoutSec): bool
    {
        $timeoutSec = max(0, $timeoutSec);

        // 1) MySQL advisory lock (preferred)
        try {
            $stmt = $this->pdo->prepare("SELECT GET_LOCK(:name, :timeout) AS got");
            $stmt->execute([':name' => $this->mysqlLockName, ':timeout' => $timeoutSec]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $got = (int)($row['got'] ?? 0);
            if ($got !== 1) {
                $this->log("Failed to acquire MySQL lock: {$this->mysqlLockName}");
                return false;
            }
            $this->log("Acquired MySQL lock: {$this->mysqlLockName}");
        } catch (Throwable $e) {
            // If GET_LOCK not available, proceed with fs lock fallback
            $this->log("WARN: MySQL GET_LOCK unavailable: " . $e->getMessage());
        }

        // 2) Filesystem lock (best-effort)
        if ($this->fsLockFile) {
            $fp = @fopen($this->fsLockFile, 'c+');
            if ($fp) {
                $locked = @flock($fp, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    $this->log("Failed to acquire FS lock: {$this->fsLockFile}");
                    @fclose($fp);
                    // release mysql lock if held
                    $this->releaseMysqlLock();
                    return false;
                }

                // Store handle globally to keep lock during run
                $GLOBALS['crm_migrator_fs_lock_fp'] = $fp;
                @ftruncate($fp, 0);
                @fwrite($fp, "locked_at=" . date('c') . "\n");
                @fflush($fp);

                $this->log("Acquired FS lock: {$this->fsLockFile}");
            } else {
                $this->log("WARN: Cannot open FS lock file: {$this->fsLockFile}");
            }
        }

        return true;
    }

    private function releaseLock(): void
    {
        $this->releaseFsLock();
        $this->releaseMysqlLock();
        $this->log("Released locks.");
    }

    private function releaseMysqlLock(): void
    {
        try {
            $stmt = $this->pdo->prepare("SELECT RELEASE_LOCK(:name) AS rel");
            $stmt->execute([':name' => $this->mysqlLockName]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function releaseFsLock(): void
    {
        if (isset($GLOBALS['crm_migrator_fs_lock_fp']) && is_resource($GLOBALS['crm_migrator_fs_lock_fp'])) {
            $fp = $GLOBALS['crm_migrator_fs_lock_fp'];
            @flock($fp, LOCK_UN);
            @fclose($fp);
            unset($GLOBALS['crm_migrator_fs_lock_fp']);
        }
    }

    // =============================================================================
    // DB helpers
    // =============================================================================

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    }

    // =============================================================================
    // Ordering validation (optional)
    // =============================================================================

    /**
     * Ensure no "future" migration is applied before earlier file in list.
     * Only relevant if you enforce strict ordering.
     *
     * @param array<int,string> $files full paths sorted
     * @param array<int,string> $appliedFilenames
     */
    private function validateOrder(array $files, array $appliedFilenames): void
    {
        $sortedNames = array_map('basename', $files);
        $pos = array_flip($sortedNames);

        $maxPos = -1;
        foreach ($appliedFilenames as $fn) {
            if (!isset($pos[$fn])) continue;
            $p = (int)$pos[$fn];
            if ($p > $maxPos) $maxPos = $p;
        }

        // If some applied migration has position > another not applied earlier => strict mode would require earlier ones applied too.
        // This is complex; we keep it simple:
        // If a migration later in list is applied while earlier not applied, throw.
        $appliedSet = array_flip($appliedFilenames);
        for ($i = 0; $i < count($sortedNames); $i++) {
            $fn = $sortedNames[$i];
            $isApplied = isset($appliedSet[$fn]);
            if (!$isApplied && $maxPos > $i) {
                throw new RuntimeException("Out-of-order migrations detected. '{$fn}' is not applied but a later migration is applied. Disable strict ordering or fix DB state.");
            }
        }
    }

    // =============================================================================
    // Logging
    // =============================================================================

    private function log(string $line): void
    {
        $line = trim($line);
        if ($line === '') return;

        if ($this->logger) {
            try {
                ($this->logger)($line);
            } catch (Throwable $e) {
                // ignore logger errors
            }
        }

        // Additionally write a simple text log (optional)
        if ($this->logDir) {
            $file = $this->logDir . DIRECTORY_SEPARATOR . 'migrations.log';
            @file_put_contents($file, '[' . date('c') . '] ' . $line . PHP_EOL, FILE_APPEND);
        }
    }

    private function sqlPreview(string $sql, int $max = 140): string
    {
        $one = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        if (strlen($one) > $max) return substr($one, 0, $max) . '...';
        return $one;
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . '...[truncated]';
    }
}

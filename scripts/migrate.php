<?php
/**
 * File: scripts/migrate.php
 *
 * CRM V2 - CLI Migration Runner
 * ------------------------------------------------------------
 * این اسکریپت فقط یک Wrapper است که کلاس Migrator را اجرا می‌کند.
 *
 * Usage:
 *   php scripts/migrate.php
 *
 * Common options:
 *   php scripts/migrate.php --help
 *   php scripts/migrate.php --config=/path/to/private/config.php
 *   php scripts/migrate.php --migrations=/path/to/database/migrations
 *   php scripts/migrate.php --logdir=/path/to/private/storage/logs
 *   php scripts/migrate.php --dry-run
 *   php scripts/migrate.php --only=005_woocommerce
 *   php scripts/migrate.php --lock-timeout=60
 *
 * Exit codes:
 *   0  success
 *   2  config not found / invalid
 *   3  DB connection failed
 *   4  migration failed
 *   5  wrong usage
 */

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migrator;

// ---------------------------------------------------------------------
// Bootstrap (autoload)
// ---------------------------------------------------------------------

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "ERROR: Could not resolve project root.\n");
    exit(5);
}

// اگر Composer دارید:
$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // Autoload ساده (اگر composer ندارید)
    // این Autoload فرض می‌کند namespace App\... داخل app/ قرار دارد.
    spl_autoload_register(function (string $class) use ($root) {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) return;

        $relative = substr($class, strlen($prefix)); // e.g. Database\Migrator
        $path = $root . '/app/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    });
}

// ---------------------------------------------------------------------
// CLI guard
// ---------------------------------------------------------------------

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(5);
}

// ---------------------------------------------------------------------
// Parse arguments
// ---------------------------------------------------------------------

$args = parseArgs($argv);

if (isset($args['help']) || isset($args['h'])) {
    printHelp($root);
    exit(0);
}

$configPath = (string)($args['config'] ?? ($root . '/private/config.php'));
$migrationsPath = (string)($args['migrations'] ?? ($root . '/database/migrations'));
$logDir = (string)($args['logdir'] ?? ($root . '/private/storage/logs'));
$dryRun = isset($args['dry-run']) || isset($args['dryrun']);
$only = $args['only'] ?? null; // string|null
$lockTimeout = (int)($args['lock-timeout'] ?? 30);

// Optional: verbose / json output
$verbose = isset($args['verbose']) || isset($args['v']);
$jsonOut = isset($args['json']);

// ---------------------------------------------------------------------
// Load config
// ---------------------------------------------------------------------

if (!is_file($configPath)) {
    fwrite(STDERR, "ERROR: Config file not found: {$configPath}\n");
    fwrite(STDERR, "Tip: run installer first, or pass --config=/path/to/config.php\n");
    exit(2);
}

$config = requireConfig($configPath);
if (!is_array($config)) {
    fwrite(STDERR, "ERROR: Config file did not return an array: {$configPath}\n");
    exit(2);
}

// ---------------------------------------------------------------------
// Connect DB
// ---------------------------------------------------------------------

try {
    $conn = new Connection($config);
    $pdo = $conn->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Database connection failed.\n");
    fwrite(STDERR, "Reason: " . $e->getMessage() . "\n");
    exit(3);
}

// ---------------------------------------------------------------------
// Run migrations
// ---------------------------------------------------------------------

$logger = function (string $line) use ($verbose) {
    // اگر verbose باشد، در کنسول هم چاپ می‌کنیم
    if ($verbose) {
        echo $line . PHP_EOL;
    }
};

try {
    $migrator = new Migrator($pdo, $migrationsPath, $logDir, $logger);

    $runOptions = [
        'dry_run' => $dryRun,
        'specific' => $only,
        'lock_timeout_sec' => $lockTimeout,
    ];

    $result = $migrator->run($runOptions);

    if ($jsonOut) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        printHumanResult($result, $dryRun);
    }

    if (!($result['ok'] ?? false)) {
        exit(4);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Migration runner crashed.\n");
    fwrite(STDERR, "Reason: " . $e->getMessage() . "\n");
    exit(4);
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Parse CLI args.
 * Supports:
 *  --key=value
 *  --flag
 *  -h, -v, ...
 *
 * @param array<int,string> $argv
 * @return array<string,mixed>
 */
function parseArgs(array $argv): array
{
    $out = [];
    $count = count($argv);

    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];

        if (str_starts_with($arg, '--')) {
            $eqPos = strpos($arg, '=');
            if ($eqPos !== false) {
                $key = substr($arg, 2, $eqPos - 2);
                $val = substr($arg, $eqPos + 1);
                $out[$key] = $val;
            } else {
                $key = substr($arg, 2);
                $out[$key] = true;
            }
            continue;
        }

        if (str_starts_with($arg, '-') && strlen($arg) > 1) {
            // short flags: -hv -> h=true, v=true
            $flags = substr($arg, 1);
            for ($j = 0; $j < strlen($flags); $j++) {
                $out[$flags[$j]] = true;
            }
            continue;
        }

        // positional arguments (not used currently)
        $out[] = $arg;
    }

    return $out;
}

/**
 * Safely require config file.
 * config.php باید array برگرداند.
 *
 * @return mixed
 */
function requireConfig(string $path)
{
    // جلوگیری از require فایل خارج از پروژه (اختیاری)
    // اگر خواستی سخت‌گیرترش کنی:
    // - چک کن داخل private/ باشد
    return require $path;
}

/**
 * Human readable output for non-json.
 *
 * @param array<string,mixed> $result
 */
function printHumanResult(array $result, bool $dryRun): void
{
    $ok = (bool)($result['ok'] ?? false);
    $batch = (int)($result['batch'] ?? 0);
    $started = (string)($result['started_at'] ?? '');
    $finished = (string)($result['finished_at'] ?? '');

    echo "------------------------------------------------------------\n";
    echo "CRM V2 Migration Runner\n";
    echo "------------------------------------------------------------\n";
    echo "Mode     : " . ($dryRun ? "DRY-RUN (no changes)" : "APPLY") . "\n";
    echo "Batch    : {$batch}\n";
    echo "Started  : {$started}\n";
    echo "Finished : {$finished}\n";
    echo "Status   : " . ($ok ? "OK ✅" : "FAILED ❌") . "\n";
    echo "------------------------------------------------------------\n";

    $applied = $result['applied'] ?? [];
    $skipped = $result['skipped'] ?? [];
    $errors  = $result['errors'] ?? [];

    echo "Applied migrations: " . count($applied) . "\n";
    foreach ($applied as $m) {
        $name = (string)($m['name'] ?? '');
        $ms = (int)($m['ms'] ?? 0);
        $st = (int)($m['statements'] ?? 0);
        echo "  + {$name}  ({$st} statements, {$ms}ms)\n";
    }

    echo "Skipped migrations: " . count($skipped) . "\n";
    foreach ($skipped as $name) {
        echo "  - {$name}\n";
    }

    if (!$ok) {
        echo "------------------------------------------------------------\n";
        echo "Errors:\n";
        foreach ($errors as $err) {
            echo "  * {$err}\n";
        }
        echo "------------------------------------------------------------\n";
        echo "Tip: check logs in private/storage/logs/migrations.log\n";
    }

    echo "\n";
}

function printHelp(string $root): void
{
    echo "CRM V2 - Migration Runner (CLI)\n\n";
    echo "Usage:\n";
    echo "  php scripts/migrate.php [options]\n\n";
    echo "Options:\n";
    echo "  --help, -h                 Show this help\n";
    echo "  --config=PATH              Path to private/config.php\n";
    echo "                             Default: {$root}/private/config.php\n";
    echo "  --migrations=PATH          Path to database/migrations\n";
    echo "                             Default: {$root}/database/migrations\n";
    echo "  --logdir=PATH              Directory for logs\n";
    echo "                             Default: {$root}/private/storage/logs\n";
    echo "  --dry-run                  Do not execute SQL; just simulate\n";
    echo "  --only=NAME                Run only migrations matching NAME\n";
    echo "                             Example: --only=005_woocommerce\n";
    echo "  --lock-timeout=SECONDS     Lock wait timeout (default 30)\n";
    echo "  --verbose, -v              Print migration logs to console\n";
    echo "  --json                     Print result as JSON\n\n";
    echo "Examples:\n";
    echo "  php scripts/migrate.php\n";
    echo "  php scripts/migrate.php --dry-run\n";
    echo "  php scripts/migrate.php --only=001_init\n";
    echo "  php scripts/migrate.php --config=/var/www/private/config.php --verbose\n";
    echo "\n";
}

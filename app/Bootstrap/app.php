<?php
/**
 * File: app/Bootstrap/app.php
 *
 * CRM V2 - Application Bootstrap
 * ------------------------------------------------------------
 * هدف: آماده‌سازی محیط اجرای برنامه (Config, DB, Logger, Container, Helpers)
 *
 * این فایل باید در ابتدای هر درخواست (web/cli) فراخوانی شود تا:
 *  - config لود شود
 *  - سرویس‌ها (PDO, Logger, Migrator...) آماده شوند
 *  - container ساده برای DI وجود داشته باشد
 *
 * Usage (web):
 *   $app = require __DIR__ . '/app.php';
 *   $pdo = $app['db'];  // PDO
 *
 * Usage (cli):
 *   $app = require __DIR__ . '/../Bootstrap/app.php';
 */

declare(strict_types=1);

namespace App\Bootstrap;

use App\Database\Connection;
use App\Database\Migrator;
use App\Support\Logger;
use PDO;
use RuntimeException;
use Throwable;

// -----------------------------------------------------------------------------
// Paths
// -----------------------------------------------------------------------------
$ROOT_DIR    = defined('CRM_ROOT_DIR') ? CRM_ROOT_DIR : (realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
$APP_DIR     = $ROOT_DIR . '/app';
$PRIVATE_DIR = $ROOT_DIR . '/private';
$LOG_DIR     = $PRIVATE_DIR . '/storage/logs';
$MIGRATIONS_DIR = $ROOT_DIR . '/database/migrations';

// -----------------------------------------------------------------------------
// Config loading
// -----------------------------------------------------------------------------
$configPath = $PRIVATE_DIR . '/config.php';
$config = [];

if (is_file($configPath)) {
    $loaded = require $configPath;
    if (!is_array($loaded)) {
        throw new RuntimeException("Config file must return array: {$configPath}");
    }
    $config = $loaded;
} else {
    // Installer should have created it; but in some CLI operations we may allow config missing.
    $config = [
        'app' => [
            'env' => 'production',
            'timezone' => 'UTC',
            'base_url' => null,
        ],
        'db' => [
            'host' => 'localhost',
            'port' => 3306,
            'name' => '',
            'user' => '',
            'pass' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'timezone' => '+00:00',
        ],
        'security' => [
            'cookie_secure_auto' => true,
            'session_name' => 'CRMSESSID',
        ],
        'features' => [
            'enable_ai' => true,
            'enable_woocommerce' => true,
        ],
    ];
}

// -----------------------------------------------------------------------------
// Environment + timezone
// -----------------------------------------------------------------------------
$env = strtolower((string)($config['app']['env'] ?? 'production'));
$isDev = ($env !== 'production');

$timezone = (string)($config['app']['timezone'] ?? 'UTC');
if ($timezone !== '') {
    @date_default_timezone_set($timezone);
}

// -----------------------------------------------------------------------------
// Ensure log directory exists (best-effort)
// -----------------------------------------------------------------------------
if (!is_dir($LOG_DIR)) {
    @mkdir($LOG_DIR, 0775, true);
}

// -----------------------------------------------------------------------------
// Build Logger (minimal but reliable)
// -----------------------------------------------------------------------------
$logger = new Logger($LOG_DIR, $isDev);

// -----------------------------------------------------------------------------
// Global error handler (optional - web has its own too; but this helps in CLI)
// -----------------------------------------------------------------------------
set_exception_handler(function (Throwable $e) use ($logger, $isDev) {
    $logger->error('UNCAUGHT_EXCEPTION', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);

    // In CLI show details
    if (php_sapi_name() === 'cli' || $isDev) {
        fwrite(STDERR, "FATAL: " . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    } else {
        http_response_code(500);
        echo "خطای داخلی سرور";
    }
    exit(1);
});

// -----------------------------------------------------------------------------
// Very small container (array-like registry)
// -----------------------------------------------------------------------------
/**
 * Container contract:
 * - $app['config'] -> config array
 * - $app['logger'] -> Logger
 * - $app['db']     -> PDO (lazy)
 * - $app['migrator'] -> Migrator (lazy)
 * - $app['paths']  -> array of paths
 */
$app = new class implements \ArrayAccess {
    private array $items = [];
    private array $factories = [];
    private array $cache = [];

    public function offsetExists($offset): bool {
        return isset($this->items[$offset]) || isset($this->factories[$offset]) || isset($this->cache[$offset]);
    }

    public function offsetGet($offset) {
        if (isset($this->cache[$offset])) {
            return $this->cache[$offset];
        }
        if (isset($this->items[$offset])) {
            return $this->items[$offset];
        }
        if (isset($this->factories[$offset])) {
            $this->cache[$offset] = ($this->factories[$offset])($this);
            return $this->cache[$offset];
        }
        throw new \RuntimeException("Service not found: {$offset}");
    }

    public function offsetSet($offset, $value): void {
        $this->items[$offset] = $value;
    }

    public function offsetUnset($offset): void {
        unset($this->items[$offset], $this->factories[$offset], $this->cache[$offset]);
    }

    public function factory(string $key, callable $fn): void {
        $this->factories[$key] = $fn;
    }
};

// -----------------------------------------------------------------------------
// Register base services
// -----------------------------------------------------------------------------
$app['config'] = $config;
$app['logger'] = $logger;

$app['paths'] = [
    'root' => $ROOT_DIR,
    'app' => $APP_DIR,
    'private' => $PRIVATE_DIR,
    'logs' => $LOG_DIR,
    'migrations' => $MIGRATIONS_DIR,
];

// PDO (lazy)
$app->factory('db', function ($app): PDO {
    $cfg = $app['config'];
    /** @var Logger $logger */
    $logger = $app['logger'];

    try {
        $conn = new Connection($cfg);
        $pdo = $conn->pdo();
        $logger->info('DB_CONNECTED', ['server_version' => $conn->serverVersion()]);
        return $pdo;
    } catch (Throwable $e) {
        $logger->error('DB_CONNECT_FAILED', [
            'message' => $e->getMessage(),
        ]);
        throw $e;
    }
});

// Migrator (lazy)
$app->factory('migrator', function ($app): Migrator {
    /** @var PDO $pdo */
    $pdo = $app['db'];
    $paths = $app['paths'];
    /** @var Logger $logger */
    $logger = $app['logger'];

    $logDir = $paths['logs'] ?? null;
    $migrationsPath = $paths['migrations'] ?? null;

    if (!$migrationsPath || !is_dir($migrationsPath)) {
        throw new RuntimeException("Migrations directory not found: " . (string)$migrationsPath);
    }

    $callableLogger = function (string $line) use ($logger) {
        // line already formatted; store as debug log
        $logger->debug('MIGRATOR', ['line' => $line]);
    };

    return new Migrator($pdo, $migrationsPath, $logDir, $callableLogger);
});

// -----------------------------------------------------------------------------
// Helper functions (optional global helpers for simplicity)
// -----------------------------------------------------------------------------
/**
 * Get app container globally.
 * This is intentionally simple; in a larger codebase you may avoid globals.
 */
if (!function_exists('app')) {
    function app(string $key = null) {
        $container = $GLOBALS['crm_app_container'] ?? null;
        if ($container === null) {
            throw new RuntimeException("App container not initialized.");
        }
        if ($key === null) return $container;
        return $container[$key];
    }
}

/**
 * Read config value by dot notation: config('db.host')
 */
if (!function_exists('config')) {
    function config(string $key, $default = null) {
        $cfg = app('config');
        $parts = explode('.', $key);
        $cur = $cfg;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }
}

/**
 * Quick logging helpers
 */
if (!function_exists('logger')) {
    function logger(): Logger {
        return app('logger');
    }
}

// Store container globally for helper access
$GLOBALS['crm_app_container'] = $app;

// -----------------------------------------------------------------------------
// Optional: auto-run migrations on boot (DISABLED by default)
// -----------------------------------------------------------------------------
/**
 * توصیه: اجرای خودکار migration در هر درخواست وب را انجام نده.
 * فقط از:
 * - Installer مرحله 3
 * - یا CLI scripts/migrate.php
 * - یا صفحه Admin (Settings -> DB -> Run Migrations)
 * استفاده کن.
 *
 * اگر مجبور شدی، این گزینه را فقط در dev فعال کن.
 */
// $autoMigrate = (bool)($config['db']['auto_migrate'] ?? false);
// if ($autoMigrate && $isDev) {
//     /** @var Migrator $m */
//     $m = $app['migrator'];
//     $m->run();
// }

return $app;

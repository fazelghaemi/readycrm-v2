<?php
/**
 * File: public/index.php
 *
 * CRM V2 - Front Controller
 * ------------------------------------------------------------
 * همه درخواست‌های وب باید به این فایل برسند (DocumentRoot = public).
 *
 * Responsibilities:
 *  - Load config (private/config.php)
 *  - Redirect to installer if not installed
 *  - Setup environment (timezone, error reporting)
 *  - Security headers
 *  - Start secure session
 *  - Simple router dispatch to controllers
 *
 * Notes:
 *  - This project intentionally avoids large frameworks.
 *  - Code is written to be very explicit and maintainable.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 0) Constants & Paths
// -----------------------------------------------------------------------------

$PUBLIC_DIR = __DIR__;
$ROOT_DIR   = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$APP_DIR    = $ROOT_DIR . '/app';
$PRIVATE_DIR= $ROOT_DIR . '/private';
$INSTALL_DIR= $ROOT_DIR . '/install';

define('CRM_PUBLIC_DIR', $PUBLIC_DIR);
define('CRM_ROOT_DIR', $ROOT_DIR);
define('CRM_APP_DIR', $APP_DIR);
define('CRM_PRIVATE_DIR', $PRIVATE_DIR);
define('CRM_INSTALL_DIR', $INSTALL_DIR);

// -----------------------------------------------------------------------------
// 1) Minimal autoload (Composer if exists, otherwise PSR-4-ish for App\*)
// -----------------------------------------------------------------------------

$composerAutoload = $ROOT_DIR . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(function (string $class) use ($APP_DIR) {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix)); // e.g. Http\Controllers\AuthController
        $file = $APP_DIR . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// -----------------------------------------------------------------------------
// 2) Installation guard (if not installed -> /install)
// -----------------------------------------------------------------------------

// Installer lock file strategy:
// - After successful install, create: install/install.lock (or private/install.lock)
// - Here we check config.php exists + install.lock exists.
// If either missing, we redirect to /install (unless we're already in /install).
$configPath = $PRIVATE_DIR . '/config.php';
$installLock = $INSTALL_DIR . '/install.lock';

// Detect current request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$pathOnly   = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// Normalize path (ensure starts with /)
if ($pathOnly === '') $pathOnly = '/';
if ($pathOnly[0] !== '/') $pathOnly = '/' . $pathOnly;

// If request is for install itself, do not redirect
$isInstallRoute = str_starts_with($pathOnly, '/install');

// If not installed:
$installed = (is_file($configPath) && is_file($installLock));

if (!$installed && !$isInstallRoute) {
    // Redirect to installer
    header('Location: /install', true, 302);
    exit;
}

// -----------------------------------------------------------------------------
// 3) Load configuration (if exists)
// -----------------------------------------------------------------------------

$config = [];
if (is_file($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

// -----------------------------------------------------------------------------
// 4) Environment settings (timezone, error reporting)
// -----------------------------------------------------------------------------

// Default timezone - can be overridden in config
$tz = $config['app']['timezone'] ?? 'UTC';
date_default_timezone_set((string)$tz);

// App environment: 'production' | 'development'
$env = $config['app']['env'] ?? 'production';
$env = is_string($env) ? strtolower($env) : 'production';

$isDev = ($env !== 'production');

// Error reporting policy
if ($isDev) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// -----------------------------------------------------------------------------
// 5) Security headers (baseline)
// -----------------------------------------------------------------------------

/**
 * For a CRM:
 * - X-Frame-Options: DENY (prevent clickjacking)
 * - X-Content-Type-Options: nosniff
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - Permissions-Policy: restrict powerful APIs
 * - Content-Security-Policy: minimal baseline (tune later)
 *
 * IMPORTANT: CSP must be tuned if you load external scripts/fonts.
 */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// CSP baseline (very strict). Adjust when you add external assets/CDNs.
// If this blocks your JS/CSS, relax carefully.
$csp = "default-src 'self'; "
     . "img-src 'self' data:; "
     . "style-src 'self' 'unsafe-inline'; "
     . "script-src 'self'; "
     . "connect-src 'self'; "
     . "frame-ancestors 'none'; "
     . "base-uri 'self'; "
     . "form-action 'self';";
header("Content-Security-Policy: {$csp}");

// Optional: HSTS only if HTTPS is always enabled.
// if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
//     header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
// }

// -----------------------------------------------------------------------------
// 6) Secure session initialization
// -----------------------------------------------------------------------------

/**
 * Session security settings:
 * - secure cookies if HTTPS
 * - HttpOnly cookies
 * - SameSite Lax (usually best for CRMs)
 * - regenerate session id on login (done in AuthService later)
 */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_name($config['app']['session_name'] ?? 'CRMSESSID');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',          // keep empty for current domain
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 7) Global exception/error handler (nice responses + logging)
// -----------------------------------------------------------------------------

set_exception_handler(function (Throwable $e) use ($isDev, $PRIVATE_DIR) {
    // Log to file
    $logDir = $PRIVATE_DIR . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/app_errors.log';

    $message = '[' . date('Y-m-d H:i:s') . '] '
             . get_class($e) . ': ' . $e->getMessage()
             . ' @ ' . $e->getFile() . ':' . $e->getLine()
             . "\n" . $e->getTraceAsString()
             . "\n\n";

    @file_put_contents($logFile, $message, FILE_APPEND);

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    if ($isDev) {
        echo "<h1>Application Error (Dev)</h1>";
        echo "<pre style='white-space:pre-wrap;'>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</pre>";
    } else {
        echo "<h1>خطای داخلی سرور</h1>";
        echo "<p>متأسفانه خطایی رخ داد. لطفاً بعداً دوباره تلاش کنید.</p>";
    }
    exit;
});

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    // Convert all PHP errors to exceptions (except suppressed @)
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// -----------------------------------------------------------------------------
// 8) CSRF token helper (minimal; will be expanded later)
// -----------------------------------------------------------------------------

if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * CSRF validation:
 * - for POST/PUT/PATCH/DELETE
 * - expect token in POST['_csrf'] or header 'X-CSRF-Token'
 */
function csrfValidateOrFail(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $token = $_POST['_csrf'] ?? null;
    if ($token === null) {
        $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($hdr !== null) $token = $hdr;
    }

    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo "<h1>خطای امنیتی</h1>";
        echo "<p>توکن امنیتی (CSRF) نامعتبر است. صفحه را رفرش کنید و دوباره تلاش کنید.</p>";
        exit;
    }
}

// -----------------------------------------------------------------------------
// 9) Simple Router (no framework)
// -----------------------------------------------------------------------------

/**
 * Route definition style:
 *   $routes = [
 *      ['GET',  '/',                 [DashboardController::class, 'index']],
 *      ['GET',  '/login',            [AuthController::class, 'showLogin']],
 *      ['POST', '/login',            [AuthController::class, 'login']],
 *      ['POST', '/logout',           [AuthController::class, 'logout']],
 *      ...
 *   ];
 *
 * Later we will move these routes to app/Bootstrap/routes.php
 */
$routes = [];

// If routes file exists, load it
$routesFile = $APP_DIR . '/Bootstrap/routes.php';
if (is_file($routesFile)) {
    $loadedRoutes = require $routesFile;
    if (is_array($loadedRoutes)) {
        $routes = $loadedRoutes;
    }
}

// Minimal fallback routes if routes.php doesn't exist yet
if (empty($routes)) {
    $routes = [
        ['GET', '/', function () {
            header('Content-Type: text/html; charset=utf-8');
            echo "<h1>CRM V2</h1><p>Routes not configured yet.</p>";
        }],
    ];
}

// Dispatch
dispatch($routes, $config);

// -----------------------------------------------------------------------------
// Router helpers
// -----------------------------------------------------------------------------

/**
 * Dispatch request to handler
 *
 * @param array<int, array{0:string,1:string,2:mixed}> $routes
 * @param array<string,mixed> $config
 */
function dispatch(array $routes, array $config): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    // Normalize path
    if ($path === '') $path = '/';
    if ($path[0] !== '/') $path = '/' . $path;

    // Remove trailing slash (except root)
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    // If method is OPTIONS -> quick response
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    // Find match
    foreach ($routes as $route) {
        [$rm, $rp, $handler] = $route;

        $rm = strtoupper((string)$rm);
        $rp = (string)$rp;

        // Normalize route path
        if ($rp === '') $rp = '/';
        if ($rp[0] !== '/') $rp = '/' . $rp;
        if ($rp !== '/' && str_ends_with($rp, '/')) $rp = rtrim($rp, '/');

        if ($rm !== $method) {
            continue;
        }

        // Exact match
        if ($rp === $path) {
            runHandler($handler, $config);
            return;
        }

        // Parameter match (very simple): /customers/{id}
        $params = matchWithParams($rp, $path);
        if ($params !== null) {
            // Expose params to handler via global
            $_GET = array_merge($_GET, $params);
            runHandler($handler, $config);
            return;
        }
    }

    // Not found
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<h1>404</h1><p>صفحه مورد نظر یافت نشد.</p>";
}

/**
 * Very simple route pattern matcher:
 *  /customers/{id} matches /customers/123 and returns ['id'=>'123']
 *
 * @return array<string,string>|null
 */
function matchWithParams(string $routePattern, string $path): ?array
{
    if (strpos($routePattern, '{') === false) {
        return null;
    }

    $rpParts = explode('/', trim($routePattern, '/'));
    $pParts  = explode('/', trim($path, '/'));

    if (count($rpParts) !== count($pParts)) {
        return null;
    }

    $params = [];
    foreach ($rpParts as $i => $rpSeg) {
        $pSeg = $pParts[$i];

        if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $rpSeg, $m)) {
            $params[$m[1]] = $pSeg;
            continue;
        }

        if ($rpSeg !== $pSeg) {
            return null;
        }
    }

    return $params;
}

/**
 * Execute handler:
 * - callable: function() { ... }
 * - [ControllerClass, 'method']
 */
function runHandler($handler, array $config): void
{
    // CSRF check for non-GET methods (basic)
    csrfValidateOrFail();

    // Provide config globally if needed (better: DI container later)
    $GLOBALS['crm_config'] = $config;

    if (is_callable($handler)) {
        $handler();
        return;
    }

    if (is_array($handler) && count($handler) === 2) {
        [$class, $method] = $handler;

        if (!class_exists($class)) {
            throw new RuntimeException("Controller class not found: {$class}");
        }

        $obj = new $class($config); // Controllers can accept config in constructor
        if (!method_exists($obj, $method)) {
            throw new RuntimeException("Controller method not found: {$class}::{$method}");
        }

        $obj->$method();
        return;
    }

    throw new RuntimeException("Invalid route handler.");
}

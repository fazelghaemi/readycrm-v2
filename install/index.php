<?php
/**
 * File: install/index.php
 *
 * CRM V2 - Easy Installer (WordPress-like)
 * ------------------------------------------------------------
 * Goals:
 *  - Provide a guided setup wizard
 *  - Create config file: private/config.php
 *  - Run migrations automatically (no manual table creation)
 *  - Create first admin user
 *  - Create install lock: install/install.lock
 *
 * Requirements:
 *  - DocumentRoot should be /public (recommended)
 *  - /private must be writable (for config + logs)
 *  - /install must be accessible until finished
 */

declare(strict_types=1);

// ------------------------------------------------------------
// Paths
// ------------------------------------------------------------
$INSTALL_DIR = __DIR__;
$ROOT_DIR    = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$APP_DIR     = $ROOT_DIR . '/app';
$PRIVATE_DIR = $ROOT_DIR . '/private';
$CONFIG_FILE = $PRIVATE_DIR . '/config.php';
$LOCK_FILE   = $INSTALL_DIR . '/install.lock';
$LOG_DIR     = $PRIVATE_DIR . '/storage/logs';
$MIGRATIONS_DIR = $ROOT_DIR . '/database/migrations';

// ------------------------------------------------------------
// Autoload (Composer or minimal PSR-4 for App\*)
// ------------------------------------------------------------
$composerAutoload = $ROOT_DIR . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(function (string $class) use ($APP_DIR) {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) return;
        $relative = substr($class, strlen($prefix));
        $file = $APP_DIR . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) require_once $file;
    });
}

// ------------------------------------------------------------
// Minimal dependencies (we will use these classes)
// ------------------------------------------------------------
use App\Database\Connection;
use App\Database\Migrator;
use App\Support\Logger;

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function is_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function redirect(string $to): void { header("Location: {$to}", true, 302); exit; }

// Start session
session_name('CRM_INSTALLER');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/install',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
function csrf_check(): void {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($m, ['GET','HEAD','OPTIONS'], true)) return;
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || $t === '' || !hash_equals($_SESSION['_csrf'] ?? '', $t)) {
        http_response_code(419);
        echo "<h1>CSRF Error</h1><p>توکن امنیتی نامعتبر است.</p>";
        exit;
    }
}

// Flash
function flash_set(string $k, string $v): void { $_SESSION['_flash'][$k] = $v; }
function flash_get(string $k): ?string {
    $v = $_SESSION['_flash'][$k] ?? null;
    if ($v !== null) unset($_SESSION['_flash'][$k]);
    return is_string($v) ? $v : null;
}

// ------------------------------------------------------------
// Installer lock guard
// ------------------------------------------------------------
if (is_file($LOCK_FILE) && !isset($_GET['force'])) {
    // Already installed. Show minimal message.
    echo installer_layout("نصب انجام شده", "
      <div style='padding:14px;background:#e7fff0;border:1px solid #9ae6b4;border-radius:12px;'>
        <b>این سامانه قبلاً نصب شده است.</b><br>
        اگر قصد نصب مجدد دارید، فایل <code>install/install.lock</code> را حذف کنید یا با <code>?force=1</code> وارد شوید (پیشنهاد نمی‌شود).
      </div>
      <div style='margin-top:12px;'>
        <a href='/login' style='color:#2563eb;text-decoration:none;'>رفتن به صفحه ورود</a>
      </div>
    ");
    exit;
}

// ------------------------------------------------------------
// Logger (installer)
// ------------------------------------------------------------
$isDev = true;
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$logger = new Logger($LOG_DIR, $isDev);

// ------------------------------------------------------------
// Wizard step routing
// ------------------------------------------------------------
$step = (string)($_GET['step'] ?? 'welcome');
$allowed = ['welcome','requirements','database','admin','migrate','writeconfig','finish'];
if (!in_array($step, $allowed, true)) $step = 'welcome';

// POST CSRF
csrf_check();

// ------------------------------------------------------------
// Step handlers
// ------------------------------------------------------------
switch ($step) {

    case 'welcome':
        show_welcome();
        break;

    case 'requirements':
        show_requirements();
        break;

    case 'database':
        handle_database_step($logger, $PRIVATE_DIR, $CONFIG_FILE);
        break;

    case 'admin':
        handle_admin_step($logger);
        break;

    case 'migrate':
        handle_migrate_step($logger, $MIGRATIONS_DIR, $LOG_DIR);
        break;

    case 'writeconfig':
        handle_writeconfig_step($logger, $PRIVATE_DIR, $CONFIG_FILE, $LOCK_FILE);
        break;

    case 'finish':
        show_finish();
        break;

    default:
        show_welcome();
        break;
}

// =============================================================================
// UI helpers
// =============================================================================
function installer_layout(string $title, string $bodyHtml): string
{
    $csrf = h((string)($_SESSION['_csrf'] ?? ''));
    return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title} | نصب CRM V2</title>
</head>
<body style="font-family:tahoma,Arial;background:#f5f6f8;margin:0;padding:22px;">
  <div style="max-width:820px;margin:0 auto;">
    <div style="background:#111827;color:#fff;padding:14px 18px;border-radius:14px;">
      <div style="font-size:18px;font-weight:bold;">نصب CRM V2</div>
      <div style="font-size:12px;opacity:0.75;margin-top:4px;">Wizard نصب سریع مانند وردپرس</div>
    </div>

    <div style="background:#fff;margin-top:12px;padding:18px;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 10px 30px rgba(0,0,0,0.05);">
      {$bodyHtml}
    </div>

    <div style="margin-top:10px;font-size:12px;color:#9ca3af;line-height:1.7;">
      CSRF: <code>{$csrf}</code> | توصیه امنیتی: پس از نصب، پوشه install را محدود/حذف کنید.
    </div>
  </div>
</body>
</html>
HTML;
}

function step_nav(string $current): string
{
    $steps = [
        'welcome' => 'شروع',
        'requirements' => 'پیش‌نیازها',
        'database' => 'دیتابیس',
        'admin' => 'ادمین',
        'migrate' => 'ساخت جداول',
        'writeconfig' => 'ذخیره تنظیمات',
        'finish' => 'پایان',
    ];

    $chips = '';
    foreach ($steps as $k => $label) {
        $active = ($k === $current);
        $style = $active
            ? "background:#2563eb;color:#fff;border:1px solid #2563eb;"
            : "background:#f3f4f6;color:#111827;border:1px solid #e5e7eb;";
        $chips .= "<span style='padding:6px 10px;border-radius:999px;{$style}font-size:12px;margin-left:6px;display:inline-block;'>{$label}</span>";
    }
    return "<div style='margin-bottom:14px;'>{$chips}</div>";
}

// =============================================================================
// Step: Welcome
// =============================================================================
function show_welcome(): void
{
    $csrf = h((string)($_SESSION['_csrf'] ?? ''));
    $body = step_nav('welcome') . "
      <h2 style='margin:0 0 10px;'>به نصب CRM V2 خوش آمدید</h2>
      <p style='margin:0 0 14px;color:#6b7280;font-size:13px;line-height:1.9;'>
        این Wizard مثل وردپرس، نصب دیتابیس و ساخت جدول‌ها و ساخت اولین ادمین را انجام می‌دهد.
      </p>

      <div style='padding:12px;background:#fff7e6;border:1px solid #ffd08a;border-radius:12px;'>
        <b>پیش‌نیاز:</b> بهتر است DocumentRoot روی پوشه <code>public/</code> باشد.
      </div>

      <form method='get' action=''>
        <input type='hidden' name='step' value='requirements'>
        <button type='submit' style='margin-top:14px;padding:10px 14px;border:none;border-radius:12px;background:#2563eb;color:#fff;cursor:pointer;'>
          شروع بررسی پیش‌نیازها →
        </button>
      </form>
    ";
    echo installer_layout("شروع", $body);
    exit;
}

// =============================================================================
// Step: Requirements
// =============================================================================
function show_requirements(): void
{
    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $exts = [
        'pdo' => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mbstring' => extension_loaded('mbstring'),
        'json' => extension_loaded('json'),
        'openssl' => extension_loaded('openssl'),
    ];

    $ROOT_DIR = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $PRIVATE_DIR = $ROOT_DIR . '/private';
    $INSTALL_DIR = __DIR__;

    $writable = [
        'private/' => is_dir($PRIVATE_DIR) ? is_writable($PRIVATE_DIR) : false,
        'private/storage/logs/' => is_dir($PRIVATE_DIR . '/storage/logs') ? is_writable($PRIVATE_DIR . '/storage/logs') : @mkdir($PRIVATE_DIR . '/storage/logs', 0775, true),
        'install/' => is_writable($INSTALL_DIR),
    ];

    $allExtOk = true;
    foreach ($exts as $k => $v) if (!$v) $allExtOk = false;

    $allWritableOk = true;
    foreach ($writable as $k => $v) if (!$v) $allWritableOk = false;

    $ok = $phpOk && $allExtOk && $allWritableOk;

    $list = "<ul style='line-height:2;margin:10px 0 0;padding-right:18px;color:#374151;'>";
    $list .= "<li>PHP Version: <b>" . h(PHP_VERSION) . "</b> " . ($phpOk ? "✅" : "❌ (حداقل 8.0)") . "</li>";
    foreach ($exts as $k => $v) {
        $list .= "<li>Extension {$k}: " . ($v ? "✅" : "❌") . "</li>";
    }
    foreach ($writable as $k => $v) {
        $list .= "<li>Writable {$k}: " . ($v ? "✅" : "❌") . "</li>";
    }
    $list .= "</ul>";

    $btn = $ok
        ? "<form method='get'><input type='hidden' name='step' value='database'><button style='margin-top:14px;padding:10px 14px;border:none;border-radius:12px;background:#2563eb;color:#fff;cursor:pointer;'>ادامه به مرحله دیتابیس →</button></form>"
        : "<div style='margin-top:14px;padding:12px;background:#ffe7e7;border:1px solid #ffb3b3;border-radius:12px;'>
             پیش‌نیازها کامل نیست. موارد ❌ را رفع کنید و صفحه را رفرش کنید.
           </div>";

    $body = step_nav('requirements') . "
      <h2 style='margin:0 0 10px;'>بررسی پیش‌نیازها</h2>
      <p style='margin:0 0 10px;color:#6b7280;font-size:13px;line-height:1.9;'>
        وضعیت PHP، افزونه‌ها و مجوزهای نوشتن بررسی شد.
      </p>
      {$list}
      {$btn}
    ";
    echo installer_layout("پیش‌نیازها", $body);
    exit;
}

// =============================================================================
// Step: Database
// =============================================================================
function handle_database_step(Logger $logger, string $PRIVATE_DIR, string $CONFIG_FILE): void
{
    $csrf = h((string)($_SESSION['_csrf'] ?? ''));

    // Default values from session
    $db = $_SESSION['install_db'] ?? [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'crm',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'timezone' => '+00:00',
        'create_db' => 0,
    ];

    $error = flash_get('error');
    $info  = flash_get('info');

    // POST: test connection
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $host = trim((string)($_POST['host'] ?? 'localhost'));
        $port = (int)($_POST['port'] ?? 3306);
        $name = trim((string)($_POST['name'] ?? ''));
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        $charset = trim((string)($_POST['charset'] ?? 'utf8mb4'));
        $collation = trim((string)($_POST['collation'] ?? 'utf8mb4_unicode_ci'));
        $tz = trim((string)($_POST['timezone'] ?? '+00:00'));
        $createDb = isset($_POST['create_db']) ? 1 : 0;

        $db = [
            'host' => $host ?: 'localhost',
            'port' => $port ?: 3306,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
            'charset' => $charset ?: 'utf8mb4',
            'collation' => $collation ?: 'utf8mb4_unicode_ci',
            'timezone' => $tz ?: '+00:00',
            'create_db' => $createDb,
        ];
        $_SESSION['install_db'] = $db;

        if ($name === '' || $user === '') {
            flash_set('error', 'نام دیتابیس و نام کاربری دیتابیس الزامی است.');
            redirect('/install?step=database');
        }

        $config = [
            'app' => [
                'env' => 'production',
                'timezone' => 'UTC',
                'base_url' => null,
                'currency' => 'IRR',
            ],
            'db' => [
                'host' => $db['host'],
                'port' => $db['port'],
                'name' => $db['name'],
                'user' => $db['user'],
                'pass' => $db['pass'],
                'charset' => $db['charset'],
                'collation' => $db['collation'],
                'timezone' => $db['timezone'],
            ],
            'features' => [
                'enable_ai' => true,
                'enable_woocommerce' => true,
            ],
        ];

        // Optional: create DB first
        if ($createDb === 1) {
            $res = Connection::createDatabaseIfNotExists($config);
            $logger->info('INSTALL_CREATE_DB', ['ok' => $res['ok'] ?? false, 'msg' => $res['message'] ?? '']);
            if (!($res['ok'] ?? false)) {
                flash_set('error', 'ساخت دیتابیس ناموفق بود: ' . ($res['message'] ?? ''));
                redirect('/install?step=database');
            }
        }

        // Test connection
        try {
            $conn = new Connection($config);
            $test = $conn->test();
            if (!($test['ok'] ?? false)) {
                flash_set('error', 'اتصال ناموفق: ' . ($test['message'] ?? ''));
                $logger->warn('INSTALL_DB_TEST_FAIL', ['message' => $test['message'] ?? '']);
                redirect('/install?step=database');
            }

            $logger->info('INSTALL_DB_TEST_OK', ['details' => $test['details'] ?? []]);
            flash_set('info', 'اتصال دیتابیس موفق بود. می‌توانید ادامه دهید.');
            // Store config draft in session (will be written later)
            $_SESSION['install_config_draft'] = $config;

            redirect('/install?step=admin');
        } catch (Throwable $e) {
            $logger->exception('INSTALL_DB_EXCEPTION', $e, [
                'host' => $db['host'],
                'port' => $db['port'],
                'name' => $db['name'],
                'user' => $db['user'],
                'pass' => '[masked]',
            ]);
            flash_set('error', 'خطا در اتصال دیتابیس: ' . $e->getMessage());
            redirect('/install?step=database');
        }
    }

    $errorHtml = $error ? "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffb3b3;margin-bottom:10px;border-radius:10px;'>".h($error)."</div>" : "";
    $infoHtml  = $info  ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin-bottom:10px;border-radius:10px;'>".h($info)."</div>" : "";

    $body = step_nav('database') . "
      <h2 style='margin:0 0 10px;'>تنظیم دیتابیس</h2>
      <p style='margin:0 0 12px;color:#6b7280;font-size:13px;line-height:1.9;'>
        اطلاعات دیتابیس را وارد کنید. اگر دسترسی دارید، می‌توانید ساخت دیتابیس را هم تیک بزنید.
      </p>
      {$errorHtml}
      {$infoHtml}

      <form method='post' action='/install?step=database'>
        <input type='hidden' name='_csrf' value='{$csrf}'>

        <div style='display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;'>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Host</label>
            <input name='host' value='".h((string)$db['host'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Port</label>
            <input name='port' value='".h((string)$db['port'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>

          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>DB Name</label>
            <input name='name' value='".h((string)$db['name'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>DB User</label>
            <input name='user' value='".h((string)$db['user'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>

          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>DB Password</label>
            <input name='pass' type='password' value='".h((string)$db['pass'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Timezone (MySQL session)</label>
            <input name='timezone' value='".h((string)$db['timezone'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>

          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Charset</label>
            <input name='charset' value='".h((string)$db['charset'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Collation</label>
            <input name='collation' value='".h((string)$db['collation'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
        </div>

        <label style='display:block;margin-top:12px;font-size:13px;color:#374151;'>
          <input type='checkbox' name='create_db' ".(((int)$db['create_db']===1) ? 'checked' : '').">
          دیتابیس را اگر وجود ندارد ایجاد کن (نیازمند دسترسی CREATE DATABASE)
        </label>

        <button type='submit' style='margin-top:14px;padding:10px 14px;border:none;border-radius:12px;background:#2563eb;color:#fff;cursor:pointer;'>
          تست اتصال و ادامه →
        </button>
      </form>
    ";

    echo installer_layout("دیتابیس", $body);
    exit;
}

// =============================================================================
// Step: Admin
// =============================================================================
function handle_admin_step(Logger $logger): void
{
    $csrf = h((string)($_SESSION['_csrf'] ?? ''));

    if (empty($_SESSION['install_config_draft'])) {
        flash_set('error', 'ابتدا باید مرحله دیتابیس را کامل کنید.');
        redirect('/install?step=database');
    }

    $error = flash_get('error');
    $info  = flash_get('info');

    $admin = $_SESSION['install_admin'] ?? [
        'username' => 'admin',
        'email' => '',
        'password' => '',
        'password_confirm' => '',
    ];

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        $admin = [
            'username' => $username,
            'email' => $email,
            'password' => '',
            'password_confirm' => '',
        ];
        $_SESSION['install_admin'] = $admin;

        if ($username === '' || $pass1 === '' || $pass2 === '') {
            flash_set('error', 'نام کاربری و رمز عبور الزامی است.');
            redirect('/install?step=admin');
        }
        if ($pass1 !== $pass2) {
            flash_set('error', 'رمزها یکسان نیستند.');
            redirect('/install?step=admin');
        }
        if (mb_strlen($pass1, 'UTF-8') < 8) {
            flash_set('error', 'رمز عبور حداقل ۸ کاراکتر باشد.');
            redirect('/install?step=admin');
        }

        // Store admin in session (password temporarily kept only in session)
        $_SESSION['install_admin_secret'] = [
            'username' => $username,
            'email' => $email,
            'password' => $pass1,
        ];

        $logger->info('INSTALL_ADMIN_COLLECTED', ['username' => $username, 'email' => $email ? '[set]' : '[empty]']);
        redirect('/install?step=migrate');
    }

    $errorHtml = $error ? "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffb3b3;margin-bottom:10px;border-radius:10px;'>".h($error)."</div>" : "";
    $infoHtml  = $info  ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin-bottom:10px;border-radius:10px;'>".h($info)."</div>" : "";

    $body = step_nav('admin') . "
      <h2 style='margin:0 0 10px;'>ساخت ادمین</h2>
      <p style='margin:0 0 12px;color:#6b7280;font-size:13px;line-height:1.9;'>
        اولین کاربر مدیر ساخته می‌شود.
      </p>
      {$errorHtml}
      {$infoHtml}

      <form method='post' action='/install?step=admin'>
        <input type='hidden' name='_csrf' value='{$csrf}'>

        <div style='display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;'>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Username</label>
            <input name='username' value='".h((string)$admin['username'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Email (optional)</label>
            <input name='email' value='".h((string)$admin['email'])."' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>

          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Password</label>
            <input name='password' type='password' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
          <div>
            <label style='display:block;margin-bottom:6px;font-size:13px;'>Confirm Password</label>
            <input name='password_confirm' type='password' style='width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;'>
          </div>
        </div>

        <button type='submit' style='margin-top:14px;padding:10px 14px;border:none;border-radius:12px;background:#2563eb;color:#fff;cursor:pointer;'>
          ادامه به ساخت جداول →
        </button>
      </form>
    ";

    echo installer_layout("ادمین", $body);
    exit;
}

// =============================================================================
// Step: Migrate
// =============================================================================
function handle_migrate_step(Logger $logger, string $MIGRATIONS_DIR, string $LOG_DIR): void
{
    $csrf = h((string)($_SESSION['_csrf'] ?? ''));

    $draft = $_SESSION['install_config_draft'] ?? null;
    if (!is_array($draft)) {
        flash_set('error', 'ابتدا مرحله دیتابیس را کامل کنید.');
        redirect('/install?step=database');
    }

    if (empty($_SESSION['install_admin_secret'])) {
        flash_set('error', 'ابتدا مرحله ساخت ادمین را کامل کنید.');
        redirect('/install?step=admin');
    }

    $error = flash_get('error');
    $info  = flash_get('info');

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $conn = new Connection($draft);
            $pdo = $conn->pdo();

            // Run migrator (full)
            $callableLogger = function (string $line) use ($logger) {
                $logger->debug('INSTALL_MIGRATOR', ['line' => $line]);
            };

            $migrator = new Migrator($pdo, $MIGRATIONS_DIR, $LOG_DIR, $callableLogger);

            $res = $migrator->run([
                'dry_run' => false,
                'specific' => null,
                'lock_timeout_sec' => 60,
            ]);

            if (!($res['ok'] ?? false)) {
                $logger->error('INSTALL_MIGRATE_FAILED', ['errors' => $res['errors'] ?? []]);
                flash_set('error', 'خطا در ساخت جدول‌ها. لطفاً لاگ‌ها را بررسی کنید.');
                redirect('/install?step=migrate');
            }

            // After migrations, create admin user
            $admin = $_SESSION['install_admin_secret'];
            $created = installer_create_admin_user($pdo, $admin['username'], $admin['email'], $admin['password'], $logger);
            if (!$created['ok']) {
                flash_set('error', 'خطا در ساخت ادمین: ' . ($created['message'] ?? ''));
                redirect('/install?step=migrate');
            }

            $logger->info('INSTALL_MIGRATE_OK', ['applied' => $res['applied'] ?? []]);
            flash_set('info', 'ساخت جدول‌ها و ادمین با موفقیت انجام شد.');
            redirect('/install?step=writeconfig');
        } catch (Throwable $e) {
            $logger->exception('INSTALL_MIGRATE_EXCEPTION', $e);
            flash_set('error', 'خطای اجرای migration: ' . $e->getMessage());
            redirect('/install?step=migrate');
        }
    }

    $errorHtml = $error ? "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffb3b3;margin-bottom:10px;border-radius:10px;'>".h($error)."</div>" : "";
    $infoHtml  = $info  ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin-bottom:10px;border-radius:10px;'>".h($info)."</div>" : "";

    $body = step_nav('migrate') . "
      <h2 style='margin:0 0 10px;'>ساخت جدول‌ها (Migration)</h2>
      <p style='margin:0 0 12px;color:#6b7280;font-size:13px;line-height:1.9;'>
        در این مرحله تمام جدول‌ها به‌صورت خودکار ساخته می‌شوند و سپس کاربر ادمین ثبت می‌شود.
      </p>
      {$errorHtml}
      {$infoHtml}

      <form method='post' action='/install?step=migrate'>
        <input type='hidden' name='_csrf' value='{$csrf}'>
        <button type='submit' style='margin-top:10px;padding:10px 14px;border:none;border-radius:12px;background:#2563eb;color:#fff;cursor:pointer;'>
          اجرای ساخت جداول و ساخت ادمین →
        </button>
      </form>

      <div style='margin-top:12px;padding:12px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:12px;color:#374151;font-size:13px;line-height:1.9;'>
        اگر این مرحله خطا داد، مسیر لاگ‌ها: <code>private/storage/logs/</code>
      </div>
    ";
    echo installer_layout("ساخت جدول‌ها", $body);
    exit;
}

// =============================================================================
// Step: Write Config + Lock
// =============================================================================
function handle_writeconfig_step(Logger $logger, string $PRIVATE_DIR, string $CONFIG_FILE, string $LOCK_FILE): void
{
    $draft = $_SESSION['install_config_draft'] ?? null;
    if (!is_array($draft)) {
        flash_set('error', 'پیکربندی دیتابیس موجود نیست.');
        redirect('/install?step=database');
    }

    // Add security / app defaults
    $draft['app']['env'] = $draft['app']['env'] ?? 'production';
    $draft['app']['timezone'] = $draft['app']['timezone'] ?? 'UTC';
    $draft['security']['session_name'] = $draft['security']['session_name'] ?? 'CRMSESSID';
    $draft['security']['install_completed_at'] = date('c');

    // Ensure private dir exists
    if (!is_dir($PRIVATE_DIR)) {
        @mkdir($PRIVATE_DIR, 0775, true);
    }

    // Write config.php
    $php = "<?php\n// Auto-generated by CRM Installer\nreturn " . var_export($draft, true) . ";\n";
    $ok = @file_put_contents($CONFIG_FILE, $php);

    if ($ok === false) {
        $logger->error('INSTALL_CONFIG_WRITE_FAILED', ['file' => $CONFIG_FILE]);
        flash_set('error', 'نوشتن فایل config.php ناموفق بود. مجوز نوشتن روی private را بررسی کنید.');
        redirect('/install?step=writeconfig');
    }

    // Write lock file
    $lockData = "installed_at=" . date('c') . "\n";
    $lockOk = @file_put_contents($LOCK_FILE, $lockData);

    if ($lockOk === false) {
        $logger->error('INSTALL_LOCK_WRITE_FAILED', ['file' => $LOCK_FILE]);
        flash_set('error', 'نوشتن install.lock ناموفق بود. مجوز نوشتن روی install را بررسی کنید.');
        redirect('/install?step=writeconfig');
    }

    $logger->info('INSTALL_WRITE_OK', ['config' => $CONFIG_FILE, 'lock' => $LOCK_FILE]);

    // Cleanup sensitive session
    unset($_SESSION['install_admin_secret']);

    flash_set('info', 'نصب کامل شد. اکنون می‌توانید وارد شوید.');
    redirect('/install?step=finish');
}

// =============================================================================
// Step: Finish
// =============================================================================
function show_finish(): void
{
    $info = flash_get('info');

    $infoHtml  = $info  ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin-bottom:10px;border-radius:10px;'>".h($info)."</div>" : "";

    $body = step_nav('finish') . "
      <h2 style='margin:0 0 10px;'>پایان نصب ✅</h2>
      <p style='margin:0 0 12px;color:#6b7280;font-size:13px;line-height:1.9;'>
        نصب انجام شد و فایل قفل نصب ایجاد گردید.
      </p>
      {$infoHtml}

      <div style='padding:12px;background:#fff7e6;border:1px solid #ffd08a;border-radius:12px;color:#374151;line-height:1.9;'>
        توصیه امنیتی:
        <ul style='margin:8px 0 0;padding-right:18px;'>
          <li>پس از نصب، دسترسی به پوشه <code>install/</code> را محدود کنید یا آن را حذف کنید.</li>
          <li>فایل <code>private/config.php</code> باید خارج از public بماند.</li>
          <li>از اکانت ادمین یک رمز قوی استفاده کنید.</li>
        </ul>
      </div>

      <div style='margin-top:14px;'>
        <a href='/login' style='display:inline-block;padding:10px 14px;border-radius:12px;background:#2563eb;color:#fff;text-decoration:none;'>
          رفتن به صفحه ورود →
        </a>
      </div>
    ";

    echo installer_layout("پایان", $body);
    exit;
}

// =============================================================================
// Create admin user after migrations
// =============================================================================
function installer_create_admin_user(PDO $pdo, string $username, string $email, string $password, Logger $logger): array
{
    // Validate users table exists
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        if (!$stmt->fetch()) {
            return ['ok' => false, 'message' => "جدول users وجود ندارد (migrations ناقص است)."];
        }
    } catch (Throwable $e) {
        $logger->exception('INSTALL_CHECK_USERS_TABLE_FAILED', $e);
        return ['ok' => false, 'message' => "خطا در بررسی جدول users."];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        // Ensure username unique
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $chk->execute([':u' => $username]);
        if ($chk->fetch()) {
            return ['ok' => false, 'message' => 'این نام کاربری قبلاً وجود دارد.'];
        }

        // Insert
        $sql = "INSERT INTO users (username, email, password_hash, is_active, created_at, updated_at)
                VALUES (:u, :e, :h, 1, NOW(), NOW())";
        $ins = $pdo->prepare($sql);
        $ins->execute([
            ':u' => $username,
            ':e' => $email,
            ':h' => $hash,
        ]);

        $logger->info('INSTALL_ADMIN_CREATED', ['username' => $username]);
        return ['ok' => true, 'message' => 'ادمین ساخته شد.'];
    } catch (Throwable $e) {
        $logger->exception('INSTALL_ADMIN_CREATE_FAILED', $e, ['username' => $username]);
        return ['ok' => false, 'message' => 'خطا در ساخت ادمین: ' . $e->getMessage()];
    }
}

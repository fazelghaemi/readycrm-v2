<?php
/**
 * File: app/Http/Controllers/AuthController.php
 *
 * CRM V2 - Authentication Controller
 * ------------------------------------------------------------
 * Routes (from app/Bootstrap/routes.php):
 *   GET  /login               -> showLogin
 *   POST /login               -> login
 *   POST /logout              -> logout
 *
 *   GET  /forgot-password     -> showForgotPassword
 *   POST /forgot-password     -> sendResetLink
 *   GET  /reset-password      -> showResetPassword   (expects ?token=...)
 *   POST /reset-password      -> resetPassword
 *
 * DB expectations:
 * - users table:
 *     id (PK), username, email, password_hash, is_active, created_at, updated_at
 * - password_resets table (recommended; should be created in migrations):
 *     id, user_id, token_hash, expires_at, used_at, created_at, ip, user_agent
 *
 * Security:
 * - CSRF is validated globally in public/index.php (csrfValidateOrFail()).
 * - Login form includes hidden _csrf.
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Database\Connection;
use App\Support\Logger;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AuthController
{
    /** @var array<string,mixed> */
    private array $config;

    private PDO $pdo;
    private Logger $logger;

    // -------------------------------
    // Security tuning
    // -------------------------------
    private int $maxLoginAttempts = 8;         // per session window
    private int $lockSeconds = 60;             // lock after too many tries
    private int $minDelayMs = 300;             // always add small delay to slow brute force
    private int $extraDelayMsPerFail = 250;    // incremental delay per fail

    // Password reset tuning
    private int $resetTokenBytes = 32;         // raw token bytes
    private int $resetTokenTtlMinutes = 30;    // token validity
    private int $resetMinIntervalSec = 45;     // to prevent spamming reset requests
    private int $resetMaxPerHour = 6;

    public function __construct(array $config)
    {
        $this->config = $config;

        // Build logger
        $logDir = (defined('CRM_PRIVATE_DIR') ? CRM_PRIVATE_DIR : (dirname(__DIR__, 4) . '/private')) . '/storage/logs';
        $isDev = strtolower((string)($config['app']['env'] ?? 'production')) !== 'production';
        $this->logger = new Logger($logDir, $isDev);

        // Build PDO
        $conn = new Connection($config);
        $this->pdo = $conn->pdo();
    }

    // ---------------------------------------------------------------------
    // GET /login
    // ---------------------------------------------------------------------
    public function showLogin(): void
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/');
            return;
        }

        $error = $_SESSION['_flash_error'] ?? null;
        $info  = $_SESSION['_flash_info'] ?? null;
        unset($_SESSION['_flash_error'], $_SESSION['_flash_info']);

        $csrf = $this->csrfToken();
        $this->renderHtml($this->pageLogin($csrf, $error, $info));
    }

    // ---------------------------------------------------------------------
    // POST /login
    // ---------------------------------------------------------------------
    public function login(): void
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/');
            return;
        }

        // Basic anti brute-force using session counters
        if ($this->isLoginLocked()) {
            $remaining = $this->loginLockRemaining();
            $this->flashError("تعداد تلاش‌ها زیاد بوده. لطفاً {$remaining} ثانیه دیگر دوباره تلاش کنید.");
            $this->logger->warn('AUTH_LOGIN_LOCKED', [
                'remaining_sec' => $remaining,
                'ip' => $this->ip(),
            ]);
            $this->redirect('/login');
            return;
        }

        // Normalize inputs
        $usernameOrEmail = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $remember = isset($_POST['remember']) && (string)$_POST['remember'] === '1';

        // Always apply a minimum delay to slow down brute force
        $this->delayMs($this->minDelayMs);

        if ($usernameOrEmail === '' || $password === '') {
            $this->registerFailedAttempt();
            $this->flashError('نام کاربری/ایمیل و رمز عبور الزامی است.');
            $this->redirect('/login');
            return;
        }

        // Fetch user
        try {
            $user = $this->findUserByUsernameOrEmail($usernameOrEmail);
        } catch (Throwable $e) {
            $this->registerFailedAttempt();
            $this->flashError('خطای داخلی. لطفاً دوباره تلاش کنید.');
            $this->logger->exception('AUTH_LOGIN_DB_ERROR', $e, [
                'input' => $this->mask($usernameOrEmail),
            ]);
            $this->redirect('/login');
            return;
        }

        // If user not found => fail with same message (avoid user enumeration)
        if (!$user) {
            $this->registerFailedAttempt();
            $this->delayMs($this->extraDelayMsPerFail * $this->failedAttemptsCount());
            $this->flashError('اطلاعات ورود نامعتبر است.');
            $this->logger->warn('AUTH_LOGIN_INVALID_USER', [
                'input' => $this->mask($usernameOrEmail),
                'ip' => $this->ip(),
            ]);
            $this->redirect('/login');
            return;
        }

        // If inactive
        if ((int)($user['is_active'] ?? 1) !== 1) {
            $this->registerFailedAttempt();
            $this->flashError('حساب کاربری غیرفعال است.');
            $this->logger->warn('AUTH_LOGIN_INACTIVE', [
                'user_id' => $user['id'] ?? null,
                'ip' => $this->ip(),
            ]);
            $this->redirect('/login');
            return;
        }

        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            $this->registerFailedAttempt();
            $this->delayMs($this->extraDelayMsPerFail * $this->failedAttemptsCount());
            $this->flashError('اطلاعات ورود نامعتبر است.');
            $this->logger->warn('AUTH_LOGIN_BAD_PASSWORD', [
                'user_id' => $user['id'] ?? null,
                'ip' => $this->ip(),
            ]);

            // Lock if too many tries
            $this->maybeLockLogin();

            $this->redirect('/login');
            return;
        }

        // Success
        $this->clearFailedAttempts();

        // Upgrade hash if algorithm changed
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            try {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $this->updateUserPasswordHash((int)$user['id'], $newHash);
                $this->logger->info('AUTH_PASSWORD_REHASH', ['user_id' => $user['id'] ?? null]);
            } catch (Throwable $e) {
                // Not critical; proceed
                $this->logger->exception('AUTH_PASSWORD_REHASH_FAILED', $e, ['user_id' => $user['id'] ?? null]);
            }
        }

        // Regenerate session id to prevent fixation
        $this->regenerateSession();

        // Store auth session
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = (string)($user['username'] ?? '');
        $_SESSION['email'] = (string)($user['email'] ?? '');
        $_SESSION['logged_in_at'] = time();

        // Remember-me cookie (optional / simplified)
        // پیشنهاد حرفه‌ای: token-based remember-me با جدول جدا.
        // اینجا فعلاً خاموش/ساده نگه می‌داریم تا بعداً کاملش کنیم.
        if ($remember) {
            $this->setRememberCookie();
        }

        $this->logger->info('AUTH_LOGIN_OK', [
            'user_id' => $user['id'] ?? null,
            'ip' => $this->ip(),
        ]);

        $this->redirect('/');
    }

    // ---------------------------------------------------------------------
    // POST /logout
    // ---------------------------------------------------------------------
    public function logout(): void
    {
        $uid = $_SESSION['user_id'] ?? null;

        // Clear session data
        $_SESSION = [];

        // Expire session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
        }

        // Remove remember cookie (if any)
        $this->clearRememberCookie();

        // Destroy session
        session_destroy();

        $this->logger->info('AUTH_LOGOUT', [
            'user_id' => $uid,
            'ip' => $this->ip(),
        ]);

        // New session for flash messages
        session_start();
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        $this->flashInfo('خروج با موفقیت انجام شد.');
        $this->redirect('/login');
    }

    // ---------------------------------------------------------------------
    // GET /forgot-password
    // ---------------------------------------------------------------------
    public function showForgotPassword(): void
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/');
            return;
        }

        $error = $_SESSION['_flash_error'] ?? null;
        $info  = $_SESSION['_flash_info'] ?? null;
        unset($_SESSION['_flash_error'], $_SESSION['_flash_info']);

        $csrf = $this->csrfToken();
        $this->renderHtml($this->pageForgotPassword($csrf, $error, $info));
    }

    // ---------------------------------------------------------------------
    // POST /forgot-password
    // ---------------------------------------------------------------------
    public function sendResetLink(): void
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/');
            return;
        }

        $input = trim((string)($_POST['username_or_email'] ?? ''));
        $this->delayMs($this->minDelayMs);

        if ($input === '') {
            $this->flashError('ایمیل یا نام کاربری الزامی است.');
            $this->redirect('/forgot-password');
            return;
        }

        // Rate-limit reset requests (session-based)
        if ($this->isResetRateLimited()) {
            $this->flashError('تعداد درخواست‌های بازیابی زیاد است. لطفاً کمی بعد تلاش کنید.');
            $this->logger->warn('AUTH_RESET_RATE_LIMIT', ['ip' => $this->ip()]);
            $this->redirect('/forgot-password');
            return;
        }

        // Find user (do NOT reveal existence)
        try {
            $user = $this->findUserByUsernameOrEmail($input);
        } catch (Throwable $e) {
            $this->flashInfo('اگر حسابی با این مشخصات وجود داشته باشد، لینک بازیابی ارسال خواهد شد.');
            $this->logger->exception('AUTH_RESET_DB_ERROR', $e, ['input' => $this->mask($input)]);
            $this->redirect('/forgot-password');
            return;
        }

        // If password_resets table missing, fail gracefully
        if (!$this->tableExists('password_resets')) {
            $this->logger->warn('AUTH_RESET_TABLE_MISSING', []);
            $this->flashInfo('این قابلیت هنوز فعال نشده است. (جدول password_resets موجود نیست)');
            $this->redirect('/forgot-password');
            return;
        }

        if ($user && (int)($user['is_active'] ?? 1) === 1) {
            // Create reset token
            $token = bin2hex(random_bytes($this->resetTokenBytes));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + ($this->resetTokenTtlMinutes * 60));

            try {
                $this->insertPasswordResetToken((int)$user['id'], $tokenHash, $expiresAt);
                $this->logger->info('AUTH_RESET_TOKEN_CREATED', [
                    'user_id' => $user['id'] ?? null,
                    'ip' => $this->ip(),
                ]);

                // ارسال ایمیل/پیامک واقعی در فاز بعد:
                // اینجا فعلاً لینک را در log ثبت می‌کنیم (در dev) یا پیام عمومی می‌دهیم.
                $resetUrl = $this->absoluteUrl('/reset-password?token=' . urlencode($token));
                $this->logger->info('AUTH_RESET_URL', [
                    'user_id' => $user['id'] ?? null,
                    'reset_url' => $resetUrl, // در prod بهتر است این را log نکنید
                ]);

                // برای کاربر: پیام عمومی
                $this->flashInfo('اگر حسابی با این مشخصات وجود داشته باشد، لینک بازیابی ارسال خواهد شد.');

                // در محیط توسعه می‌توانیم لینک را نشان بدهیم:
                if ($this->isDev()) {
                    $this->flashInfo("Dev لینک ریست: {$resetUrl}");
                }
            } catch (Throwable $e) {
                $this->logger->exception('AUTH_RESET_TOKEN_INSERT_FAILED', $e, ['user_id' => $user['id'] ?? null]);
                // پیام عمومی بدون افشای اطلاعات
                $this->flashInfo('اگر حسابی با این مشخصات وجود داشته باشد، لینک بازیابی ارسال خواهد شد.');
            }
        } else {
            $this->flashInfo('اگر حسابی با این مشخصات وجود داشته باشد، لینک بازیابی ارسال خواهد شد.');
        }

        $this->registerResetRequest();
        $this->redirect('/forgot-password');
    }

    // ---------------------------------------------------------------------
    // GET /reset-password?token=...
    // ---------------------------------------------------------------------
    public function showResetPassword(): void
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/');
            return;
        }

        $token = trim((string)($_GET['token'] ?? ''));
        $error = $_SESSION['_flash_error'] ?? null;
        $info  = $_SESSION['_flash_info'] ?? null;
        unset($_SESSION['_flash_error'], $_SESSION['_flash_info']);

        $csrf = $this->csrfToken();
        $this->renderHtml($this->pageResetPassword($csrf, $token, $error, $info));
    }

    // ---------------------------------------------------------------------
    // POST /reset-password
    // ---------------------------------------------------------------------
    public function resetPassword(): void
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/');
            return;
        }

        $token = trim((string)($_POST['token'] ?? ''));
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        $this->delayMs($this->minDelayMs);

        if ($token === '' || $pass1 === '' || $pass2 === '') {
            $this->flashError('همه فیلدها الزامی است.');
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        if ($pass1 !== $pass2) {
            $this->flashError('رمزهای عبور یکسان نیستند.');
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        if (!$this->isStrongPassword($pass1)) {
            $this->flashError('رمز عبور باید حداقل ۸ کاراکتر و شامل حروف و عدد باشد.');
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        if (!$this->tableExists('password_resets')) {
            $this->flashError('این قابلیت هنوز فعال نشده است.');
            $this->redirect('/forgot-password');
            return;
        }

        $tokenHash = hash('sha256', $token);

        try {
            $resetRow = $this->findValidResetRow($tokenHash);
            if (!$resetRow) {
                $this->flashError('توکن نامعتبر یا منقضی شده است.');
                $this->redirect('/forgot-password');
                return;
            }

            $userId = (int)$resetRow['user_id'];
            $newHash = password_hash($pass1, PASSWORD_DEFAULT);

            $this->pdo->beginTransaction();

            $this->updateUserPasswordHash($userId, $newHash);
            $this->markResetTokenUsed((int)$resetRow['id']);

            $this->pdo->commit();

            $this->logger->info('AUTH_PASSWORD_RESET_OK', [
                'user_id' => $userId,
                'ip' => $this->ip(),
            ]);

            $this->flashInfo('رمز عبور با موفقیت تغییر کرد. اکنون وارد شوید.');
            $this->redirect('/login');
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (Throwable $ignore) {}
            }
            $this->logger->exception('AUTH_PASSWORD_RESET_FAILED', $e, ['ip' => $this->ip()]);
            $this->flashError('خطا در تغییر رمز. لطفاً دوباره تلاش کنید.');
            $this->redirect('/reset-password?token=' . urlencode($token));
        }
    }

    // =============================================================================
    // Internals: DB
    // =============================================================================

    /**
     * @return array<string,mixed>|null
     */
    private function findUserByUsernameOrEmail(string $usernameOrEmail): ?array
    {
        // prevent very long input
        $usernameOrEmail = mb_substr($usernameOrEmail, 0, 190, 'UTF-8');

        $sql = "SELECT id, username, email, password_hash, is_active
                FROM users
                WHERE username = :u OR email = :u
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':u' => $usernameOrEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function updateUserPasswordHash(int $userId, string $hash): void
    {
        $sql = "UPDATE users SET password_hash = :h, updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':h' => $hash, ':id' => $userId]);
    }

    private function insertPasswordResetToken(int $userId, string $tokenHash, string $expiresAt): void
    {
        $sql = "INSERT INTO password_resets (user_id, token_hash, expires_at, used_at, created_at, ip, user_agent)
                VALUES (:uid, :th, :exp, NULL, NOW(), :ip, :ua)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $userId,
            ':th' => $tokenHash,
            ':exp' => $expiresAt,
            ':ip' => $this->ip(),
            ':ua' => $this->userAgent(),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findValidResetRow(string $tokenHash): ?array
    {
        $sql = "SELECT id, user_id, expires_at, used_at
                FROM password_resets
                WHERE token_hash = :th
                  AND used_at IS NULL
                  AND expires_at >= NOW()
                ORDER BY id DESC
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':th' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function markResetTokenUsed(int $resetId): void
    {
        $sql = "UPDATE password_resets SET used_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $resetId]);
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE :t");
            $stmt->execute([':t' => $table]);
            $row = $stmt->fetch(PDO::FETCH_NUM);
            return (bool)$row;
        } catch (Throwable $e) {
            return false;
        }
    }

    // =============================================================================
    // Internals: Security / Sessions / Rate Limits
    // =============================================================================

    private function isAuthenticated(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    private function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }

    private function csrfToken(): string
    {
        $t = $_SESSION['_csrf_token'] ?? null;
        if (!is_string($t) || $t === '') {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf_token'];
    }

    private function isStrongPassword(string $pass): bool
    {
        if (mb_strlen($pass, 'UTF-8') < 8) return false;

        $hasLetter = (bool)preg_match('/[A-Za-z]/', $pass);
        $hasDigit  = (bool)preg_match('/[0-9]/', $pass);

        return $hasLetter && $hasDigit;
    }

    private function isLoginLocked(): bool
    {
        $until = $_SESSION['_login_lock_until'] ?? 0;
        return is_int($until) && $until > time();
    }

    private function loginLockRemaining(): int
    {
        $until = (int)($_SESSION['_login_lock_until'] ?? 0);
        $r = $until - time();
        return $r > 0 ? $r : 0;
    }

    private function registerFailedAttempt(): void
    {
        $_SESSION['_login_attempts'] = (int)($_SESSION['_login_attempts'] ?? 0) + 1;
        $_SESSION['_login_last_fail'] = time();
    }

    private function failedAttemptsCount(): int
    {
        return (int)($_SESSION['_login_attempts'] ?? 0);
    }

    private function clearFailedAttempts(): void
    {
        unset($_SESSION['_login_attempts'], $_SESSION['_login_last_fail'], $_SESSION['_login_lock_until']);
    }

    private function maybeLockLogin(): void
    {
        $attempts = $this->failedAttemptsCount();
        if ($attempts >= $this->maxLoginAttempts) {
            $_SESSION['_login_lock_until'] = time() + $this->lockSeconds;
        }
    }

    private function delayMs(int $ms): void
    {
        if ($ms <= 0) return;
        usleep($ms * 1000);
    }

    private function setRememberCookie(): void
    {
        // پیاده‌سازی کامل remember-me نیازمند جدول و token rotation است.
        // اینجا فقط یک placeholder ایمن می‌گذاریم:
        // - Cookie صرفاً یک فلگ است (بدون ارزش امنیتی)، بعداً با سیستم token جایگزین می‌شود.
        setcookie('crm_remember', '1', [
            'expires' => time() + 60 * 60 * 24 * 14,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberCookie(): void
    {
        setcookie('crm_remember', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // Reset request rate-limit (session-based)
    private function registerResetRequest(): void
    {
        $_SESSION['_reset_last'] = time();
        $_SESSION['_reset_count_hour'] = (int)($_SESSION['_reset_count_hour'] ?? 0) + 1;
        $_SESSION['_reset_count_hour_start'] = (int)($_SESSION['_reset_count_hour_start'] ?? time());
    }

    private function isResetRateLimited(): bool
    {
        $last = (int)($_SESSION['_reset_last'] ?? 0);
        if ($last > 0 && (time() - $last) < $this->resetMinIntervalSec) {
            return true;
        }

        $start = (int)($_SESSION['_reset_count_hour_start'] ?? time());
        $count = (int)($_SESSION['_reset_count_hour'] ?? 0);

        if ((time() - $start) > 3600) {
            // reset window
            $_SESSION['_reset_count_hour_start'] = time();
            $_SESSION['_reset_count_hour'] = 0;
            return false;
        }

        return $count >= $this->resetMaxPerHour;
    }

    // =============================================================================
    // Internals: Rendering / UI helpers (temporary inline HTML)
    // =============================================================================

    private function renderHtml(string $html): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    private function pageLogin(string $csrf, ?string $error, ?string $info): string
    {
        $errorHtml = $error ? "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffb3b3;margin-bottom:10px;border-radius:8px;'>".htmlspecialchars($error)."</div>" : "";
        $infoHtml  = $info  ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin-bottom:10px;border-radius:8px;'>".htmlspecialchars($info)."</div>" : "";

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ورود | CRM V2</title>
</head>
<body style="font-family:tahoma,Arial; background:#f5f6f8; margin:0; padding:30px;">
  <div style="max-width:420px; margin:0 auto; background:#fff; padding:22px; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,0.06);">
    <h2 style="margin:0 0 10px;">ورود به CRM</h2>
    <p style="margin:0 0 18px; color:#666; font-size:13px;">نام کاربری یا ایمیل و رمز عبور را وارد کنید.</p>
    {$errorHtml}
    {$infoHtml}

    <form method="post" action="/login">
      <input type="hidden" name="_csrf" value="{$csrf}">
      <div style="margin-bottom:12px;">
        <label style="display:block; margin-bottom:6px; font-size:13px;">نام کاربری / ایمیل</label>
        <input name="username" type="text" autocomplete="username" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:10px;">
      </div>

      <div style="margin-bottom:12px;">
        <label style="display:block; margin-bottom:6px; font-size:13px;">رمز عبور</label>
        <input name="password" type="password" autocomplete="current-password" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:10px;">
      </div>

      <div style="display:flex; align-items:center; justify-content:space-between; margin:10px 0 16px;">
        <label style="font-size:13px; color:#555;">
          <input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار
        </label>
        <a href="/forgot-password" style="font-size:13px; color:#2b6cb0; text-decoration:none;">فراموشی رمز؟</a>
      </div>

      <button type="submit" style="width:100%; padding:11px; border:none; background:#2b6cb0; color:#fff; border-radius:10px; cursor:pointer;">
        ورود
      </button>
    </form>
  </div>
</body>
</html>
HTML;
    }

    private function pageForgotPassword(string $csrf, ?string $error, ?string $info): string
    {
        $errorHtml = $error ? "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffb3b3;margin-bottom:10px;border-radius:8px;'>".htmlspecialchars($error)."</div>" : "";
        $infoHtml  = $info  ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin-bottom:10px;border-radius:8px;'>".htmlspecialchars($info)."</div>" : "";

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>فراموشی رمز | CRM V2</title>
</head>
<body style="font-family:tahoma,Arial; background:#f5f6f8; margin:0; padding:30px;">
  <div style="max-width:480px; margin:0 auto; background:#fff; padding:22px; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,0.06);">
    <h2 style="margin:0 0 10px;">بازیابی رمز عبور</h2>
    <p style="margin:0 0 18px; color:#666; font-size:13px;">ایمیل یا نام کاربری را وارد کنید.</p>
    {$errorHtml}
    {$infoHtml}

    <form method="post" action="/forgot-password">
      <input type="hidden" name="_csrf" value="{$csrf}">
      <div style="margin-bottom:12px;">
        <label style="display:block; margin-bottom:6px; font-size:13px;">ایمیل / نام کاربری</label>
        <input name="username_or_email" type="text" autocomplete="username" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:10px;">
      </div>

      <button type="submit" style="width:100%; padding:11px; border:none; background:#2b6cb0; color:#fff; border-radius:10px; cursor:pointer;">
        ارسال لینک بازیابی
      </button>
    </form>

    <div style="margin-top:14px;">
      <a href="/login" style="font-size:13px; color:#2b6cb0; text-decoration:none;">بازگشت به ورود</a>
    </div>
  </div>
</body>
</html>
HTML;
    }

    private function pageResetPassword(string $csrf, string $token, ?string $error, ?string $info): string
    {
        $errorHtml = $error ? "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffb3b3;margin-bottom:10px;border-radius:8px;'>".htmlspecialchars($error)."</div>" : "";
        $infoHtml  = $info  ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin-bottom:10px;border-radius:8px;'>".htmlspecialchars($info)."</div>" : "";

        $tokenEsc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ریست رمز | CRM V2</title>
</head>
<body style="font-family:tahoma,Arial; background:#f5f6f8; margin:0; padding:30px;">
  <div style="max-width:480px; margin:0 auto; background:#fff; padding:22px; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,0.06);">
    <h2 style="margin:0 0 10px;">تغییر رمز عبور</h2>
    <p style="margin:0 0 18px; color:#666; font-size:13px;">رمز عبور جدید را وارد کنید.</p>
    {$errorHtml}
    {$infoHtml}

    <form method="post" action="/reset-password">
      <input type="hidden" name="_csrf" value="{$csrf}">
      <input type="hidden" name="token" value="{$tokenEsc}">

      <div style="margin-bottom:12px;">
        <label style="display:block; margin-bottom:6px; font-size:13px;">رمز جدید</label>
        <input name="password" type="password" autocomplete="new-password" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:10px;">
      </div>

      <div style="margin-bottom:12px;">
        <label style="display:block; margin-bottom:6px; font-size:13px;">تکرار رمز جدید</label>
        <input name="password_confirm" type="password" autocomplete="new-password" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:10px;">
      </div>

      <button type="submit" style="width:100%; padding:11px; border:none; background:#2b6cb0; color:#fff; border-radius:10px; cursor:pointer;">
        تغییر رمز
      </button>
    </form>

    <div style="margin-top:14px;">
      <a href="/login" style="font-size:13px; color:#2b6cb0; text-decoration:none;">بازگشت به ورود</a>
    </div>
  </div>
</body>
</html>
HTML;
    }

    // =============================================================================
    // Internals: misc helpers
    // =============================================================================

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 302);
        exit;
    }

    private function flashError(string $msg): void
    {
        $_SESSION['_flash_error'] = $msg;
    }

    private function flashInfo(string $msg): void
    {
        $_SESSION['_flash_info'] = $msg;
    }

    private function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function userAgent(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return mb_substr($ua, 0, 255, 'UTF-8');
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    }

    private function isDev(): bool
    {
        $env = strtolower((string)($this->config['app']['env'] ?? 'production'));
        return $env !== 'production';
    }

    private function absoluteUrl(string $path): string
    {
        $base = (string)($this->config['app']['base_url'] ?? '');
        if ($base !== '') {
            return rtrim($base, '/') . $path;
        }
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . $path;
    }

    private function mask(string $s): string
    {
        // mask email/username partially for logs
        $s = trim($s);
        if ($s === '') return '';
        if (mb_strlen($s, 'UTF-8') <= 3) return '***';
        return mb_substr($s, 0, 2, 'UTF-8') . '***' . mb_substr($s, -1, 1, 'UTF-8');
    }
}

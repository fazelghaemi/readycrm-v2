<?php
/**
 * File: app/Database/Connection.php
 *
 * CRM V2 - Database Connection (PDO)
 * ------------------------------------------------------------
 * این کلاس یک اتصال استاندارد و امن به دیتابیس MySQL ایجاد می‌کند.
 * مناسب برای:
 *  - اجرای Migrationها (CLI/Installer)
 *  - اجرای اپلیکیشن در زمان اجرا
 *
 * انتظار از config:
 *  [
 *    'db' => [
 *      'host' => 'localhost',
 *      'port' => 3306,                 // optional
 *      'name' => 'crm',
 *      'user' => 'root',
 *      'pass' => 'secret',
 *      'charset' => 'utf8mb4',         // optional
 *      'collation' => 'utf8mb4_unicode_ci', // optional
 *      'timezone' => '+00:00',         // optional
 *      'ssl' => [ ... ]                // optional advanced
 *    ]
 *  ]
 *
 * Notes:
 * - در پروژه واقعی، پیشنهاد می‌شود config.php در مسیر private/config.php
 *   توسط Installer ساخته شود و داخل public قرار نگیرد.
 */

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    /** @var array<string,mixed> */
    private array $config;

    private ?PDO $pdo = null;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * ساخت یا دریافت PDO آماده
     */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $db = $this->getDbConfig();

        $host = (string)($db['host'] ?? 'localhost');
        $port = (int)($db['port'] ?? 3306);
        $name = (string)($db['name'] ?? '');
        $user = (string)($db['user'] ?? '');
        $pass = (string)($db['pass'] ?? '');

        if ($name === '' || $user === '') {
            throw new RuntimeException("Database configuration is incomplete (db.name/db.user).");
        }

        $charset = (string)($db['charset'] ?? 'utf8mb4');
        // Collation را معمولاً در سطح DB/Table تنظیم می‌کنیم، ولی اینجا برای completeness نگه می‌داریم
        $timezone = (string)($db['timezone'] ?? '+00:00');

        // DSN استاندارد
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // برای جلوگیری از تزریق و رفتارهای عجیب
            PDO::ATTR_EMULATE_PREPARES   => false,
            // اختیاری: اتصال پایدار (پیشنهاد نمی‌شود مگر نیاز داشته باشید)
            // PDO::ATTR_PERSISTENT         => false,
        ];

        // SSL (اختیاری/پیشرفته)
        // اگر در config['db']['ssl'] مشخص شده باشد، اعمال می‌شود.
        if (!empty($db['ssl']) && is_array($db['ssl'])) {
            $ssl = $db['ssl'];
            // مثال:
            // 'ssl' => [
            //   'ca' => '/path/to/ca.pem',
            //   'cert' => '/path/to/client-cert.pem',
            //   'key' => '/path/to/client-key.pem',
            //   'verify_server_cert' => true
            // ]
            if (!empty($ssl['ca'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl['ca'];
            }
            if (!empty($ssl['cert'])) {
                $options[PDO::MYSQL_ATTR_SSL_CERT] = $ssl['cert'];
            }
            if (!empty($ssl['key'])) {
                $options[PDO::MYSQL_ATTR_SSL_KEY] = $ssl['key'];
            }
            if (isset($ssl['verify_server_cert'])) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool)$ssl['verify_server_cert'];
            }
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            // تنظیم timezone session در MySQL (اختیاری اما برای تاریخ‌ها مفید)
            // توجه: نیازمند اجازه SET time_zone است.
            if ($timezone !== '') {
                try {
                    $stmt = $pdo->prepare("SET time_zone = :tz");
                    $stmt->execute([':tz' => $timezone]);
                } catch (\Throwable $ignore) {
                    // اگر اجازه نبود یا خطا داد، اپ را fail نکن
                }
            }

            // چند تنظیم پیشنهادی برای پایداری/سازگاری
            // strict mode را بهتر است در سطح سرور یا DB تنظیم کنید
            // ولی اینجا می‌توانیم حداقل character_set را مطمئن کنیم:
            try {
                $pdo->exec("SET NAMES {$charset}");
            } catch (\Throwable $ignore) {}

            $this->pdo = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException($this->friendlyPdoError($e), 0, $e);
        }
    }

    /**
     * تست اتصال (برای Installer یا صفحه Settings)
     * @return array{ok:bool,message:string,details?:array<string,mixed>}
     */
    public function test(): array
    {
        try {
            $pdo = $this->pdo();
            $row = $pdo->query("SELECT 1 AS ok")->fetch();
            $version = $this->serverVersion();

            return [
                'ok' => true,
                'message' => 'اتصال به دیتابیس با موفقیت انجام شد.',
                'details' => [
                    'ping' => $row['ok'] ?? 1,
                    'server_version' => $version,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'اتصال به دیتابیس ناموفق بود: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * اجرای یک query ساده (برای ابزارهای داخلی)
     * @param string $sql
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * گرفتن نسخه MySQL/MariaDB
     */
    public function serverVersion(): string
    {
        try {
            $row = $this->pdo()->query("SELECT VERSION() AS v")->fetch();
            return (string)($row['v'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * ساخت دیتابیس (اختیاری) - اگر در Installer خواستی DB را خودکار ایجاد کنی
     * NOTE: این کار نیازمند اجازه CREATE DATABASE است.
     */
    public static function createDatabaseIfNotExists(array $config): array
    {
        // انتظار: config['db'] شامل host, port, name, user, pass
        $db = $config['db'] ?? [];
        $host = (string)($db['host'] ?? 'localhost');
        $port = (int)($db['port'] ?? 3306);
        $name = (string)($db['name'] ?? '');
        $user = (string)($db['user'] ?? '');
        $pass = (string)($db['pass'] ?? '');
        $charset = (string)($db['charset'] ?? 'utf8mb4');
        $collation = (string)($db['collation'] ?? 'utf8mb4_unicode_ci');

        if ($name === '' || $user === '') {
            return ['ok' => false, 'message' => 'نام دیتابیس یا نام کاربری دیتابیس مشخص نشده است.'];
        }

        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            // MySQL identifier escaping:
            // از backtick استفاده می‌کنیم، و فقط نام‌های سالم را اجازه می‌دهیم.
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                return ['ok' => false, 'message' => 'نام دیتابیس نامعتبر است. فقط حروف/عدد/underscore مجاز است.'];
            }

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE {$collation}");
            return ['ok' => true, 'message' => "دیتابیس `{$name}` آماده است."];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'خطا در ساخت دیتابیس: ' . $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function getDbConfig(): array
    {
        $db = $this->config['db'] ?? null;
        if (!is_array($db)) {
            throw new RuntimeException("Missing 'db' config section.");
        }
        return $db;
    }

    /**
     * پیام خطای قابل فهم برای کاربر غیر فنی
     */
    private function friendlyPdoError(PDOException $e): string
    {
        $msg = $e->getMessage();

        // چند الگوی رایج:
        if (stripos($msg, 'SQLSTATE[HY000] [1045]') !== false) {
            return 'خطای دسترسی دیتابیس: نام کاربری یا رمز عبور اشتباه است (1045).';
        }
        if (stripos($msg, 'SQLSTATE[HY000] [2002]') !== false) {
            return 'خطای اتصال: هاست/پورت دیتابیس در دسترس نیست (2002).';
        }
        if (stripos($msg, 'Unknown database') !== false) {
            return 'دیتابیس پیدا نشد. نام دیتابیس را بررسی کنید یا اجازه ساخت دیتابیس بدهید.';
        }
        if (stripos($msg, 'Access denied') !== false) {
            return 'دسترسی به دیتابیس رد شد. سطح دسترسی یوزر دیتابیس را بررسی کنید.';
        }

        // حالت عمومی
        return 'خطا در اتصال به دیتابیس: ' . $msg;
    }
}

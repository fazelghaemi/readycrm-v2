<?php
/**
 * File: app/Http/Controllers/DashboardController.php
 *
 * CRM V2 - Dashboard Controller
 * ------------------------------------------------------------
 * Routes:
 *   GET /, /dashboard -> index()
 *
 * Responsibilities:
 *  - Require authentication
 *  - Show high-level KPIs
 *  - Show latest activity (sales/customers)
 *  - Surface integration statuses (WooCommerce / AI) if tables exist
 *
 * Notes:
 *  - This controller is intentionally resilient:
 *    If DB tables are not created yet, it will not crash.
 *  - Uses inline HTML for now (fast). Later you can move to Views.
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Database\Connection;
use App\Support\Logger;
use PDO;
use Throwable;

final class DashboardController
{
    /** @var array<string,mixed> */
    private array $config;

    private PDO $pdo;
    private Logger $logger;

    public function __construct(array $config)
    {
        $this->config = $config;

        $logDir = (defined('CRM_PRIVATE_DIR') ? CRM_PRIVATE_DIR : (dirname(__DIR__, 4) . '/private')) . '/storage/logs';
        $isDev = strtolower((string)($config['app']['env'] ?? 'production')) !== 'production';
        $this->logger = new Logger($logDir, $isDev);

        $conn = new Connection($config);
        $this->pdo = $conn->pdo();
    }

    // ---------------------------------------------------------------------
    // GET /, /dashboard
    // ---------------------------------------------------------------------
    public function index(): void
    {
        $this->requireAuth();

        $error = $_SESSION['_flash_error'] ?? null;
        $info  = $_SESSION['_flash_info'] ?? null;
        unset($_SESSION['_flash_error'], $_SESSION['_flash_info']);

        // Gather data safely
        $kpis = $this->getKpisSafe();
        $latestSales = $this->getLatestSalesSafe(8);
        $latestCustomers = $this->getLatestCustomersSafe(8);
        $integrations = $this->getIntegrationsStatusSafe();

        $username = (string)($_SESSION['username'] ?? 'کاربر');

        $this->logger->info('DASHBOARD_VIEW', [
            'user_id' => $_SESSION['user_id'] ?? null,
        ]);

        $html = $this->renderDashboardHtml([
            'username' => $username,
            'kpis' => $kpis,
            'latestSales' => $latestSales,
            'latestCustomers' => $latestCustomers,
            'integrations' => $integrations,
            'flash_error' => $error,
            'flash_info' => $info,
        ]);

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    // =============================================================================
    // Auth
    // =============================================================================

    private function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login', true, 302);
            exit;
        }
    }

    // =============================================================================
    // KPIs
    // =============================================================================

    /**
     * @return array<string,mixed>
     */
    private function getKpisSafe(): array
    {
        $kpis = [
            'customers_count' => null,
            'products_count' => null,
            'sales_count' => null,
            'sales_today_count' => null,
            'sales_30d_total' => null,
            'payments_30d_total' => null,
            'db_warnings' => [],
        ];

        // Customers
        if ($this->tableExists('customers')) {
            $kpis['customers_count'] = $this->scalarInt("SELECT COUNT(*) AS c FROM customers");
        } else {
            $kpis['db_warnings'][] = 'جدول customers وجود ندارد.';
        }

        // Products
        if ($this->tableExists('products')) {
            $kpis['products_count'] = $this->scalarInt("SELECT COUNT(*) AS c FROM products");
        } else {
            $kpis['db_warnings'][] = 'جدول products وجود ندارد.';
        }

        // Sales
        if ($this->tableExists('sales')) {
            $kpis['sales_count'] = $this->scalarInt("SELECT COUNT(*) AS c FROM sales");

            // today count (based on created_at)
            $kpis['sales_today_count'] = $this->scalarInt("
                SELECT COUNT(*) AS c
                FROM sales
                WHERE DATE(created_at) = CURDATE()
            ");

            // 30d total (based on total_amount)
            if ($this->columnExists('sales', 'total_amount') && $this->columnExists('sales', 'created_at')) {
                $kpis['sales_30d_total'] = $this->scalarFloat("
                    SELECT COALESCE(SUM(total_amount), 0) AS s
                    FROM sales
                    WHERE created_at >= (NOW() - INTERVAL 30 DAY)
                ");
            }
        } else {
            $kpis['db_warnings'][] = 'جدول sales وجود ندارد.';
        }

        // Payments 30d total (optional)
        if ($this->tableExists('payments') && $this->columnExists('payments', 'amount') && $this->columnExists('payments', 'created_at')) {
            $kpis['payments_30d_total'] = $this->scalarFloat("
                SELECT COALESCE(SUM(amount), 0) AS s
                FROM payments
                WHERE created_at >= (NOW() - INTERVAL 30 DAY)
            ");
        }

        return $kpis;
    }

    // =============================================================================
    // Latest lists
    // =============================================================================

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getLatestSalesSafe(int $limit = 10): array
    {
        if (!$this->tableExists('sales')) {
            return [];
        }

        // Flexible select depending on available columns
        $cols = [
            'id' => $this->columnExists('sales', 'id'),
            'customer_id' => $this->columnExists('sales', 'customer_id'),
            'status' => $this->columnExists('sales', 'status'),
            'total_amount' => $this->columnExists('sales', 'total_amount'),
            'currency' => $this->columnExists('sales', 'currency'),
            'created_at' => $this->columnExists('sales', 'created_at'),
            'source' => $this->columnExists('sales', 'source'),
            'woo_order_id' => $this->columnExists('sales', 'woo_order_id'),
        ];

        $select = [];
        foreach ($cols as $c => $ok) {
            if ($ok) $select[] = $c;
        }
        if (empty($select)) $select = ['id'];

        $sql = "SELECT " . implode(',', $select) . " FROM sales ORDER BY id DESC LIMIT " . (int)$limit;

        try {
            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->logger->exception('DASHBOARD_LATEST_SALES_FAILED', $e);
            return [];
        }

        // Optional: enrich customer name if customers table exists
        if ($this->tableExists('customers') && $this->columnExists('customers', 'id') && $this->columnExists('customers', 'full_name')) {
            foreach ($rows as &$r) {
                if (!isset($r['customer_id'])) continue;
                $cid = (int)$r['customer_id'];
                $r['customer_name'] = $this->scalarString("SELECT full_name FROM customers WHERE id = :id LIMIT 1", [':id' => $cid]);
            }
            unset($r);
        }

        return $rows ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getLatestCustomersSafe(int $limit = 10): array
    {
        if (!$this->tableExists('customers')) {
            return [];
        }

        $cols = [
            'id' => $this->columnExists('customers', 'id'),
            'full_name' => $this->columnExists('customers', 'full_name'),
            'email' => $this->columnExists('customers', 'email'),
            'phone' => $this->columnExists('customers', 'phone'),
            'created_at' => $this->columnExists('customers', 'created_at'),
            'woo_customer_id' => $this->columnExists('customers', 'woo_customer_id'),
        ];

        $select = [];
        foreach ($cols as $c => $ok) {
            if ($ok) $select[] = $c;
        }
        if (empty($select)) $select = ['id'];

        $sql = "SELECT " . implode(',', $select) . " FROM customers ORDER BY id DESC LIMIT " . (int)$limit;

        try {
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->exception('DASHBOARD_LATEST_CUSTOMERS_FAILED', $e);
            return [];
        }
    }

    // =============================================================================
    // Integrations status
    // =============================================================================

    /**
     * @return array<string,mixed>
     */
    private function getIntegrationsStatusSafe(): array
    {
        $out = [
            'woocommerce' => [
                'enabled' => (bool)($this->config['features']['enable_woocommerce'] ?? true),
                'configured' => false,
                'last_sync_at' => null,
                'outbox_pending' => null,
                'notes' => [],
            ],
            'ai' => [
                'enabled' => (bool)($this->config['features']['enable_ai'] ?? true),
                'configured' => false,
                'requests_pending' => null,
                'notes' => [],
            ],
        ];

        // Woo: check settings table if exists
        // Recommended: settings table with key/value or integrations table.
        // We'll try a few common options, but won't crash if absent.

        // 1) settings table (key/value)
        if ($this->tableExists('settings') && $this->columnExists('settings', 'key') && $this->columnExists('settings', 'value')) {
            $ck = $this->scalarString("SELECT value FROM settings WHERE `key`='woo_ck' LIMIT 1");
            $cs = $this->scalarString("SELECT value FROM settings WHERE `key`='woo_cs' LIMIT 1");
            $url= $this->scalarString("SELECT value FROM settings WHERE `key`='woo_url' LIMIT 1");
            if ($ck && $cs && $url) $out['woocommerce']['configured'] = true;

            $aiKey = $this->scalarString("SELECT value FROM settings WHERE `key`='gapgpt_api_key' LIMIT 1");
            $aiBase= $this->scalarString("SELECT value FROM settings WHERE `key`='gapgpt_base_url' LIMIT 1");
            if ($aiKey && $aiBase) $out['ai']['configured'] = true;
        } else {
            $out['woocommerce']['notes'][] = 'جدول settings (key/value) موجود نیست؛ وضعیت تنظیمات قابل تشخیص نیست.';
            $out['ai']['notes'][] = 'جدول settings (key/value) موجود نیست؛ وضعیت تنظیمات قابل تشخیص نیست.';
        }

        // 2) sync_state table (optional)
        if ($this->tableExists('sync_state')) {
            if ($this->columnExists('sync_state', 'provider') && $this->columnExists('sync_state', 'last_sync_at')) {
                $out['woocommerce']['last_sync_at'] = $this->scalarString("
                    SELECT last_sync_at FROM sync_state WHERE provider='woocommerce' LIMIT 1
                ");
            }
        }

        // 3) outbox (optional) - pending actions to push to Woo
        if ($this->tableExists('outbox') && $this->columnExists('outbox', 'status')) {
            $out['woocommerce']['outbox_pending'] = $this->scalarInt("
                SELECT COUNT(*) AS c FROM outbox WHERE status='pending'
            ");
        }

        // 4) ai_requests (optional)
        if ($this->tableExists('ai_requests') && $this->columnExists('ai_requests', 'status')) {
            $out['ai']['requests_pending'] = $this->scalarInt("
                SELECT COUNT(*) AS c FROM ai_requests WHERE status IN ('queued','running')
            ");
        }

        return $out;
    }

    // =============================================================================
    // DB helper utilities
    // =============================================================================

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE :t");
            $stmt->execute([':t' => $table]);
            return (bool)$stmt->fetch(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            $this->logger->exception('DB_TABLE_EXISTS_FAILED', $e, ['table' => $table]);
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
            $stmt->execute([':c' => $column]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // don't spam logs too hard; but still record in debug if enabled
            $this->logger->debug('DB_COLUMN_EXISTS_FAILED', ['table' => $table, 'column' => $column]);
            return false;
        }
    }

    private function scalarInt(string $sql, array $params = []): ?int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 0;
            $v = array_values($row)[0] ?? 0;
            return (int)$v;
        } catch (Throwable $e) {
            $this->logger->exception('DB_SCALAR_INT_FAILED', $e, ['sql' => $this->sqlPreview($sql)]);
            return null;
        }
    }

    private function scalarFloat(string $sql, array $params = []): ?float
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 0.0;
            $v = array_values($row)[0] ?? 0;
            return (float)$v;
        } catch (Throwable $e) {
            $this->logger->exception('DB_SCALAR_FLOAT_FAILED', $e, ['sql' => $this->sqlPreview($sql)]);
            return null;
        }
    }

    private function scalarString(string $sql, array $params = []): ?string
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $v = array_values($row)[0] ?? null;
            return $v === null ? null : (string)$v;
        } catch (Throwable $e) {
            $this->logger->debug('DB_SCALAR_STRING_FAILED', ['sql' => $this->sqlPreview($sql)]);
            return null;
        }
    }

    private function sqlPreview(string $sql, int $max = 120): string
    {
        $one = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        if (strlen($one) > $max) return substr($one, 0, $max) . '...';
        return $one;
    }

    // =============================================================================
    // Rendering
    // =============================================================================

    /**
     * @param array{
     *  username:string,
     *  kpis:array<string,mixed>,
     *  latestSales:array<int,array<string,mixed>>,
     *  latestCustomers:array<int,array<string,mixed>>,
     *  integrations:array<string,mixed>,
     *  flash_error:?string,
     *  flash_info:?string
     * } $data
     */
    private function renderDashboardHtml(array $data): string
    {
        $username = htmlspecialchars($data['username'], ENT_QUOTES, 'UTF-8');

        $flashError = $data['flash_error']
            ? "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffb3b3;margin:12px 0;border-radius:10px;'>".htmlspecialchars($data['flash_error'], ENT_QUOTES, 'UTF-8')."</div>"
            : "";

        $flashInfo = $data['flash_info']
            ? "<div style='padding:10px;background:#e7fff0;border:1px solid #9ae6b4;margin:12px 0;border-radius:10px;'>".htmlspecialchars($data['flash_info'], ENT_QUOTES, 'UTF-8')."</div>"
            : "";

        $k = $data['kpis'];

        $customers = $this->fmtNullable($k['customers_count'], '—');
        $products  = $this->fmtNullable($k['products_count'], '—');
        $sales     = $this->fmtNullable($k['sales_count'], '—');
        $salesToday= $this->fmtNullable($k['sales_today_count'], '—');

        $sales30d  = $this->fmtMoney($k['sales_30d_total'], $this->defaultCurrency());
        $pay30d    = $this->fmtMoney($k['payments_30d_total'], $this->defaultCurrency());

        $warnings = $k['db_warnings'] ?? [];
        $warningsHtml = '';
        if (is_array($warnings) && count($warnings) > 0) {
            $lis = '';
            foreach ($warnings as $w) {
                $lis .= "<li>" . htmlspecialchars((string)$w, ENT_QUOTES, 'UTF-8') . "</li>";
            }
            $warningsHtml = "<div style='padding:12px;background:#fff7e6;border:1px solid #ffd08a;border-radius:12px;margin-top:12px;'>
                <b>هشدار دیتابیس:</b>
                <ul style='margin:8px 0 0; padding-right:18px;'>{$lis}</ul>
            </div>";
        }

        $integrationsHtml = $this->renderIntegrations($data['integrations'] ?? []);
        $latestSalesHtml = $this->renderLatestSalesTable($data['latestSales'] ?? []);
        $latestCustomersHtml = $this->renderLatestCustomersTable($data['latestCustomers'] ?? []);

        $csrf = htmlspecialchars((string)($_SESSION['_csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>داشبورد | CRM V2</title>
</head>
<body style="margin:0;background:#f5f6f8;font-family:tahoma,Arial;">
  <div style="display:flex;min-height:100vh;">

    <!-- Sidebar -->
    <aside style="width:240px;background:#111827;color:#fff;padding:18px;">
      <div style="font-weight:bold;font-size:18px;margin-bottom:16px;">CRM V2</div>
      <div style="opacity:0.85;font-size:13px;margin-bottom:18px;">سلام، {$username}</div>

      <nav style="display:flex;flex-direction:column;gap:8px;font-size:14px;">
        <a href="/dashboard" style="color:#fff;text-decoration:none;padding:10px;border-radius:10px;background:rgba(255,255,255,0.08);">داشبورد</a>
        <a href="/customers" style="color:#fff;text-decoration:none;padding:10px;border-radius:10px;">مشتریان</a>
        <a href="/products" style="color:#fff;text-decoration:none;padding:10px;border-radius:10px;">محصولات</a>
        <a href="/sales" style="color:#fff;text-decoration:none;padding:10px;border-radius:10px;">فروش‌ها</a>
        <a href="/settings" style="color:#fff;text-decoration:none;padding:10px;border-radius:10px;">تنظیمات</a>
      </nav>

      <form method="post" action="/logout" style="margin-top:18px;">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <button type="submit" style="width:100%;padding:10px;border:none;border-radius:10px;background:#ef4444;color:#fff;cursor:pointer;">
          خروج
        </button>
      </form>

      <div style="margin-top:18px;font-size:12px;opacity:0.65;line-height:1.7;">
        نسخه: V2 (اسکلت)<br>
        وضعیت: در حال توسعه
      </div>
    </aside>

    <!-- Main -->
    <main style="flex:1;padding:20px;">
      <h2 style="margin:0 0 6px;">داشبورد</h2>
      <div style="color:#6b7280;font-size:13px;">نمای کلی از وضعیت سیستم، فروش و همگام‌سازی</div>

      {$flashError}
      {$flashInfo}

      <!-- KPIs -->
      <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-top:14px;">
        {$this->kpiCard('مشتریان', $customers)}
        {$this->kpiCard('محصولات', $products)}
        {$this->kpiCard('فروش‌ها', $sales)}
        {$this->kpiCard('فروش امروز', $salesToday)}
        {$this->kpiCard('فروش ۳۰ روز', $sales30d)}
      </div>

      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px;">
        {$this->kpiCard('پرداختی ۳۰ روز', $pay30d)}
        {$integrationsHtml}
      </div>

      {$warningsHtml}

      <!-- Latest tables -->
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px;">
        {$latestSalesHtml}
        {$latestCustomersHtml}
      </div>

      <div style="margin-top:16px;color:#9ca3af;font-size:12px;">
        نکته: اگر جدول‌ها هنوز کامل نیستند، برخی بخش‌ها “—” نمایش داده می‌شوند و طبیعی است.
      </div>
    </main>
  </div>
</body>
</html>
HTML;
    }

    private function kpiCard(string $title, string $value): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $v = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(0,0,0,0.04);">
  <div style="color:#6b7280;font-size:12px;margin-bottom:6px;">{$t}</div>
  <div style="font-size:20px;font-weight:bold;color:#111827;">{$v}</div>
</div>
HTML;
    }

    private function renderIntegrations(array $integrations): string
    {
        $woo = $integrations['woocommerce'] ?? [];
        $ai  = $integrations['ai'] ?? [];

        $wooEnabled = !empty($woo['enabled']);
        $wooConf = !empty($woo['configured']);
        $wooLast = $woo['last_sync_at'] ?? null;
        $wooOut  = $woo['outbox_pending'] ?? null;

        $aiEnabled = !empty($ai['enabled']);
        $aiConf = !empty($ai['configured']);
        $aiPend = $ai['requests_pending'] ?? null;

        $wooStatus = $wooEnabled ? ($wooConf ? "✅ فعال و تنظیم‌شده" : "⚠️ فعال ولی تنظیم نشده") : "⛔ غیرفعال";
        $aiStatus  = $aiEnabled ? ($aiConf ? "✅ فعال و تنظیم‌شده" : "⚠️ فعال ولی تنظیم نشده") : "⛔ غیرفعال";

        $wooLastTxt = $wooLast ? htmlspecialchars((string)$wooLast, ENT_QUOTES, 'UTF-8') : '—';
        $wooOutTxt  = ($wooOut === null) ? '—' : (string)$wooOut;
        $aiPendTxt  = ($aiPend === null) ? '—' : (string)$aiPend;

        $wooLink = "<a href='/settings/woocommerce' style='color:#2563eb;text-decoration:none;'>تنظیمات ووکامرس</a>";
        $aiLink  = "<a href='/settings/ai' style='color:#2563eb;text-decoration:none;'>تنظیمات هوش مصنوعی</a>";

        return <<<HTML
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(0,0,0,0.04);">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <div style="font-weight:bold;color:#111827;">یکپارچه‌سازی‌ها</div>
    <div style="font-size:12px;color:#6b7280;">وضعیت</div>
  </div>

  <div style="border:1px solid #f3f4f6;border-radius:12px;padding:10px;margin-bottom:10px;">
    <div style="font-weight:bold;margin-bottom:6px;">WooCommerce</div>
    <div style="font-size:13px;color:#374151;line-height:1.9;">
      وضعیت: {$wooStatus}<br>
      آخرین Sync: {$wooLastTxt}<br>
      Outbox در انتظار: {$wooOutTxt}<br>
      {$wooLink}
    </div>
  </div>

  <div style="border:1px solid #f3f4f6;border-radius:12px;padding:10px;">
    <div style="font-weight:bold;margin-bottom:6px;">GapGPT / AI</div>
    <div style="font-size:13px;color:#374151;line-height:1.9;">
      وضعیت: {$aiStatus}<br>
      درخواست‌های در صف: {$aiPendTxt}<br>
      {$aiLink}
    </div>
  </div>
</div>
HTML;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function renderLatestSalesTable(array $rows): string
    {
        $trs = '';
        if (!$rows) {
            $trs = "<tr><td colspan='5' style='padding:10px;color:#6b7280;'>اطلاعی برای نمایش وجود ندارد.</td></tr>";
        } else {
            foreach ($rows as $r) {
                $id = htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $customer = htmlspecialchars((string)($r['customer_name'] ?? ($r['customer_id'] ?? '—')), ENT_QUOTES, 'UTF-8');
                $status = htmlspecialchars((string)($r['status'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $source = htmlspecialchars((string)($r['source'] ?? '—'), ENT_QUOTES, 'UTF-8');

                $amount = '—';
                if (isset($r['total_amount'])) {
                    $amount = $this->fmtMoney((float)$r['total_amount'], (string)($r['currency'] ?? $this->defaultCurrency()));
                }

                $trs .= "<tr>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;'>{$id}</td>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;'>{$customer}</td>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;'>{$status}</td>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;'>{$source}</td>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;text-align:left;'>{$amount}</td>
                </tr>";
            }
        }

        return <<<HTML
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(0,0,0,0.04);">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <div style="font-weight:bold;color:#111827;">آخرین فروش‌ها</div>
    <a href="/sales" style="font-size:12px;color:#2563eb;text-decoration:none;">مشاهده همه</a>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="color:#6b7280;text-align:right;">
        <th style="padding:8px;">ID</th>
        <th style="padding:8px;">مشتری</th>
        <th style="padding:8px;">وضعیت</th>
        <th style="padding:8px;">منبع</th>
        <th style="padding:8px;text-align:left;">مبلغ</th>
      </tr>
    </thead>
    <tbody>{$trs}</tbody>
  </table>
</div>
HTML;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function renderLatestCustomersTable(array $rows): string
    {
        $trs = '';
        if (!$rows) {
            $trs = "<tr><td colspan='4' style='padding:10px;color:#6b7280;'>اطلاعی برای نمایش وجود ندارد.</td></tr>";
        } else {
            foreach ($rows as $r) {
                $id = htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars((string)($r['full_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $email = htmlspecialchars((string)($r['email'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $phone = htmlspecialchars((string)($r['phone'] ?? '—'), ENT_QUOTES, 'UTF-8');

                $trs .= "<tr>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;'>{$id}</td>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;'>{$name}</td>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;direction:ltr;text-align:right;'>{$email}</td>
                    <td style='padding:10px;border-top:1px solid #f3f4f6;direction:ltr;text-align:right;'>{$phone}</td>
                </tr>";
            }
        }

        return <<<HTML
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(0,0,0,0.04);">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <div style="font-weight:bold;color:#111827;">آخرین مشتریان</div>
    <a href="/customers" style="font-size:12px;color:#2563eb;text-decoration:none;">مشاهده همه</a>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="color:#6b7280;text-align:right;">
        <th style="padding:8px;">ID</th>
        <th style="padding:8px;">نام</th>
        <th style="padding:8px;">ایمیل</th>
        <th style="padding:8px;">تلفن</th>
      </tr>
    </thead>
    <tbody>{$trs}</tbody>
  </table>
</div>
HTML;
    }

    private function fmtNullable($v, string $fallback): string
    {
        if ($v === null) return $fallback;
        return (string)$v;
    }

    private function defaultCurrency(): string
    {
        return (string)($this->config['app']['currency'] ?? 'IRR');
    }

    private function fmtMoney($amount, string $currency): string
    {
        if ($amount === null) return '—';
        $n = number_format((float)$amount, 0, '.', ',');
        $cur = htmlspecialchars($currency, ENT_QUOTES, 'UTF-8');
        return "{$n} {$cur}";
    }
}

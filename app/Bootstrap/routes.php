<?php
/**
 * File: app/Bootstrap/routes.php
 *
 * CRM V2 - Routes Registry
 * ------------------------------------------------------------
 * این فایل باید یک array از Route ها برگرداند.
 *
 * Format:
 *   [
 *     ['METHOD', '/path', [ControllerClass::class, 'method']],
 *     ['METHOD', '/path', callable],
 *   ]
 *
 * Notes:
 * - Routing engine در public/index.php نوشته شده (router ساده).
 * - بعداً می‌توانیم middleware-level routing هم اضافه کنیم.
 */

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Webhooks\WooWebhookController;

// اگر هنوز این کلاس‌ها را ایجاد نکرده‌ای، مهم نیست؛
// ولی حتماً در فاز بعدی باید فایل‌های Controller را بسازیم.

return [

    // ---------------------------------------------------------------------
    // System / Health
    // ---------------------------------------------------------------------

    ['GET', '/health', function () {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'app' => 'CRM V2',
            'time' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }],

    // ---------------------------------------------------------------------
    // Auth
    // ---------------------------------------------------------------------
    ['GET',  '/login',  [AuthController::class, 'showLogin']],
    ['POST', '/login',  [AuthController::class, 'login']],
    ['POST', '/logout', [AuthController::class, 'logout']],

    // Password reset (optional placeholders)
    ['GET',  '/forgot-password', [AuthController::class, 'showForgotPassword']],
    ['POST', '/forgot-password', [AuthController::class, 'sendResetLink']],
    ['GET',  '/reset-password',  [AuthController::class, 'showResetPassword']],
    ['POST', '/reset-password',  [AuthController::class, 'resetPassword']],

    // ---------------------------------------------------------------------
    // Dashboard
    // ---------------------------------------------------------------------
    ['GET', '/',          [DashboardController::class, 'index']],
    ['GET', '/dashboard', [DashboardController::class, 'index']],

    // ---------------------------------------------------------------------
    // Products
    // ---------------------------------------------------------------------
    // List + Create form
    ['GET',  '/products',             [ProductsController::class, 'index']],
    ['GET',  '/products/create',      [ProductsController::class, 'create']],
    // Create action
    ['POST', '/products',             [ProductsController::class, 'store']],
    // Show / Edit / Update
    ['GET',  '/products/{id}',        [ProductsController::class, 'show']],
    ['GET',  '/products/{id}/edit',   [ProductsController::class, 'edit']],
    ['POST', '/products/{id}/update', [ProductsController::class, 'update']],
    // Delete
    ['POST', '/products/{id}/delete', [ProductsController::class, 'delete']],

    // Variants management (optional)
    ['GET',  '/products/{id}/variants',              [ProductsController::class, 'variants']],
    ['POST', '/products/{id}/variants/create',       [ProductsController::class, 'createVariant']],
    ['POST', '/products/{id}/variants/{vid}/update', [ProductsController::class, 'updateVariant']],
    ['POST', '/products/{id}/variants/{vid}/delete', [ProductsController::class, 'deleteVariant']],

    // ---------------------------------------------------------------------
    // Customers
    // ---------------------------------------------------------------------
    ['GET',  '/customers',               [CustomersController::class, 'index']],
    ['GET',  '/customers/create',        [CustomersController::class, 'create']],
    ['POST', '/customers',               [CustomersController::class, 'store']],
    ['GET',  '/customers/{id}',          [CustomersController::class, 'show']],
    ['GET',  '/customers/{id}/edit',     [CustomersController::class, 'edit']],
    ['POST', '/customers/{id}/update',   [CustomersController::class, 'update']],
    ['POST', '/customers/{id}/delete',   [CustomersController::class, 'delete']],

    // Customer notes / timeline (optional)
    ['POST', '/customers/{id}/notes/add', [CustomersController::class, 'addNote']],
    ['POST', '/customers/{id}/tags/set',  [CustomersController::class, 'setTags']],

    // ---------------------------------------------------------------------
    // Sales (Orders -> Sales)
    // ---------------------------------------------------------------------
    ['GET',  '/sales',                 [SalesController::class, 'index']],
    ['GET',  '/sales/create',          [SalesController::class, 'create']],
    ['POST', '/sales',                 [SalesController::class, 'store']],
    ['GET',  '/sales/{id}',            [SalesController::class, 'show']],
    ['GET',  '/sales/{id}/edit',       [SalesController::class, 'edit']],
    ['POST', '/sales/{id}/update',     [SalesController::class, 'update']],
    ['POST', '/sales/{id}/delete',     [SalesController::class, 'delete']],

    // Payments / Refunds
    ['POST', '/sales/{id}/payments/add', [SalesController::class, 'addPayment']],
    ['POST', '/sales/{id}/refunds/add',  [SalesController::class, 'addRefund']],

    // Notes
    ['POST', '/sales/{id}/notes/add',    [SalesController::class, 'addNote']],

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------
    ['GET',  '/settings',                  [SettingsController::class, 'index']],
    ['GET',  '/settings/profile',          [SettingsController::class, 'profile']],
    ['POST', '/settings/profile/update',   [SettingsController::class, 'updateProfile']],

    // DB tools
    ['GET',  '/settings/db',               [SettingsController::class, 'dbStatus']],
    ['POST', '/settings/db/migrate',       [SettingsController::class, 'runMigrations']],

    // WooCommerce integration settings
    ['GET',  '/settings/woocommerce',              [SettingsController::class, 'wooSettings']],
    ['POST', '/settings/woocommerce/save',         [SettingsController::class, 'wooSave']],
    ['POST', '/settings/woocommerce/test',         [SettingsController::class, 'wooTestConnection']],
    ['POST', '/settings/woocommerce/import/products',  [SettingsController::class, 'wooImportProducts']],
    ['POST', '/settings/woocommerce/import/customers', [SettingsController::class, 'wooImportCustomers']],
    ['POST', '/settings/woocommerce/import/orders',    [SettingsController::class, 'wooImportOrders']],
    ['POST', '/settings/woocommerce/reconcile',        [SettingsController::class, 'wooReconcile']],
    ['POST', '/settings/woocommerce/outbox/push',      [SettingsController::class, 'wooPushOutbox']],

    // GapGPT AI settings
    ['GET',  '/settings/ai',                  [SettingsController::class, 'aiSettings']],
    ['POST', '/settings/ai/save',             [SettingsController::class, 'aiSave']],
    ['POST', '/settings/ai/test',             [SettingsController::class, 'aiTestConnection']],

    // ---------------------------------------------------------------------
    // Webhooks (WooCommerce -> CRM)
    // ---------------------------------------------------------------------
    // Example: WooCommerce webhook should call:
    //   POST https://your-crm-domain.com/webhooks/woocommerce
    // with a secret signature or shared secret in headers/body.
    ['POST', '/webhooks/woocommerce', [WooWebhookController::class, 'handle']],

    // ---------------------------------------------------------------------
    // Admin / Logs (optional, placeholders)
    // ---------------------------------------------------------------------
    ['GET', '/admin/logs', function () {
        header('Content-Type: text/html; charset=utf-8');
        echo "<h1>Logs</h1><p>این بخش بعداً تکمیل می‌شود.</p>";
    }],
];

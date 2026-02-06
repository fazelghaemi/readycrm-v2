<?php
/**
 * File: cron/process-sms-queue.php
 *
 * Cron Job: پردازش صف پیام‌های پیامکی
 * 
 * Usage:
 *   php cron/process-sms-queue.php
 *
 * Crontab (هر دقیقه):
 *   * * * * * cd /path/to/crm && php cron/process-sms-queue.php >> private/storage/logs/sms-cron.log 2>&1
 *
 * یا هر 2 دقیقه:
 *   */2 * * * * cd /path/to/crm && php cron/process-sms-queue.php >> private/storage/logs/sms-cron.log 2>&1
 */

declare(strict_types=1);

// Set working directory to project root
if (isset($_SERVER['PWD'])) {
    chdir($_SERVER['PWD']);
}

define('CRM_ROOT_DIR', dirname(__DIR__));

// Load app
$app = require __DIR__ . '/../app/Bootstrap/app.php';

use App\Services\SMS\SMSCampaignService;

// Prevent running from web
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line.');
}

// =============================================================================
// Configuration
// =============================================================================

$config = $app['config'];
$logger = $app['logger'];
$db = $app['db'];

$smsConfig = $config['sms'] ?? [];

// Check if SMS module is enabled
if (!($smsConfig['enabled'] ?? false)) {
    echo "[" . date('Y-m-d H:i:s') . "] SMS module is disabled. Exiting.\n";
    exit(0);
}

// Check if queue processing is enabled
if (!($smsConfig['queue']['enabled'] ?? false)) {
    echo "[" . date('Y-m-d H:i:s') . "] SMS queue processing is disabled. Exiting.\n";
    exit(0);
}

// =============================================================================
// Lock mechanism (prevent concurrent runs)
// =============================================================================

$lockFile = ($app['paths']['private'] ?? __DIR__ . '/../private') . '/storage/sms-queue.lock';
$lockDir = dirname($lockFile);

if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lock = fopen($lockFile, 'c');
if (!$lock) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Could not open lock file.\n";
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another instance is already running. Exiting.\n";
    fclose($lock);
    exit(0);
}

// =============================================================================
// Initialize SMS Service
// =============================================================================

$logFn = function(string $msg) use ($logger) {
    $logger->info('SMS_CRON', ['message' => $msg]);
    echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
};

try {
    $smsService = new SMSCampaignService($db, $config, $logFn);
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to initialize SMS service: " . $e->getMessage() . "\n";
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

// =============================================================================
// Main Processing
// =============================================================================

echo "[" . date('Y-m-d H:i:s') . "] SMS Queue Processor Started\n";
echo str_repeat('=', 60) . "\n";

$startTime = microtime(true);
$totalProcessed = 0;
$totalSent = 0;
$totalFailed = 0;

try {
    // Step 1: Check and start scheduled campaigns
    echo "[" . date('Y-m-d H:i:s') . "] Checking scheduled campaigns...\n";
    
    $startedCampaigns = $smsService->checkScheduledCampaigns();
    
    if ($startedCampaigns > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] ✓ Started {$startedCampaigns} scheduled campaign(s)\n";
    }

    // Step 2: Process message queue
    echo "[" . date('Y-m-d H:i:s') . "] Processing message queue...\n";
    
    $batchSize = (int)($smsConfig['queue']['process_batch_size'] ?? 100);
    $maxIterations = 10; // Prevent infinite loops
    $iteration = 0;
    
    while ($iteration < $maxIterations) {
        $result = $smsService->processQueue($batchSize);
        
        $processed = $result['processed'];
        $sent = $result['sent'];
        $failed = $result['failed'];
        
        $totalProcessed += $processed;
        $totalSent += $sent;
        $totalFailed += $failed;
        
        if ($processed > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Batch {$iteration}: Processed={$processed}, Sent={$sent}, Failed={$failed}\n";
        }
        
        // If no messages were processed, we're done
        if ($processed === 0) {
            break;
        }
        
        $iteration++;
        
        // Small delay between batches to avoid overwhelming the API
        $delaySeconds = (int)($smsConfig['campaign']['delay_between_batches_sec'] ?? 2);
        if ($delaySeconds > 0 && $processed > 0) {
            sleep($delaySeconds);
        }
    }

    // Step 3: Summary
    $duration = round(microtime(true) - $startTime, 2);
    
    echo str_repeat('=', 60) . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] SMS Queue Processor Completed\n";
    echo "  Total Processed: {$totalProcessed}\n";
    echo "  Successfully Sent: {$totalSent}\n";
    echo "  Failed: {$totalFailed}\n";
    echo "  Duration: {$duration}s\n";
    echo str_repeat('=', 60) . "\n";

    // Log summary
    $logger->info('SMS_CRON_COMPLETED', [
        'total_processed' => $totalProcessed,
        'total_sent' => $totalSent,
        'total_failed' => $totalFailed,
        'duration_sec' => $duration,
    ]);

} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    
    $logger->error('SMS_CRON_ERROR', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    
    // Release lock
    flock($lock, LOCK_UN);
    fclose($lock);
    
    exit(1);
}

// =============================================================================
// Cleanup
// =============================================================================

// Release lock
flock($lock, LOCK_UN);
fclose($lock);

// Optional: Clean up old processed messages (e.g., older than 90 days)
$retentionDays = (int)($smsConfig['logging']['retention_days'] ?? 90);
if ($retentionDays > 0) {
    try {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        
        $stmt = $db->prepare("DELETE FROM sms_api_logs WHERE created_at < :cutoff LIMIT 1000");
        $stmt->execute([':cutoff' => $cutoffDate]);
        $deleted = $stmt->rowCount();
        
        if ($deleted > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Cleaned up {$deleted} old API logs\n";
        }
    } catch (Throwable $e) {
        // Cleanup errors are non-fatal
        echo "[" . date('Y-m-d H:i:s') . "] Warning: Cleanup failed: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Cron job finished successfully.\n";

exit(0);

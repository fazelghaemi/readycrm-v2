<?php
/**
 * File: app/Http/Controllers/SMSCampaignsController.php
 *
 * کنترلر مدیریت کمپین‌های پیامکی
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SMS\SMSCampaignService;
use PDO;

final class SMSCampaignsController
{
    private PDO $db;
    private SMSCampaignService $smsService;
    
    /** @var array<string,mixed> */
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        
        $logger = function(string $msg) {
            error_log('[SMS] ' . $msg);
        };
        
        $this->smsService = new SMSCampaignService($db, $config, $logger);
    }

    /**
     * لیست کمپین‌ها
     * GET /sms/campaigns
     */
    public function index(): void
    {
        $this->requireAuth();
        
        $page = (int)($_GET['page'] ?? 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        
        $filters = [
            'user_id' => $_SESSION['user_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'type' => $_GET['type'] ?? null,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        
        $campaigns = $this->smsService->listCampaigns($filters);
        $stats = $this->smsService->getSystemStats();
        
        // Render view
        $this->render('sms/campaigns/index', [
            'campaigns' => $campaigns,
            'stats' => $stats,
            'page' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * فرم ایجاد کمپین
     * GET /sms/campaigns/create
     */
    public function create(): void
    {
        $this->requireAuth();
        
        // Get customer stats for recipient filters
        $customerStats = $this->getCustomerStats();
        
        $this->render('sms/campaigns/create', [
            'customer_stats' => $customerStats,
        ]);
    }

    /**
     * ذخیره کمپین جدید
     * POST /sms/campaigns
     */
    public function store(): void
    {
        $this->requireAuth();
        
        $data = [
            'user_id' => $_SESSION['user_id'],
            'name' => trim($_POST['name'] ?? ''),
            'type' => $_POST['type'] ?? 'advertising',
            'message' => trim($_POST['message'] ?? ''),
            'template_id' => $_POST['template_id'] ?? null,
            'sender' => $_POST['sender'] ?? null,
            'recipient_filter' => $this->buildRecipientFilter($_POST),
            'scheduled_at' => $_POST['scheduled_at'] ?? null,
            'notes' => $_POST['notes'] ?? null,
        ];
        
        try {
            $campaign = $this->smsService->createCampaign($data);
            
            $this->flash('success', 'کمپین با موفقیت ایجاد شد.');
            $this->redirect('/sms/campaigns/' . $campaign->id);
        } catch (\Throwable $e) {
            $this->flash('error', 'خطا در ایجاد کمپین: ' . $e->getMessage());
            $this->redirect('/sms/campaigns/create');
        }
    }

    /**
     * نمایش جزئیات کمپین
     * GET /sms/campaigns/{id}
     */
    public function show(int $id): void
    {
        $this->requireAuth();
        
        $campaign = $this->smsService->getCampaign($id);
        if (!$campaign) {
            $this->flash('error', 'کمپین یافت نشد.');
            $this->redirect('/sms/campaigns');
            return;
        }
        
        $stats = $this->smsService->getCampaignStats($id);
        
        // Get recent messages
        $recentMessages = $this->getRecentMessages($id, 20);
        
        $this->render('sms/campaigns/show', [
            'campaign' => $campaign,
            'stats' => $stats,
            'recent_messages' => $recentMessages,
        ]);
    }

    /**
     * شروع کمپین
     * POST /sms/campaigns/{id}/start
     */
    public function start(int $id): void
    {
        $this->requireAuth();
        
        try {
            $this->smsService->startCampaign($id);
            $this->flash('success', 'کمپین شروع شد.');
        } catch (\Throwable $e) {
            $this->flash('error', 'خطا: ' . $e->getMessage());
        }
        
        $this->redirect('/sms/campaigns/' . $id);
    }

    /**
     * توقف موقت کمپین
     * POST /sms/campaigns/{id}/pause
     */
    public function pause(int $id): void
    {
        $this->requireAuth();
        
        try {
            $this->smsService->pauseCampaign($id);
            $this->flash('success', 'کمپین متوقف شد.');
        } catch (\Throwable $e) {
            $this->flash('error', 'خطا: ' . $e->getMessage());
        }
        
        $this->redirect('/sms/campaigns/' . $id);
    }

    /**
     * ادامه کمپین
     * POST /sms/campaigns/{id}/resume
     */
    public function resume(int $id): void
    {
        $this->requireAuth();
        
        try {
            $this->smsService->resumeCampaign($id);
            $this->flash('success', 'کمپین از سر گرفته شد.');
        } catch (\Throwable $e) {
            $this->flash('error', 'خطا: ' . $e->getMessage());
        }
        
        $this->redirect('/sms/campaigns/' . $id);
    }

    /**
     * لغو کمپین
     * POST /sms/campaigns/{id}/cancel
     */
    public function cancel(int $id): void
    {
        $this->requireAuth();
        
        try {
            $this->smsService->cancelCampaign($id);
            $this->flash('success', 'کمپین لغو شد.');
        } catch (\Throwable $e) {
            $this->flash('error', 'خطا: ' . $e->getMessage());
        }
        
        $this->redirect('/sms/campaigns/' . $id);
    }

    /**
     * آمار کمپین (JSON)
     * GET /sms/campaigns/{id}/stats
     */
    public function stats(int $id): void
    {
        $this->requireAuth();
        
        $stats = $this->smsService->getCampaignStats($id);
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // =========================================================
    // Helper Methods
    // =========================================================

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function buildRecipientFilter(array $post): array
    {
        $filter = [];
        
        // Customer status filter
        if (!empty($post['filter_status'])) {
            $statuses = is_array($post['filter_status']) 
                ? $post['filter_status'] 
                : [$post['filter_status']];
            $filter['customer_status'] = $statuses;
        }
        
        // Tags filter
        if (!empty($post['filter_tags'])) {
            $tags = is_array($post['filter_tags'])
                ? $post['filter_tags']
                : array_map('trim', explode(',', $post['filter_tags']));
            $filter['tags'] = array_filter($tags);
        }
        
        // Specific customer IDs
        if (!empty($post['filter_customer_ids'])) {
            $ids = is_array($post['filter_customer_ids'])
                ? $post['filter_customer_ids']
                : array_map('intval', explode(',', $post['filter_customer_ids']));
            $filter['customer_ids'] = array_filter($ids);
        }
        
        return $filter;
    }

    /**
     * @return array<string,mixed>
     */
    private function getCustomerStats(): array
    {
        $sql = "SELECT 
                    status,
                    COUNT(*) as count
                FROM customers
                WHERE deleted_at IS NULL
                GROUP BY status";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getRecentMessages(int $campaignId, int $limit = 20): array
    {
        $sql = "SELECT * FROM sms_messages 
                WHERE campaign_id = :id 
                ORDER BY created_at DESC 
                LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function render(string $view, array $data = []): void
    {
        extract($data);
        $viewPath = __DIR__ . '/../../../resources/views/' . $view . '.php';
        
        if (file_exists($viewPath)) {
            require $viewPath;
        } else {
            echo "<h1>View not found: {$view}</h1>";
            echo "<pre>";
            print_r($data);
            echo "</pre>";
        }
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

<?php
/**
 * File: app/Services/SMS/SMSCampaignService.php
 *
 * CRM V2 - SMS Campaign Service
 * ------------------------------------------------------------------
 * سرویس مدیریت کمپین‌های پیامکی با استفاده از MessageWay
 * 
 * قابلیت‌ها:
 *  - ایجاد، ویرایش، حذف کمپین
 *  - بارگذاری گیرندگان بر اساس فیلترها (وضعیت، برچسب‌ها، و...)
 *  - صف‌بندی پیام‌ها برای ارسال انبوه
 *  - ارسال OTP تک‌نفره (SMS/Messenger/IVR)
 *  - تایید OTP
 *  - پردازش صف پیام‌ها (برای اجرا توسط Cron)
 *  - گزارش‌گیری از وضعیت کمپین
 *  - مدیریت خطاها و retry
 * 
 * استفاده:
 *   $service = new SMSCampaignService($db, $messageWayClient, $config, $logger);
 *   $campaign = $service->createCampaign(...);
 *   $service->startCampaign($campaign->id);
 *   $service->processQueue(100); // در cron job
 */

declare(strict_types=1);

namespace App\Services\SMS;

use App\Domain\Entities\SMSCampaign;
use App\Domain\Entities\SMSMessage;
use App\Domain\Enums\SMSCampaignStatus;
use App\Domain\Enums\SMSCampaignType;
use App\Domain\Enums\SMSMessageStatus;
use PDO;
use RuntimeException;
use Throwable;

final class SMSCampaignService
{
    private PDO $db;
    private MessageWayClient $client;
    
    /** @var array<string,mixed> */
    private array $config;
    
    /** @var callable|null */
    private $logger;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        PDO $db,
        MessageWayClient $client,
        array $config = [],
        ?callable $logger = null
    ) {
        $this->db = $db;
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger;
    }

    // =========================================================
    // Campaign CRUD Operations
    // =========================================================

    /**
     * ایجاد کمپین جدید
     */
    public function createCampaign(
        string $name,
        string $description,
        SMSCampaignType $type,
        ?int $templateId = null,
        ?string $messageText = null,
        ?array $recipientFilter = null,
        ?\DateTimeImmutable $scheduledAt = null,
        ?int $createdBy = null,
        ?array $metadata = null
    ): SMSCampaign {
        $this->log("Creating SMS campaign: {$name}");

        // Validation
        if (trim($name) === '') {
            throw new RuntimeException("Campaign name is required.");
        }

        if (!$templateId && !$messageText) {
            throw new RuntimeException("Either templateId or messageText must be provided.");
        }

        $campaign = new SMSCampaign(
            id: null,
            name: $name,
            description: $description,
            type: $type,
            status: SMSCampaignStatus::DRAFT,
            templateId: $templateId,
            messageText: $messageText,
            recipientFilter: $recipientFilter ? json_encode($recipientFilter, JSON_UNESCAPED_UNICODE) : null,
            totalRecipients: null,
            sentCount: 0,
            deliveredCount: 0,
            failedCount: 0,
            scheduledAt: $scheduledAt,
            startedAt: null,
            completedAt: null,
            createdBy: $createdBy,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            metadata: $metadata
        );

        $sql = "INSERT INTO sms_campaigns 
                (name, description, type, status, template_id, message_text, recipient_filter, 
                 total_recipients, sent_count, delivered_count, failed_count,
                 scheduled_at, created_by, created_at, updated_at, metadata)
                VALUES 
                (:name, :desc, :type, :status, :tid, :msg, :filter, 
                 :total, :sent, :delivered, :failed,
                 :sched, :by, NOW(), NOW(), :meta)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $campaign->name,
            ':desc' => $campaign->description,
            ':type' => $campaign->type->value,
            ':status' => $campaign->status->value,
            ':tid' => $campaign->templateId,
            ':msg' => $campaign->messageText,
            ':filter' => $campaign->recipientFilter,
            ':total' => $campaign->totalRecipients,
            ':sent' => $campaign->sentCount,
            ':delivered' => $campaign->deliveredCount,
            ':failed' => $campaign->failedCount,
            ':sched' => $campaign->scheduledAt?->format('Y-m-d H:i:s'),
            ':by' => $campaign->createdBy,
            ':meta' => $campaign->metadata ? json_encode($campaign->metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $campaign->id = (int)$this->db->lastInsertId();

        $this->log("Campaign created with ID: {$campaign->id}");

        return $campaign;
    }

    /**
     * دریافت کمپین با ID
     */
    public function getCampaign(int $id): ?SMSCampaign
    {
        $sql = "SELECT * FROM sms_campaigns WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? SMSCampaign::fromArray($row) : null;
    }

    /**
     * لیست کمپین‌ها با فیلتر و صفحه‌بندی
     * 
     * @param array<string,mixed> $filters
     * @return array<int,SMSCampaign>
     */
    public function listCampaigns(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM sms_campaigns WHERE 1=1";
        $params = [];

        // Filter by status
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        // Filter by type
        if (!empty($filters['type'])) {
            $sql .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        // Filter by created_by
        if (!empty($filters['created_by'])) {
            $sql .= " AND created_by = :created_by";
            $params[':created_by'] = (int)$filters['created_by'];
        }

        // Search by name
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $campaigns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $campaigns[] = SMSCampaign::fromArray($row);
        }

        return $campaigns;
    }

    /**
     * شمارش کل کمپین‌ها (برای pagination)
     */
    public function countCampaigns(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM sms_campaigns WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND type = :type";
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['created_by'])) {
            $sql .= " AND created_by = :created_by";
            $params[':created_by'] = (int)$filters['created_by'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    /**
     * بروزرسانی کمپین
     */
    public function updateCampaign(
        int $id,
        ?string $name = null,
        ?string $description = null,
        ?int $templateId = null,
        ?string $messageText = null,
        ?array $recipientFilter = null,
        ?\DateTimeImmutable $scheduledAt = null,
        ?array $metadata = null
    ): bool {
        $campaign = $this->getCampaign($id);
        if (!$campaign) {
            throw new RuntimeException("Campaign not found: {$id}");
        }

        if (!$campaign->status->canEdit()) {
            throw new RuntimeException("Campaign cannot be edited in status: {$campaign->status->value}");
        }

        $updates = [];
        $params = [':id' => $id];

        if ($name !== null) {
            $updates[] = "name = :name";
            $params[':name'] = $name;
        }

        if ($description !== null) {
            $updates[] = "description = :desc";
            $params[':desc'] = $description;
        }

        if ($templateId !== null) {
            $updates[] = "template_id = :tid";
            $params[':tid'] = $templateId;
        }

        if ($messageText !== null) {
            $updates[] = "message_text = :msg";
            $params[':msg'] = $messageText;
        }

        if ($recipientFilter !== null) {
            $updates[] = "recipient_filter = :filter";
            $params[':filter'] = json_encode($recipientFilter, JSON_UNESCAPED_UNICODE);
        }

        if ($scheduledAt !== null) {
            $updates[] = "scheduled_at = :sched";
            $params[':sched'] = $scheduledAt->format('Y-m-d H:i:s');
        }

        if ($metadata !== null) {
            $updates[] = "metadata = :meta";
            $params[':meta'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        }

        if (empty($updates)) {
            return false;
        }

        $updates[] = "updated_at = NOW()";

        $sql = "UPDATE sms_campaigns SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $this->log("Campaign {$id} updated");

        return true;
    }

    /**
     * تغییر وضعیت کمپین
     */
    public function updateCampaignStatus(int $id, SMSCampaignStatus $status): void
    {
        $sql = "UPDATE sms_campaigns SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':status' => $status->value, ':id' => $id]);

        $this->log("Campaign {$id} status changed to: {$status->value}");
    }

    /**
     * حذف کمپین (soft delete)
     */
    public function deleteCampaign(int $id): void
    {
        $this->updateCampaignStatus($id, SMSCampaignStatus::CANCELLED);
        $this->log("Campaign {$id} deleted (cancelled)");
    }

    // =========================================================
    // Campaign Execution
    // =========================================================

    /**
     * شروع اجرای کمپین
     * - لود کردن گیرندگان
     * - صف‌بندی پیام‌ها
     */
    public function startCampaign(int $id): array
    {
        $campaign = $this->getCampaign($id);
        if (!$campaign) {
            throw new RuntimeException("Campaign not found: {$id}");
        }

        if (!$campaign->status->canStart()) {
            throw new RuntimeException("Campaign cannot be started in status: {$campaign->status->value}");
        }

        $this->log("Starting campaign: {$id}");

        try {
            $this->db->beginTransaction();

            // Update status to PROCESSING
            $sql = "UPDATE sms_campaigns 
                    SET status = :status, started_at = NOW(), updated_at = NOW() 
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':status' => SMSCampaignStatus::PROCESSING->value,
                ':id' => $id
            ]);

            // Load recipients
            $recipients = $this->loadRecipients($campaign);

            if (empty($recipients)) {
                throw new RuntimeException("No recipients found for this campaign.");
            }

            // Queue messages
            $queued = $this->queueMessages($campaign, $recipients);

            // Update total recipients
            $sql = "UPDATE sms_campaigns 
                    SET total_recipients = :total, updated_at = NOW() 
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':total' => count($recipients),
                ':id' => $id,
            ]);

            $this->db->commit();

            $this->log("Campaign {$id} started with {$queued} messages queued");

            return [
                'success' => true,
                'campaign_id' => $id,
                'total_recipients' => count($recipients),
                'queued_messages' => $queued,
            ];

        } catch (Throwable $e) {
            $this->db->rollBack();
            
            // Mark as failed
            $this->updateCampaignStatus($id, SMSCampaignStatus::FAILED);
            
            $this->log("Campaign {$id} start failed: " . $e->getMessage());
            
            throw $e;
        }
    }

    /**
     * توقف کمپین
     */
    public function pauseCampaign(int $id): void
    {
        $this->updateCampaignStatus($id, SMSCampaignStatus::PAUSED);
        $this->log("Campaign {$id} paused");
    }

    /**
     * ادامه کمپین متوقف‌شده
     */
    public function resumeCampaign(int $id): void
    {
        $campaign = $this->getCampaign($id);
        if (!$campaign) {
            throw new RuntimeException("Campaign not found");
        }

        if ($campaign->status !== SMSCampaignStatus::PAUSED) {
            throw new RuntimeException("Only paused campaigns can be resumed");
        }

        $this->updateCampaignStatus($id, SMSCampaignStatus::PROCESSING);
        $this->log("Campaign {$id} resumed");
    }

    /**
     * لغو کمپین
     */
    public function cancelCampaign(int $id): void
    {
        $this->updateCampaignStatus($id, SMSCampaignStatus::CANCELLED);
        
        // Cancel pending messages
        $sql = "UPDATE sms_messages 
                SET status = :cancelled, updated_at = NOW() 
                WHERE campaign_id = :id AND status = :pending";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':cancelled' => 'cancelled',
            ':pending' => SMSMessageStatus::PENDING->value,
        ]);

        $this->log("Campaign {$id} cancelled");
    }

    // =========================================================
    // Recipient Loading
    // =========================================================

    /**
     * بارگذاری لیست گیرندگان بر اساس فیلترهای کمپین
     * 
     * @return array<int,array{mobile:string,name:?string,data:?array}>
     */
    private function loadRecipients(SMSCampaign $campaign): array
    {
        $filter = [];
        if ($campaign->recipientFilter) {
            $decoded = json_decode($campaign->recipientFilter, true);
            $filter = is_array($decoded) ? $decoded : [];
        }

        // Build query
        $sql = "SELECT id, phone as mobile, full_name as name, email, tags FROM customers WHERE 1=1";
        $params = [];

        // Filter by status
        if (!empty($filter['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filter['status'];
        }

        // Filter by tags (JSON_CONTAINS)
        if (!empty($filter['tags']) && is_array($filter['tags'])) {
            $tagConditions = [];
            foreach ($filter['tags'] as $i => $tag) {
                $key = ":tag{$i}";
                $tagConditions[] = "JSON_CONTAINS(tags, JSON_QUOTE({$key}))";
                $params[$key] = $tag;
            }
            if ($tagConditions) {
                $sql .= " AND (" . implode(' OR ', $tagConditions) . ")";
            }
        }

        // Filter by customer IDs (if specified)
        if (!empty($filter['customer_ids']) && is_array($filter['customer_ids'])) {
            $placeholders = [];
            foreach ($filter['customer_ids'] as $i => $customerId) {
                $key = ":cid{$i}";
                $placeholders[] = $key;
                $params[$key] = (int)$customerId;
            }
            $sql .= " AND id IN (" . implode(',', $placeholders) . ")";
        }

        // Only active customers with valid phone
        $sql .= " AND phone IS NOT NULL AND phone != ''";

        // Safety limit (can be configured)
        $maxRecipients = (int)($this->config['sms']['max_recipients_per_campaign'] ?? 10000);
        $sql .= " LIMIT {$maxRecipients}";

        $this->log("Loading recipients with query: " . $sql);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $recipients = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['mobile'])) {
                $recipients[] = [
                    'mobile' => (string)$row['mobile'],
                    'name' => $row['name'] ?? null,
                    'data' => [
                        'customer_id' => (int)$row['id'],
                        'email' => $row['email'] ?? null,
                    ],
                ];
            }
        }

        $this->log("Loaded " . count($recipients) . " recipients");

        return $recipients;
    }

    /**
     * صف‌بندی پیام‌ها برای ارسال
     * 
     * @param array<int,array{mobile:string,name:?string,data:?array}> $recipients
     * @return int تعداد پیام‌های صف‌بندی شده
     */
    private function queueMessages(SMSCampaign $campaign, array $recipients): int
    {
        $queued = 0;

        foreach ($recipients as $recipient) {
            $message = $this->prepareMessage($campaign, $recipient);
            $this->saveMessage($message);
            $queued++;
        }

        return $queued;
    }

    /**
     * آماده‌سازی یک پیام برای ذخیره در صف
     * 
     * @param array{mobile:string,name:?string,data:?array} $recipient
     */
    private function prepareMessage(SMSCampaign $campaign, array $recipient): SMSMessage
    {
        $message = $campaign->messageText ?? '';
        $templateParams = null;

        // If using template, prepare parameters
        if ($campaign->templateId) {
            $templateParams = [
                'name' => $recipient['name'] ?? 'مشتری',
                // Add more template params based on your needs
            ];

            // Replace placeholders in message if needed
            if ($message) {
                $message = $this->replacePlaceholders($message, [
                    'name' => $recipient['name'] ?? 'مشتری',
                    'mobile' => $recipient['mobile'],
                ]);
            }
        } else {
            // Plain text message - replace placeholders
            if ($message) {
                $message = $this->replacePlaceholders($message, [
                    'name' => $recipient['name'] ?? 'مشتری',
                    'mobile' => $recipient['mobile'],
                ]);
            }
        }

        return new SMSMessage(
            id: null,
            campaignId: $campaign->id,
            mobile: $recipient['mobile'],
            message: $message,
            templateId: $campaign->templateId,
            templateParams: $templateParams,
            status: SMSMessageStatus::PENDING,
            referenceId: null,
            provider: 'messageway',
            errorMessage: null,
            sentAt: null,
            deliveredAt: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            metadata: $recipient['data'] ?? null,
        );
    }

    /**
     * ذخیره پیام در دیتابیس
     */
    private function saveMessage(SMSMessage $message): void
    {
        $sql = "INSERT INTO sms_messages 
                (campaign_id, mobile, message, template_id, template_params, status, 
                 provider, created_at, updated_at, metadata)
                VALUES 
                (:cid, :mobile, :msg, :tid, :params, :status, :provider, NOW(), NOW(), :meta)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':cid' => $message->campaignId,
            ':mobile' => $message->mobile,
            ':msg' => $message->message,
            ':tid' => $message->templateId,
            ':params' => $message->templateParams ? json_encode($message->templateParams, JSON_UNESCAPED_UNICODE) : null,
            ':status' => $message->status->value,
            ':provider' => $message->provider,
            ':meta' => $message->metadata ? json_encode($message->metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * جایگزینی placeholder ها در متن پیام
     * 
     * @param array<string,mixed> $data
     */
    private function replacePlaceholders(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            $text = str_replace("{{$key}}", (string)$value, $text);
            $text = str_replace("[{$key}]", (string)$value, $text);
        }
        return $text;
    }

    // =========================================================
    // Queue Processing (for Cron Job)
    // =========================================================

    /**
     * پردازش صف پیام‌های معلق (برای اجرا در Cron)
     * 
     * @param int $batchSize تعداد پیام در هر batch
     * @return array آمار پردازش
     */
    public function processQueue(int $batchSize = 100): array
    {
        $this->log("Processing SMS queue (batch size: {$batchSize})");

        $sql = "SELECT * FROM sms_messages 
                WHERE status = :status 
                ORDER BY created_at ASC 
                LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', SMSMessageStatus::PENDING->value);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();

        $messages = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = SMSMessage::fromArray($row);
        }

        if (empty($messages)) {
            $this->log("No pending messages in queue");
            return [
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
            ];
        }

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
        ];

        foreach ($messages as $message) {
            $result = $this->sendMessage($message);
            $stats['processed']++;
            
            if ($result['success']) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }

            // Rate limiting (if configured)
            $delayMs = (int)($this->config['sms']['send_delay_ms'] ?? 100);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        // Update campaign statistics
        $this->updateCampaignStats();

        $this->log("Queue processing completed: " . json_encode($stats, JSON_UNESCAPED_UNICODE));

        return $stats;
    }

    /**
     * ارسال یک پیام
     */
    private function sendMessage(SMSMessage $message): array
    {
        if (!$message->id) {
            return ['success' => false, 'error' => 'Message ID is null'];
        }

        try {
            // Mark as sending
            $this->updateMessageStatus($message->id, SMSMessageStatus::SENDING);

            // Send via MessageWay
            if ($message->templateId) {
                // Template-based send
                $result = $this->client->sendViaSMS(
                    $message->mobile,
                    $message->templateId,
                    $message->templateParams ?? []
                );
            } else {
                // Plain text send (if API supports it)
                // Note: MessageWay might require template for all sends
                // In that case, you need to create templates for each message type
                throw new RuntimeException("Plain text SMS not supported. Use template.");
            }

            // Update message with success
            $sql = "UPDATE sms_messages 
                    SET status = :status, 
                        reference_id = :ref, 
                        sent_at = NOW(), 
                        updated_at = NOW() 
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':status' => SMSMessageStatus::SENT->value,
                ':ref' => $result['referenceID'] ?? null,
                ':id' => $message->id,
            ]);

            $this->log("Message {$message->id} sent successfully to {$message->mobile}");

            return [
                'success' => true,
                'reference_id' => $result['referenceID'] ?? null,
            ];

        } catch (Throwable $e) {
            // Update message with error
            $this->updateMessageStatus($message->id, SMSMessageStatus::FAILED, $e->getMessage());
            
            $this->log("Message {$message->id} failed: " . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * بروزرسانی وضعیت پیام
     */
    private function updateMessageStatus(
        int $messageId,
        SMSMessageStatus $status,
        ?string $errorMessage = null
    ): void {
        $sql = "UPDATE sms_messages 
                SET status = :status, 
                    error_message = :error, 
                    updated_at = NOW() 
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status->value,
            ':error' => $errorMessage,
            ':id' => $messageId,
        ]);
    }

    /**
     * بروزرسانی آمار کمپین‌ها
     */
    private function updateCampaignStats(): void
    {
        $sql = "UPDATE sms_campaigns c
                SET c.sent_count = (
                    SELECT COUNT(*) FROM sms_messages 
                    WHERE campaign_id = c.id 
                    AND status IN ('sent', 'delivered')
                ),
                c.delivered_count = (
                    SELECT COUNT(*) FROM sms_messages 
                    WHERE campaign_id = c.id 
                    AND status = 'delivered'
                ),
                c.failed_count = (
                    SELECT COUNT(*) FROM sms_messages 
                    WHERE campaign_id = c.id 
                    AND status IN ('failed', 'rejected')
                ),
                c.updated_at = NOW()
                WHERE c.status = 'processing'";
        
        try {
            $this->db->exec($sql);
            
            // Check for completed campaigns
            $this->checkCompletedCampaigns();
        } catch (Throwable $e) {
            $this->log("Error updating campaign stats: " . $e->getMessage());
        }
    }

    /**
     * بررسی و علامت‌گذاری کمپین‌های تکمیل‌شده
     */
    private function checkCompletedCampaigns(): void
    {
        $sql = "SELECT id, total_recipients, sent_count, failed_count 
                FROM sms_campaigns 
                WHERE status = 'processing'";
        
        $stmt = $this->db->query($sql);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $total = (int)($row['total_recipients'] ?? 0);
            $sent = (int)($row['sent_count'] ?? 0);
            $failed = (int)($row['failed_count'] ?? 0);
            
            if ($total > 0 && ($sent + $failed) >= $total) {
                // Campaign completed
                $sql2 = "UPDATE sms_campaigns 
                         SET status = 'completed', completed_at = NOW(), updated_at = NOW() 
                         WHERE id = :id";
                $stmt2 = $this->db->prepare($sql2);
                $stmt2->execute([':id' => (int)$row['id']]);
                
                $this->log("Campaign {$row['id']} marked as completed");
            }
        }
    }

    // =========================================================
    // Single Message Operations (OTP, Transactional)
    // =========================================================

    /**
     * ارسال OTP تک‌نفره
     * 
     * @param array<string,mixed> $params پارامترهای template
     */
    public function sendSingleOTP(
        string $mobile,
        int $templateId,
        array $params = [],
        string $provider = 'sms'
    ): array {
        $this->log("Sending single OTP to {$mobile} via {$provider}");

        try {
            // Send via appropriate channel
            $result = match($provider) {
                'sms' => $this->client->sendViaSMS($mobile, $templateId, $params),
                'ivr' => $this->client->sendViaIVR($mobile, $templateId, $params),
                default => $this->client->sendViaMessenger($mobile, $templateId, $provider, $params),
            };

            // Log the message (without campaign)
            $this->logSingleMessage(
                $mobile,
                $templateId,
                $result['referenceID'],
                $provider,
                'otp'
            );

            return [
                'success' => true,
                'reference_id' => $result['referenceID'],
                'sender' => $result['sender'] ?? null,
            ];

        } catch (Throwable $e) {
            $this->log("OTP send failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * تایید کد OTP
     */
    public function verifyOTP(string $code, string $mobile): bool
    {
        try {
            $result = $this->client->verifyOTP($code, $mobile);
            return (bool)($result['status'] ?? false);
        } catch (Throwable $e) {
            $this->log("OTP verify failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ثبت پیام تک‌نفره (بدون کمپین)
     */
    private function logSingleMessage(
        string $mobile,
        int $templateId,
        string $referenceId,
        string $provider,
        string $messageType = 'single'
    ): void {
        $sql = "INSERT INTO sms_messages 
                (campaign_id, mobile, message, template_id, status, reference_id, provider, 
                 sent_at, created_at, updated_at, metadata)
                VALUES 
                (NULL, :mobile, '', :tid, 'sent', :ref, :provider, NOW(), NOW(), NOW(), :meta)";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':mobile' => $mobile,
                ':tid' => $templateId,
                ':ref' => $referenceId,
                ':provider' => $provider,
                ':meta' => json_encode(['type' => $messageType], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            $this->log("Failed to log single message: " . $e->getMessage());
        }
    }

    // =========================================================
    // Statistics & Reporting
    // =========================================================

    /**
     * دریافت آمار کلی کمپین
     */
    public function getCampaignStats(int $campaignId): array
    {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign) {
            throw new RuntimeException("Campaign not found");
        }

        // Get message breakdown
        $sql = "SELECT status, COUNT(*) as count 
                FROM sms_messages 
                WHERE campaign_id = :id 
                GROUP BY status";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $campaignId]);
        
        $messageStats = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messageStats[$row['status']] = (int)$row['count'];
        }

        return [
            'campaign' => $campaign->toArray(),
            'message_breakdown' => $messageStats,
            'success_rate' => $campaign->totalRecipients > 0 
                ? round(($campaign->deliveredCount / $campaign->totalRecipients) * 100, 2)
                : 0,
            'failure_rate' => $campaign->totalRecipients > 0
                ? round(($campaign->failedCount / $campaign->totalRecipients) * 100, 2)
                : 0,
        ];
    }

    /**
     * دریافت آمار کلی سیستم SMS
     */
    public function getGlobalStats(): array
    {
        $stats = [];

        // Total campaigns by status
        $sql = "SELECT status, COUNT(*) as count FROM sms_campaigns GROUP BY status";
        $stmt = $this->db->query($sql);
        $campaignsByStatus = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $campaignsByStatus[$row['status']] = (int)$row['count'];
        }
        $stats['campaigns_by_status'] = $campaignsByStatus;

        // Total messages by status
        $sql = "SELECT status, COUNT(*) as count FROM sms_messages GROUP BY status";
        $stmt = $this->db->query($sql);
        $messagesByStatus = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messagesByStatus[$row['status']] = (int)$row['count'];
        }
        $stats['messages_by_status'] = $messagesByStatus;

        // Messages sent today
        $sql = "SELECT COUNT(*) as count FROM sms_messages WHERE DATE(created_at) = CURDATE()";
        $stmt = $this->db->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['messages_today'] = (int)($row['count'] ?? 0);

        // Check account balance
        try {
            $balance = $this->client->getBalance();
            $stats['account_balance'] = $balance;
        } catch (Throwable $e) {
            $stats['account_balance'] = null;
            $stats['balance_error'] = $e->getMessage();
        }

        return $stats;
    }

    // =========================================================
    // Utilities
    // =========================================================

    private function log(string $message): void
    {
        if ($this->logger) {
            try {
                ($this->logger)("[SMSCampaignService] " . $message);
            } catch (Throwable $e) {
                // Ignore logging errors
            }
        }
    }
}

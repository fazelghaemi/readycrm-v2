<?php
/**
 * File: app/Domain/Entities/SMSCampaign.php
 * 
 * کمپین پیامکی
 */

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\SMSCampaignStatus;
use App\Domain\Enums\SMSCampaignType;

final class SMSCampaign
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $description,
        public SMSCampaignType $type,
        public SMSCampaignStatus $status,
        public ?int $templateId,
        public ?string $messageText,
        public ?string $recipientFilter, // JSON: criteria for selecting customers
        public ?int $totalRecipients,
        public ?int $sentCount,
        public ?int $deliveredCount,
        public ?int $failedCount,
        public ?\DateTimeImmutable $scheduledAt,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $completedAt,
        public ?int $createdBy,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?array $metadata = null, // Extra config
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            name: (string)($row['name'] ?? ''),
            description: (string)($row['description'] ?? ''),
            type: SMSCampaignType::from((string)($row['type'] ?? 'bulk')),
            status: SMSCampaignStatus::from((string)($row['status'] ?? 'draft')),
            templateId: isset($row['template_id']) && $row['template_id'] !== null ? (int)$row['template_id'] : null,
            messageText: $row['message_text'] ?? null,
            recipientFilter: $row['recipient_filter'] ?? null,
            totalRecipients: isset($row['total_recipients']) ? (int)$row['total_recipients'] : null,
            sentCount: isset($row['sent_count']) ? (int)$row['sent_count'] : null,
            deliveredCount: isset($row['delivered_count']) ? (int)$row['delivered_count'] : null,
            failedCount: isset($row['failed_count']) ? (int)$row['failed_count'] : null,
            scheduledAt: isset($row['scheduled_at']) && $row['scheduled_at'] ? new \DateTimeImmutable($row['scheduled_at']) : null,
            startedAt: isset($row['started_at']) && $row['started_at'] ? new \DateTimeImmutable($row['started_at']) : null,
            completedAt: isset($row['completed_at']) && $row['completed_at'] ? new \DateTimeImmutable($row['completed_at']) : null,
            createdBy: isset($row['created_by']) ? (int)$row['created_by'] : null,
            createdAt: new \DateTimeImmutable($row['created_at'] ?? 'now'),
            updatedAt: new \DateTimeImmutable($row['updated_at'] ?? 'now'),
            metadata: isset($row['metadata']) && is_string($row['metadata']) ? json_decode($row['metadata'], true) : null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'template_id' => $this->templateId,
            'message_text' => $this->messageText,
            'recipient_filter' => $this->recipientFilter,
            'total_recipients' => $this->totalRecipients,
            'sent_count' => $this->sentCount,
            'delivered_count' => $this->deliveredCount,
            'failed_count' => $this->failedCount,
            'scheduled_at' => $this->scheduledAt?->format('Y-m-d H:i:s'),
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata ? json_encode($this->metadata, JSON_UNESCAPED_UNICODE) : null,
        ];
    }
}

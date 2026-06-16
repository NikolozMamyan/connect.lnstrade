<?php

namespace App\Entity;

use App\Repository\ErpOrderExportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ErpOrderExportRepository::class)]
#[ORM\Table(name: 'erp_order_export')]
#[ORM\UniqueConstraint(name: 'uniq_erp_order_export_deal', columns: ['hubspot_deal_id'])]
#[ORM\Index(columns: ['status', 'updated_at'], name: 'idx_erp_order_export_status_updated')]
class ErpOrderExport
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'hubspot_deal_id', length: 64)]
    private string $hubspotDealId;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PROCESSING;

    #[ORM\Column(name: 'reference_commande', length: 255, nullable: true)]
    private ?string $referenceCommande = null;

    #[ORM\Column(name: 'num_client', length: 64, nullable: true)]
    private ?string $numClient = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'erp_response', type: Types::JSON, nullable: true)]
    private ?array $erpResponse = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $hubspotDealId)
    {
        $this->hubspotDealId = trim($hubspotDealId);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHubspotDealId(): string
    {
        return $this->hubspotDealId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function getReferenceCommande(): ?string
    {
        return $this->referenceCommande;
    }

    public function getNumClient(): ?string
    {
        return $this->numClient;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getErpResponse(): ?array
    {
        return $this->erpResponse;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markProcessing(): static
    {
        $this->status = self::STATUS_PROCESSING;
        $this->errorMessage = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markSent(array $payload, array $erpResponse): static
    {
        $this->status = self::STATUS_SENT;
        $this->payload = $payload;
        $this->erpResponse = $erpResponse;
        $this->referenceCommande = $this->nullableString($payload['referenceCommande'] ?? null, 255);
        $this->numClient = $this->nullableString($payload['numClient'] ?? null, 64);
        $this->errorMessage = null;
        $this->sentAt = new \DateTimeImmutable();
        $this->updatedAt = $this->sentAt;

        return $this;
    }

    public function markFailed(string $errorMessage): static
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }
}

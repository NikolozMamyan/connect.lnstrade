<?php

namespace App\Entity;

use App\Repository\ErpDeliveryNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ErpDeliveryNoteRepository::class)]
#[ORM\Table(name: 'erp_delivery_note')]
#[ORM\UniqueConstraint(name: 'uniq_erp_delivery_note_sage_key', columns: ['sage_key'])]
#[ORM\UniqueConstraint(name: 'uniq_erp_delivery_note_invoice_piece', columns: ['invoice_piece'])]
#[ORM\Index(columns: ['status', 'document_date'], name: 'idx_erp_delivery_note_status_date')]
#[ORM\Index(columns: ['client_id', 'reference'], name: 'idx_erp_delivery_note_client_reference')]
class ErpDeliveryNote
{
    public const STATUS_DISCOVERED = 'discovered';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CHANGED = 'changed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'sage_key', length: 190)]
    private string $sageKey;

    #[ORM\Column(length: 64)]
    private string $piece;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'source_document_type', options: ['default' => 3])]
    private int $sourceDocumentType = 3;

    #[ORM\Column(name: 'invoice_piece', length: 64, nullable: true)]
    private ?string $invoicePiece = null;

    #[ORM\Column(name: 'client_id', length: 64, nullable: true)]
    private ?string $clientId = null;

    #[ORM\Column(name: 'client_name', length: 255, nullable: true)]
    private ?string $clientName = null;

    #[ORM\Column(name: 'document_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $documentDate = null;

    #[ORM\Column(name: 'delivery_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveryDate = null;

    #[ORM\Column(name: 'amount_excluding_tax', type: Types::FLOAT)]
    private float $amountExcludingTax = 0.0;

    #[ORM\Column(name: 'amount_including_tax', type: Types::FLOAT)]
    private float $amountIncludingTax = 0.0;

    #[ORM\Column(name: 'line_count')]
    private int $lineCount = 0;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_DISCOVERED;

    #[ORM\Column(name: 'hubspot_order_id', length: 64, nullable: true)]
    private ?string $hubspotOrderId = null;

    #[ORM\Column(name: 'hubspot_line_item_ids', type: Types::JSON, nullable: true)]
    private ?array $hubspotLineItemIds = null;

    #[ORM\Column(name: 'payload_hash', length: 64, nullable: true)]
    private ?string $payloadHash = null;

    #[ORM\Column(name: 'exported_payload_hash', length: 64, nullable: true)]
    private ?string $exportedPayloadHash = null;

    #[ORM\Column(name: 'raw_payload', type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $warnings = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'analyzed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    #[ORM\Column(name: 'exported_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $exportedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $sageKey, string $piece)
    {
        $this->sageKey = trim($sageKey);
        $this->piece = trim($piece);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSageKey(): string
    {
        return $this->sageKey;
    }

    public function getPiece(): string
    {
        return $this->piece;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getSourceDocumentType(): int
    {
        return $this->sourceDocumentType;
    }

    public function getInvoicePiece(): ?string
    {
        return $this->invoicePiece;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function getDocumentDate(): ?\DateTimeImmutable
    {
        return $this->documentDate;
    }

    public function getDeliveryDate(): ?\DateTimeImmutable
    {
        return $this->deliveryDate;
    }

    public function getAmountExcludingTax(): float
    {
        return $this->amountExcludingTax;
    }

    public function getAmountIncludingTax(): float
    {
        return $this->amountIncludingTax;
    }

    public function getLineCount(): int
    {
        return $this->lineCount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function getHubspotOrderId(): ?string
    {
        return $this->hubspotOrderId;
    }

    /**
     * @return list<string>
     */
    public function getHubspotLineItemIds(): array
    {
        return array_values(array_filter(
            $this->hubspotLineItemIds ?? [],
            static fn (mixed $id): bool => is_string($id) && trim($id) !== ''
        ));
    }

    public function getPayloadHash(): ?string
    {
        return $this->payloadHash;
    }

    public function getExportedPayloadHash(): ?string
    {
        return $this->exportedPayloadHash;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function getWarnings(): ?array
    {
        return $this->warnings;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function getExportedAt(): ?\DateTimeImmutable
    {
        return $this->exportedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null $client
     * @param list<array<string, mixed>> $contacts
     * @param list<array<string, mixed>> $deliveries
     */
    public function refreshFromSage(
        array $header,
        array $lines,
        ?array $client,
        array $contacts,
        array $deliveries,
        string $payloadHash,
        int $sourceDocumentType = 3,
    ): static {
        $freeFields = isset($header['champsLibres']) && is_array($header['champsLibres']) ? $header['champsLibres'] : [];
        $clientName = $this->nullableString($client['intitule'] ?? ($freeFields['nomtiers'] ?? null), 255);

        $this->clientId = $this->nullableString($header['tiers'] ?? null, 64);
        $this->clientName = $clientName;
        $this->reference = $this->nullableString($header['reference'] ?? null, 255);
        $this->sourceDocumentType = $sourceDocumentType;

        if (in_array($sourceDocumentType, [6, 7], true)) {
            $this->invoicePiece = $this->nullableString($header['piece'] ?? null, 64);
        }

        $this->documentDate = $this->toDate($header['date'] ?? null);
        $this->deliveryDate = $this->toDate($header['dateLivraison'] ?? ($freeFields['Date de livraison client'] ?? null));
        $this->amountExcludingTax = $this->toFloat($header['montantHT'] ?? null);
        $this->amountIncludingTax = $this->toFloat($header['montantTTC'] ?? null);
        $this->lineCount = count($lines);
        $this->payloadHash = $payloadHash;
        $this->rawPayload = [
            'sourceDocumentType' => $sourceDocumentType,
            'header' => $header,
            'lines' => $lines,
            'client' => $client,
            'contacts' => $contacts,
            'deliveries' => $deliveries,
        ];
        $this->analyzedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->analyzedAt;

        return $this;
    }

    public function markProcessing(): static
    {
        $this->status = self::STATUS_PROCESSING;
        $this->errorMessage = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markSent(string $hubspotOrderId, array $hubspotLineItemIds, string $payloadHash, array $warnings = []): static
    {
        $this->status = self::STATUS_SENT;
        $this->hubspotOrderId = trim($hubspotOrderId);
        $this->hubspotLineItemIds = array_values(array_unique(array_map('strval', $hubspotLineItemIds)));
        $this->exportedPayloadHash = $payloadHash;
        $this->warnings = $warnings !== [] ? $warnings : null;
        $this->errorMessage = null;
        $this->exportedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->exportedAt;

        return $this;
    }

    public function rememberHubspotLineItem(string $lineItemId): static
    {
        $ids = $this->getHubspotLineItemIds();
        $ids[] = trim($lineItemId);
        $this->hubspotLineItemIds = array_values(array_unique(array_filter($ids)));
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @param list<string> $lineItemIds
     */
    public function replaceHubspotLineItems(array $lineItemIds): static
    {
        $this->hubspotLineItemIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $lineItemIds),
        )));
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markFailed(string $message, array $warnings = []): static
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $message;
        $this->warnings = $warnings !== [] ? $warnings : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markChanged(): static
    {
        $this->status = self::STATUS_CHANGED;
        $this->errorMessage = 'Le BL a change dans Sage apres le debut de son export HubSpot. Une verification manuelle est requise.';
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    private function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

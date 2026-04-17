<?php

namespace App\Entity;

use App\Repository\ErpInvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ErpInvoiceRepository::class)]
#[ORM\Table(name: 'erp_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_erp_invoice_number', columns: ['invoice_number'])]
class ErpInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'invoice_number', length: 64, unique: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(name: 'client_id', length: 64, nullable: true)]
    private ?string $clientId = null;

    #[ORM\Column(name: 'line_count')]
    private int $lineCount = 0;

    #[ORM\Column(name: 'quantity_total', type: Types::FLOAT)]
    private float $quantityTotal = 0.0;

    #[ORM\Column(name: 'amount_total', type: Types::FLOAT)]
    private float $amountTotal = 0.0;

    #[ORM\Column(name: 'raw_payload', type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(name: 'last_synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, ErpInvoiceLine>
     */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: ErpInvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(string $invoiceNumber): static
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getLineCount(): int
    {
        return $this->lineCount;
    }

    public function setLineCount(int $lineCount): static
    {
        $this->lineCount = $lineCount;

        return $this;
    }

    public function getQuantityTotal(): float
    {
        return $this->quantityTotal;
    }

    public function setQuantityTotal(float $quantityTotal): static
    {
        $this->quantityTotal = $quantityTotal;

        return $this;
    }

    public function getAmountTotal(): float
    {
        return $this->amountTotal;
    }

    public function setAmountTotal(float $amountTotal): static
    {
        $this->amountTotal = $amountTotal;

        return $this;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?array $rawPayload): static
    {
        $this->rawPayload = $rawPayload;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, ErpInvoiceLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(ErpInvoiceLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setInvoice($this);
        }

        return $this;
    }

    public function removeLine(ErpInvoiceLine $line): static
    {
        if ($this->lines->removeElement($line) && $line->getInvoice() === $this) {
            $line->setInvoice(null);
        }

        return $this;
    }
}

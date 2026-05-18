<?php

namespace App\Entity;

use App\Repository\DealRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DealRepository::class)]
#[ORM\Table(name: 'deal')]
#[ORM\Index(columns: ['submitted_at'], name: 'idx_deal_submitted_at')]
class Deal
{
    public const STATUS_VALIDATED = 'validated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'deal', targetEntity: OrderForm::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OrderForm $orderForm = null;

    #[ORM\ManyToOne(targetEntity: Commercial::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Commercial $commercial = null;

    #[ORM\Column(name: 'reference_number', length: 16, unique: true)]
    private ?string $referenceNumber = null;

    #[ORM\Column(name: 'deal_type', length: 16)]
    private ?string $dealType = null;

    #[ORM\Column(name: 'deal_id', length: 64, nullable: true)]
    private ?string $dealId = null;

    #[ORM\Column(name: 'enterprise_id', length: 64, nullable: true)]
    private ?string $enterpriseId = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_VALIDATED;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'line_item_count')]
    private int $lineItemCount = 0;

    #[ORM\Column(name: 'total_amount', type: Types::FLOAT)]
    private float $totalAmount = 0.0;

    #[ORM\Column(name: 'source_file_name', length: 255, nullable: true)]
    private ?string $sourceFileName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, DealLineItem>
     */
    #[ORM\OneToMany(mappedBy: 'deal', targetEntity: DealLineItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lineItems;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->submittedAt = new \DateTimeImmutable();
        $this->lineItems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderForm(): ?OrderForm
    {
        return $this->orderForm;
    }

    public function setOrderForm(?OrderForm $orderForm): static
    {
        $this->orderForm = $orderForm;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCommercial(): ?Commercial
    {
        return $this->commercial;
    }

    public function setCommercial(?Commercial $commercial): static
    {
        $this->commercial = $commercial;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(string $referenceNumber): static
    {
        $this->referenceNumber = strtoupper(trim($referenceNumber));
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDealType(): ?string
    {
        return $this->dealType;
    }

    public function setDealType(string $dealType): static
    {
        $this->dealType = trim($dealType);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDealId(): ?string
    {
        return $this->dealId;
    }

    public function setDealId(?string $dealId): static
    {
        $this->dealId = $dealId !== null ? trim($dealId) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getEnterpriseId(): ?string
    {
        return $this->enterpriseId;
    }

    public function setEnterpriseId(?string $enterpriseId): static
    {
        $this->enterpriseId = $enterpriseId !== null ? trim($enterpriseId) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLineItemCount(): int
    {
        return $this->lineItemCount;
    }

    public function setLineItemCount(int $lineItemCount): static
    {
        $this->lineItemCount = $lineItemCount;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(float $totalAmount): static
    {
        $this->totalAmount = $totalAmount;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSourceFileName(): ?string
    {
        return $this->sourceFileName;
    }

    public function setSourceFileName(?string $sourceFileName): static
    {
        $this->sourceFileName = $sourceFileName;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, DealLineItem>
     */
    public function getLineItems(): Collection
    {
        return $this->lineItems;
    }

    public function addLineItem(DealLineItem $lineItem): static
    {
        if (!$this->lineItems->contains($lineItem)) {
            $this->lineItems->add($lineItem);
            $lineItem->setDeal($this);
        }

        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\DealLineItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DealLineItemRepository::class)]
#[ORM\Table(name: 'deal_line_item')]
class DealLineItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lineItems', targetEntity: Deal::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Deal $deal = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'article_ref', length: 128)]
    private ?string $articleRef = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'ean_unit', length: 128, nullable: true)]
    private ?string $eanUnit = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $quantity = 0.0;

    #[ORM\Column(name: 'unit_price', type: Types::FLOAT)]
    private float $unitPrice = 0.0;

    #[ORM\Column(name: 'line_total', type: Types::FLOAT)]
    private float $lineTotal = 0.0;

    #[ORM\Column(name: 'raw_payload', type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeal(): ?Deal
    {
        return $this->deal;
    }

    public function setDeal(?Deal $deal): static
    {
        $this->deal = $deal;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getArticleRef(): ?string
    {
        return $this->articleRef;
    }

    public function setArticleRef(string $articleRef): static
    {
        $this->articleRef = trim($articleRef);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description !== null ? trim($description) : null;

        return $this;
    }

    public function getEanUnit(): ?string
    {
        return $this->eanUnit;
    }

    public function setEanUnit(?string $eanUnit): static
    {
        $this->eanUnit = $eanUnit !== null ? trim($eanUnit) : null;

        return $this;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getLineTotal(): float
    {
        return $this->lineTotal;
    }

    public function setLineTotal(float $lineTotal): static
    {
        $this->lineTotal = $lineTotal;

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
}

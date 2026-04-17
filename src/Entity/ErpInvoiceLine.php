<?php

namespace App\Entity;

use App\Repository\ErpInvoiceLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ErpInvoiceLineRepository::class)]
#[ORM\Table(name: 'erp_invoice_line')]
class ErpInvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines', targetEntity: ErpInvoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ErpInvoice $invoice = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $intitule = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $quantite = 0.0;

    #[ORM\Column(name: 'prix_unitaire', type: Types::FLOAT)]
    private float $prixUnitaire = 0.0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $total = 0.0;

    #[ORM\Column(name: 'raw_payload', type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): ?ErpInvoice
    {
        return $this->invoice;
    }

    public function setInvoice(?ErpInvoice $invoice): static
    {
        $this->invoice = $invoice;

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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getIntitule(): ?string
    {
        return $this->intitule;
    }

    public function setIntitule(?string $intitule): static
    {
        $this->intitule = $intitule;

        return $this;
    }

    public function getQuantite(): float
    {
        return $this->quantite;
    }

    public function setQuantite(float $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPrixUnitaire(): float
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(float $prixUnitaire): static
    {
        $this->prixUnitaire = $prixUnitaire;

        return $this;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function setTotal(float $total): static
    {
        $this->total = $total;

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

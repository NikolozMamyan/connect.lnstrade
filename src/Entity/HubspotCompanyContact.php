<?php

namespace App\Entity;

use App\Repository\HubspotCompanyContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HubspotCompanyContactRepository::class)]
#[ORM\Table(name: 'hubspot_company_contact')]
#[ORM\UniqueConstraint(name: 'uniq_company_contact_relation', columns: ['company_id', 'contact_id', 'association_type'])]
class HubspotCompanyContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HubspotCompany::class, inversedBy: 'companyContacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?HubspotCompany $company = null;

    #[ORM\ManyToOne(targetEntity: HubspotContact::class, inversedBy: 'companyContacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?HubspotContact $contact = null;

    #[ORM\Column(name: 'association_type', length: 100)]
    private ?string $associationType = null;

    #[ORM\Column(name: 'association_category', length: 100, nullable: true)]
    private ?string $associationCategory = null;

    #[ORM\Column(name: 'is_primary', nullable: true)]
    private ?bool $isPrimary = null;

    #[ORM\Column(name: 'raw_payload', type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?HubspotCompany
    {
        return $this->company;
    }

    public function setCompany(?HubspotCompany $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getContact(): ?HubspotContact
    {
        return $this->contact;
    }

    public function setContact(?HubspotContact $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getAssociationType(): ?string
    {
        return $this->associationType;
    }

    public function setAssociationType(string $associationType): static
    {
        $this->associationType = $associationType;

        return $this;
    }

    public function getAssociationCategory(): ?string
    {
        return $this->associationCategory;
    }

    public function setAssociationCategory(?string $associationCategory): static
    {
        $this->associationCategory = $associationCategory;

        return $this;
    }

    public function isPrimary(): ?bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(?bool $isPrimary): static
    {
        $this->isPrimary = $isPrimary;

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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
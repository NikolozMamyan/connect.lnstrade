<?php

namespace App\Entity;

use App\Entity\HubspotCompanyContact;
use App\Repository\HubspotContactRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HubspotContactRepository::class)]
#[ORM\Table(name: 'hubspot_contact')]
#[ORM\UniqueConstraint(name: 'uniq_hubspot_contact_hubspot_id', columns: ['hubspot_id'])]
class HubspotContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'hubspot_id', length: 32, unique: true)]
    private ?string $hubspotId = null;

    #[ORM\Column(name: 'hubspot_object_id', length: 32, nullable: true)]
    private ?string $hubspotObjectId = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $firstname = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $lastname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mobilephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $jobtitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(name: 'hubspot_created_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hubspotCreatedAt = null;

    #[ORM\Column(name: 'hubspot_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hubspotUpdatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?bool $archived = false;

    #[ORM\Column(name: 'hubspot_url', type: Types::TEXT, nullable: true)]
    private ?string $hubspotUrl = null;

    #[ORM\Column(name: 'raw_properties', type: Types::JSON, nullable: true)]
    private ?array $rawProperties = null;

    #[ORM\Column(name: 'raw_payload', type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, HubspotCompanyContact>
     */
    #[ORM\OneToMany(mappedBy: 'contact', targetEntity: HubspotCompanyContact::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $companyContacts;

    public function __construct()
    {
        $this->companyContacts = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return trim(($this->firstname ?? '') . ' ' . ($this->lastname ?? '')) ?: ($this->email ?? sprintf('Contact #%s', $this->id ?? 'new'));
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHubspotId(): ?string
    {
        return $this->hubspotId;
    }

    public function setHubspotId(string $hubspotId): static
    {
        $this->hubspotId = $hubspotId;

        return $this;
    }

    public function getHubspotObjectId(): ?string
    {
        return $this->hubspotObjectId;
    }

    public function setHubspotObjectId(?string $hubspotObjectId): static
    {
        $this->hubspotObjectId = $hubspotObjectId;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getMobilephone(): ?string
    {
        return $this->mobilephone;
    }

    public function setMobilephone(?string $mobilephone): static
    {
        $this->mobilephone = $mobilephone;

        return $this;
    }

    public function getJobtitle(): ?string
    {
        return $this->jobtitle;
    }

    public function setJobtitle(?string $jobtitle): static
    {
        $this->jobtitle = $jobtitle;

        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getHubspotCreatedAt(): ?\DateTimeImmutable
    {
        return $this->hubspotCreatedAt;
    }

    public function setHubspotCreatedAt(?\DateTimeImmutable $hubspotCreatedAt): static
    {
        $this->hubspotCreatedAt = $hubspotCreatedAt;

        return $this;
    }

    public function getHubspotUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->hubspotUpdatedAt;
    }

    public function setHubspotUpdatedAt(?\DateTimeImmutable $hubspotUpdatedAt): static
    {
        $this->hubspotUpdatedAt = $hubspotUpdatedAt;

        return $this;
    }

    public function isArchived(): ?bool
    {
        return $this->archived;
    }

    public function setArchived(?bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    public function getHubspotUrl(): ?string
    {
        return $this->hubspotUrl;
    }

    public function setHubspotUrl(?string $hubspotUrl): static
    {
        $this->hubspotUrl = $hubspotUrl;

        return $this;
    }

    public function getRawProperties(): ?array
    {
        return $this->rawProperties;
    }

    public function setRawProperties(?array $rawProperties): static
    {
        $this->rawProperties = $rawProperties;

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
     * @return Collection<int, HubspotCompanyContact>
     */
    public function getCompanyContacts(): Collection
    {
        return $this->companyContacts;
    }

    public function addCompanyContact(HubspotCompanyContact $companyContact): static
    {
        if (!$this->companyContacts->contains($companyContact)) {
            $this->companyContacts->add($companyContact);
            $companyContact->setContact($this);
        }

        return $this;
    }

    public function removeCompanyContact(HubspotCompanyContact $companyContact): static
    {
        if ($this->companyContacts->removeElement($companyContact)) {
            if ($companyContact->getContact() === $this) {
                $companyContact->setContact(null);
            }
        }

        return $this;
    }
}
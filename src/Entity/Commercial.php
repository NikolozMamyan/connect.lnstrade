<?php

namespace App\Entity;

use App\Repository\CommercialRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommercialRepository::class)]
#[ORM\Table(name: 'commercial')]
class Commercial
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'first_name', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'hubspot_id', length: 64, nullable: true)]
    private ?string $hubspotId = null;

    #[ORM\Column(name: 'last_name', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $lastName = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, OrderForm>
     */
    #[ORM\OneToMany(mappedBy: 'commercial', targetEntity: OrderForm::class)]
    private Collection $orderForms;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->orderForms = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = trim($firstName);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getHubspotId(): ?string
    {
        return $this->hubspotId;
    }

    public function setHubspotId(?string $hubspotId): static
    {
        $this->hubspotId = $hubspotId !== null ? trim($hubspotId) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = trim($lastName);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email !== null ? mb_strtolower(trim($email)) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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

    public function getFullName(): string
    {
        return trim(sprintf('%s %s', (string) $this->firstName, (string) $this->lastName));
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}

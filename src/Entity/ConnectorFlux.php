<?php

namespace App\Entity;

use App\Repository\ConnectorFluxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConnectorFluxRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ConnectorFlux
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 60, unique: true)]
    private string $code;

    #[ORM\Column(length: 30)]
    private string $sourceSystem;

    #[ORM\Column(length: 30)]
    private string $targetSystem;

    #[ORM\Column(length: 50)]
    private string $sourceObject;

    #[ORM\Column(length: 50)]
    private string $targetObject;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getSourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function setSourceSystem(string $sourceSystem): self
    {
        $this->sourceSystem = $sourceSystem;

        return $this;
    }

    public function getTargetSystem(): string
    {
        return $this->targetSystem;
    }

    public function setTargetSystem(string $targetSystem): self
    {
        $this->targetSystem = $targetSystem;

        return $this;
    }

    public function getSourceObject(): string
    {
        return $this->sourceObject;
    }

    public function setSourceObject(string $sourceObject): self
    {
        $this->sourceObject = $sourceObject;

        return $this;
    }

    public function getTargetObject(): string
    {
        return $this->targetObject;
    }

    public function setTargetObject(string $targetObject): self
    {
        $this->targetObject = $targetObject;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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
}
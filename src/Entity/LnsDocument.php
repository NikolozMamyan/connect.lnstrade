<?php

namespace App\Entity;

use App\Repository\LnsDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LnsDocumentRepository::class)]
#[ORM\Table(name: 'lns_document')]
#[ORM\Index(name: 'idx_lns_document_updated_at', columns: ['updated_at'])]
class LnsDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Le titre du document est obligatoire.')]
    #[Assert\Length(max: 180, maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La description du document est obligatoire.')]
    #[Assert\Length(max: 20000, maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $description = null;

    #[ORM\Column(name: 'auto_generate_toc', options: ['default' => true])]
    private bool $autoGenerateToc = true;

    /**
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $content = [];

    #[ORM\Column(name: 'is_draft', options: ['default' => false])]
    private bool $draft = true;

    #[ORM\Version]
    #[ORM\Column(name: 'edit_version', type: Types::INTEGER, options: ['default' => 1])]
    private int $editVersion = 1;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = trim($description);
        $this->touch();

        return $this;
    }

    public function isAutoGenerateToc(): bool
    {
        return $this->autoGenerateToc;
    }

    public function setAutoGenerateToc(bool $autoGenerateToc): static
    {
        $this->autoGenerateToc = $autoGenerateToc;
        $this->touch();

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    public function setContent(array $content): static
    {
        $this->content = $content;
        $this->touch();

        return $this;
    }

    public function getPageCount(): int
    {
        return count($this->content);
    }

    public function isDraft(): bool
    {
        return $this->draft;
    }

    public function setDraft(bool $draft): static
    {
        $this->draft = $draft;
        $this->touch();

        return $this;
    }

    public function getEditVersion(): int
    {
        return $this->editVersion;
    }

    public function getDisplayTitle(): string
    {
        return $this->title !== null && $this->title !== '' ? $this->title : 'Document sans titre';
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

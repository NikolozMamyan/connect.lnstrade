<?php

namespace App\Entity;

use App\Repository\OrderFormRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: OrderFormRepository::class)]
#[ORM\Table(name: 'order_form')]
#[ORM\Index(columns: ['submitted_at'], name: 'idx_order_form_submitted_at')]
#[ORM\Index(columns: ['status'], name: 'idx_order_form_status')]
class OrderForm
{
    public const DEAL_TYPE_NOUVEAU = 'nouveau';
    public const DEAL_TYPE_EXISTANT = 'existant';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'deal_type', length: 16)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::DEAL_TYPE_NOUVEAU, self::DEAL_TYPE_EXISTANT])]
    private ?string $dealType = null;

    #[ORM\ManyToOne(targetEntity: Commercial::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Commercial $commercial = null;

    #[ORM\Column(name: 'deal_id', length: 64, nullable: true)]
    private ?string $dealId = null;

    #[ORM\Column(name: 'enterprise_id', length: 64, nullable: true)]
    private ?string $enterpriseId = null;

    #[ORM\Column(name: 'file_name', length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(name: 'original_file_name', length: 255, nullable: true)]
    private ?string $originalFileName = null;

    #[ORM\Column(name: 'file_size', nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_PENDING])]
    #[Assert\Choice(choices: [self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_FAILED])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'reference_number', length: 16, unique: true)]
    private ?string $referenceNumber = null;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(name: 'processing_errors', type: Types::JSON, nullable: true)]
    private ?array $processingErrors = null;

    #[ORM\Column(name: 'retry_rows', type: Types::JSON, nullable: true)]
    private ?array $retryRows = null;

    #[ORM\OneToOne(mappedBy: 'orderForm', targetEntity: Deal::class)]
    private ?Deal $deal = null;

    #[Assert\NotNull(groups: ['NouveauDeal', 'ExistantDeal'], message: 'Le fichier est obligatoire.')]
    #[Assert\File(
        maxSize: '10M',
        extensions: ['xlsx', 'xls'],
        groups: ['NouveauDeal', 'ExistantDeal']
    )]
    private ?File $uploadedFile = null;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_PENDING;
    }

    #[Assert\Callback(groups: ['NouveauDeal', 'ExistantDeal'])]
    public function validateConditionalFields(ExecutionContextInterface $context): void
    {
        if ($this->dealType === self::DEAL_TYPE_EXISTANT) {
            if ($this->dealId === null || trim($this->dealId) === '') {
                $context->buildViolation('Le deal ID est obligatoire pour un deal existant.')
                    ->atPath('dealId')
                    ->addViolation();
            } elseif (!preg_match('/^\d+$/', $this->dealId)) {
                $context->buildViolation('Le deal ID doit contenir uniquement des chiffres.')
                    ->atPath('dealId')
                    ->addViolation();
            }
        }

        if ($this->dealType === self::DEAL_TYPE_NOUVEAU) {
            if ($this->enterpriseId === null || trim($this->enterpriseId) === '') {
                $context->buildViolation('L identifiant entreprise est obligatoire pour un nouveau deal.')
                    ->atPath('enterpriseId')
                    ->addViolation();
            } elseif (!preg_match('/^\d+$/', $this->enterpriseId)) {
                $context->buildViolation('L identifiant entreprise doit contenir uniquement des chiffres.')
                    ->atPath('enterpriseId')
                    ->addViolation();
            }
        }

        if ($this->uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $clientMimeType = trim((string) $this->uploadedFile->getClientMimeType());
            $allowedMimeTypes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
            ];

            if ($clientMimeType !== '' && !in_array($clientMimeType, $allowedMimeTypes, true)) {
                $context->buildViolation('Le fichier doit etre un document Excel valide.')
                    ->atPath('uploadedFile')
                    ->addViolation();
            }
        }
    }

    public static function resolveValidationGroup(?string $dealType): string
    {
        return match ($dealType) {
            self::DEAL_TYPE_NOUVEAU => 'NouveauDeal',
            self::DEAL_TYPE_EXISTANT => 'ExistantDeal',
            default => 'Default',
        };
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDealType(): ?string
    {
        return $this->dealType;
    }

    public function setDealType(string $dealType): static
    {
        $this->dealType = trim($dealType);

        return $this;
    }

    public function getCommercial(): ?Commercial
    {
        return $this->commercial;
    }

    public function setCommercial(?Commercial $commercial): static
    {
        $this->commercial = $commercial;

        return $this;
    }

    public function getDealId(): ?string
    {
        return $this->dealId;
    }

    public function setDealId(?string $dealId): static
    {
        $this->dealId = $dealId !== null ? trim($dealId) : null;

        return $this;
    }

    public function getEnterpriseId(): ?string
    {
        return $this->enterpriseId;
    }

    public function setEnterpriseId(?string $enterpriseId): static
    {
        $this->enterpriseId = $enterpriseId !== null ? trim($enterpriseId) : null;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getOriginalFileName(): ?string
    {
        return $this->originalFileName;
    }

    public function setOriginalFileName(?string $originalFileName): static
    {
        $this->originalFileName = $originalFileName;

        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(string $referenceNumber): static
    {
        $this->referenceNumber = strtoupper(trim($referenceNumber));

        return $this;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeImmutable $processedAt): static
    {
        $this->processedAt = $processedAt;

        return $this;
    }

    public function getProcessingErrors(): ?array
    {
        return $this->processingErrors;
    }

    public function setProcessingErrors(?array $processingErrors): static
    {
        $this->processingErrors = $processingErrors;

        return $this;
    }

    public function getRetryRows(): ?array
    {
        return $this->retryRows;
    }

    public function setRetryRows(?array $retryRows): static
    {
        $this->retryRows = $retryRows;

        return $this;
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

    public function getUploadedFile(): ?File
    {
        return $this->uploadedFile;
    }

    public function setUploadedFile(?File $uploadedFile): static
    {
        $this->uploadedFile = $uploadedFile;

        return $this;
    }
}

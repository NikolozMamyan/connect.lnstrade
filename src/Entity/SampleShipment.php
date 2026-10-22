<?php

namespace App\Entity;

use App\Repository\SampleShipmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SampleShipmentRepository::class)]
#[ORM\Table(name: 'sample_shipment')]
#[ORM\UniqueConstraint(name: 'uniq_sample_shipment_request_token', columns: ['request_token'])]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_sample_shipment_status_created')]
class SampleShipment
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'request_token', length: 64)]
    private string $requestToken;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PROCESSING;

    #[ORM\Column(name: 'order_reference', length: 32, nullable: true)]
    private ?string $orderReference = null;

    #[ORM\Column(name: 'delivery_label', length: 64, nullable: true)]
    private ?string $deliveryLabel = null;

    #[ORM\Column(length: 150)]
    private string $company = '';

    #[ORM\Column(name: 'contact_name', length: 150, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(length: 150)]
    private string $address = '';

    #[ORM\Column(name: 'address_complement', length: 150, nullable: true)]
    private ?string $addressComplement = null;

    #[ORM\Column(name: 'postal_code', length: 32)]
    private string $postalCode = '';

    #[ORM\Column(length: 100)]
    private string $city = '';

    #[ORM\Column(length: 100)]
    private string $country = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(name: 'order_lines', type: Types::JSON)]
    private array $lines = [];

    #[ORM\Column(name: 'sage_payload', type: Types::JSON, nullable: true)]
    private ?array $sagePayload = null;

    #[ORM\Column(name: 'sage_response', type: Types::JSON, nullable: true)]
    private ?array $sageResponse = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $requestToken)
    {
        $this->requestToken = trim($requestToken);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequestToken(): string
    {
        return $this->requestToken;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function getOrderReference(): ?string
    {
        return $this->orderReference;
    }

    public function getDeliveryLabel(): ?string
    {
        return $this->deliveryLabel;
    }

    public function getSageResponse(): ?array
    {
        return $this->sageResponse;
    }

    public function setRecipient(array $recipient): static
    {
        $this->company = $recipient['company'];
        $this->contactName = $recipient['contactName'];
        $this->address = $recipient['address'];
        $this->addressComplement = $recipient['addressComplement'];
        $this->postalCode = $recipient['postalCode'];
        $this->city = $recipient['city'];
        $this->country = $recipient['country'];
        $this->phone = $recipient['phone'];
        $this->email = $recipient['email'];
        $this->message = $recipient['message'];
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setLines(array $lines): static
    {
        $this->lines = $lines;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markProcessing(): static
    {
        $this->status = self::STATUS_PROCESSING;
        $this->errorMessage = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function prepareExport(string $orderReference, string $deliveryLabel): static
    {
        $this->orderReference = $orderReference;
        $this->deliveryLabel = $deliveryLabel;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markSent(string $orderReference, string $deliveryLabel, array $sagePayload, array $sageResponse): static
    {
        $this->status = self::STATUS_SENT;
        $this->orderReference = $orderReference;
        $this->deliveryLabel = $deliveryLabel;
        $this->sagePayload = $sagePayload;
        $this->sageResponse = $sageResponse;
        $this->errorMessage = null;
        $this->sentAt = new \DateTimeImmutable();
        $this->updatedAt = $this->sentAt;

        return $this;
    }

    public function markFailed(string $errorMessage): static
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}

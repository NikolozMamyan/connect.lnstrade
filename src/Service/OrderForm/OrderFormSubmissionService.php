<?php

namespace App\Service\OrderForm;

use App\Entity\Deal;
use App\Entity\DealLineItem;
use App\Entity\OrderForm;
use App\Repository\OrderFormRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class OrderFormSubmissionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderFormRepository $orderFormRepository,
        private readonly OrderFormSpreadsheetParser $spreadsheetParser,
        private readonly SluggerInterface $slugger,
        #[Autowire('%order_form_upload_dir%')]
        private readonly string $orderFormUploadDir,
    ) {
    }

    /**
     * @return array{
     *   success: bool,
     *   orderForm: OrderForm,
     *   deal: ?Deal,
     *   errors: array<int, array<string, mixed>>
     * }
     */
    public function submit(OrderForm $orderForm, UploadedFile $uploadedFile): array
    {
        if ($orderForm->getReferenceNumber() === null || $orderForm->getReferenceNumber() === '') {
            $orderForm->setReferenceNumber($this->generateUniqueReferenceNumber());
        }

        $originalFileName = $uploadedFile->getClientOriginalName();
        $fileSize = $uploadedFile->getSize();

        if ($fileSize === false || $fileSize === null) {
            $fileSize = $uploadedFile->getClientSize();
        }

        $storedFileName = $this->storeFile($uploadedFile, (string) $orderForm->getReferenceNumber());
        $storedFilePath = $this->orderFormUploadDir . DIRECTORY_SEPARATOR . $storedFileName;

        $orderForm
            ->setFileName($storedFileName)
            ->setOriginalFileName($originalFileName)
            ->setFileSize($fileSize !== false ? $fileSize : null)
            ->setStatus(OrderForm::STATUS_PENDING)
            ->setSubmittedAt($orderForm->getSubmittedAt() ?? new \DateTimeImmutable())
            ->setUploadedFile(null);

        $parseResult = $this->spreadsheetParser->parse($storedFilePath);

        if (($parseResult['success'] ?? false) !== true) {
            $orderForm
                ->setStatus(OrderForm::STATUS_FAILED)
                ->setProcessedAt(new \DateTimeImmutable())
                ->setProcessingErrors($parseResult['errors'] ?? [])
                ->setRetryRows($parseResult['failedRows'] ?? []);

            $this->entityManager->persist($orderForm);
            $this->entityManager->flush();

            return [
                'success' => false,
                'orderForm' => $orderForm,
                'deal' => null,
                'errors' => $this->normalizeErrors($parseResult),
            ];
        }

        $deal = $this->createDealFromOrderForm($orderForm, $parseResult['lineItems'] ?? []);

        $orderForm
            ->setStatus(OrderForm::STATUS_VALIDATED)
            ->setProcessedAt(new \DateTimeImmutable())
            ->setProcessingErrors(null)
            ->setRetryRows(null)
            ->setDeal($deal);

        $this->entityManager->persist($orderForm);
        $this->entityManager->persist($deal);
        $this->entityManager->flush();

        return [
            'success' => true,
            'orderForm' => $orderForm,
            'deal' => $deal,
            'errors' => [],
        ];
    }

    private function storeFile(UploadedFile $uploadedFile, string $referenceNumber): string
    {
        if (!is_dir($this->orderFormUploadDir)) {
            mkdir($this->orderFormUploadDir, 0775, true);
        }

        $baseName = $uploadedFile->getClientOriginalName() !== ''
            ? $uploadedFile->getClientOriginalName()
            : $referenceNumber;
        $safeBaseName = (string) $this->slugger->slug(pathinfo($baseName, PATHINFO_FILENAME));
        $extension = $uploadedFile->guessExtension() ?: $uploadedFile->getClientOriginalExtension() ?: 'xlsx';
        $storedFileName = sprintf('%s-%s.%s', $referenceNumber, $safeBaseName ?: 'order-form', strtolower($extension));

        $uploadedFile->move($this->orderFormUploadDir, $storedFileName);

        return $storedFileName;
    }

    private function generateUniqueReferenceNumber(): string
    {
        do {
            $candidate = 'OF-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 6));
        } while ($this->orderFormRepository->referenceExists($candidate));

        return $candidate;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function createDealFromOrderForm(OrderForm $orderForm, array $lineItems): Deal
    {
        $deal = (new Deal())
            ->setOrderForm($orderForm)
            ->setCommercial($orderForm->getCommercial())
            ->setReferenceNumber((string) $orderForm->getReferenceNumber())
            ->setDealType((string) $orderForm->getDealType())
            ->setDealId($orderForm->getDealId())
            ->setEnterpriseId($orderForm->getEnterpriseId())
            ->setSubmittedAt($orderForm->getSubmittedAt() ?? new \DateTimeImmutable())
            ->setSourceFileName($orderForm->getOriginalFileName())
            ->setStatus(Deal::STATUS_VALIDATED);

        $lineItemCount = 0;
        $totalAmount = 0.0;

        foreach ($lineItems as $index => $payload) {
            $lineItem = (new DealLineItem())
                ->setPosition($index + 1)
                ->setArticleRef((string) ($payload['articleRef'] ?? ''))
                ->setDescription(isset($payload['description']) ? (string) $payload['description'] : null)
                ->setEanUnit(isset($payload['eanUnit']) ? (string) $payload['eanUnit'] : null)
                ->setQuantity((float) ($payload['quantity'] ?? 0))
                ->setUnitPrice((float) ($payload['unitPrice'] ?? 0))
                ->setLineTotal((float) ($payload['lineTotal'] ?? 0))
                ->setRawPayload(isset($payload['rawPayload']) && is_array($payload['rawPayload']) ? $payload['rawPayload'] : null);

            $deal->addLineItem($lineItem);
            ++$lineItemCount;
            $totalAmount += $lineItem->getLineTotal();
        }

        $deal
            ->setLineItemCount($lineItemCount)
            ->setTotalAmount($totalAmount);

        return $deal;
    }

    /**
     * @param array{errors?: array<int, array<string, mixed>>, failedRows?: array<int, array<string, mixed>>} $parseResult
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeErrors(array $parseResult): array
    {
        $errors = $parseResult['errors'] ?? [];

        foreach ($parseResult['failedRows'] ?? [] as $failedRow) {
            $errors[] = [
                'rowNumber' => $failedRow['rowNumber'] ?? 0,
                'field' => 'row',
                'message' => implode(' ', $failedRow['errors'] ?? []),
            ];
        }

        return $errors;
    }
}

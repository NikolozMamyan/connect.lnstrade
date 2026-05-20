<?php

namespace App\Service\OrderForm;

use App\Entity\Deal;
use App\Entity\DealLineItem;
use App\Entity\OrderForm;
use App\Repository\OrderFormRepository;
use App\Service\HubSpot\HubspotOrderFormDealSyncService;
use App\Service\Mailer\SimpleMailerService;
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
        private readonly HubspotOrderFormDealSyncService $hubspotOrderFormDealSyncService,
        private readonly SimpleMailerService $simpleMailerService,
        private readonly SluggerInterface $slugger,
        #[Autowire('%hubspot_portal_id%')]
        private readonly string $hubspotPortalId,
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
            $this->markOrderFormAsFailed(
                $orderForm,
                $parseResult['errors'] ?? [],
                $parseResult['failedRows'] ?? [],
            );
            $this->notifyCommercialFailure(
                $orderForm,
                $this->normalizeErrors($parseResult),
                $parseResult['failedRows'] ?? [],
            );

            return [
                'success' => false,
                'orderForm' => $orderForm,
                'deal' => null,
                'errors' => $this->normalizeErrors($parseResult),
            ];
        }

        $lineItems = $parseResult['lineItems'] ?? [];
        try {
            $hubspotResult = $this->hubspotOrderFormDealSyncService->sync($orderForm, $lineItems);
        } catch (\Throwable $exception) {
            $hubspotErrors = [[
                'field' => 'hubspot',
                'message' => 'La synchronisation HubSpot a echoue. Merci de reessayer.',
                'details' => $exception->getMessage(),
            ]];

            $this->markOrderFormAsFailed($orderForm, $hubspotErrors, []);
            $this->notifyCommercialFailure($orderForm, $hubspotErrors, []);

            return [
                'success' => false,
                'orderForm' => $orderForm,
                'deal' => null,
                'errors' => $hubspotErrors,
            ];
        }

        if (($hubspotResult['success'] ?? false) !== true) {
            $hubspotErrors = $hubspotResult['errors'] ?? [];

            $this->markOrderFormAsFailed($orderForm, $hubspotErrors, []);
            $this->notifyCommercialFailure($orderForm, $hubspotErrors, []);

            return [
                'success' => false,
                'orderForm' => $orderForm,
                'deal' => null,
                'errors' => $hubspotErrors,
            ];
        }

        $deal = $this->createDealFromOrderForm(
            $orderForm,
            $lineItems,
            $hubspotResult['hubspotDealId'] ?? null,
        );

        $orderForm
            ->setStatus(OrderForm::STATUS_VALIDATED)
            ->setProcessedAt(new \DateTimeImmutable())
            ->setProcessingErrors(null)
            ->setRetryRows(null)
            ->setDeal($deal);

        $this->entityManager->persist($orderForm);
        $this->entityManager->persist($deal);
        $this->entityManager->flush();
        $this->notifyCommercialSuccess($orderForm, $deal);

        return [
            'success' => true,
            'orderForm' => $orderForm,
            'deal' => $deal,
            'errors' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     * @param array<int, array<string, mixed>> $retryRows
     */
    private function markOrderFormAsFailed(OrderForm $orderForm, array $errors, array $retryRows): void
    {
        $orderForm
            ->setStatus(OrderForm::STATUS_FAILED)
            ->setProcessedAt(new \DateTimeImmutable())
            ->setProcessingErrors($errors)
            ->setRetryRows($retryRows === [] ? null : $retryRows);

        $this->entityManager->persist($orderForm);
        $this->entityManager->flush();
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
    private function createDealFromOrderForm(OrderForm $orderForm, array $lineItems, ?string $hubspotDealId): Deal
    {
        $deal = (new Deal())
            ->setOrderForm($orderForm)
            ->setCommercial($orderForm->getCommercial())
            ->setReferenceNumber((string) $orderForm->getReferenceNumber())
            ->setDealType((string) $orderForm->getDealType())
            ->setDealId($hubspotDealId ?? $orderForm->getDealId())
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

    private function notifyCommercialSuccess(OrderForm $orderForm, Deal $deal): void
    {
        $recipient = trim((string) $orderForm->getCommercial()?->getEmail());

        if ($recipient === '') {
            return;
        }

        $subject = sprintf('[LNS Connecteur] Order form %s valide', (string) $orderForm->getReferenceNumber());
        $text = implode("\n", [
            sprintf('Bonjour %s,', $orderForm->getCommercial()?->getFullName() ?? 'commercial'),
            '',
            'Votre order form a bien ete valide et envoye vers HubSpot.',
            sprintf('Reference: %s', (string) $orderForm->getReferenceNumber()),
            sprintf('Type de deal: %s', (string) $orderForm->getDealType()),
            sprintf('Deal HubSpot: %s', (string) ($deal->getDealId() ?? $orderForm->getDealId() ?? 'cree avec succes')),
            sprintf('Montant total: %.2f EUR', $deal->getTotalAmount()),
        ]);

        try {
            $this->simpleMailerService->sendTemplateMessage(
                $subject,
                'mailer/order_form_validated.html.twig',
                [
                    'subject' => $subject,
                    'commercialName' => $orderForm->getCommercial()?->getFullName() ?? 'commercial',
                    'orderForm' => $orderForm,
                    'deal' => $deal,
                    'dealUrl' => $this->buildHubspotDealUrl($deal->getDealId()),
                ],
                $text,
                [$recipient]
            );
        } catch (\Throwable) {
        }
    }

    private function buildHubspotDealUrl(?string $dealId): ?string
    {
        $portalId = trim($this->hubspotPortalId);
        $dealId = trim((string) $dealId);

        if ($portalId === '' || $dealId === '') {
            return null;
        }

        return sprintf('https://app-eu1.hubspot.com/contacts/%s/record/0-3/%s', $portalId, $dealId);
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     * @param array<int, array<string, mixed>> $retryRows
     */
    private function notifyCommercialFailure(OrderForm $orderForm, array $errors, array $retryRows): void
    {
        $recipient = trim((string) $orderForm->getCommercial()?->getEmail());

        if ($recipient === '') {
            return;
        }

        $subject = sprintf('[LNS Connecteur] Order form %s en echec', (string) $orderForm->getReferenceNumber());
        $textLines = [
            sprintf('Bonjour %s,', $orderForm->getCommercial()?->getFullName() ?? 'commercial'),
            '',
            'Votre order form n a pas pu etre valide.',
            sprintf('Reference: %s', (string) $orderForm->getReferenceNumber()),
            'Erreurs:',
        ];

        foreach ($errors as $error) {
            $textLines[] = sprintf('- %s: %s', (string) ($error['field'] ?? 'validation'), (string) ($error['message'] ?? 'Erreur inconnue.'));
        }

        try {
            $this->simpleMailerService->sendTemplateMessage(
                $subject,
                'mailer/order_form_failed.html.twig',
                [
                    'subject' => $subject,
                    'commercialName' => $orderForm->getCommercial()?->getFullName() ?? 'commercial',
                    'orderForm' => $orderForm,
                    'errors' => $errors,
                    'retryRows' => $retryRows,
                ],
                implode("\n", $textLines),
                [$recipient]
            );
        } catch (\Throwable) {
        }
    }
}

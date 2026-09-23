<?php

namespace App\Service\HubSpot;

use App\Entity\Commercial;
use App\Entity\ErpDeliveryNote;
use App\Repository\CommercialRepository;
use App\Repository\ErpDeliveryNoteRepository;
use App\Service\Erp\SageClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class HubspotDeliveryOrderSyncService
{
    private const API_VERSION = '2026-09';
    private const ORDER_TO_PRIMARY_COMPANY_ASSOCIATION = 509;
    private const ORDER_TO_BILLING_CONTACT_ASSOCIATION = 2694;
    private const ORDER_TO_LINE_ITEM_ASSOCIATION = 513;
    private const SAGE_DOCUMENT_DOMAIN = 0;
    private const SAGE_DELIVERY_NOTE_TYPE = 3;
    private const SAGE_INVOICE_TYPES = [6, 7];

    /** @var array<string, array<string, mixed>|null> */
    private array $productCache = [];

    /** @var array<string, string|null> */
    private array $ownerCache = [];

    /** @var array<string, string> */
    private array $companyCache = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $clientCache = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $sageListCache = [];

    public function __construct(
        private readonly SageClient $sageClient,
        private readonly HubSpotClient $hubSpotClient,
        private readonly ErpDeliveryNoteRepository $deliveryNoteRepository,
        private readonly CommercialRepository $commercialRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%hubspot_delivery_order_pipeline%')]
        private readonly string $orderPipeline,
        #[Autowire('%hubspot_delivery_order_stage%')]
        private readonly string $orderStage,
        #[Autowire('%hubspot_order_form_tax_rate_group_ids%')]
        private readonly array $taxRateGroupIds,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadHeaders(int $documentType, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        return $this->extractList($this->sageClient->get('/Document/header', [
            'domaine' => self::SAGE_DOCUMENT_DOMAIN,
            'type' => $documentType,
            'dateDebut' => $dateFrom->format('Y-m-d'),
            'dateFin' => $dateTo->format('Y-m-d'),
        ]));
    }

    /**
     * @return list<array{type: int, header: array<string, mixed>}>
     */
    private function loadInvoiceHeaders(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $invoicesByPiece = [];

        foreach (self::SAGE_INVOICE_TYPES as $documentType) {
            foreach ($this->loadHeaders($documentType, $dateFrom, $dateTo) as $header) {
                $piece = strtoupper(trim((string) ($header['piece'] ?? '')));

                if ($piece === '' || !str_starts_with($piece, 'FA')) {
                    continue;
                }

                if (!isset($invoicesByPiece[$piece]) || $documentType > $invoicesByPiece[$piece]['type']) {
                    $invoicesByPiece[$piece] = ['type' => $documentType, 'header' => $header];
                }
            }
        }

        return array_values($invoicesByPiece);
    }

    /**
     * @param array<string, mixed> $header
     */
    private function resolveLocalRecord(array $header, int $documentType): ?ErpDeliveryNote
    {
        $piece = trim((string) ($header['piece'] ?? ''));

        if (!in_array($documentType, self::SAGE_INVOICE_TYPES, true)) {
            return $this->deliveryNoteRepository->findOneBySageKey(
                sprintf('sage:%d:%d:%s', self::SAGE_DOCUMENT_DOMAIN, $documentType, $piece)
            );
        }

        $invoiceRecord = $this->deliveryNoteRepository->findOneByInvoicePiece($piece)
            ?? $this->deliveryNoteRepository->findOneBySageKey(
                sprintf('sage:%d:invoice:%s', self::SAGE_DOCUMENT_DOMAIN, $piece)
            );

        if ($invoiceRecord instanceof ErpDeliveryNote) {
            return $invoiceRecord;
        }

        $reference = $this->normalizeDocumentReference((string) ($header['reference'] ?? ''));
        $clientId = trim((string) ($header['tiers'] ?? ''));

        if ($reference === '' || $clientId === '') {
            return null;
        }

        $matches = array_values(array_filter(
            $this->deliveryNoteRepository->findPotentialInvoiceMatches($clientId),
            function (ErpDeliveryNote $deliveryNote) use ($reference): bool {
                $storedReference = $deliveryNote->getReference();

                if ($storedReference === null) {
                    $rawPayload = $deliveryNote->getRawPayload();
                    $storedReference = is_array($rawPayload['header'] ?? null)
                        ? (string) ($rawPayload['header']['reference'] ?? '')
                        : '';
                }

                return in_array($reference, [
                    $this->normalizeDocumentReference($deliveryNote->getPiece()),
                    $this->normalizeDocumentReference($storedReference),
                ], true);
            },
        ));

        if (count($matches) <= 1) {
            return $matches[0] ?? null;
        }

        $invoiceAmount = $this->numeric($header['montantTTC'] ?? null);

        if ($invoiceAmount === null) {
            return null;
        }

        $amountMatches = array_values(array_filter(
            $matches,
            static fn (ErpDeliveryNote $deliveryNote): bool => abs($deliveryNote->getAmountIncludingTax() - $invoiceAmount) < 0.01,
        ));

        return count($amountMatches) === 1 ? $amountMatches[0] : null;
    }

    /**
     * @param array<string, mixed> $header
     */
    private function hasSameExportedHeader(ErpDeliveryNote $deliveryNote, array $header, int $documentType): bool
    {
        $rawPayload = $deliveryNote->getRawPayload();
        $storedHeader = is_array($rawPayload['header'] ?? null) ? $rawPayload['header'] : null;

        return $deliveryNote->isSent()
            && $deliveryNote->getHubspotOrderId() !== null
            && $deliveryNote->getExportedPayloadHash() !== null
            && $deliveryNote->getSourceDocumentType() === $documentType
            && $storedHeader !== null
            && $this->payloadHash($storedHeader) === $this->payloadHash($header);
    }

    /**
     * @return array{
     *   analyzed: int,
     *   deliveryNotesAnalyzed: int,
     *   invoicesAnalyzed: int,
     *   discovered: int,
     *   sent: int,
     *   existing: int,
     *   updated: int,
     *   skipped: int,
     *   changed: int,
     *   failed: int,
     *   warnings: list<array<string, mixed>>,
     *   errors: list<array<string, mixed>>
     * }
     */
    public function sync(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $this->clientCache = [];
        $this->sageListCache = [];
        $this->companyCache = [];
        $result = [
            'analyzed' => 0,
            'deliveryNotesAnalyzed' => 0,
            'invoicesAnalyzed' => 0,
            'discovered' => 0,
            'sent' => 0,
            'existing' => 0,
            'updated' => 0,
            'skipped' => 0,
            'changed' => 0,
            'failed' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        foreach ($this->loadHeaders(self::SAGE_DELIVERY_NOTE_TYPE, $dateFrom, $dateTo) as $header) {
            $this->processDocument($header, self::SAGE_DELIVERY_NOTE_TYPE, $result);
        }

        foreach ($this->loadInvoiceHeaders($dateFrom, $dateTo) as $invoice) {
            $this->processDocument($invoice['header'], $invoice['type'], $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $result
     */
    private function processDocument(array $header, int $documentType, array &$result): void
    {
        $piece = trim((string) ($header['piece'] ?? ''));

        if ($piece === '') {
            ++$result['failed'];
            $result['errors'][] = $this->buildReportIssue($header, null, '', 'Numero de piece Sage manquant.', $documentType);

            return;
        }

        $isInvoice = in_array($documentType, self::SAGE_INVOICE_TYPES, true);
        ++$result['analyzed'];
        ++$result[$isInvoice ? 'invoicesAnalyzed' : 'deliveryNotesAnalyzed'];
        $deliveryNote = $this->resolveLocalRecord($header, $documentType);

        if (!$isInvoice && $deliveryNote?->getInvoicePiece() !== null) {
            ++$result['skipped'];

            return;
        }

        if (!$deliveryNote instanceof ErpDeliveryNote) {
            $sageKey = $isInvoice
                ? sprintf('sage:%d:invoice:%s', self::SAGE_DOCUMENT_DOMAIN, $piece)
                : sprintf('sage:%d:%d:%s', self::SAGE_DOCUMENT_DOMAIN, $documentType, $piece);
            $deliveryNote = new ErpDeliveryNote($sageKey, $piece);
            $this->entityManager->persist($deliveryNote);
            ++$result['discovered'];
        }

        try {
            $warnings = [];

            if ($this->hasSameExportedHeader($deliveryNote, $header, $documentType)) {
                ++$result['skipped'];

                return;
            }

            $lines = $this->extractList($this->sageClient->get('/Document/line', [
                'piece' => $piece,
                'domaine' => self::SAGE_DOCUMENT_DOMAIN,
                'type' => $documentType,
            ]));

            if ($lines === []) {
                throw new \RuntimeException(sprintf('Le document Sage %s ne contient aucune ligne exploitable.', $piece));
            }

            $clientId = trim((string) ($header['tiers'] ?? ''));
            $client = $this->loadClient($clientId, $warnings);
            $contacts = $this->loadOptionalList('/Contacts', ['client' => $clientId, 'limit' => 100], 'contacts', $warnings);
            $deliveries = $this->loadOptionalList('/Livraisons', ['client' => $clientId], 'adresses de livraison', $warnings);
            $payloadHash = $this->payloadHash([
                'sourceDocumentType' => $documentType,
                'header' => $header,
                'lines' => $lines,
            ]);
            $previousPayloadHash = $deliveryNote->getPayloadHash();

            $deliveryNote->refreshFromSage($header, $lines, $client, $contacts, $deliveries, $payloadHash, $documentType);

            if ($deliveryNote->getExportedPayloadHash() === $payloadHash && $deliveryNote->getHubspotOrderId() !== null) {
                ++$result['skipped'];
                $this->entityManager->flush();

                return;
            }

            if (!$isInvoice
                && $previousPayloadHash !== null
                && $previousPayloadHash !== $payloadHash
                && ($deliveryNote->getHubspotOrderId() !== null || $deliveryNote->getHubspotLineItemIds() !== [])
            ) {
                $deliveryNote->markChanged();
                ++$result['changed'];
                $this->entityManager->flush();

                return;
            }

            if (!$isInvoice && $deliveryNote->getExportedPayloadHash() !== null && $deliveryNote->getExportedPayloadHash() !== $payloadHash) {
                $deliveryNote->markChanged();
                ++$result['changed'];
                $this->entityManager->flush();

                return;
            }

            $deliveryNote->markProcessing();
            $this->entityManager->flush();

            $export = $this->exportDeliveryNote(
                $deliveryNote,
                $header,
                $lines,
                $client,
                $contacts,
                $deliveries,
                $warnings,
                $isInvoice,
            );
            $deliveryNote->markSent(
                $export['orderId'],
                $export['lineItemIds'],
                $payloadHash,
                $export['warnings'],
            );
            $this->entityManager->flush();

            foreach ($export['warnings'] as $warning) {
                if (str_starts_with($warning, 'Order HubSpot ')) {
                    continue;
                }

                $result['warnings'][] = $this->buildReportIssue($header, $deliveryNote, $piece, $warning, $documentType);
            }

            if ($export['updated']) {
                ++$result['updated'];
            } elseif ($export['existing']) {
                ++$result['existing'];
            } else {
                ++$result['sent'];
            }
        } catch (\Throwable $exception) {
            $deliveryNote->markFailed($exception->getMessage(), $warnings ?? []);
            $this->entityManager->persist($deliveryNote);
            $this->entityManager->flush();
            ++$result['failed'];
            $result['errors'][] = $this->buildReportIssue(
                $header,
                $deliveryNote,
                $piece,
                $exception->getMessage(),
                $documentType,
            );

            $this->logger->error('Sage document to HubSpot Order synchronization failed.', [
                'piece' => $piece,
                'documentType' => $documentType,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $header
     *
     * @return array<string, mixed>
     */
    private function buildReportIssue(
        array $header,
        ?ErpDeliveryNote $deliveryNote,
        string $piece,
        string $message,
        int $documentType,
    ): array {
        $freeFields = is_array($header['champsLibres'] ?? null) ? $header['champsLibres'] : [];
        $clientId = trim((string) ($header['tiers'] ?? $deliveryNote?->getClientId() ?? ''));
        $clientName = trim((string) ($deliveryNote?->getClientName() ?? $freeFields['nomtiers'] ?? ''));

        return [
            'piece' => $piece,
            'documentLabel' => in_array($documentType, self::SAGE_INVOICE_TYPES, true) ? 'Facture' : 'BL',
            'clientId' => $clientId,
            'clientName' => $clientName !== '' ? $clientName : $clientId,
            'hubspotOrderId' => $deliveryNote?->getHubspotOrderId(),
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null $client
     * @param list<array<string, mixed>> $contacts
     * @param list<array<string, mixed>> $deliveries
     * @param list<string> $warnings
     *
     * @return array{orderId: string, lineItemIds: list<string>, warnings: list<string>, existing: bool, updated: bool}
     */
    private function exportDeliveryNote(
        ErpDeliveryNote $deliveryNote,
        array $header,
        array $lines,
        ?array $client,
        array $contacts,
        array $deliveries,
        array $warnings,
        bool $isInvoice,
    ): array {
        $existingOrderId = $deliveryNote->getHubspotOrderId()
            ?? $this->findExistingOrderId($deliveryNote->getSageKey());

        if ($existingOrderId === null && $isInvoice) {
            $existingOrderId = $this->findExistingOrderIdByReference($header);
        }

        if ($existingOrderId !== null) {
            if ($isInvoice) {
                $ownerId = $this->resolveOwnerId((string) ($header['representant'] ?? ''), $warnings);
                $lineItemIds = $this->synchronizeExistingLineItems(
                    $deliveryNote,
                    $existingOrderId,
                    $lines,
                    $warnings,
                );
                $this->hubSpotClient->patch(
                    sprintf('/crm/objects/%s/orders/%s', self::API_VERSION, $existingOrderId),
                    ['properties' => $this->buildOrderProperties($deliveryNote, $header, $lines, $client, $contacts, $deliveries, $ownerId)],
                );

                $warnings[] = sprintf('Order HubSpot %s actualise avec la facture %s.', $existingOrderId, (string) ($header['piece'] ?? ''));

                return [
                    'orderId' => $existingOrderId,
                    'lineItemIds' => $lineItemIds,
                    'warnings' => array_values(array_unique($warnings)),
                    'existing' => true,
                    'updated' => true,
                ];
            }

            $warnings[] = sprintf('Order HubSpot %s deja existant, rattache au registre local.', $existingOrderId);

            return [
                'orderId' => $existingOrderId,
                'lineItemIds' => $deliveryNote->getHubspotLineItemIds(),
                'warnings' => $warnings,
                'existing' => true,
                'updated' => false,
            ];
        }

        $clientId = trim((string) ($header['tiers'] ?? ''));

        if ($clientId === '') {
            throw new \RuntimeException('Identifiant client Sage manquant sur le document.');
        }

        $companyId = $this->resolveCompanyId($clientId);
        $contactId = $this->resolveContactId($contacts, $deliveries, $warnings);
        $ownerId = $this->resolveOwnerId((string) ($header['representant'] ?? ''), $warnings);
        $lineItemIds = $deliveryNote->getHubspotLineItemIds();

        if (count($lineItemIds) > count($lines)) {
            throw new \RuntimeException('Le registre local contient plus de line items HubSpot que le document Sage.');
        }

        for ($position = count($lineItemIds); $position < count($lines); ++$position) {
            $line = $lines[$position];
            $properties = $this->buildLineItemProperties($line, $warnings);
            $response = $this->hubSpotClient->post(
                sprintf('/crm/objects/%s/line_items', self::API_VERSION),
                ['properties' => $properties, 'associations' => []],
            );
            $lineItemId = trim((string) ($response['id'] ?? ''));

            if ($lineItemId === '') {
                throw new \RuntimeException(sprintf('HubSpot n a retourne aucun identifiant pour la ligne %d du document.', $position + 1));
            }

            $deliveryNote->rememberHubspotLineItem($lineItemId);
            $this->entityManager->flush();
            $lineItemIds[] = $lineItemId;
        }

        $associations = [
            $this->association($companyId, self::ORDER_TO_PRIMARY_COMPANY_ASSOCIATION),
        ];

        if ($contactId !== null) {
            $associations[] = $this->association($contactId, self::ORDER_TO_BILLING_CONTACT_ASSOCIATION);
        }

        foreach ($lineItemIds as $lineItemId) {
            $associations[] = $this->association($lineItemId, self::ORDER_TO_LINE_ITEM_ASSOCIATION);
        }

        $response = $this->hubSpotClient->post(
            sprintf('/crm/objects/%s/orders', self::API_VERSION),
            [
                'properties' => $this->buildOrderProperties($deliveryNote, $header, $lines, $client, $contacts, $deliveries, $ownerId),
                'associations' => $associations,
            ],
        );
        $orderId = trim((string) ($response['id'] ?? ''));

        if ($orderId === '') {
            throw new \RuntimeException('HubSpot n a retourne aucun identifiant pour l Order cree.');
        }

        return [
            'orderId' => $orderId,
            'lineItemIds' => $lineItemIds,
            'warnings' => array_values(array_unique($warnings)),
            'existing' => false,
            'updated' => false,
        ];
    }

    /**
     * @param array<string, mixed> $header
     */
    private function findExistingOrderIdByReference(array $header): ?string
    {
        $reference = trim((string) ($header['reference'] ?? ''));

        if ($reference === '') {
            return null;
        }

        $response = $this->hubSpotClient->post(sprintf('/crm/objects/%s/orders/search', self::API_VERSION), [
            'limit' => 10,
            'properties' => ['order_reference', 'hs_total_price', 'hs_external_order_id'],
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'order_reference',
                    'operator' => 'EQ',
                    'value' => $reference,
                ]],
            ]],
        ]);
        $results = $this->extractList($response);

        if ($results === []) {
            return null;
        }

        $clientId = trim((string) ($header['tiers'] ?? ''));

        if ($clientId === '') {
            return null;
        }

        $companyId = $this->resolveCompanyId($clientId);
        $results = array_values(array_filter(
            $results,
            fn (array $order): bool => in_array(
                $companyId,
                $this->fetchOrderAssociationIds((string) ($order['id'] ?? ''), 'companies'),
                true,
            ),
        ));

        if ($results === []) {
            return null;
        }

        if (count($results) > 1) {
            $invoiceAmount = $this->numeric($header['montantTTC'] ?? null);
            $results = $invoiceAmount === null ? [] : array_values(array_filter(
                $results,
                function (array $order) use ($invoiceAmount): bool {
                    $properties = is_array($order['properties'] ?? null) ? $order['properties'] : [];
                    $orderAmount = $this->numeric($properties['hs_total_price'] ?? null);

                    return $orderAmount !== null && abs($orderAmount - $invoiceAmount) < 0.01;
                },
            ));

            if (count($results) !== 1) {
                throw new \RuntimeException(sprintf('Plusieurs Orders HubSpot correspondent a la reference Sage %s.', $reference));
            }
        }

        if ($results === []) {
            return null;
        }

        $id = trim((string) ($results[0]['id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param list<string> $warnings
     *
     * @return list<string>
     */
    private function synchronizeExistingLineItems(
        ErpDeliveryNote $deliveryNote,
        string $orderId,
        array $lines,
        array &$warnings,
    ): array {
        $lineItemIds = $deliveryNote->getHubspotLineItemIds();

        if ($lineItemIds === []) {
            $lineItemIds = $this->fetchOrderLineItemIds($orderId);
        }

        $synchronizedIds = [];

        foreach ($lines as $position => $line) {
            $properties = $this->buildLineItemProperties($line, $warnings);
            $lineItemId = $lineItemIds[$position] ?? null;

            if ($lineItemId !== null) {
                $this->hubSpotClient->patch(
                    sprintf('/crm/objects/%s/line_items/%s', self::API_VERSION, $lineItemId),
                    ['properties' => $properties],
                );
                $synchronizedIds[] = $lineItemId;

                continue;
            }

            $response = $this->hubSpotClient->post(
                sprintf('/crm/objects/%s/line_items', self::API_VERSION),
                ['properties' => $properties, 'associations' => []],
            );
            $lineItemId = trim((string) ($response['id'] ?? ''));

            if ($lineItemId === '') {
                throw new \RuntimeException(sprintf('HubSpot n a retourne aucun identifiant pour la ligne %d de la facture.', $position + 1));
            }

            $this->hubSpotClient->put(
                sprintf('/crm/objects/%s/orders/%s/associations/line_items/%s', self::API_VERSION, $orderId, $lineItemId),
                [[
                    'associationCategory' => 'HUBSPOT_DEFINED',
                    'associationTypeId' => self::ORDER_TO_LINE_ITEM_ASSOCIATION,
                ]],
            );
            $synchronizedIds[] = $lineItemId;
            $deliveryNote->replaceHubspotLineItems($synchronizedIds);
            $this->entityManager->flush();
        }

        foreach (array_slice($lineItemIds, count($lines)) as $obsoleteLineItemId) {
            $this->hubSpotClient->delete(sprintf('/crm/objects/%s/line_items/%s', self::API_VERSION, $obsoleteLineItemId));
        }

        $deliveryNote->replaceHubspotLineItems($synchronizedIds);
        $this->entityManager->flush();

        return $synchronizedIds;
    }

    /**
     * @return list<string>
     */
    private function fetchOrderLineItemIds(string $orderId): array
    {
        return $this->fetchOrderAssociationIds($orderId, 'line_items');
    }

    /**
     * @return list<string>
     */
    private function fetchOrderAssociationIds(string $orderId, string $associationType): array
    {
        if (trim($orderId) === '') {
            return [];
        }

        $response = $this->hubSpotClient->get(
            sprintf('/crm/objects/%s/orders/%s', self::API_VERSION, $orderId),
            ['associations' => [$associationType]],
        );
        $associations = is_array($response['associations'] ?? null) ? $response['associations'] : [];
        $association = is_array($associations[$associationType] ?? null) ? $associations[$associationType] : [];
        $results = is_array($association['results'] ?? null) ? $association['results'] : [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $association): string => is_array($association)
                ? trim((string) ($association['id'] ?? ($association['toObjectId'] ?? '')))
                : '',
            $results,
        ))));
    }

    private function findExistingOrderId(string $externalOrderId): ?string
    {
        $response = $this->hubSpotClient->post(sprintf('/crm/objects/%s/orders/search', self::API_VERSION), [
            'limit' => 2,
            'properties' => ['hs_external_order_id', 'hs_order_name'],
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'hs_external_order_id',
                    'operator' => 'EQ',
                    'value' => $externalOrderId,
                ]],
            ]],
        ]);
        $results = $this->extractList($response);

        if (count($results) > 1) {
            throw new \RuntimeException(sprintf('Plusieurs Orders HubSpot utilisent la cle externe %s.', $externalOrderId));
        }

        if ($results === []) {
            return null;
        }

        $id = trim((string) ($results[0]['id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    private function resolveCompanyId(string $clientId): string
    {
        if (isset($this->companyCache[$clientId])) {
            return $this->companyCache[$clientId];
        }

        $response = $this->hubSpotClient->post(sprintf('/crm/objects/%s/companies/search', self::API_VERSION), [
            'limit' => 2,
            'properties' => ['name', 'id_erp'],
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'id_erp',
                    'operator' => 'EQ',
                    'value' => $clientId,
                ]],
            ]],
        ]);
        $results = $this->extractList($response);

        if ($results === []) {
            throw new \RuntimeException(sprintf('Aucune societe HubSpot trouvee pour le client Sage %s.', $clientId));
        }

        if (count($results) > 1) {
            throw new \RuntimeException(sprintf('Plusieurs societes HubSpot correspondent au client Sage %s.', $clientId));
        }

        $companyId = trim((string) ($results[0]['id'] ?? ''));

        if ($companyId === '') {
            throw new \RuntimeException(sprintf('La societe HubSpot du client Sage %s ne contient aucun identifiant.', $clientId));
        }

        return $this->companyCache[$clientId] = $companyId;
    }

    /**
     * @param list<array<string, mixed>> $contacts
     * @param list<array<string, mixed>> $deliveries
     * @param list<string> $warnings
     */
    private function resolveContactId(array $contacts, array $deliveries, array &$warnings): ?string
    {
        $mainDelivery = $this->selectMainDelivery($deliveries);
        $deliveryContact = $this->normalizeText((string) ($mainDelivery['contact'] ?? ''));
        $deliveryEmail = mb_strtolower(trim((string) ($mainDelivery['email'] ?? '')));
        $preferred = [];

        foreach ($contacts as $contact) {
            $contactNames = [
                $this->normalizeText(trim((string) ($contact['prenom'] ?? '')).' '.trim((string) ($contact['nom'] ?? ''))),
                $this->normalizeText(trim((string) ($contact['nom'] ?? '')).' '.trim((string) ($contact['prenom'] ?? ''))),
            ];
            $contactEmail = mb_strtolower(trim((string) ($contact['email'] ?? '')));

            if (($deliveryContact !== '' && in_array($deliveryContact, $contactNames, true)) || ($deliveryEmail !== '' && $deliveryEmail === $contactEmail)) {
                $preferred[] = $contact;
            }
        }

        $candidates = $preferred !== [] ? $preferred : $contacts;
        $hubspotIds = array_values(array_unique(array_filter(array_map(
            static fn (array $contact): string => trim((string) ($contact['hubSpotID'] ?? '')),
            $candidates,
        ), static fn (string $id): bool => preg_match('/^\d+$/', $id) === 1)));

        if (count($hubspotIds) === 1) {
            try {
                $contact = $this->hubSpotClient->get(sprintf('/crm/objects/%s/contacts/%s', self::API_VERSION, $hubspotIds[0]), [
                    'properties' => ['email'],
                ]);

                return trim((string) ($contact['id'] ?? '')) ?: null;
            } catch (\Throwable $exception) {
                $warnings[] = sprintf('Contact HubSpot %s inaccessible: %s', $hubspotIds[0], $exception->getMessage());
            }
        } elseif (count($hubspotIds) > 1) {
            $warnings[] = 'Plusieurs contacts Sage possedent un identifiant HubSpot; aucun contact n a ete associe.';

            return null;
        }

        $emails = array_values(array_unique(array_filter(array_map(
            static fn (array $contact): string => mb_strtolower(trim((string) ($contact['email'] ?? ''))),
            $candidates,
        ), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));

        if (count($emails) !== 1) {
            if ($contacts !== []) {
                $warnings[] = 'Aucun contact HubSpot unique n a pu etre determine pour le document.';
            }

            return null;
        }

        $response = $this->hubSpotClient->post(sprintf('/crm/objects/%s/contacts/search', self::API_VERSION), [
            'limit' => 2,
            'properties' => ['email'],
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'email',
                    'operator' => 'EQ',
                    'value' => $emails[0],
                ]],
            ]],
        ]);
        $results = $this->extractList($response);

        if (count($results) === 1) {
            return trim((string) ($results[0]['id'] ?? '')) ?: null;
        }

        $warnings[] = sprintf('Le contact %s n a pas de correspondance HubSpot unique.', $emails[0]);

        return null;
    }

    /**
     * @param list<string> $warnings
     */
    private function resolveOwnerId(string $representative, array &$warnings): ?string
    {
        $normalizedRepresentative = $this->normalizeText($representative);

        if ($normalizedRepresentative === '') {
            return null;
        }

        if (array_key_exists($normalizedRepresentative, $this->ownerCache)) {
            return $this->ownerCache[$normalizedRepresentative];
        }

        $matches = array_values(array_filter(
            $this->commercialRepository->findActiveOrdered(),
            function (Commercial $commercial) use ($normalizedRepresentative): bool {
                return in_array($normalizedRepresentative, [
                    $this->normalizeText($commercial->getFullName()),
                    $this->normalizeText(trim((string) $commercial->getLastName()).' '.trim((string) $commercial->getFirstName())),
                    $this->normalizeText((string) $commercial->getEmail()),
                ], true);
            },
        ));

        if (count($matches) !== 1) {
            $warnings[] = sprintf('Commercial HubSpot non determine pour le representant Sage "%s".', $representative);

            return $this->ownerCache[$normalizedRepresentative] = null;
        }

        $email = trim((string) $matches[0]->getEmail());

        if ($email === '') {
            $warnings[] = sprintf('Le commercial %s ne possede pas d email exploitable.', $matches[0]->getFullName());

            return $this->ownerCache[$normalizedRepresentative] = null;
        }

        try {
            $response = $this->hubSpotClient->get('/crm/owners/2026-03', ['email' => $email, 'limit' => 2]);
            $owners = $this->extractList($response);

            if (count($owners) === 1) {
                $ownerId = trim((string) ($owners[0]['id'] ?? ''));

                if ($ownerId !== '') {
                    return $this->ownerCache[$normalizedRepresentative] = $ownerId;
                }
            }
        } catch (\Throwable $exception) {
            $warnings[] = sprintf('Recherche du owner HubSpot impossible pour %s: %s', $email, $exception->getMessage());

            return $this->ownerCache[$normalizedRepresentative] = null;
        }

        $warnings[] = sprintf('Aucun owner HubSpot unique trouve pour %s.', $email);

        return $this->ownerCache[$normalizedRepresentative] = null;
    }

    /**
     * @param array<string, mixed> $line
     * @param list<string> $warnings
     *
     * @return array<string, mixed>
     */
    private function buildLineItemProperties(array $line, array &$warnings): array
    {
        $reference = trim((string) ($line['referenceArticle'] ?? ($line['reference'] ?? '')));
        $name = trim((string) ($line['designationArticle'] ?? ''));
        $quantity = $this->numeric($line['qteArticle'] ?? null);

        if ($quantity === null || abs($quantity) < 0.000001) {
            throw new \RuntimeException(sprintf('Quantite invalide pour la ligne Sage %s.', $reference !== '' ? $reference : 'sans reference'));
        }

        $listedUnitPrice = $this->numeric($line['prixHTArticle'] ?? null) ?? 0.0;
        $lineAmount = $this->numeric($line['montantArticle'] ?? null);
        $netUnitPrice = $lineAmount !== null ? $lineAmount / $quantity : $listedUnitPrice;
        $properties = [
            'name' => $name !== '' ? $name : ($reference !== '' ? $reference : 'Ligne Sage'),
            'quantity' => $quantity,
            'price' => round($netUnitPrice, 6),
            'hs_line_item_currency_code' => 'EUR',
        ];

        if ($reference !== '') {
            $properties['hs_sku'] = $reference;
            $product = $this->findProductBySku($reference);

            if ($product !== null) {
                $properties['hs_product_id'] = (string) $product['id'];
            } else {
                $warnings[] = sprintf('Produit HubSpot introuvable pour le SKU %s; line item autonome cree.', $reference);
            }
        }

        $unitDiscount = $listedUnitPrice - $netUnitPrice;

        if ($unitDiscount > 0.000001) {
            $properties['discount'] = round($unitDiscount, 6);
            $properties['price'] = $listedUnitPrice;
        }

        $freeFields = isset($line['champsLibres']) && is_array($line['champsLibres']) ? $line['champsLibres'] : [];
        $comment = trim((string) ($freeFields['Commentaire'] ?? ''));

        if ($comment !== '') {
            $properties['description'] = $comment;
        }

        $vatRate = $this->normalizeVatRate($line['tauxTva'] ?? null);

        if ($vatRate !== null && isset($this->taxRateGroupIds[$vatRate])) {
            $properties['hs_tax_rate_group_id'] = (string) $this->taxRateGroupIds[$vatRate];
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null $client
     * @param list<array<string, mixed>> $contacts
     * @param list<array<string, mixed>> $deliveries
     *
     * @return array<string, mixed>
     */
    private function buildOrderProperties(
        ErpDeliveryNote $deliveryNote,
        array $header,
        array $lines,
        ?array $client,
        array $contacts,
        array $deliveries,
        ?string $ownerId,
    ): array {
        $clientName = trim((string) ($client['intitule'] ?? $deliveryNote->getClientName() ?? $deliveryNote->getClientId() ?? ''));
        $freeFields = isset($header['champsLibres']) && is_array($header['champsLibres']) ? $header['champsLibres'] : [];
        $amountExcludingTax = $this->numeric($header['montantHT'] ?? null) ?? 0.0;
        $amountIncludingTax = $this->numeric($header['montantTTC'] ?? null) ?? 0.0;
        $documentName = match (true) {
            $deliveryNote->getInvoicePiece() === null => sprintf('BL %s', $deliveryNote->getPiece()),
            $deliveryNote->getInvoicePiece() === $deliveryNote->getPiece() => sprintf('Facture %s', $deliveryNote->getPiece()),
            default => sprintf('BL %s / Facture %s', $deliveryNote->getPiece(), $deliveryNote->getInvoicePiece()),
        };
        $properties = [
            'hs_order_name' => sprintf('%s%s', $documentName, $clientName !== '' ? ' - '.$clientName : ''),
            'hs_external_order_id' => $deliveryNote->getSageKey(),
            'hs_subtotal_price' => $amountExcludingTax,
            'hs_tax' => round($amountIncludingTax - $amountExcludingTax, 6),
            'hs_total_price' => $amountIncludingTax,
            'hs_currency_code' => 'EUR',
            'hs_external_order_status' => trim((string) ($header['statut'] ?? '')),
            'hs_fulfillment_status' => trim((string) ($header['statut'] ?? '')),
            'hs_source_store' => 'Sage 100',
            'hs_order_type' => 'initial',
            'hs_pipeline' => $this->orderPipeline,
            'hs_pipeline_stage' => $this->orderStage,
            'order_reference' => trim((string) ($header['reference'] ?? '')) ?: $deliveryNote->getPiece(),
        ];

        if ($deliveryNote->getDocumentDate() !== null) {
            $properties['hs_external_created_date'] = $deliveryNote->getDocumentDate()->format(\DateTimeInterface::ATOM);
        }

        if ($deliveryNote->getDeliveryDate() !== null) {
            $properties['hs_processed_date'] = $deliveryNote->getDeliveryDate()->format(\DateTimeInterface::ATOM);
        }

        $remainingAmount = $this->numeric($header['resteAPayer'] ?? null);

        if ($remainingAmount !== null) {
            $properties['order_paid'] = abs($remainingAmount) < 0.01 ? 'Yes' : 'No';
        }

        $incoterm = $this->normalizeIncoterm($header['conditionLivraison'] ?? null);

        if ($incoterm !== null) {
            $properties['incoterm'] = $incoterm;
        }

        $paymentTerm = $this->normalizePaymentTerm($header['paiement'] ?? null);

        if ($paymentTerm !== null) {
            $properties['payment_term'] = $paymentTerm;
        }

        $shippingCost = $this->numeric($freeFields['Frais de port TTC'] ?? null);

        if ($shippingCost !== null) {
            $properties['hs_shipping_cost'] = $shippingCost;
        }

        $totalWeight = 0.0;

        foreach ($lines as $line) {
            $totalWeight += $this->numeric($line['poidsBrut'] ?? null) ?? 0.0;
        }

        if ($totalWeight > 0.0) {
            $properties['hs_total_weight'] = (string) round($totalWeight, 6);
        }

        $noteParts = array_values(array_filter([
            trim((string) ($freeFields['Instruction de livraison'] ?? '')),
            trim((string) ($freeFields['Commentaires'] ?? '')),
            trim((string) ($header['livraison'] ?? '')),
        ]));

        if ($noteParts !== []) {
            $properties['hs_order_note'] = implode("\n", array_unique($noteParts));
        }

        if ($ownerId !== null) {
            $properties['hubspot_owner_id'] = $ownerId;
        }

        $this->appendBillingAddress($properties, $client, $contacts);
        $this->appendShippingAddress($properties, $this->selectMainDelivery($deliveries));

        return array_filter($properties, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed>|null $client
     * @param list<array<string, mixed>> $contacts
     */
    private function appendBillingAddress(array &$properties, ?array $client, array $contacts): void
    {
        if ($client === null) {
            return;
        }

        $contact = count($contacts) === 1 ? $contacts[0] : null;
        $street = $this->joinAddress($client['adresse'] ?? null, $client['complementAdresse'] ?? null);
        $mapping = [
            'hs_billing_address_name' => $client['intitule'] ?? null,
            'hs_billing_address_street' => $street,
            'hs_billing_address_city' => $client['ville'] ?? null,
            'hs_billing_address_state' => $client['region'] ?? null,
            'hs_billing_address_postal_code' => $client['codePostal'] ?? null,
            'hs_billing_address_country' => $client['pays'] ?? null,
            'hs_billing_address_email' => $contact['email'] ?? ($client['email'] ?? null),
            'hs_billing_address_firstname' => $contact['prenom'] ?? null,
            'hs_billing_address_lastname' => $contact['nom'] ?? null,
            'hs_billing_address_phone' => $contact['telephone'] ?? ($client['telephone'] ?? null),
        ];

        foreach ($mapping as $property => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $properties[$property] = $value;
            }
        }
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed>|null $delivery
     */
    private function appendShippingAddress(array &$properties, ?array $delivery): void
    {
        if ($delivery === null) {
            return;
        }

        $mapping = [
            'hs_shipping_address_name' => $delivery['intitule'] ?? null,
            'hs_shipping_address_street' => $this->joinAddress($delivery['adresse'] ?? null, $delivery['complement'] ?? null),
            'hs_shipping_address_city' => $delivery['ville'] ?? null,
            'hs_shipping_address_state' => $delivery['region'] ?? null,
            'hs_shipping_address_postal_code' => $delivery['codePostal'] ?? null,
            'hs_shipping_address_country' => $delivery['pays'] ?? null,
            'hs_shipping_address_phone' => $delivery['telephone'] ?? null,
        ];

        foreach ($mapping as $property => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $properties[$property] = $value;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findProductBySku(string $sku): ?array
    {
        if (array_key_exists($sku, $this->productCache)) {
            return $this->productCache[$sku];
        }

        $response = $this->hubSpotClient->post(sprintf('/crm/objects/%s/products/search', self::API_VERSION), [
            'limit' => 2,
            'properties' => ['name', 'hs_sku'],
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'hs_sku',
                    'operator' => 'EQ',
                    'value' => $sku,
                ]],
            ]],
        ]);
        $results = $this->extractList($response);

        return $this->productCache[$sku] = count($results) === 1 ? $results[0] : null;
    }

    /**
     * @param list<string> $warnings
     *
     * @return array<string, mixed>|null
     */
    private function loadClient(string $clientId, array &$warnings): ?array
    {
        if ($clientId === '') {
            return null;
        }

        if (array_key_exists($clientId, $this->clientCache)) {
            return $this->clientCache[$clientId];
        }

        try {
            $clients = $this->extractList($this->sageClient->get('/Clients', [
                'reference' => $clientId,
                'limit' => 2,
            ]));

            foreach ($clients as $client) {
                if (trim((string) ($client['reference'] ?? '')) === $clientId) {
                    return $this->clientCache[$clientId] = $client;
                }
            }
        } catch (\Throwable $exception) {
            $warnings[] = sprintf('Fiche client Sage non chargee: %s', $exception->getMessage());
        }

        return $this->clientCache[$clientId] = null;
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $warnings
     *
     * @return list<array<string, mixed>>
     */
    private function loadOptionalList(string $path, array $query, string $label, array &$warnings): array
    {
        if (trim((string) ($query['client'] ?? '')) === '') {
            return [];
        }

        $cacheKey = $path.':'.trim((string) $query['client']);

        if (array_key_exists($cacheKey, $this->sageListCache)) {
            return $this->sageListCache[$cacheKey];
        }

        try {
            return $this->sageListCache[$cacheKey] = $this->extractList($this->sageClient->get($path, $query));
        } catch (\Throwable $exception) {
            $warnings[] = sprintf('Chargement des %s Sage impossible: %s', $label, $exception->getMessage());

            return $this->sageListCache[$cacheKey] = [];
        }
    }

    /**
     * @param list<array<string, mixed>> $deliveries
     *
     * @return array<string, mixed>|null
     */
    private function selectMainDelivery(array $deliveries): ?array
    {
        foreach ($deliveries as $delivery) {
            if (filter_var($delivery['livraisonPrincipal'] ?? false, FILTER_VALIDATE_BOOL)) {
                return $delivery;
            }

            if ((int) ($delivery['livraisonPrincipal'] ?? 0) === 1) {
                return $delivery;
            }
        }

        return $deliveries[0] ?? null;
    }

    /**
     * @return array{to: array{id: string}, types: list<array{associationCategory: string, associationTypeId: int}>}
     */
    private function association(string $objectId, int $typeId): array
    {
        return [
            'to' => ['id' => $objectId],
            'types' => [[
                'associationCategory' => 'HUBSPOT_DEFINED',
                'associationTypeId' => $typeId,
            ]],
        ];
    }

    /**
     * @param array<string, mixed>|list<mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function extractList(array $response): array
    {
        $items = isset($response['results']) && is_array($response['results']) ? $response['results'] : $response;

        return array_values(array_filter($items, 'is_array'));
    }

    private function payloadHash(array $payload): string
    {
        $normalized = $this->sortAssociativeArrays($payload);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sortAssociativeArrays(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortAssociativeArrays($item);
        }

        return $value;
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($transliterated) ? $transliterated : $value;

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private function normalizeDocumentReference(string $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    private function normalizeVatRate(mixed $value): ?string
    {
        $rate = $this->numeric($value);

        if ($rate === null) {
            return null;
        }

        if ($rate > 0.0 && $rate < 1.0) {
            $rate *= 100.0;
        }

        return match (true) {
            abs($rate - 5.5) < 0.001 => '5.5',
            abs($rate - 20.0) < 0.001 => '20',
            default => null,
        };
    }

    private function normalizeIncoterm(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        if (preg_match('/^(DDP|EXW|DAP|FCA|CPT|CIP|DPU)\b/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function normalizePaymentTerm(mixed $value): ?string
    {
        $value = $this->normalizeText((string) $value);

        return match (true) {
            $value === '' => null,
            str_contains($value, 'comptant'), str_contains($value, 'cash') => 'Cash payment',
            preg_match('/\b30\b/', $value) === 1 => '30 days',
            preg_match('/\b60\b/', $value) === 1 => '60 days',
            preg_match('/\b90\b/', $value) === 1 => '90 days',
            default => null,
        };
    }

    private function joinAddress(mixed $line1, mixed $line2): string
    {
        return implode("\n", array_values(array_filter([
            trim((string) $line1),
            trim((string) $line2),
        ])));
    }

    private function numeric(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], trim($value));
        }

        return is_numeric($value) ? (float) $value : null;
    }
}

<?php

namespace App\Service\Erp;

use App\Entity\Commercial;
use App\Entity\ErpOrderExport;
use App\Repository\ErpOrderExportRepository;
use App\Repository\HubspotCompanyRepository;
use App\Service\HubSpot\HubSpotClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class ErpOrderExportService
{
    private const DELIVERY_INSTRUCTION_MAX_LENGTH = 69;
    private const SAGE_ORDER_TIMEOUT_SECONDS = 120;
    private const SAGE_ORDER_MAX_DURATION_SECONDS = 180;
    private const LINE_VALUE_TOLERANCE = 0.005001;

    private const INCOTERM_TO_SAGE_DELIVERY_CONDITION = [
        'DDP' => "DDP - Rendu droits acquitt\u{00E9}s",
        'EXW' => "EXW - A l'usine",
        'DAP' => 'DAP - Rendu au lieu de dest.',
        'FCA' => 'FCA - Franco transporteur',
    ];

    private const PAYMENT_TERM_TO_SAGE_MODEL = [
        'cash payment' => 'Comptant',
        '30 days' => 'A 30 jours net',
        '60 days' => 'A 60 jours net',
        '90 days' => 'A 90 jours net',
    ];

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly ErpOrderExportRepository $erpOrderExportRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SageClient $sageClient,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendDealToErp(string $hubspotDealId, ?string $hubspotEventId = null): array
    {
        $hubspotDealId = trim($hubspotDealId);
        $hubspotEventId = trim((string) $hubspotEventId);

        if ($hubspotDealId === '') {
            throw new \RuntimeException('Identifiant deal HubSpot manquant pour l export ERP.');
        }

        $lockResource = $hubspotEventId !== ''
            ? sprintf('erp-order-export-event-%s', $hubspotEventId)
            : sprintf('erp-order-export-deal-%s', $hubspotDealId);
        $lock = $this->lockFactory->createLock($lockResource, 300);

        if (!$lock->acquire()) {
            return [
                'skipped' => true,
                'reason' => 'event_already_processing',
                'dealHubspotId' => $hubspotDealId,
                'hubspotEventId' => $hubspotEventId !== '' ? $hubspotEventId : null,
            ];
        }

        try {
            $reservation = $this->reserveExport($hubspotDealId, $hubspotEventId);

            if (($reservation['skipped'] ?? false) === true) {
                return $reservation;
            }

            $export = $reservation['export'];

            try {
                return $this->sendReservedDealToErp($hubspotDealId, $export);
            } catch (\Throwable $exception) {
                if ($export instanceof ErpOrderExport && !$export->isSent()) {
                    $this->markExportFailed($export, $exception->getMessage());
                }

                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{skipped: false, export: ?ErpOrderExport}|array{skipped: true, reason: string, payload: array<string, mixed>, response: ?array, dealHubspotId: string, hubspotEventId: ?string}
     */
    private function reserveExport(string $hubspotDealId, string $hubspotEventId): array
    {
        if ($hubspotEventId === '') {
            return [
                'skipped' => false,
                'export' => null,
            ];
        }

        $export = $this->erpOrderExportRepository->findOneByHubspotEventId($hubspotEventId);

        if ($export instanceof ErpOrderExport) {
            return $this->skippedExportResult($export, 'event_already_processed', $hubspotDealId);
        }

        $export = new ErpOrderExport($hubspotEventId, $hubspotDealId);

        $this->entityManager->persist($export);
        $this->entityManager->flush();

        return [
            'skipped' => false,
            'export' => $export,
        ];
    }

    /**
     * @return array{skipped: true, reason: string, payload: array<string, mixed>, response: ?array, dealHubspotId: string, hubspotEventId: ?string}
     */
    private function skippedExportResult(ErpOrderExport $export, string $reason, string $hubspotDealId): array
    {
        return [
            'skipped' => true,
            'reason' => $reason,
            'payload' => $export->getPayload() ?? [],
            'response' => $export->getErpResponse(),
            'dealHubspotId' => $hubspotDealId,
            'hubspotEventId' => $export->getHubspotEventId(),
        ];
    }

    private function sendReservedDealToErp(string $hubspotDealId, ?ErpOrderExport $export): array
    {
        $dealData = $this->hubSpotClient->getObject('deals', $hubspotDealId, [
            'properties' => [
                'dealname',
                'dealstage',
                'createdate',
                'closedate',
                'hubspot_owner_id',
                'order_reference',
                'incoterm',
                'expected_delivery_date',
                'delivery_information',
                'subcontracting',
                'payment_term',
            ],
            'associations' => ['companies', 'line_item'],
        ]);

        $companyId = $this->extractFirstAssociatedCompanyId($dealData);
        $numClient = $this->resolveNumClient($companyId);

        if ($numClient === null || trim($numClient) === '') {
            throw new \RuntimeException(sprintf('Aucun id_erp exploitable n a ete trouve pour le deal HubSpot %s.', $hubspotDealId));
        }

        $owner = $this->resolveOwnerData((string) (($dealData['properties']['hubspot_owner_id'] ?? null) ?: ''));
        $order = $this->buildOrderPayload($dealData, $numClient, $owner);

        $this->logger->info('ERP order payload prepared', [
            'dealHubspotId' => $hubspotDealId,
            'referenceCommande' => $order['referenceCommande'] ?? null,
            'numClient' => $order['numClient'] ?? null,
            'action' => 'create',
            'modeExpedition' => $order['modeExpedition'] ?? null,
            'condLivraison' => $order['condLivraison'] ?? null,
            'modeleReglement' => $order['modeleReglement'] ?? null,
        ]);

        try {
            $sageResult = $this->sendOrderToSage($order);
            $response = $sageResult['response'];
        } catch (\Throwable $exception) {
            $wrappedException = new \RuntimeException(sprintf(
                "%s\nERP order payload: %s",
                $exception->getMessage(),
                json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ), 0, $exception);

            throw $wrappedException;
        }

        if ($export instanceof ErpOrderExport) {
            $export->markSent($order, $response);
            $this->entityManager->flush();
        }

        return [
            'skipped' => false,
            'action' => $sageResult['action'],
            'payload' => $order,
            'response' => $response,
            'dealHubspotId' => $hubspotDealId,
            'commercialEmail' => $owner['email'] !== '' ? $owner['email'] : null,
        ];
    }

    public function resolveDealOwnerEmail(string $hubspotDealId): ?string
    {
        $hubspotDealId = trim($hubspotDealId);

        if ($hubspotDealId === '') {
            return null;
        }

        try {
            $dealData = $this->hubSpotClient->getObject('deals', $hubspotDealId, [
                'properties' => ['hubspot_owner_id'],
            ]);
            $owner = $this->resolveOwnerData((string) (($dealData['properties']['hubspot_owner_id'] ?? null) ?: ''));

            return $owner['email'] !== '' ? $owner['email'] : null;
        } catch (\Throwable $exception) {
            $this->logger->warning('HubSpot deal owner email lookup failed', [
                'dealHubspotId' => $hubspotDealId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array{action: string, response: array<string, mixed>}
     */
    private function sendOrderToSage(array $order): array
    {
        $existingOrder = $this->findExistingSageOrder($order);

        if ($existingOrder !== null) {
            if (!$this->hasExpectedSageLines($existingOrder, $order['orderLines'] ?? [])) {
                throw new \RuntimeException(sprintf(
                    'Le bon de commande Sage %s existe pour la reference %s, mais ses lignes ne correspondent pas au deal HubSpot.',
                    (string) ($existingOrder['piece'] ?? ''),
                    (string) ($order['referenceCommande'] ?? '')
                ));
            }

            return [
                'action' => 'existing',
                'response' => $existingOrder,
            ];
        }

        try {
            $response = $this->sageClient->post('/order', $order, [], [
                'timeout' => self::SAGE_ORDER_TIMEOUT_SECONDS,
                'max_duration' => self::SAGE_ORDER_MAX_DURATION_SECONDS,
            ]);
        } catch (TransportExceptionInterface $exception) {
            $reconciledOrder = $this->findExistingSageOrder($order);

            if ($reconciledOrder === null || !$this->hasExpectedSageLines($reconciledOrder, $order['orderLines'] ?? [])) {
                throw $exception;
            }

            $this->logger->warning('ERP order reconciled after Sage transport error', [
                'referenceCommande' => $order['referenceCommande'] ?? null,
                'numClient' => $order['numClient'] ?? null,
                'piece' => $reconciledOrder['piece'] ?? null,
                'transportError' => $exception->getMessage(),
            ]);

            return [
                'action' => 'reconciled',
                'response' => $reconciledOrder,
            ];
        }

        return [
            'action' => 'create',
            'response' => $response,
        ];
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>|null
     */
    private function findExistingSageOrder(array $order): ?array
    {
        $reference = trim((string) ($order['referenceCommande'] ?? ''));
        $numClient = trim((string) ($order['numClient'] ?? ''));
        $dateCommande = $this->normalizeErpDate($order['dateCommande'] ?? null);

        if ($reference === '' || $numClient === '' || $dateCommande === null) {
            return null;
        }

        try {
            $response = $this->sageClient->get('/Document/header', [
                'domaine' => 0,
                'type' => 1,
                'tiers' => $numClient,
                'dateDebut' => $dateCommande,
                'dateFin' => (new \DateTimeImmutable($dateCommande))->modify('+1 day')->format('Y-m-d'),
            ]);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Erreur API Sage [404]')) {
                return null;
            }

            throw $exception;
        }

        $headers = $this->extractSageList($response);

        foreach ($headers as $header) {
            if (trim((string) ($header['reference'] ?? '')) === $reference
                && trim((string) ($header['tiers'] ?? '')) === $numClient
                && trim((string) ($header['piece'] ?? '')) !== ''
            ) {
                return $header;
            }
        }

        $legacyMatches = [];

        foreach ($headers as $header) {
            if (trim((string) ($header['reference'] ?? '')) !== ''
                || trim((string) ($header['tiers'] ?? '')) !== $numClient
                || trim((string) ($header['piece'] ?? '')) === ''
            ) {
                continue;
            }

            if ($this->hasExpectedSageLines($header, $order['orderLines'] ?? [])) {
                $legacyMatches[] = $header;
            }
        }

        if (count($legacyMatches) === 1) {
            $this->logger->warning('ERP order matched by lines because Sage reference is empty', [
                'referenceCommande' => $reference,
                'numClient' => $numClient,
                'piece' => $legacyMatches[0]['piece'] ?? null,
            ]);

            return $legacyMatches[0];
        }

        if (count($legacyMatches) > 1) {
            throw new \RuntimeException(sprintf(
                'Plusieurs bons de commande Sage sans reference correspondent au deal HubSpot pour le client %s.',
                $numClient
            ));
        }

        return null;
    }

    /**
     * @param array<string, mixed>       $sageOrder
     * @param list<array<string, mixed>> $expectedLines
     */
    private function hasExpectedSageLines(array $sageOrder, array $expectedLines): bool
    {
        $piece = trim((string) ($sageOrder['piece'] ?? ''));

        if ($piece === '' || $expectedLines === []) {
            return false;
        }

        try {
            $response = $this->sageClient->get('/Document/line', [
                'piece' => $piece,
                'domaine' => 0,
                'type' => 1,
            ]);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Erreur API Sage [404]')) {
                return false;
            }

            throw $exception;
        }

        $actualByReference = [];

        foreach ($this->extractSageList($response) as $line) {
            $reference = trim((string) ($line['referenceArticle'] ?? ''));

            if ($reference === '') {
                continue;
            }

            $actualByReference[$reference] ??= ['quantity' => 0.0, 'prices' => []];
            $actualByReference[$reference]['quantity'] += (float) ($line['qteArticle'] ?? 0);
            $actualByReference[$reference]['prices'][] = (float) ($line['prixHTArticle'] ?? 0);
        }

        $expectedByReference = [];

        foreach ($expectedLines as $line) {
            $reference = trim((string) ($line['reference'] ?? ''));

            if ($reference === '') {
                return false;
            }

            $expectedByReference[$reference] ??= ['quantity' => 0.0, 'price' => (float) ($line['prixHT'] ?? 0)];
            $expectedByReference[$reference]['quantity'] += (float) ($line['quantite'] ?? 0);
        }

        foreach ($expectedByReference as $reference => $expected) {
            $actual = $actualByReference[$reference] ?? null;

            if ($actual === null || abs($actual['quantity'] - $expected['quantity']) > self::LINE_VALUE_TOLERANCE) {
                return false;
            }

            foreach ($actual['prices'] as $actualPrice) {
                if (abs($actualPrice - $expected['price']) > self::LINE_VALUE_TOLERANCE) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function extractSageList(array $response): array
    {
        $items = isset($response['results']) && is_array($response['results']) ? $response['results'] : $response;

        if (!array_is_list($items) && isset($items['piece'])) {
            $items = [$items];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private function markExportFailed(ErpOrderExport $export, string $errorMessage): void
    {
        try {
            $export->markFailed($errorMessage);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->warning('ERP order export failure tracking failed', [
                'dealHubspotId' => $export->getHubspotDealId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function extractFirstAssociatedCompanyId(array $dealData): ?string
    {
        $results = $dealData['associations']['companies']['results'] ?? null;

        if (!is_array($results) || $results === []) {
            return null;
        }

        $first = $results[0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        $companyId = trim((string) ($first['id'] ?? ''));

        return $companyId !== '' ? $companyId : null;
    }

    private function resolveNumClient(?string $companyId): ?string
    {
        if ($companyId === null || trim($companyId) === '') {
            return null;
        }

        try {
            $company = $this->hubSpotClient->getObject('companies', $companyId, [
                'properties' => ['id_erp'],
            ]);
            $idErp = trim((string) (($company['properties']['id_erp'] ?? null) ?: ''));

            if ($idErp !== '') {
                return $idErp;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('HubSpot company id_erp lookup failed', [
                'companyHubspotId' => $companyId,
                'message' => $exception->getMessage(),
            ]);
        }

        $localCompany = $this->hubspotCompanyRepository->findOneByHubspotId($companyId);

        return $localCompany?->getIdErp();
    }

    /**
     * @return array{firstname: string, lastname: string, email: string}
     */
    private function resolveOwnerData(string $ownerId): array
    {
        if ($ownerId !== '') {
            try {
                $owner = $this->hubSpotClient->get(sprintf('/crm/v3/owners/%s', $ownerId));

                $email = trim((string) (($owner['email'] ?? null) ?: ''));

                return [
                    'firstname' => trim((string) (($owner['firstName'] ?? null) ?: '')),
                    'lastname' => trim((string) (($owner['lastName'] ?? null) ?: '')),
                    'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
                ];
            } catch (\Throwable $exception) {
                $this->logger->warning('HubSpot owner lookup failed', [
                    'ownerId' => $ownerId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'firstname' => '',
            'lastname' => '',
            'email' => '',
        ];
    }

    /**
     * @param array<string, mixed> $dealData
     * @param array{firstname: string, lastname: string, email: string} $owner
     *
     * @return array<string, mixed>
     */
    private function buildOrderPayload(array $dealData, string $numClient, array $owner): array
    {
        $properties = isset($dealData['properties']) && is_array($dealData['properties']) ? $dealData['properties'] : [];
        $dateCommande = $this->normalizeErpDate($properties['createdate'] ?? null) ?? (new \DateTimeImmutable())->format('Y-m-d');
        $dateLivraison = $this->normalizeIsoDate($properties['expected_delivery_date'] ?? null)
            ?? $this->normalizeIsoDate($properties['closedate'] ?? null)
            ?? $dateCommande;
        $ownerFirstName = $owner['firstname'];
        $ownerName = $owner['lastname'];

        $order = [
            'numClient' => $numClient,
            'dateCommande' => $dateCommande,
            'dateLivraison' => $dateLivraison,
            'referenceCommande' => $this->resolveOrderReference($properties['order_reference'] ?? null, (string) ($dealData['id'] ?? '')),
            'statut' => 'Saisi',
            'modeExpedition' => 'Standard',
            'ownerFirstName' => $ownerFirstName,
            'ownerName' => $ownerName,
            'instructionDeLivraison' => mb_substr(
                trim((string) (($properties['delivery_information'] ?? null) ?: '')),
                0,
                self::DELIVERY_INSTRUCTION_MAX_LENGTH
            ),
            'orderLines' => $this->buildOrderLines($dealData, (string) ($dealData['id'] ?? '')),
        ];

        $deliveryCondition = $this->normalizeIncotermForSage($properties['incoterm'] ?? null);

        if ($deliveryCondition !== null) {
            $order['condLivraison'] = $deliveryCondition;
        }

        $subcontracting = $this->normalizeSubcontractingForSage($properties['subcontracting'] ?? null);

        if ($subcontracting !== null) {
            $order['champsLibres'] = [
                'Sous-traitance' => $subcontracting,
            ];
        }

        $paymentModel = $this->normalizePaymentTermForSage($properties['payment_term'] ?? null);

        if ($paymentModel !== null) {
            $order['modeleReglement'] = $paymentModel;
        }

        return $order;
    }

    private function normalizeIncotermForSage(mixed $value): ?string
    {
        $incoterm = strtoupper(trim((string) $value));

        if ($incoterm === '') {
            return null;
        }

        return self::INCOTERM_TO_SAGE_DELIVERY_CONDITION[$incoterm] ?? $incoterm;
    }

    private function normalizeSubcontractingForSage(mixed $value): ?string
    {
        $subcontracting = mb_strtolower(trim((string) $value));

        return match ($subcontracting) {
            '' => null,
            'yes', 'true', '1', 'on' => 'OUI',
            'no', 'false', '0', 'off' => 'NON',
            default => trim((string) $value),
        };
    }

    private function normalizePaymentTermForSage(mixed $value): ?string
    {
        $paymentTerm = mb_strtolower(trim((string) $value));

        if ($paymentTerm === '') {
            return null;
        }

        return self::PAYMENT_TERM_TO_SAGE_MODEL[$paymentTerm] ?? trim((string) $value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOrderLines(array $dealData, string $hubspotDealId): array
    {
        $lineItemIds = $this->extractAssociatedLineItemIds($dealData, $hubspotDealId);

        if ($lineItemIds === []) {
            throw new \RuntimeException('Le deal HubSpot ne contient aucun line item associe.');
        }

        $lines = [];

        foreach ($lineItemIds as $lineItemId) {
            $lineItem = $this->hubSpotClient->getObject('line_items', $lineItemId, [
                'properties' => ['hs_sku', 'name', 'price', 'quantity'],
            ]);
            $properties = isset($lineItem['properties']) && is_array($lineItem['properties']) ? $lineItem['properties'] : [];
            $quantity = (float) (($properties['quantity'] ?? null) ?: 0);

            $lines[] = [
                'reference' => trim((string) (($properties['hs_sku'] ?? null) ?: '')),
                'designation' => trim((string) (($properties['name'] ?? null) ?: (($properties['hs_sku'] ?? null) ?: ''))),
                'prixHT' => (float) (($properties['price'] ?? null) ?: 0),
                'quantite' => $quantity,
                'quantitePreparee' => $quantity,
            ];
        }

        if ($lines === []) {
            throw new \RuntimeException('Le deal HubSpot ne contient aucune ligne exploitable.');
        }

        return $lines;
    }

    private function normalizeIsoDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0)->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveOrderReference(mixed $configuredReference, string $hubspotDealId): string
    {
        $configuredReference = trim((string) $configuredReference);

        if ($configuredReference !== '') {
            return $configuredReference;
        }

        $normalizedDealId = preg_replace('/[^A-Za-z0-9]/', '', $hubspotDealId) ?? '';

        if ($normalizedDealId !== '' && strlen($normalizedDealId) <= 15) {
            return 'HS'.strtoupper($normalizedDealId);
        }

        return 'HS'.strtoupper(substr(hash('sha256', $hubspotDealId), 0, 15));
    }

    private function normalizeErpDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $dealData
     *
     * @return array<int, string>
     */
    private function extractAssociatedLineItemIds(array $dealData, string $hubspotDealId): array
    {
        $results = $dealData['associations']['line_item']['results']
            ?? $dealData['associations']['line_items']['results']
            ?? null;

        if (!is_array($results) || $results === []) {
            $results = $this->fetchDealLineItemAssociations($hubspotDealId);
        }

        $lineItemIds = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $lineItemId = trim((string) ($result['id'] ?? ''));

            if ($lineItemId !== '') {
                $lineItemIds[] = $lineItemId;
            }
        }

        return array_values(array_unique($lineItemIds));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDealLineItemAssociations(string $hubspotDealId): array
    {
        if (trim($hubspotDealId) === '') {
            return [];
        }

        $paths = [
            sprintf('/crm/v3/objects/deals/%s/associations/line_items', $hubspotDealId),
            sprintf('/crm/v3/objects/deals/%s/associations/line_item', $hubspotDealId),
        ];

        foreach ($paths as $path) {
            try {
                $response = $this->hubSpotClient->get($path);
                $results = $response['results'] ?? null;

                if (is_array($results) && $results !== []) {
                    return array_values(array_filter($results, 'is_array'));
                }
            } catch (\Throwable $exception) {
                $this->logger->warning('HubSpot deal line item association lookup failed', [
                    'dealHubspotId' => $hubspotDealId,
                    'path' => $path,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [];
    }
}

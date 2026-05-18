<?php

namespace App\Service\Erp;

use App\Entity\Commercial;
use App\Entity\Deal;
use App\Entity\DealLineItem;
use App\Repository\DealRepository;
use App\Repository\HubspotCompanyRepository;
use App\Service\HubSpot\HubSpotClient;
use Psr\Log\LoggerInterface;

class ErpOrderExportService
{
    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly DealRepository $dealRepository,
        private readonly SageClient $sageClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendDealToErp(string $hubspotDealId): array
    {
        $dealData = $this->hubSpotClient->getObject('deals', $hubspotDealId, [
            'properties' => ['dealname', 'dealstage', 'createdate', 'closedate', 'hubspot_owner_id'],
            'associations' => ['companies'],
        ]);

        $localDeal = $this->dealRepository->findOneByDealIdWithLineItems($hubspotDealId);

        if (!$localDeal instanceof Deal) {
            throw new \RuntimeException(sprintf('Aucun deal local n a ete trouve pour le deal HubSpot %s.', $hubspotDealId));
        }

        $companyId = $this->extractFirstAssociatedCompanyId($dealData);
        $numClient = $this->resolveNumClient($companyId);

        if ($numClient === null || trim($numClient) === '') {
            throw new \RuntimeException(sprintf('Aucun id_erp exploitable n a ete trouve pour le deal HubSpot %s.', $hubspotDealId));
        }

        $owner = $this->resolveOwnerData((string) (($dealData['properties']['hubspot_owner_id'] ?? null) ?: ''));
        $order = $this->buildOrderPayload($dealData, $localDeal, $numClient, $owner);

        $this->logger->info('ERP order payload prepared', [
            'dealHubspotId' => $hubspotDealId,
            'referenceCommande' => $order['referenceCommande'] ?? null,
            'numClient' => $order['numClient'] ?? null,
        ]);

        dd($order);

        $response = $this->sageClient->post('/order', $order);

        return [
            'skipped' => false,
            'payload' => $order,
            'response' => $response,
            'dealHubspotId' => $hubspotDealId,
        ];
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
     * @return array{firstname: string, lastname: string}
     */
    private function resolveOwnerData(string $ownerId): array
    {
        if ($ownerId !== '') {
            try {
                $owner = $this->hubSpotClient->get(sprintf('/crm/v3/owners/%s', $ownerId));

                return [
                    'firstname' => trim((string) (($owner['firstName'] ?? null) ?: '')),
                    'lastname' => trim((string) (($owner['lastName'] ?? null) ?: '')),
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
        ];
    }

    /**
     * @param array<string, mixed> $dealData
     * @param array{firstname: string, lastname: string} $owner
     *
     * @return array<string, mixed>
     */
    private function buildOrderPayload(array $dealData, Deal $localDeal, string $numClient, array $owner): array
    {
        $properties = isset($dealData['properties']) && is_array($dealData['properties']) ? $dealData['properties'] : [];
        $commercial = $localDeal->getCommercial();
        $dateCommande = $this->normalizeIsoDate($properties['createdate'] ?? null) ?? $localDeal->getSubmittedAt()?->format(DATE_ATOM) ?? (new \DateTimeImmutable())->format(DATE_ATOM);
        $dateLivraison = $this->normalizeIsoDate($properties['closedate'] ?? null) ?? $dateCommande;
        $ownerFirstName = $owner['firstname'] !== '' ? $owner['firstname'] : $commercial?->getFirstName() ?? '';
        $ownerName = $owner['lastname'] !== '' ? $owner['lastname'] : $commercial?->getLastName() ?? '';

        return [
            'numClient' => $numClient,
            'dateCommande' => $dateCommande,
            'dateLivraison' => $dateLivraison,
            'referenceCommande' => trim((string) (($properties['dealname'] ?? null) ?: $localDeal->getReferenceNumber() ?: $localDeal->getDealId() ?: '')),
            'statut' => trim((string) (($properties['dealstage'] ?? null) ?: $localDeal->getStatus())),
            'modeExpedition' => '',
            'ownerFirstName' => $ownerFirstName,
            'ownerName' => $ownerName,
            'instructionDeLivraison' => '',
            'orderLines' => $this->buildOrderLines($localDeal),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOrderLines(Deal $localDeal): array
    {
        $lines = [];

        foreach ($localDeal->getLineItems() as $lineItem) {
            if (!$lineItem instanceof DealLineItem) {
                continue;
            }

            $lines[] = [
                'reference' => (string) ($lineItem->getArticleRef() ?? ''),
                'designation' => $lineItem->getDescription() ?? (string) ($lineItem->getArticleRef() ?? ''),
                'prixHT' => $lineItem->getUnitPrice(),
                'quantite' => $lineItem->getQuantity(),
                'quantitePreparee' => $lineItem->getQuantity(),
            ];
        }

        if ($lines === []) {
            throw new \RuntimeException(sprintf('Le deal local %s ne contient aucune ligne exploitable.', $localDeal->getDealId() ?? $localDeal->getReferenceNumber() ?? ''));
        }

        return $lines;
    }

    private function normalizeIsoDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}

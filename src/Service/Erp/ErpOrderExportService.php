<?php

namespace App\Service\Erp;

use App\Entity\Commercial;
use App\Repository\HubspotCompanyRepository;
use App\Service\HubSpot\HubSpotClient;
use Psr\Log\LoggerInterface;

class ErpOrderExportService
{
    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly SageClient $sageClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendDealToErp(string $hubspotDealId): array
    {
        $dealData = $this->hubSpotClient->getObject('deals', $hubspotDealId, [
            'properties' => ['dealname', 'dealstage', 'createdate', 'closedate', 'hubspot_owner_id'],
            'associations' => ['companies', 'line_items'],
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
        ]);

        

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
    private function buildOrderPayload(array $dealData, string $numClient, array $owner): array
    {
        $properties = isset($dealData['properties']) && is_array($dealData['properties']) ? $dealData['properties'] : [];
        $dateCommande = $this->normalizeIsoDate($properties['createdate'] ?? null) ?? (new \DateTimeImmutable())->format(DATE_ATOM);
        $dateLivraison = $this->normalizeIsoDate($properties['closedate'] ?? null) ?? $dateCommande;
        $ownerFirstName = $owner['firstname'];
        $ownerName = $owner['lastname'];

        return [
            'numClient' => $numClient,
            'dateCommande' => $dateCommande,
            'dateLivraison' => $dateLivraison,
            'referenceCommande' => trim((string) (($properties['dealname'] ?? null) ?: '')),
            'statut' => 'Saisi',
            'modeExpedition' => '',
            'ownerFirstName' => $ownerFirstName,
            'ownerName' => $ownerName,
            'instructionDeLivraison' => '',
            'orderLines' => $this->buildOrderLines($dealData),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOrderLines(array $dealData): array
    {
        $lineItemIds = $this->extractAssociatedLineItemIds($dealData);

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
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $dealData
     *
     * @return array<int, string>
     */
    private function extractAssociatedLineItemIds(array $dealData): array
    {
        $results = $dealData['associations']['line_items']['results'] ?? null;

        if (!is_array($results)) {
            return [];
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
}

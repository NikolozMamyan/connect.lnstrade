<?php

namespace App\Service\HubSpot;

use App\Entity\HubspotCompany;
use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\ErpCompanyExportService;

class CompanyErpProvisioningService
{
    private const SAGE_INTEGRATION_ENABLED = 'Yes';

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly HubspotCompanySyncService $hubspotCompanySyncService,
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly ErpCompanyExportService $erpCompanyExportService,
    ) {
    }

    public function ensureCompanyHasErpId(string $companyHubspotId): string
    {
        $companyHubspotId = trim($companyHubspotId);

        if ($companyHubspotId === '') {
            throw new \InvalidArgumentException('HubSpot company id is required.');
        }

        $this->hubSpotClient->updateObject('companies', $companyHubspotId, [
            'sage_integration' => self::SAGE_INTEGRATION_ENABLED,
        ]);

        $syncResult = $this->hubspotCompanySyncService->syncCompanyById($companyHubspotId);

        if (($syncResult['skipped'] ?? false) === true) {
            throw new \RuntimeException(sprintf(
                'La company HubSpot %s n a pas ete synchronisee apres activation sage_integration.',
                $companyHubspotId
            ));
        }

        $company = $this->hubspotCompanyRepository->findOneByHubspotId($companyHubspotId);

        if (!$company instanceof HubspotCompany) {
            throw new \RuntimeException(sprintf('Company %s introuvable apres synchronisation HubSpot.', $companyHubspotId));
        }

        $existingErpId = trim((string) $company->getIdErp());

        if ($existingErpId !== '') {
            return $existingErpId;
        }

        $erpResult = $this->erpCompanyExportService->sendCompanyToErp($company);

        if (($erpResult['skipped'] ?? false) === true) {
            throw new \RuntimeException(sprintf(
                'La company HubSpot %s n a pas pu etre creee dans Sage.',
                $companyHubspotId
            ));
        }

        $reference = trim((string) (($erpResult['reference'] ?? null) ?: $company->getIdErp()));

        if ($reference === '') {
            throw new \RuntimeException(sprintf(
                'La creation Sage de la company HubSpot %s n a pas retourne de id_erp.',
                $companyHubspotId
            ));
        }

        return $reference;
    }
}

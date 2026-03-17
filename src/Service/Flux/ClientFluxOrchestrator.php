<?php

namespace App\Service\Flux;

use App\Service\Erp\ErpCompanyExportService;
use App\Service\HubSpot\HubspotCompanySyncService;

class ClientFluxOrchestrator
{
    public function __construct(
        private readonly HubspotCompanySyncService $hubspotCompanySyncService,
        private readonly ErpCompanyExportService $erpCompanyExportService,
    ) {
    }

    public function run(): array
    {
        $syncResult = $this->hubspotCompanySyncService->syncCompanies();
        $erpResult = $this->erpCompanyExportService->sendCompaniesToErp();

        return [
            'savedCompanies' => $syncResult['savedCompanies'] ?? 0,
            'savedContacts' => $syncResult['savedContacts'] ?? 0,
            'savedRelations' => $syncResult['savedRelations'] ?? 0,
            'erpSent' => $erpResult['sent'] ?? 0,
            'erpSkipped' => $erpResult['skipped'] ?? 0,
            'erpErrors' => $erpResult['errors'] ?? [],
            'erpPayloads' => $erpResult['payloads'] ?? [],
        ];
    }
}
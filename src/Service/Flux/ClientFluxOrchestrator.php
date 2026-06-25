<?php

namespace App\Service\Flux;

use App\Service\Erp\ErpProductExportService;
use App\Service\Erp\ErpProductStockUpdateService;
use App\Service\Erp\ErpCompanyExportService;
use App\Service\HubSpot\HubspotCompanySyncService;
use App\Service\HubSpot\HubspotProductSyncService;
use App\Service\HubSpot\HubspotProductStockSyncService;

class ClientFluxOrchestrator
{
    public function __construct(
        private readonly HubspotCompanySyncService $hubspotCompanySyncService,
        private readonly ErpCompanyExportService $erpCompanyExportService,
        private readonly ErpProductExportService $erpProductExportService,
        private readonly HubspotProductSyncService $hubspotProductSyncService,
        private readonly ErpProductStockUpdateService $erpProductStockUpdateService,
        private readonly HubspotProductStockSyncService $hubspotProductStockSyncService,
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
            'erpCreated' => $erpResult['created'] ?? 0,
            'erpUpdated' => $erpResult['updated'] ?? 0,
            'hubspotUpdated' => $erpResult['hubspotUpdated'] ?? 0,
            'erpSkipped' => $erpResult['skipped'] ?? 0,
            'erpErrors' => $erpResult['errors'] ?? [],
            'erpPayloads' => $erpResult['payloads'] ?? [],
        ];
    }

    public function runProductSync(): array
    {
        $importResult = $this->erpProductExportService->importProductsFromErp();
        $syncResult = $this->hubspotProductSyncService->syncProducts();

        return [
            'imported' => $importResult['imported'] ?? 0,
            'importCreated' => $importResult['created'] ?? 0,
            'importUpdated' => $importResult['updated'] ?? 0,
            'importSkipped' => $importResult['skipped'] ?? 0,
            'importErrors' => $importResult['errors'] ?? [],
            'hubspotSent' => $syncResult['sent'] ?? 0,
            'hubspotCreated' => $syncResult['created'] ?? 0,
            'hubspotUpdated' => $syncResult['updated'] ?? 0,
            'hubspotSkipped' => $syncResult['skipped'] ?? 0,
            'hubspotErrors' => $syncResult['errors'] ?? [],
            'hubspotPayloads' => $syncResult['payloads'] ?? [],
            'mapping' => $syncResult['mapping'] ?? [],
        ];
    }

    public function runProductStockSync(): array
    {
        $stockUpdateResult = $this->erpProductStockUpdateService->updateStocksFromErp();
        $hubspotResult = $this->hubspotProductStockSyncService->syncStocks();

        return [
            'stockUpdated' => $stockUpdateResult['updated'] ?? 0,
            'stockSkipped' => $stockUpdateResult['skipped'] ?? 0,
            'stockErrors' => $stockUpdateResult['errors'] ?? [],
            'hubspotSent' => $hubspotResult['sent'] ?? 0,
            'hubspotUpdated' => $hubspotResult['updated'] ?? 0,
            'hubspotSkipped' => $hubspotResult['skipped'] ?? 0,
            'hubspotErrors' => $hubspotResult['errors'] ?? [],
            'hubspotPayloads' => $hubspotResult['payloads'] ?? [],
            'mapping' => $hubspotResult['mapping'] ?? [],
        ];
    }
}

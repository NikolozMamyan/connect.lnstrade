<?php

namespace App\Tests\Service\HubSpot;

use App\Entity\HubspotCompany;
use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\ErpCompanyExportService;
use App\Service\HubSpot\CompanyErpProvisioningService;
use App\Service\HubSpot\HubSpotClient;
use App\Service\HubSpot\HubspotCompanySyncService;
use PHPUnit\Framework\TestCase;

class CompanyErpProvisioningServiceTest extends TestCase
{
    public function testEnsureCompanyHasErpIdActivatesSageIntegrationThenExportsCompany(): void
    {
        $company = (new HubspotCompany())
            ->setHubspotId('company-123')
            ->setName('ACME France')
            ->setSageIntegration('Yes');

        $hubSpotClient = $this->createMock(HubSpotClient::class);
        $hubSpotClient
            ->expects($this->once())
            ->method('updateObject')
            ->with('companies', 'company-123', ['sage_integration' => 'Yes'])
            ->willReturn(['id' => 'company-123']);

        $companySyncService = $this->createMock(HubspotCompanySyncService::class);
        $companySyncService
            ->expects($this->once())
            ->method('syncCompanyById')
            ->with('company-123')
            ->willReturn([
                'savedCompanies' => 1,
                'savedContacts' => 0,
                'savedRelations' => 0,
                'companyHubspotId' => 'company-123',
                'skipped' => false,
            ]);

        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository
            ->expects($this->once())
            ->method('findOneByHubspotId')
            ->with('company-123')
            ->willReturn($company);

        $erpCompanyExportService = $this->createMock(ErpCompanyExportService::class);
        $erpCompanyExportService
            ->expects($this->once())
            ->method('sendCompanyToErp')
            ->with($company)
            ->willReturn([
                'skipped' => false,
                'action' => 'create',
                'reference' => '9ACME',
            ]);

        $service = new CompanyErpProvisioningService(
            $hubSpotClient,
            $companySyncService,
            $companyRepository,
            $erpCompanyExportService,
        );

        self::assertSame('9ACME', $service->ensureCompanyHasErpId('company-123'));
    }

    public function testEnsureCompanyHasErpIdDoesNotExportWhenSyncAlreadyProvidesErpId(): void
    {
        $company = (new HubspotCompany())
            ->setHubspotId('company-123')
            ->setName('ACME France')
            ->setSageIntegration('Yes')
            ->setIdErp('CLI-001');

        $hubSpotClient = $this->createMock(HubSpotClient::class);
        $hubSpotClient
            ->expects($this->once())
            ->method('updateObject')
            ->with('companies', 'company-123', ['sage_integration' => 'Yes'])
            ->willReturn(['id' => 'company-123']);

        $companySyncService = $this->createMock(HubspotCompanySyncService::class);
        $companySyncService
            ->expects($this->once())
            ->method('syncCompanyById')
            ->with('company-123')
            ->willReturn([
                'savedCompanies' => 0,
                'savedContacts' => 0,
                'savedRelations' => 0,
                'companyHubspotId' => 'company-123',
                'skipped' => false,
            ]);

        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository
            ->expects($this->once())
            ->method('findOneByHubspotId')
            ->with('company-123')
            ->willReturn($company);

        $erpCompanyExportService = $this->createMock(ErpCompanyExportService::class);
        $erpCompanyExportService
            ->expects($this->never())
            ->method('sendCompanyToErp');

        $service = new CompanyErpProvisioningService(
            $hubSpotClient,
            $companySyncService,
            $companyRepository,
            $erpCompanyExportService,
        );

        self::assertSame('CLI-001', $service->ensureCompanyHasErpId('company-123'));
    }
}

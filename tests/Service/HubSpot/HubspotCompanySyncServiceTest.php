<?php

namespace App\Tests\Service\HubSpot;

use App\Entity\HubspotCompany;
use App\Service\HubSpot\HubspotCompanySyncService;
use PHPUnit\Framework\TestCase;

class HubspotCompanySyncServiceTest extends TestCase
{
    public function testBlankHubSpotErpIdDoesNotEraseConfirmedLocalReference(): void
    {
        $company = (new HubspotCompany())
            ->setHubspotId('company-123')
            ->setName('ACME')
            ->setIdErp('9ACME');
        $service = (new \ReflectionClass(HubspotCompanySyncService::class))->newInstanceWithoutConstructor();
        $hydrateCompany = new \ReflectionMethod(HubspotCompanySyncService::class, 'hydrateCompany');

        $hydrateCompany->invoke($service, $company, [
            'id' => 'company-123',
            'properties' => [
                'name' => 'ACME',
                'sage_integration' => 'yes',
                'id_erp' => '',
            ],
        ]);

        self::assertSame('9ACME', $company->getIdErp());
    }
}

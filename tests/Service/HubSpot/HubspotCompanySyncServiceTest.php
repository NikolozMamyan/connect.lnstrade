<?php

namespace App\Tests\Service\HubSpot;

use App\Entity\HubspotCompany;
use App\Repository\HubspotCompanyContactRepository;
use App\Repository\HubspotCompanyRepository;
use App\Repository\HubspotContactRepository;
use App\Service\HubSpot\HubSpotClient;
use App\Service\HubSpot\HubspotCompanySyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('syncMethods')]
    public function testSyncFetchesNewPropertiesAndAttachesPrimaryContact(string $syncMethod): void
    {
        $company = (new HubspotCompany())->setHubspotId('company-123')->setName('ACME');
        $companyData = [
            'id' => 'company-123',
            'properties' => [
                'name' => 'ACME',
                'sage_integration' => 'Yes',
                'country' => 'France',
                'company_country_en' => 'Germany',
                'insured_credit_limit' => '15000',
                'delivery_address' => 'Berlin',
                'linkedin_company_page' => 'https://linkedin.com/company/acme',
            ],
            'associations' => ['contacts' => ['results' => [
                ['id' => 'contact-other', 'type' => 'company_to_contact_unlabeled'],
                ['id' => 'contact-primary', 'type' => 'company_to_contact'],
                ['id' => 'contact-primary', 'type' => 'company_to_contact_unlabeled'],
            ]]],
        ];
        $assertCompanyQuery = static function (array $query): void {
            self::assertContains('company_country_en', $query['properties']);
            self::assertContains('linkedin_company_page', $query['properties']);
            self::assertContains('insured_credit_limit', $query['properties']);
            self::assertContains('delivery_address', $query['properties']);
            self::assertSame(['contacts'], $query['associations']);
        };
        $hubspotClient = $this->createMock(HubSpotClient::class);
        $hubspotClient->expects($this->exactly($syncMethod === 'single' ? 3 : 2))->method('getObject')->willReturnCallback(
            static function (string $type, string $id, array $query) use ($companyData, $assertCompanyQuery): array {
                if ($type === 'companies') {
                    $assertCompanyQuery($query);

                    return $companyData;
                }

                self::assertSame('contacts', $type);
                self::assertContains('hs_linkedin_url', $query['properties']);

                return ['id' => $id, 'properties' => [
                    'firstname' => 'Marie',
                    'lastname' => 'Dupont',
                    'hs_linkedin_url' => 'https://linkedin.com/in/marie',
                ]];
            }
        );
        $hubspotClient->expects($this->exactly($syncMethod === 'single' ? 0 : 1))->method('listObjects')->willReturnCallback(
            static function (string $type, array $query) use ($companyData, $assertCompanyQuery): array {
                self::assertSame('companies', $type);
                $assertCompanyQuery($query);

                return ['results' => [$companyData]];
            }
        );
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects($this->once())->method('findOneByHubspotId')->with('company-123')->willReturn($company);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $service = new HubspotCompanySyncService(
            $hubspotClient,
            $companyRepository,
            $this->createStub(HubspotContactRepository::class),
            $this->createStub(HubspotCompanyContactRepository::class),
            $entityManager,
        );

        $result = $syncMethod === 'single' ? $service->syncCompanyById('company-123') : $service->syncCompanies();

        self::assertSame('Germany', $company->getCountry());
        self::assertSame('15000', $company->getRawProperties()['insured_credit_limit']);
        self::assertSame(2, $result['savedContacts']);
        self::assertSame(3, $result['savedRelations']);
        self::assertCount(3, $company->getCompanyContacts());
        $primaryRelations = $company->getCompanyContacts()->filter(static fn ($relation): bool => $relation->isPrimary() === true);
        self::assertCount(1, $primaryRelations);
        self::assertSame('contact-primary', $primaryRelations->first()->getContact()->getHubspotId());
        self::assertSame('https://linkedin.com/in/marie', $primaryRelations->first()->getContact()->getRawProperties()['hs_linkedin_url']);
    }

    public static function syncMethods(): iterable
    {
        yield 'Single company' => ['single'];
        yield 'Batch' => ['batch'];
    }
}

<?php

namespace App\Tests\Service\Erp;

use App\Entity\HubspotCompany;
use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\ErpCompanyExportService;
use App\Service\Erp\SageClient;
use App\Service\HubSpot\HubSpotClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ErpCompanyExportServiceTest extends TestCase
{
    public function testExportCreatesSageClientThenSynchronizesReferenceToHubSpot(): void
    {
        $sageCreated = false;
        $sagePayload = [];
        $hubspotPayload = [];
        $company = $this->createEligibleCompany();

        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$sageCreated, &$sagePayload): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse(['results' => []]);
                }

                if ($method === 'POST' && $path === '/Clients') {
                    $sagePayload = json_decode((string) ($options['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                    $sageCreated = true;

                    return self::jsonResponse(['reference' => $sagePayload['reference']]);
                }

                self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
            }
        );
        $hubSpotHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$sageCreated, &$hubspotPayload): MockResponse {
                self::assertTrue($sageCreated, 'HubSpot must only be updated after Sage accepts the client.');
                self::assertSame('PATCH', $method);
                self::assertSame('/crm/objects/v3/companies/company-123', parse_url($url, PHP_URL_PATH));

                $hubspotPayload = json_decode((string) ($options['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

                return self::jsonResponse([
                    'id' => 'company-123',
                    'properties' => $hubspotPayload['properties'],
                ]);
            }
        );
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository
            ->expects($this->once())
            ->method('findExportableWithContacts')
            ->willReturn([$company]);
        $companyRepository
            ->expects($this->once())
            ->method('save')
            ->with($company, true)
            ->willReturnCallback(static function (HubspotCompany $savedCompany) use (&$sageCreated): void {
                self::assertTrue($sageCreated, 'The ERP reference must not be persisted before Sage succeeds.');
                self::assertSame('9ACME', $savedCompany->getIdErp());
            });

        $result = $this->createService($companyRepository, $sageHttpClient, $hubSpotHttpClient)
            ->sendCompaniesToErp();

        self::assertSame('9ACME', $sagePayload['reference']);
        self::assertSame(['id_erp' => '9ACME'], $hubspotPayload['properties']);
        self::assertSame('9ACME', $company->getIdErp());
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['hubspotUpdated']);
        self::assertSame([], $result['errors']);
    }

    public function testDirectExportSkipsCompanyThatIsNotEligibleForSage(): void
    {
        $company = (new HubspotCompany())
            ->setHubspotId('company-123')
            ->setName('ACME')
            ->setSageIntegration('no');
        $unexpectedRequest = static function (string $method, string $url): MockResponse {
            self::fail(sprintf('No HTTP request expected, got: %s %s', $method, $url));
        };
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects($this->never())->method('save');

        $result = $this->createService(
            $companyRepository,
            new MockHttpClient($unexpectedRequest),
            new MockHttpClient($unexpectedRequest),
        )->sendCompanyToErp($company);

        self::assertTrue($result['skipped']);
        self::assertSame('company_not_eligible', $result['reason']);
        self::assertNull($company->getIdErp());
    }

    public function testExistingSageClientAlsoSynchronizesItsReferenceToHubSpot(): void
    {
        $hubspotPayload = [];
        $company = $this->createEligibleCompany()->setIdErp('CLI-001');
        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse([
                        'results' => [
                            ['reference' => 'CLI-001'],
                        ],
                    ]);
                }

                if ($method === 'PATCH' && $path === '/Clients') {
                    return self::jsonResponse(['reference' => 'CLI-001']);
                }

                self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
            }
        );
        $hubSpotHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$hubspotPayload): MockResponse {
                self::assertSame('PATCH', $method);
                self::assertSame('/crm/objects/v3/companies/company-123', parse_url($url, PHP_URL_PATH));
                $hubspotPayload = json_decode((string) ($options['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

                return self::jsonResponse(['id' => 'company-123']);
            }
        );
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects($this->never())->method('save');

        $result = $this->createService($companyRepository, $sageHttpClient, $hubSpotHttpClient)
            ->sendCompanyToErp($company);

        self::assertFalse($result['skipped']);
        self::assertSame('update', $result['action']);
        self::assertTrue($result['hubspotUpdated']);
        self::assertSame(['id_erp' => 'CLI-001'], $hubspotPayload['properties']);
    }

    private function createEligibleCompany(): HubspotCompany
    {
        return (new HubspotCompany())
            ->setHubspotId('company-123')
            ->setName('ACME France')
            ->setEmail('contact@example.com')
            ->setSageIntegration('Yes')
            ->setArchived(false);
    }

    private function createService(
        HubspotCompanyRepository $companyRepository,
        MockHttpClient $sageHttpClient,
        MockHttpClient $hubSpotHttpClient,
    ): ErpCompanyExportService {
        $parameters = new ParameterBag([
            'base_uri_hubspot' => 'https://hubspot.test',
            'hubspot_access' => 'hubspot-token',
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ]);

        return new ErpCompanyExportService(
            $companyRepository,
            new SageClient($sageHttpClient, $parameters),
            new HubSpotClient($hubSpotHttpClient, $parameters),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']]
        );
    }
}

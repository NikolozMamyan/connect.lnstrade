<?php

namespace App\Tests\Service\Erp;

use App\Entity\ErpOrderExport;
use App\Repository\ErpOrderExportRepository;
use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\ErpOrderExportService;
use App\Service\Erp\SageClient;
use App\Service\HubSpot\HubSpotClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class ErpOrderExportServiceTest extends TestCase
{
    public function testNormalizeSubcontractingUsesSageFreeFieldValues(): void
    {
        $service = (new \ReflectionClass(ErpOrderExportService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ErpOrderExportService::class, 'normalizeSubcontractingForSage');

        self::assertSame('OUI', $method->invoke($service, 'Yes'));
        self::assertSame('NON', $method->invoke($service, 'No'));
        self::assertNull($method->invoke($service, null));
    }

    public function testNormalizePaymentTermUsesSageModels(): void
    {
        $service = (new \ReflectionClass(ErpOrderExportService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ErpOrderExportService::class, 'normalizePaymentTermForSage');

        self::assertSame('Comptant', $method->invoke($service, 'Cash payment'));
        self::assertSame('A 30 jours net', $method->invoke($service, '30 days'));
        self::assertSame('A 60 jours net', $method->invoke($service, '60 days'));
        self::assertSame('A 90 jours net', $method->invoke($service, '90 days'));
        self::assertNull($method->invoke($service, null));
    }

    public function testNormalizeIncotermUsesConfiguredSageLabels(): void
    {
        $service = (new \ReflectionClass(ErpOrderExportService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ErpOrderExportService::class, 'normalizeIncotermForSage');

        self::assertSame('DDP - Rendu droits acquittés', $method->invoke($service, 'DDP'));
        self::assertSame("EXW - A l'usine", $method->invoke($service, 'exw'));
        self::assertSame('DAP - Rendu au lieu de dest.', $method->invoke($service, 'DAP'));
        self::assertSame('FCA - Franco transporteur', $method->invoke($service, 'FCA'));
        self::assertSame('CPT', $method->invoke($service, 'CPT'));
        self::assertNull($method->invoke($service, null));
    }

    public function testSendDealMapsAdditionalHubSpotPropertiesToSageOrder(): void
    {
        $requestedDealProperties = [];
        $deliveryInformation = str_repeat('A', 75);

        $hubSpotHttpClient = new MockHttpClient(
            static function (string $method, string $url) use (&$requestedDealProperties, $deliveryInformation): MockResponse {
                self::assertSame('GET', $method);

                $path = parse_url($url, PHP_URL_PATH);

                if ($path === '/crm/objects/v3/deals/12345') {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    $requestedDealProperties = explode(',', (string) ($query['properties'] ?? ''));

                    return self::jsonResponse([
                        'id' => '12345',
                        'properties' => [
                            'dealname' => 'Commande test',
                            'order_reference' => 'REF-ORDER-2026',
                            'createdate' => '2026-06-01T10:30:00Z',
                            'closedate' => '2026-06-30T00:00:00Z',
                            'hubspot_owner_id' => 'owner-1',
                            'incoterm' => 'DDP',
                            'expected_delivery_date' => '2026-07-15',
                            'delivery_information' => $deliveryInformation,
                            'subcontracting' => 'Yes',
                            'payment_term' => '60 days',
                        ],
                        'associations' => [
                            'companies' => [
                                'results' => [
                                    ['id' => 'company-1'],
                                ],
                            ],
                            'line_item' => [
                                'results' => [
                                    ['id' => 'line-1'],
                                ],
                            ],
                        ],
                    ]);
                }

                if ($path === '/crm/objects/v3/companies/company-1') {
                    return self::jsonResponse([
                        'id' => 'company-1',
                        'properties' => [
                            'id_erp' => 'CLI-001',
                        ],
                    ]);
                }

                if ($path === '/crm/v3/owners/owner-1') {
                    return self::jsonResponse([
                        'firstName' => 'Jean',
                        'lastName' => 'Dupont',
                    ]);
                }

                if ($path === '/crm/objects/v3/line_items/line-1') {
                    return self::jsonResponse([
                        'id' => 'line-1',
                        'properties' => [
                            'hs_sku' => 'ART-001',
                            'name' => 'Article test',
                            'price' => '12.50',
                            'quantity' => '2',
                        ],
                    ]);
                }

                self::fail(sprintf('Unexpected HubSpot request: %s %s', $method, $url));
            }
        );

        $sageOrderPayload = [];
        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$sageOrderPayload): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'test-token']);
                }

                if ($method === 'POST' && $path === '/order') {
                    $sageOrderPayload = json_decode((string) ($options['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

                    return self::jsonResponse(['success' => true]);
                }

                self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
            }
        );

        $parameters = new ParameterBag([
            'base_uri_hubspot' => 'https://hubspot.test',
            'hubspot_access' => 'hubspot-token',
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ]);
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects($this->never())->method('findOneByHubspotId');
        $exportRepository = $this->createMock(ErpOrderExportRepository::class);
        $exportRepository
            ->expects($this->once())
            ->method('findOneByHubspotDealId')
            ->with('12345')
            ->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with(self::isInstanceOf(ErpOrderExport::class));
        $entityManager->expects($this->exactly(2))->method('flush');

        $service = new ErpOrderExportService(
            new HubSpotClient($hubSpotHttpClient, $parameters),
            $companyRepository,
            $exportRepository,
            $entityManager,
            new SageClient($sageHttpClient, $parameters),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $result = $service->sendDealToErp('12345');

        self::assertContains('incoterm', $requestedDealProperties);
        self::assertContains('order_reference', $requestedDealProperties);
        self::assertContains('expected_delivery_date', $requestedDealProperties);
        self::assertContains('delivery_information', $requestedDealProperties);
        self::assertContains('subcontracting', $requestedDealProperties);
        self::assertContains('payment_term', $requestedDealProperties);
        self::assertSame('Standard', $sageOrderPayload['modeExpedition']);
        self::assertSame('DDP - Rendu droits acquittés', $sageOrderPayload['condLivraison']);
        self::assertSame('2026-07-15', $sageOrderPayload['dateLivraison']);
        self::assertSame('REF-ORDER-2026', $sageOrderPayload['referenceCommande']);
        self::assertSame(str_repeat('A', 69), $sageOrderPayload['instructionDeLivraison']);
        self::assertSame(['Sous-traitance' => 'OUI'], $sageOrderPayload['champsLibres']);
        self::assertSame('A 60 jours net', $sageOrderPayload['modeleReglement']);
        self::assertSame($sageOrderPayload, $result['payload']);
    }

    public function testSendDealSkipsAlreadySentExport(): void
    {
        $existingExport = (new ErpOrderExport('12345'))->markSent(
            [
                'referenceCommande' => 'REF-ORDER-2026',
                'numClient' => 'CLI-001',
                'orderLines' => [],
            ],
            ['success' => true]
        );
        $hubSpotHttpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('HubSpot must not be called for an already sent export.');
        });
        $sageHttpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('Sage must not be called for an already sent export.');
        });
        $parameters = new ParameterBag([
            'base_uri_hubspot' => 'https://hubspot.test',
            'hubspot_access' => 'hubspot-token',
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ]);
        $exportRepository = $this->createMock(ErpOrderExportRepository::class);
        $exportRepository
            ->expects($this->once())
            ->method('findOneByHubspotDealId')
            ->with('12345')
            ->willReturn($existingExport);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $service = new ErpOrderExportService(
            new HubSpotClient($hubSpotHttpClient, $parameters),
            $this->createStub(HubspotCompanyRepository::class),
            $exportRepository,
            $entityManager,
            new SageClient($sageHttpClient, $parameters),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $result = $service->sendDealToErp('12345');

        self::assertTrue($result['skipped']);
        self::assertSame('already_sent', $result['reason']);
        self::assertSame('12345', $result['dealHubspotId']);
        self::assertSame('REF-ORDER-2026', $result['payload']['referenceCommande']);
    }

    public function testSendDealSkipsAlreadyProcessingExport(): void
    {
        $existingExport = new ErpOrderExport('12345');
        $hubSpotHttpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('HubSpot must not be called for an already processing export.');
        });
        $sageHttpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('Sage must not be called for an already processing export.');
        });
        $parameters = new ParameterBag([
            'base_uri_hubspot' => 'https://hubspot.test',
            'hubspot_access' => 'hubspot-token',
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ]);
        $exportRepository = $this->createMock(ErpOrderExportRepository::class);
        $exportRepository
            ->expects($this->once())
            ->method('findOneByHubspotDealId')
            ->with('12345')
            ->willReturn($existingExport);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $service = new ErpOrderExportService(
            new HubSpotClient($hubSpotHttpClient, $parameters),
            $this->createStub(HubspotCompanyRepository::class),
            $exportRepository,
            $entityManager,
            new SageClient($sageHttpClient, $parameters),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $result = $service->sendDealToErp('12345');

        self::assertTrue($result['skipped']);
        self::assertSame('already_processing', $result['reason']);
        self::assertSame('12345', $result['dealHubspotId']);
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

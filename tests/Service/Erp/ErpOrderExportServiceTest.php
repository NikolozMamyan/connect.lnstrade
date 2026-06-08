<?php

namespace App\Tests\Service\Erp;

use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\ErpOrderExportService;
use App\Service\Erp\SageClient;
use App\Service\HubSpot\HubSpotClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ErpOrderExportServiceTest extends TestCase
{
    public function testNormalizeIncotermUsesConfiguredSageLabels(): void
    {
        $service = (new \ReflectionClass(ErpOrderExportService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ErpOrderExportService::class, 'normalizeIncotermForSage');

        self::assertSame('DDP - Rendu droits acquittés', $method->invoke($service, 'DDP'));
        self::assertSame("EXW - A l'usine", $method->invoke($service, 'exw'));
        self::assertSame('DAP - Rendu au lieu de dest.', $method->invoke($service, 'DAP'));
        self::assertSame('FCA - Franco transporteur', $method->invoke($service, 'FCA'));
        self::assertSame('CPT', $method->invoke($service, 'CPT'));
        self::assertSame('Standard', $method->invoke($service, null));
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
                            'createdate' => '2026-06-01T10:30:00Z',
                            'closedate' => '2026-06-30T00:00:00Z',
                            'hubspot_owner_id' => 'owner-1',
                            'incoterm' => 'DDP',
                            'expected_delivery_date' => '2026-07-15',
                            'delivery_information' => $deliveryInformation,
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

        $service = new ErpOrderExportService(
            new HubSpotClient($hubSpotHttpClient, $parameters),
            $companyRepository,
            new SageClient($sageHttpClient, $parameters),
            new NullLogger(),
        );

        $result = $service->sendDealToErp('12345');

        self::assertContains('incoterm', $requestedDealProperties);
        self::assertContains('expected_delivery_date', $requestedDealProperties);
        self::assertContains('delivery_information', $requestedDealProperties);
        self::assertSame('DDP - Rendu droits acquittés', $sageOrderPayload['modeExpedition']);
        self::assertSame('2026-07-15', $sageOrderPayload['dateLivraison']);
        self::assertSame(str_repeat('A', 69), $sageOrderPayload['instructionDeLivraison']);
        self::assertSame($sageOrderPayload, $result['payload']);
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

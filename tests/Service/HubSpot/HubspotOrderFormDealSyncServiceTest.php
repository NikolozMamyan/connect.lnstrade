<?php

namespace App\Tests\Service\HubSpot;

use App\Entity\Commercial;
use App\Entity\ErpProduct;
use App\Entity\OrderForm;
use App\Repository\ErpProductRepository;
use App\Service\HubSpot\HubSpotClient;
use App\Service\HubSpot\HubspotOrderFormDealSyncService;
use PHPUnit\Framework\TestCase;

class HubspotOrderFormDealSyncServiceTest extends TestCase
{
    public function testSyncAppliesProductVatPerLineItemForFrenchCompany(): void
    {
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('France', $createdLineItems);

        $products = [
            'AR-55' => $this->createProduct('AR-55', 'TVA 5,5'),
            'AR-20' => $this->createProduct('AR-20', 'TVA 20'),
        ];

        $erpProductRepository = $this->createMock(ErpProductRepository::class);
        $erpProductRepository
            ->expects($this->exactly(2))
            ->method('findOneByReference')
            ->willReturnCallback(static fn (string $reference): ?ErpProduct => $products[$reference] ?? null);

        $service = $this->createService($hubSpotClient, $erpProductRepository);
        $result = $service->sync($this->createNewDealOrderForm(), [
            [
                'articleRef' => 'AR-55',
                'quantity' => 2.0,
                'unitPrice' => 10.0,
            ],
            [
                'articleRef' => 'AR-20',
                'quantity' => 1.0,
                'unitPrice' => 20.0,
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertCount(2, $createdLineItems);
        self::assertSame('115152085', $createdLineItems[0]['hs_tax_rate_group_id']);
        self::assertSame('115152086', $createdLineItems[1]['hs_tax_rate_group_id']);
    }

    public function testSyncDoesNotSendVatForNonFrenchCompany(): void
    {
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('Germany', $createdLineItems);

        $erpProductRepository = $this->createMock(ErpProductRepository::class);
        $erpProductRepository
            ->expects($this->never())
            ->method('findOneByReference');

        $service = $this->createService($hubSpotClient, $erpProductRepository);
        $result = $service->sync($this->createNewDealOrderForm(), [
            [
                'articleRef' => 'AR-20',
                'quantity' => 1.0,
                'unitPrice' => 20.0,
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertCount(1, $createdLineItems);
        self::assertArrayNotHasKey('hs_tax_rate_group_id', $createdLineItems[0]);
    }

    /**
     * @param array<int, array<string, mixed>> $createdLineItems
     */
    private function createHubSpotClientForNewDeal(string $companyCountry, array &$createdLineItems): HubSpotClient
    {
        $hubSpotClient = $this->createMock(HubSpotClient::class);

        $hubSpotClient
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(static function (string $path): array {
                if ($path === '/crm/owners/2026-03') {
                    return [
                        'results' => [
                            ['id' => '111222'],
                        ],
                    ];
                }

                if ($path === '/crm/v3/pipelines/deals/default') {
                    return [
                        'stages' => [
                            [
                                'label' => 'Sending the offer',
                                'id' => 'stage-send',
                            ],
                        ],
                    ];
                }

                self::fail(sprintf('Unexpected HubSpot GET path: %s', $path));
            });

        $hubSpotClient
            ->expects($this->once())
            ->method('getObject')
            ->willReturnCallback(static function (string $objectType, string $objectId, array $query) use ($companyCountry): array {
                self::assertSame('companies', $objectType);
                self::assertSame('123456', $objectId);
                self::assertContains('company_country_en', $query['properties'] ?? []);

                return [
                    'id' => $objectId,
                    'properties' => [
                        'name' => 'LNS France',
                        'id_erp' => 'ERP-123',
                        'company_country_en' => $companyCountry,
                    ],
                ];
            });

        $hubSpotClient
            ->method('searchObjects')
            ->willReturnCallback(static function (string $objectType, array $payload): array {
                self::assertSame('products', $objectType);

                $reference = (string) ($payload['filterGroups'][0]['filters'][0]['value'] ?? '');

                return [
                    'results' => [
                        [
                            'id' => match ($reference) {
                                'AR-55' => '555555',
                                'AR-20' => '202020',
                                default => '999999',
                            },
                            'properties' => [
                                'name' => $reference,
                                'hs_sku' => $reference,
                            ],
                        ],
                    ],
                ];
            });

        $hubSpotClient
            ->expects($this->atLeast(2))
            ->method('createObject')
            ->willReturnCallback(static function (string $objectType, array $properties) use (&$createdLineItems): array {
                if ($objectType === 'deals') {
                    return ['id' => '987654'];
                }

                if ($objectType === 'line_items') {
                    $createdLineItems[] = $properties;

                    return ['id' => (string) (1000 + count($createdLineItems))];
                }

                self::fail(sprintf('Unexpected HubSpot object type: %s', $objectType));
            });

        return $hubSpotClient;
    }

    private function createService(HubSpotClient $hubSpotClient, ErpProductRepository $erpProductRepository): HubspotOrderFormDealSyncService
    {
        return new HubspotOrderFormDealSyncService(
            $hubSpotClient,
            $erpProductRepository,
            'Pipeline sales',
            'default',
            'Sending the offer',
            [
                '5.5' => '115152085',
                '20' => '115152086',
            ],
        );
    }

    private function createNewDealOrderForm(): OrderForm
    {
        $commercial = (new Commercial())
            ->setFirstName('Quentin')
            ->setLastName('Strasser')
            ->setEmail('quentin.strasser@lnstrade.fr')
            ->setHubspotId('78020060');

        return (new OrderForm())
            ->setDealType(OrderForm::DEAL_TYPE_NOUVEAU)
            ->setCommercial($commercial)
            ->setEnterpriseId('123456');
    }

    private function createProduct(string $reference, string $codeFiscal): ErpProduct
    {
        return (new ErpProduct())
            ->setReference($reference)
            ->setCodeFiscal($codeFiscal);
    }
}

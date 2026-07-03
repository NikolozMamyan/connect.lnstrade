<?php

namespace App\Tests\Service\HubSpot;

use App\Entity\Commercial;
use App\Entity\OrderForm;
use App\Service\HubSpot\CompanyErpProvisioningService;
use App\Service\HubSpot\HubSpotClient;
use App\Service\HubSpot\HubspotOrderFormDealSyncService;
use PHPUnit\Framework\TestCase;

class HubspotOrderFormDealSyncServiceTest extends TestCase
{
    public function testSyncAppliesProductVatPerLineItemForFrenchCompany(): void
    {
        $createdDeals = [];
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('France', $createdLineItems, $createdDeals);

        $service = $this->createService($hubSpotClient);
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
                'eanUnit' => '3760000000000',
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertSame('40.00', $createdDeals[0]['amount']);
        self::assertCount(2, $createdLineItems);
        self::assertSame('115152085', $createdLineItems[0]['hs_tax_rate_group_id']);
        self::assertSame('115152086', $createdLineItems[1]['hs_tax_rate_group_id']);
        self::assertSame('AR-20', $createdLineItems[1]['hs_sku']);
    }

    public function testSyncDoesNotSendVatForNonFrenchCompany(): void
    {
        $createdDeals = [];
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('Germany', $createdLineItems, $createdDeals);

        $service = $this->createService($hubSpotClient);
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

    public function testSyncWarnsButDoesNotBlockWhenFrenchProductHasNoVat(): void
    {
        $createdDeals = [];
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('France', $createdLineItems, $createdDeals);

        $service = $this->createService($hubSpotClient);
        $result = $service->sync($this->createNewDealOrderForm(), [
            [
                'articleRef' => 'AR-NOVAT',
                'quantity' => 1.0,
                'unitPrice' => 20.0,
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertCount(1, $createdLineItems);
        self::assertArrayNotHasKey('hs_tax_rate_group_id', $createdLineItems[0]);
        self::assertSame('taxRate', $result['warnings'][0]['field']);
        self::assertSame('AR-NOVAT', $result['warnings'][0]['reference']);
    }

    public function testSyncCreatesZeroPriceLineItemWhenQuantityIsPositive(): void
    {
        $createdDeals = [];
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('Germany', $createdLineItems, $createdDeals);

        $service = $this->createService($hubSpotClient);
        $result = $service->sync($this->createNewDealOrderForm(), [
            [
                'articleRef' => 'AR-20',
                'quantity' => 4.0,
                'unitPrice' => 0.0,
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertSame('0.00', $createdDeals[0]['amount']);
        self::assertCount(1, $createdLineItems);
        self::assertSame(4.0, $createdLineItems[0]['quantity']);
        self::assertSame(0.0, $createdLineItems[0]['price']);
    }

    public function testSyncProvisionsCompanyWhenNewDealHasNoErpId(): void
    {
        $createdDeals = [];
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('Germany', $createdLineItems, $createdDeals, '');
        $provisioningService = $this->createMock(CompanyErpProvisioningService::class);
        $provisioningService
            ->expects($this->once())
            ->method('ensureCompanyHasErpId')
            ->with('123456')
            ->willReturn('9ACME');

        $service = $this->createService($hubSpotClient, $provisioningService);
        $result = $service->sync($this->createNewDealOrderForm(), [
            [
                'articleRef' => 'AR-20',
                'quantity' => 2.0,
                'unitPrice' => 15.0,
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertSame('987654', $result['hubspotDealId']);
        self::assertCount(1, $createdDeals);
        self::assertSame('30.00', $createdDeals[0]['amount']);
        self::assertCount(1, $createdLineItems);
    }

    public function testSyncDoesNotProvisionCompanyWhenAnotherValidationErrorExists(): void
    {
        $createdDeals = [];
        $createdLineItems = [];
        $hubSpotClient = $this->createHubSpotClientForNewDeal('Germany', $createdLineItems, $createdDeals, '', ['AR-MISSING'], 1, false);
        $provisioningService = $this->createMock(CompanyErpProvisioningService::class);
        $provisioningService
            ->expects($this->never())
            ->method('ensureCompanyHasErpId');

        $service = $this->createService($hubSpotClient, $provisioningService);
        $result = $service->sync($this->createNewDealOrderForm(), [
            [
                'articleRef' => 'AR-MISSING',
                'quantity' => 2.0,
                'unitPrice' => 15.0,
            ],
        ]);

        self::assertFalse($result['success']);
        self::assertNull($result['hubspotDealId']);
        self::assertSame('articleRef', $result['errors'][0]['field']);
        self::assertSame('enterpriseId', $result['errors'][1]['field']);
        self::assertCount(0, $createdDeals);
        self::assertCount(0, $createdLineItems);
    }

    public function testSyncReplacesExistingDealLineItemsBeforeCreatingNewOnExistingDeal(): void
    {
        $createdLineItems = [];
        $deletedLineItemIds = [];
        $updatedDeals = [];
        $hubSpotClient = $this->createHubSpotClientForExistingDeal('France', $createdLineItems, $deletedLineItemIds, $updatedDeals);

        $service = $this->createService($hubSpotClient);
        $result = $service->sync($this->createExistingDealOrderForm(), [
            [
                'articleRef' => 'AR-20',
                'quantity' => 3.0,
                'unitPrice' => 12.0,
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertSame(['old-line-1', 'old-line-2'], $deletedLineItemIds);
        self::assertSame(['amount' => '36.00'], $updatedDeals['654321']);
        self::assertCount(1, $createdLineItems);
        self::assertSame('115152086', $createdLineItems[0]['hs_tax_rate_group_id']);
        self::assertSame(3.0, $createdLineItems[0]['quantity']);
    }

    public function testSyncProvisionsAssociatedCompanyWhenExistingDealHasNoErpId(): void
    {
        $createdLineItems = [];
        $deletedLineItemIds = [];
        $updatedDeals = [];
        $hubSpotClient = $this->createHubSpotClientForExistingDeal('Germany', $createdLineItems, $deletedLineItemIds, $updatedDeals, '');
        $provisioningService = $this->createMock(CompanyErpProvisioningService::class);
        $provisioningService
            ->expects($this->once())
            ->method('ensureCompanyHasErpId')
            ->with('123456')
            ->willReturn('9ACME');

        $service = $this->createService($hubSpotClient, $provisioningService);
        $result = $service->sync($this->createExistingDealOrderForm(), [
            [
                'articleRef' => 'AR-20',
                'quantity' => 3.0,
                'unitPrice' => 12.0,
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertSame(['old-line-1', 'old-line-2'], $deletedLineItemIds);
        self::assertSame(['amount' => '36.00'], $updatedDeals['654321']);
        self::assertCount(1, $createdLineItems);
    }

    /**
     * @param array<int, array<string, mixed>> $createdLineItems
     * @param array<int, array<string, mixed>> $createdDeals
     */
    private function createHubSpotClientForNewDeal(
        string $companyCountry,
        array &$createdLineItems,
        array &$createdDeals,
        string $companyErpId = 'ERP-123',
        array $missingProductReferences = [],
        int $expectedGetCalls = 2,
        bool $expectsObjectCreation = true,
    ): HubSpotClient
    {
        $hubSpotClient = $this->createMock(HubSpotClient::class);

        $hubSpotClient
            ->expects($this->exactly($expectedGetCalls))
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
            ->willReturnCallback(static function (string $objectType, string $objectId, array $query) use ($companyCountry, $companyErpId): array {
                self::assertSame('companies', $objectType);
                self::assertSame('123456', $objectId);
                self::assertContains('company_country_en', $query['properties'] ?? []);

                return [
                    'id' => $objectId,
                    'properties' => [
                        'name' => 'LNS France',
                        'id_erp' => $companyErpId,
                        'company_country_en' => $companyCountry,
                    ],
                ];
            });

        $hubSpotClient
            ->method('searchObjects')
            ->willReturnCallback(static function (string $objectType, array $payload) use ($missingProductReferences): array {
                self::assertSame('products', $objectType);
                self::assertContains('vat', $payload['properties'] ?? []);

                $reference = (string) ($payload['filterGroups'][0]['filters'][0]['value'] ?? '');

                if (in_array($reference, $missingProductReferences, true)) {
                    return [
                        'results' => [],
                    ];
                }

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
                                'vat' => match ($reference) {
                                    'AR-55' => '0.055',
                                    'AR-20' => '0.2',
                                    default => '',
                                },
                            ],
                        ],
                    ],
                ];
            });

        $createObjectExpectation = $expectsObjectCreation
            ? $this->atLeast(2)
            : $this->never();

        $hubSpotClient
            ->expects($createObjectExpectation)
            ->method('createObject')
            ->willReturnCallback(static function (string $objectType, array $properties) use (&$createdLineItems, &$createdDeals): array {
                if ($objectType === 'deals') {
                    $createdDeals[] = $properties;

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

    /**
     * @param array<int, array<string, mixed>> $createdLineItems
     * @param array<int, string> $deletedLineItemIds
     * @param array<string, array<string, mixed>> $updatedDeals
     */
    private function createHubSpotClientForExistingDeal(
        string $companyCountry,
        array &$createdLineItems,
        array &$deletedLineItemIds,
        array &$updatedDeals,
        string $companyErpId = 'ERP-123',
    ): HubSpotClient {
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

                if ($path === '/crm/v3/objects/deals/654321/associations/line_items') {
                    return [
                        'results' => [
                            ['id' => 'old-line-1'],
                            ['toObjectId' => 'old-line-2'],
                        ],
                    ];
                }

                self::fail(sprintf('Unexpected HubSpot GET path: %s', $path));
            });

        $hubSpotClient
            ->expects($this->exactly(2))
            ->method('getObject')
            ->willReturnCallback(static function (string $objectType, string $objectId, array $query) use ($companyCountry, $companyErpId): array {
                if ($objectType === 'deals') {
                    self::assertSame('654321', $objectId);
                    self::assertContains('companies', $query['associations'] ?? []);

                    return [
                        'id' => $objectId,
                        'properties' => [
                            'dealname' => 'Deal existing',
                        ],
                        'associations' => [
                            'companies' => [
                                'results' => [
                                    ['id' => '123456'],
                                ],
                            ],
                        ],
                    ];
                }

                if ($objectType === 'companies') {
                    self::assertSame('123456', $objectId);
                    self::assertContains('company_country_en', $query['properties'] ?? []);

                    return [
                        'id' => $objectId,
                        'properties' => [
                            'name' => 'LNS France',
                            'id_erp' => $companyErpId,
                            'company_country_en' => $companyCountry,
                        ],
                    ];
                }

                self::fail(sprintf('Unexpected HubSpot object type: %s', $objectType));
            });

        $hubSpotClient
            ->method('searchObjects')
            ->willReturnCallback(static function (string $objectType, array $payload): array {
                self::assertSame('products', $objectType);

                return [
                    'results' => [
                        [
                            'id' => '202020',
                            'properties' => [
                                'name' => 'AR-20',
                                'hs_sku' => 'AR-20',
                                'vat' => '0.2',
                            ],
                        ],
                    ],
                ];
            });

        $hubSpotClient
            ->expects($this->exactly(2))
            ->method('deleteObject')
            ->willReturnCallback(static function (string $objectType, string $objectId) use (&$deletedLineItemIds): array {
                self::assertSame('line_items', $objectType);
                $deletedLineItemIds[] = $objectId;

                return ['statusCode' => 204];
            });

        $hubSpotClient
            ->expects($this->once())
            ->method('createObject')
            ->willReturnCallback(static function (string $objectType, array $properties) use (&$createdLineItems): array {
                self::assertSame('line_items', $objectType);
                $createdLineItems[] = $properties;

                return ['id' => 'new-line-1'];
            });

        $hubSpotClient
            ->expects($this->once())
            ->method('updateObject')
            ->willReturnCallback(static function (string $objectType, string $objectId, array $properties) use (&$updatedDeals): array {
                self::assertSame('deals', $objectType);
                $updatedDeals[$objectId] = $properties;

                return ['id' => $objectId];
            });

        return $hubSpotClient;
    }

    private function createService(
        HubSpotClient $hubSpotClient,
        ?CompanyErpProvisioningService $provisioningService = null,
    ): HubspotOrderFormDealSyncService
    {
        return new HubspotOrderFormDealSyncService(
            $hubSpotClient,
            $provisioningService ?? $this->createStub(CompanyErpProvisioningService::class),
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

    private function createExistingDealOrderForm(): OrderForm
    {
        $commercial = (new Commercial())
            ->setFirstName('Quentin')
            ->setLastName('Strasser')
            ->setEmail('quentin.strasser@lnstrade.fr')
            ->setHubspotId('78020060');

        return (new OrderForm())
            ->setDealType(OrderForm::DEAL_TYPE_EXISTANT)
            ->setCommercial($commercial)
            ->setDealId('654321');
    }
}

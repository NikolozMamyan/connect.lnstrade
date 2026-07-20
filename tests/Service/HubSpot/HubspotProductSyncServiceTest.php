<?php

namespace App\Tests\Service\HubSpot;

use App\Entity\ErpProduct;
use App\Repository\ErpProductRepository;
use App\Service\HubSpot\HubSpotClient;
use App\Service\HubSpot\HubspotProductSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class HubspotProductSyncServiceTest extends TestCase
{
    public function testStoredHubspotIdAvoidsSkuSearch(): void
    {
        $product = (new ErpProduct())
            ->setReference('REF-001')
            ->setDesignation('Produit test')
            ->setHubspotObjectId('12345')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $repository = $this->createMock(ErpProductRepository::class);
        $repository->expects(self::once())
            ->method('findProductsForCatalogSync')
            ->willReturn([$product]);

        $hubSpotClient = $this->createMock(HubSpotClient::class);
        $hubSpotClient->expects(self::never())->method('searchObjects');
        $hubSpotClient->expects(self::never())->method('createObject');
        $hubSpotClient->expects(self::once())
            ->method('updateObject')
            ->with('products', '12345', [
                'name' => 'Produit test',
                'hs_sku' => 'REF-001',
            ])
            ->willReturn(['id' => '12345']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($product);
        $entityManager->expects(self::once())->method('flush');

        $result = (new HubspotProductSyncService(
            $hubSpotClient,
            $repository,
            $entityManager,
            $this->createStub(LoggerInterface::class),
        ))->syncProducts();

        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['created']);
        self::assertNotNull($product->getLastSyncedAt());
    }
}

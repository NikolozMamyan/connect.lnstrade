<?php

namespace App\Service\HubSpot;

use App\Entity\ErpProduct;
use App\Repository\ErpProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class HubspotProductStockSyncService
{
    private const HUBSPOT_STOCK_MAPPING = [
        'available_stock' => 'stockDispo',
        'stock_reel' => 'stockReel',
        'stock_terme' => 'stockATerme',
    ];

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly ErpProductRepository $erpProductRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncStocks(): array
    {
        $products = $this->erpProductRepository->findProductsForStockSync();

        $sent = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $payloads = [];

        foreach ($products as $product) {
            $payload = $this->buildHubspotProperties($product);

            if ($payload === []) {
                ++$skipped;
                continue;
            }

            try {
                $hubspotId = trim((string) $product->getHubspotObjectId());
                $sku = $product->getReference();

                if ($hubspotId === '' && $sku !== null) {
                    $existing = $this->findHubspotProductBySku($sku);
                    $hubspotId = \is_array($existing) ? trim((string) ($existing['id'] ?? '')) : '';
                }

                if ($hubspotId === '') {
                    ++$skipped;
                    continue;
                }

                $this->hubSpotClient->updateObject('products', $hubspotId, $payload);

                $product
                    ->setHubspotObjectId($hubspotId)
                    ->setStockSyncedAt(new \DateTimeImmutable());

                $this->entityManager->persist($product);

                if (count($payloads) < 20) {
                    $payloads[] = [
                        'reference' => $product->getReference(),
                        'properties' => $payload,
                    ];
                }

                ++$sent;
                ++$updated;
            } catch (\Throwable $e) {
                $errors[] = [
                    'reference' => $product->getReference(),
                    'message' => $e->getMessage(),
                ];

                $this->logger->error('HubSpot product stock sync error', [
                    'reference' => $product->getReference(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        return [
            'sent' => $sent,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'payloads' => $payloads,
            'mapping' => self::HUBSPOT_STOCK_MAPPING,
        ];
    }

    private function buildHubspotProperties(ErpProduct $product): array
    {
        $properties = [];

        foreach (self::HUBSPOT_STOCK_MAPPING as $hubspotField => $sageField) {
            $value = $product->getSageFieldValue($sageField);

            if ($value === null || $value === '') {
                continue;
            }

            $properties[$hubspotField] = $value;
        }

        return $properties;
    }

    private function findHubspotProductBySku(string $sku): ?array
    {
        $response = $this->hubSpotClient->searchObjects('products', [
            'limit' => 1,
            'properties' => ['hs_sku'],
            'filterGroups' => [
                [
                    'filters' => [
                        [
                            'propertyName' => 'hs_sku',
                            'operator' => 'EQ',
                            'value' => $sku,
                        ],
                    ],
                ],
            ],
        ]);

        $result = $response['results'][0] ?? null;

        return \is_array($result) ? $result : null;
    }
}

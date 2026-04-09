<?php

namespace App\Service\HubSpot;

use App\Entity\ErpProduct;
use App\Repository\ErpProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class HubspotProductSyncService
{
    private const HUBSPOT_FIELD_MAPPING = [
        'name' => 'designation',
        'hs_sku' => 'reference',
        'license' => 'catalogue4',
        'sage_product_status' => 'statut',
        'price' => 'prixTTC',
    ];

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly ErpProductRepository $erpProductRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncProducts(): array
    {
        $products = $this->erpProductRepository->findActiveProductsForSync();

        $sent = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $payloads = [];

        foreach ($products as $product) {
            $payload = $this->buildHubspotProperties($product);

            if (!isset($payload['hs_sku']) || !isset($payload['name'])) {
                ++$skipped;
                continue;
            }

            try {
                $existing = $this->findHubspotProductBySku((string) $payload['hs_sku']);

                if ($existing !== null) {
                    $response = $this->hubSpotClient->updateObject('products', (string) $existing['id'], $payload);
                    ++$updated;
                } else {
                    $response = $this->hubSpotClient->createObject('products', $payload);
                    ++$created;
                }

                $product
                    ->setHubspotObjectId((string) ($response['id'] ?? $existing['id'] ?? $product->getHubspotObjectId()))
                    ->setLastSyncedAt(new \DateTimeImmutable())
                    ->setUpdatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($product);

                $payloads[] = [
                    'reference' => $product->getReference(),
                    'properties' => $payload,
                ];

                ++$sent;
            } catch (\Throwable $e) {
                $errors[] = [
                    'reference' => $product->getReference(),
                    'message' => $e->getMessage(),
                ];

                $this->logger->error('HubSpot product sync error', [
                    'reference' => $product->getReference(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        return [
            'sent' => $sent,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'payloads' => $payloads,
            'mapping' => self::HUBSPOT_FIELD_MAPPING,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHubspotProperties(ErpProduct $product): array
    {
        $properties = [];

        foreach (self::HUBSPOT_FIELD_MAPPING as $hubspotField => $sageField) {
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
            'properties' => array_keys(self::HUBSPOT_FIELD_MAPPING),
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

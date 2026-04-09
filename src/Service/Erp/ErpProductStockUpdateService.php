<?php

namespace App\Service\Erp;

use App\Repository\ErpProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ErpProductStockUpdateService
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly SageClient $sageClient,
        private readonly ErpProductRepository $erpProductRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function updateStocksFromErp(): array
    {
        $products = $this->erpProductRepository->findActiveProductsForSync();

        $updated = 0;
        $skipped = 0;
        $errors = [];

        $references = array_values(array_filter(array_map(
            static fn ($product) => $product->getReference(),
            $products
        )));

        $total = count($references);
        $totalPages = (int) ceil($total / self::BATCH_SIZE);

        for ($page = 1; $page <= $totalPages; ++$page) {
            $offset = ($page - 1) * self::BATCH_SIZE;
            $batchReferences = array_slice($references, $offset, self::BATCH_SIZE);

            if ($batchReferences === []) {
                continue;
            }

            try {
                $response = $this->sageClient->get('/Stock?reference=' . implode(',', $batchReferences));
            } catch (\Throwable $e) {
                $errors[] = [
                    'references' => $batchReferences,
                    'message' => $e->getMessage(),
                ];

                $this->logger->error('ERP stock batch update error', [
                    'references' => $batchReferences,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($response as $row) {
                if (!\is_array($row)) {
                    ++$skipped;
                    continue;
                }

                $reference = trim((string) ($row['reference'] ?? ''));

                if ($reference === '') {
                    ++$skipped;
                    continue;
                }

                $product = $this->erpProductRepository->findOneByReference($reference);

                if ($product === null) {
                    ++$skipped;
                    continue;
                }

                $product
                    ->setStockReel($this->nullableFloat($row['stockReel'] ?? null))
                    ->setStockDispo($this->nullableFloat($row['stockDispo'] ?? null))
                    ->setStockATerme($this->nullableFloat($row['stockATerme'] ?? null))
                    ->setRawStockPayload($row)
                    ->setStockUpdatedAt(new \DateTimeImmutable())
                    ->setUpdatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($product);
                ++$updated;
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}

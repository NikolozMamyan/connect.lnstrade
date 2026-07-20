<?php

namespace App\Service\Erp;

use App\Entity\ErpProduct;
use App\Repository\ErpProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ErpProductExportService
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly SageClient $sageClient,
        private readonly ErpProductRepository $erpProductRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function importProductsFromErp(): array
    {
        $articles = $this->sageClient->get('/Articles');
        $activeArticles = array_values(array_filter(
            $articles,
            fn (mixed $article): bool => \is_array($article) && $this->isActiveArticle($article)
        ));

        $imported = 0;
        $created = 0;
        $updated = 0;
        $skipped = count($articles) - count($activeArticles);
        $errors = [];

        $total = count($activeArticles);
        $totalPages = (int) ceil($total / self::BATCH_SIZE);

        for ($page = 1; $page <= $totalPages; ++$page) {
            $offset = ($page - 1) * self::BATCH_SIZE;
            $batch = array_slice($activeArticles, $offset, self::BATCH_SIZE);
            $references = array_values(array_filter(array_map(
                fn (array $article): ?string => $this->nullableString($article['reference'] ?? null),
                $batch
            )));
            $existingProducts = $this->erpProductRepository->findIndexedByReferences($references);

            foreach ($batch as $article) {
                $reference = $this->nullableString($article['reference'] ?? null);

                if ($reference === null) {
                    ++$skipped;
                    continue;
                }

                try {
                    $product = $existingProducts[$reference] ?? null;

                    if (!$product instanceof ErpProduct) {
                        $product = new ErpProduct();
                        $product->setReference($reference);
                        $existingProducts[$reference] = $product;
                        ++$created;
                    } else {
                        if ($product->getRawPayload() === $article) {
                            ++$skipped;
                            continue;
                        }

                        ++$updated;
                    }

                    $this->hydrateProduct($product, $article);
                    $this->entityManager->persist($product);
                    ++$imported;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'reference' => $reference,
                        'message' => $e->getMessage(),
                    ];

                    $this->logger->error('ERP product import error', [
                        'reference' => $reference,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return [
            'imported' => $imported,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function hydrateProduct(ErpProduct $product, array $article): void
    {
        $product
            ->setDesignation($this->nullableString($article['designation'] ?? null))
            ->setFamille($this->nullableString($article['famille'] ?? null))
            ->setUnitePoids($this->nullableString($article['unitePoids'] ?? null))
            ->setPoidsNet($this->nullableFloat($article['poidsNet'] ?? null))
            ->setPoidsBrut($this->nullableFloat($article['poidsBrut'] ?? null))
            ->setUniteVente($this->nullableString($article['uniteVente'] ?? null))
            ->setPrixAchat($this->nullableFloat($article['prixAchat'] ?? null))
            ->setPrixVente($this->nullableFloat($article['prixVente'] ?? null))
            ->setPrixTtc($this->nullableFloat($article['prixTTC'] ?? null))
            ->setSuiviStock($this->nullableString($article['suiviStock'] ?? null))
            ->setStatut($this->nullableString($article['statut'] ?? null))
            ->setAnglais($this->nullableString($article['anglais'] ?? null))
            ->setEspagnol($this->nullableString($article['espagnol'] ?? null))
            ->setCodeBarre($this->nullableString($article['codeBarre'] ?? null))
            ->setCodeFiscal($this->nullableString($article['codeFiscal'] ?? null))
            ->setCodeEdi($this->nullableString($article['codeEdi'] ?? null))
            ->setPays($this->nullableString($article['pays'] ?? null))
            ->setConditionnement($this->nullableString($article['conditionnement'] ?? null))
            ->setCatalogue1($this->nullableString($article['catalogue1'] ?? null))
            ->setCatalogue2($this->nullableString($article['catalogue2'] ?? null))
            ->setCatalogue3($this->nullableString($article['catalogue3'] ?? null))
            ->setCatalogue4($this->nullableString($article['catalogue4'] ?? null))
            ->setDateCreation($this->toDateTimeImmutable($article['dateCreation'] ?? null))
            ->setChampsLibres(isset($article['champsLibres']) && \is_array($article['champsLibres']) ? $article['champsLibres'] : null)
            ->setRawPayload($article)
            ->setUpdatedAt(new \DateTimeImmutable());

        if ($product->getCreatedAt() === null) {
            $product->setCreatedAt(new \DateTimeImmutable());
        }
    }

    private function isActiveArticle(array $article): bool
    {
        return $this->nullableString($article['statut'] ?? null) === 'Actif';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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

    private function toDateTimeImmutable(mixed $value): ?\DateTimeImmutable
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

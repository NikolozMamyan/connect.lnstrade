<?php

namespace App\Repository;

use App\Entity\ErpProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErpProduct>
 */
class ErpProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErpProduct::class);
    }

    public function findOneByReference(string $reference): ?ErpProduct
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.reference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return ErpProduct[]
     */
    public function searchByTerm(string $term, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('
                LOWER(p.reference) LIKE :term
                OR LOWER(p.designation) LIKE :term
                OR LOWER(p.codeBarre) LIKE :term
                OR LOWER(p.hubspotObjectId) LIKE :term
            ')
            ->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('p.reference', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ErpProduct[]
     */
    public function findActiveProductsForSync(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.statut = :statut')
            ->setParameter('statut', 'Actif')
            ->orderBy('p.reference', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<string>
     */
    public function findActiveProductReferences(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.reference')
            ->andWhere('p.statut = :statut')
            ->andWhere('p.reference IS NOT NULL')
            ->setParameter('statut', 'Actif')
            ->orderBy('p.reference', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(
            static fn (array $row): string => (string) $row['reference'],
            $rows
        ));
    }

    /**
     * @return ErpProduct[]
     */
    public function findProductsForCatalogSync(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.statut = :statut')
            ->andWhere('p.lastSyncedAt IS NULL OR p.updatedAt > p.lastSyncedAt')
            ->setParameter('statut', 'Actif')
            ->orderBy('p.reference', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ErpProduct[]
     */
    public function findProductsForStockSync(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.statut = :statut')
            ->andWhere('p.stockUpdatedAt IS NOT NULL')
            ->andWhere('p.stockSyncedAt IS NULL OR p.stockUpdatedAt > p.stockSyncedAt')
            ->setParameter('statut', 'Actif')
            ->orderBy('p.reference', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $references
     *
     * @return array<string, ErpProduct>
     */
    public function findIndexedByReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $products = $this->createQueryBuilder('p')
            ->andWhere('p.reference IN (:references)')
            ->setParameter('references', array_values(array_unique($references)))
            ->getQuery()
            ->getResult();
        $indexed = [];

        foreach ($products as $product) {
            $reference = $product->getReference();

            if ($reference !== null) {
                $indexed[$reference] = $product;
            }
        }

        return $indexed;
    }
}

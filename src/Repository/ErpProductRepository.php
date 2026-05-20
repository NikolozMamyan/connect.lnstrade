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
}

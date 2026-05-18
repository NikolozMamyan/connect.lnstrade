<?php

namespace App\Repository;

use App\Entity\Deal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Deal>
 */
class DealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deal::class);
    }

    /**
     * @return Deal[]
     */
    public function findLatestWithLineItems(int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.commercial', 'c')
            ->addSelect('c')
            ->leftJoin('d.lineItems', 'li')
            ->addSelect('li')
            ->orderBy('d.submittedAt', 'DESC')
            ->addOrderBy('li.position', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumTotalAmount(): float
    {
        return (float) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.totalAmount), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}

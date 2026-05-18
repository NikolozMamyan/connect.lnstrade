<?php

namespace App\Repository;

use App\Entity\OrderForm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderForm>
 */
class OrderFormRepository extends ServiceEntityRepository
{
    public const STATUS_FAILED = 'failed';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderForm::class);
    }

    public function referenceExists(string $referenceNumber): bool
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.referenceNumber = :referenceNumber')
            ->setParameter('referenceNumber', $referenceNumber)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @return OrderForm[]
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.commercial', 'c')
            ->addSelect('c')
            ->andWhere('o.status = :status')
            ->setParameter('status', $status)
            ->orderBy('o.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

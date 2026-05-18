<?php

namespace App\Repository;

use App\Entity\Commercial;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commercial>
 */
class CommercialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commercial::class);
    }

    /**
     * @return Commercial[]
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('c.lastName', 'ASC')
            ->addOrderBy('c.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

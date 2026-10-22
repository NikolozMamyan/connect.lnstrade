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

    /** @return Commercial[] */
    public function findActiveSageRepresentatives(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.isActive = :active')
            ->andWhere('c.sageFirstName IS NOT NULL AND c.sageLastName IS NOT NULL')
            ->setParameter('active', true)
            ->orderBy('c.sageLastName', 'ASC')
            ->addOrderBy('c.sageFirstName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

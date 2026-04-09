<?php

namespace App\Repository;

use App\Entity\SyncLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncLog>
 */
class SyncLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncLog::class);
    }

    /**
     * @param list<string> $fluxKeys
     *
     * @return SyncLog[]
     */
    public function findLatestByFluxKeys(array $fluxKeys, int $limit = 100): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.fluxKey IN (:fluxKeys)')
            ->setParameter('fluxKeys', $fluxKeys)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

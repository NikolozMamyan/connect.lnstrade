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

    /**
     * @return SyncLog[]
     */
    public function findLatest(int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function countByLevelSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.level AS level, COUNT(l.id) AS total')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('l.level')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $level = (string) ($row['level'] ?? '');

            if ($level === '') {
                continue;
            }

            $counts[$level] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countByFluxSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.fluxKey AS fluxKey, COUNT(l.id) AS total')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('l.fluxKey')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $fluxKey = (string) ($row['fluxKey'] ?? '');

            if ($fluxKey === '') {
                continue;
            }

            $counts[$fluxKey] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    public function countOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}

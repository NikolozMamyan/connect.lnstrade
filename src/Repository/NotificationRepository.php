<?php

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findLatest(int $limit = 8): array
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countUnread(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllAsRead(): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', ':isRead')
            ->where('n.isRead = :current')
            ->setParameter('isRead', true)
            ->setParameter('current', false)
            ->getQuery()
            ->execute();
    }

    public function countReadOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.isRead = :isRead')
            ->andWhere('n.createdAt < :before')
            ->setParameter('isRead', true)
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteReadOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.isRead = :isRead')
            ->andWhere('n.createdAt < :before')
            ->setParameter('isRead', true)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}

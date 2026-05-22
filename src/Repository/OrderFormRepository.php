<?php

namespace App\Repository;

use App\Entity\Commercial;
use App\Entity\OrderForm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderForm>
 */
class OrderFormRepository extends ServiceEntityRepository
{
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';

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
    public function searchByTerm(string $term, int $limit = 25): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.commercial', 'c')
            ->addSelect('c')
            ->andWhere('
                LOWER(o.referenceNumber) LIKE :term
                OR LOWER(o.dealId) LIKE :term
                OR LOWER(o.enterpriseId) LIKE :term
                OR LOWER(c.firstName) LIKE :term
                OR LOWER(c.lastName) LIKE :term
            ')
            ->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('o.submittedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByCommercial(Commercial $commercial): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.commercial = :commercial')
            ->setParameter('commercial', $commercial)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status, COUNT(o.id) AS total')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');

            if ($status === '') {
                continue;
            }

            $counts[$status] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countByStatusForCommercial(Commercial $commercial): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status, COUNT(o.id) AS total')
            ->andWhere('o.commercial = :commercial')
            ->setParameter('commercial', $commercial)
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');

            if ($status === '') {
                continue;
            }

            $counts[$status] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countLastDays(int $days = 7): array
    {
        $since = new \DateTimeImmutable(sprintf('-%d days', max(1, $days - 1)));
        $rows = $this->createQueryBuilder('o')
            ->select('o.submittedAt AS submittedAt')
            ->andWhere('o.submittedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('o.submittedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $counts = [];

        for ($i = $days - 1; $i >= 0; --$i) {
            $date = new \DateTimeImmutable(sprintf('-%d days', $i));
            $counts[$date->format('Y-m-d')] = 0;
        }

        foreach ($rows as $row) {
            $submittedAt = $row['submittedAt'] ?? null;

            if (!$submittedAt instanceof \DateTimeImmutable) {
                continue;
            }

            $key = $submittedAt->format('Y-m-d');

            if (array_key_exists($key, $counts)) {
                ++$counts[$key];
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countLastDaysForCommercial(Commercial $commercial, int $days = 7): array
    {
        $since = new \DateTimeImmutable(sprintf('-%d days', max(1, $days - 1)));
        $rows = $this->createQueryBuilder('o')
            ->select('o.submittedAt AS submittedAt')
            ->andWhere('o.submittedAt >= :since')
            ->andWhere('o.commercial = :commercial')
            ->setParameter('since', $since)
            ->setParameter('commercial', $commercial)
            ->orderBy('o.submittedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $counts = [];

        for ($i = $days - 1; $i >= 0; --$i) {
            $date = new \DateTimeImmutable(sprintf('-%d days', $i));
            $counts[$date->format('Y-m-d')] = 0;
        }

        foreach ($rows as $row) {
            $submittedAt = $row['submittedAt'] ?? null;

            if (!$submittedAt instanceof \DateTimeImmutable) {
                continue;
            }

            $key = $submittedAt->format('Y-m-d');

            if (array_key_exists($key, $counts)) {
                ++$counts[$key];
            }
        }

        return $counts;
    }

    /**
     * @return OrderForm[]
     */
    public function findLatestForCommercial(Commercial $commercial, int $limit = 8): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.commercial', 'c')
            ->addSelect('c')
            ->andWhere('o.commercial = :commercial')
            ->setParameter('commercial', $commercial)
            ->orderBy('o.submittedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

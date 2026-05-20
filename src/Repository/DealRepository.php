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

    /**
     * @return Deal[]
     */
    public function searchByTerm(string $term, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.commercial', 'c')
            ->addSelect('c')
            ->leftJoin('d.lineItems', 'li')
            ->addSelect('li')
            ->andWhere('
                LOWER(d.referenceNumber) LIKE :term
                OR LOWER(d.dealId) LIKE :term
                OR LOWER(d.enterpriseId) LIKE :term
                OR LOWER(c.firstName) LIKE :term
                OR LOWER(c.lastName) LIKE :term
            ')
            ->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('d.submittedAt', 'DESC')
            ->addOrderBy('li.position', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByDealIdWithLineItems(string $dealId): ?Deal
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.commercial', 'c')
            ->addSelect('c')
            ->leftJoin('d.lineItems', 'li')
            ->addSelect('li')
            ->andWhere('d.dealId = :dealId')
            ->setParameter('dealId', $dealId)
            ->orderBy('li.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function sumTotalAmount(): float
    {
        return (float) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.totalAmount), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, array{name: string, deals: int, amount: float}>
     */
    public function findTopCommercials(int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select("CONCAT(COALESCE(c.firstName, ''), ' ', COALESCE(c.lastName, '')) AS fullName")
            ->addSelect('COUNT(d.id) AS totalDeals')
            ->addSelect('COALESCE(SUM(d.totalAmount), 0) AS totalAmount')
            ->leftJoin('d.commercial', 'c')
            ->groupBy('c.id, c.firstName, c.lastName')
            ->orderBy('totalAmount', 'DESC')
            ->addOrderBy('totalDeals', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $name = trim((string) ($row['fullName'] ?? ''));

            return [
                'name' => $name !== '' ? $name : 'Commercial inconnu',
                'deals' => (int) ($row['totalDeals'] ?? 0),
                'amount' => (float) ($row['totalAmount'] ?? 0),
            ];
        }, $rows);
    }
}

<?php

namespace App\Repository;

use App\Entity\Commercial;
use App\Entity\DealLineItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DealLineItem>
 */
class DealLineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DealLineItem::class);
    }

    /**
     * @return array<int, array{reference: string, lines: int, quantity: float}>
     */
    public function findTopReferences(int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('li')
            ->select('li.articleRef AS articleRef')
            ->addSelect('COUNT(li.id) AS totalLines')
            ->addSelect('COALESCE(SUM(li.quantity), 0) AS totalQuantity')
            ->andWhere('li.articleRef IS NOT NULL')
            ->andWhere("li.articleRef <> ''")
            ->groupBy('li.articleRef')
            ->orderBy('totalQuantity', 'DESC')
            ->addOrderBy('totalLines', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            return [
                'reference' => (string) ($row['articleRef'] ?? ''),
                'lines' => (int) ($row['totalLines'] ?? 0),
                'quantity' => (float) ($row['totalQuantity'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array{reference: string, lines: int, quantity: float}>
     */
    public function findTopReferencesForCommercial(Commercial $commercial, int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('li')
            ->select('li.articleRef AS articleRef')
            ->addSelect('COUNT(li.id) AS totalLines')
            ->addSelect('COALESCE(SUM(li.quantity), 0) AS totalQuantity')
            ->leftJoin('li.deal', 'd')
            ->andWhere('d.commercial = :commercial')
            ->andWhere('li.articleRef IS NOT NULL')
            ->andWhere("li.articleRef <> ''")
            ->setParameter('commercial', $commercial)
            ->groupBy('li.articleRef')
            ->orderBy('totalQuantity', 'DESC')
            ->addOrderBy('totalLines', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            return [
                'reference' => (string) ($row['articleRef'] ?? ''),
                'lines' => (int) ($row['totalLines'] ?? 0),
                'quantity' => (float) ($row['totalQuantity'] ?? 0),
            ];
        }, $rows);
    }
}

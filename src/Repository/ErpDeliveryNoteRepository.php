<?php

namespace App\Repository;

use App\Entity\ErpDeliveryNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErpDeliveryNote>
 */
class ErpDeliveryNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErpDeliveryNote::class);
    }

    public function findOneBySageKey(string $sageKey): ?ErpDeliveryNote
    {
        return $this->findOneBy(['sageKey' => trim($sageKey)]);
    }

    public function findOneByInvoicePiece(string $piece): ?ErpDeliveryNote
    {
        return $this->findOneBy(['invoicePiece' => trim($piece)]);
    }

    /**
     * @return ErpDeliveryNote[]
     */
    public function findPotentialInvoiceMatches(string $clientId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.clientId = :clientId')
            ->andWhere('n.invoicePiece IS NULL')
            ->andWhere('n.piece LIKE :deliveryNotePrefix')
            ->setParameter('clientId', trim($clientId))
            ->setParameter('deliveryNotePrefix', 'BL%')
            ->orderBy('n.documentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ErpDeliveryNote[]
     */
    public function findPaginated(string $search, string $status, int $page, int $perPage): array
    {
        return $this->createFilteredQueryBuilder($search, $status)
            ->orderBy('n.documentDate', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(string $search, string $status): int
    {
        return (int) $this->createFilteredQueryBuilder($search, $status)
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.status AS status, COUNT(n.id) AS total')
            ->groupBy('n.status')
            ->getQuery()
            ->getArrayResult();
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    private function createFilteredQueryBuilder(string $search, string $status): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('n');

        if ($search !== '') {
            $queryBuilder
                ->andWhere('LOWER(n.piece) LIKE :term OR LOWER(n.invoicePiece) LIKE :term OR LOWER(n.reference) LIKE :term OR LOWER(n.clientId) LIKE :term OR LOWER(n.clientName) LIKE :term OR LOWER(n.hubspotOrderId) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($search).'%');
        }

        if (in_array($status, [
            ErpDeliveryNote::STATUS_DISCOVERED,
            ErpDeliveryNote::STATUS_PROCESSING,
            ErpDeliveryNote::STATUS_SENT,
            ErpDeliveryNote::STATUS_FAILED,
            ErpDeliveryNote::STATUS_CHANGED,
        ], true)) {
            $queryBuilder
                ->andWhere('n.status = :status')
                ->setParameter('status', $status);
        }

        return $queryBuilder;
    }
}

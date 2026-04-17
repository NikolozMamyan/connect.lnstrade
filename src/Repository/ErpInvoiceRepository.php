<?php

namespace App\Repository;

use App\Entity\ErpInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErpInvoice>
 */
class ErpInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErpInvoice::class);
    }

    public function findOneByInvoiceNumber(string $invoiceNumber): ?ErpInvoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.invoiceNumber = :invoiceNumber')
            ->setParameter('invoiceNumber', $invoiceNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return ErpInvoice[]
     */
    public function findPaginated(string $search = '', string $clientId = '', string $documentType = '', int $page = 1, int $perPage = 25): array
    {
        return $this->createFilteredQueryBuilder($search, $clientId, $documentType)
            ->addSelect("CASE WHEN i.invoiceNumber LIKE 'FA%' THEN 0 WHEN i.invoiceNumber LIKE 'FV%' THEN 1 ELSE 2 END AS HIDDEN documentSort")
            ->orderBy('documentSort', 'ASC')
            ->addOrderBy('i.invoiceNumber', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(string $search = '', string $clientId = '', string $documentType = ''): int
    {
        return (int) $this->createFilteredQueryBuilder($search, $clientId, $documentType)
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createFilteredQueryBuilder(string $search, string $clientId, string $documentType)
    {
        $qb = $this->createQueryBuilder('i');

        if ($search !== '') {
            $qb
                ->andWhere('LOWER(i.invoiceNumber) LIKE :needle OR LOWER(i.clientId) LIKE :needle')
                ->setParameter('needle', '%' . mb_strtolower($search) . '%');
        }

        if ($clientId !== '') {
            $qb
                ->andWhere('LOWER(i.clientId) = :clientId')
                ->setParameter('clientId', mb_strtolower($clientId));
        }

        if ($documentType === 'facture') {
            $qb->andWhere('i.invoiceNumber LIKE :invoicePrefix')->setParameter('invoicePrefix', 'FA%');
        } elseif ($documentType === 'avoir') {
            $qb->andWhere('i.invoiceNumber LIKE :invoicePrefix')->setParameter('invoicePrefix', 'FV%');
        }

        return $qb;
    }
}

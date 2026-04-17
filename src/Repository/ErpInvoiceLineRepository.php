<?php

namespace App\Repository;

use App\Entity\ErpInvoiceLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErpInvoiceLine>
 */
class ErpInvoiceLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErpInvoiceLine::class);
    }
}

<?php

namespace App\Repository;

use App\Entity\ErpOrderExport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErpOrderExport>
 */
class ErpOrderExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErpOrderExport::class);
    }

    public function findOneByHubspotEventId(string $hubspotEventId): ?ErpOrderExport
    {
        return $this->findOneBy(['hubspotEventId' => trim($hubspotEventId)]);
    }
}

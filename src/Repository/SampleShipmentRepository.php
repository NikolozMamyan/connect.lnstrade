<?php

namespace App\Repository;

use App\Entity\SampleShipment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SampleShipment>
 */
class SampleShipmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SampleShipment::class);
    }

    public function findOneByRequestToken(string $requestToken): ?SampleShipment
    {
        return $this->findOneBy(['requestToken' => trim($requestToken)]);
    }
}

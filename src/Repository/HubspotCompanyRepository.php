<?php

namespace App\Repository;

use App\Entity\HubspotCompany;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HubspotCompany>
 */
class HubspotCompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HubspotCompany::class);
    }

    public function findOneByHubspotId(string $hubspotId): ?HubspotCompany
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.hubspotId = :hubspotId')
            ->setParameter('hubspotId', $hubspotId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(HubspotCompany $company, bool $flush = false): void
    {
        $this->getEntityManager()->persist($company);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(HubspotCompany $company, bool $flush = false): void
    {
        $this->getEntityManager()->remove($company);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return HubspotCompany[]
     */
    public function findUpdatedSince(\DateTimeImmutable $dateTime): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.hubspotUpdatedAt >= :dateTime')
            ->setParameter('dateTime', $dateTime)
            ->orderBy('c.hubspotUpdatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return HubspotCompany[]
     */
    public function findByCountry(string $country): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.country = :country')
            ->setParameter('country', $country)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllWithContacts(): array
{
    return $this->createQueryBuilder('c')
        ->leftJoin('c.companyContacts', 'cc')
        ->addSelect('cc')
        ->leftJoin('cc.contact', 'contact')
        ->addSelect('contact')
        ->orderBy('c.name', 'ASC')
        ->getQuery()
        ->getResult();
}
}
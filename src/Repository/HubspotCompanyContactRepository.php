<?php

namespace App\Repository;

use App\Entity\HubspotCompany;
use App\Entity\HubspotCompanyContact;
use App\Entity\HubspotContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HubspotCompanyContact>
 */
class HubspotCompanyContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HubspotCompanyContact::class);
    }

    public function save(HubspotCompanyContact $companyContact, bool $flush = false): void
    {
        $this->getEntityManager()->persist($companyContact);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(HubspotCompanyContact $companyContact, bool $flush = false): void
    {
        $this->getEntityManager()->remove($companyContact);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

public function findOneRelationByHubspotIds(
    string $companyHubspotId,
    string $contactHubspotId,
    string $associationType
): ?HubspotCompanyContact {
    return $this->createQueryBuilder('cc')
        ->join('cc.company', 'company')
        ->join('cc.contact', 'contact')
        ->andWhere('company.hubspotId = :companyHubspotId')
        ->andWhere('contact.hubspotId = :contactHubspotId')
        ->andWhere('cc.associationType = :associationType')
        ->setParameter('companyHubspotId', $companyHubspotId)
        ->setParameter('contactHubspotId', $contactHubspotId)
        ->setParameter('associationType', $associationType)
        ->getQuery()
        ->getOneOrNullResult();
}

    /**
     * @return HubspotCompanyContact[]
     */
    public function findByCompany(HubspotCompany $company): array
    {
        return $this->createQueryBuilder('cc')
            ->leftJoin('cc.contact', 'contact')
            ->addSelect('contact')
            ->andWhere('cc.company = :company')
            ->setParameter('company', $company)
            ->orderBy('contact.lastname', 'ASC')
            ->addOrderBy('contact.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return HubspotCompanyContact[]
     */
    public function findByContact(HubspotContact $contact): array
    {
        return $this->createQueryBuilder('cc')
            ->leftJoin('cc.company', 'company')
            ->addSelect('company')
            ->andWhere('cc.contact = :contact')
            ->setParameter('contact', $contact)
            ->orderBy('company.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteRelationsForCompany(HubspotCompany $company): int
    {
        return $this->createQueryBuilder('cc')
            ->delete()
            ->andWhere('cc.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->execute();
    }
}
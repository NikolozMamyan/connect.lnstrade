<?php

namespace App\Repository;

use App\Entity\HubspotContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HubspotContact>
 */
class HubspotContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HubspotContact::class);
    }

    public function findOneByHubspotId(string $hubspotId): ?HubspotContact
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.hubspotId = :hubspotId')
            ->setParameter('hubspotId', $hubspotId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByEmail(string $email): ?HubspotContact
    {
        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(HubspotContact $contact, bool $flush = false): void
    {
        $this->getEntityManager()->persist($contact);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(HubspotContact $contact, bool $flush = false): void
    {
        $this->getEntityManager()->remove($contact);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return HubspotContact[]
     */
    public function searchByNameOrEmail(string $term, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('
                LOWER(c.firstname) LIKE LOWER(:term)
                OR LOWER(c.lastname) LIKE LOWER(:term)
                OR LOWER(c.email) LIKE LOWER(:term)
            ')
            ->setParameter('term', '%' . $term . '%')
            ->orderBy('c.lastname', 'ASC')
            ->addOrderBy('c.firstname', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
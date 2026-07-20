<?php

namespace App\Repository;

use App\Entity\LnsDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LnsDocument>
 */
class LnsDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LnsDocument::class);
    }

    /**
     * @return list<LnsDocument>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('document')
            ->leftJoin('document.createdBy', 'author')
            ->addSelect('author')
            ->orderBy('document.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<LnsDocument>
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('document')
            ->leftJoin('document.createdBy', 'author')
            ->addSelect('author')
            ->andWhere('LOWER(document.title) LIKE :query OR LOWER(document.description) LIKE :query')
            ->setParameter('query', '%' . mb_strtolower(trim($query)) . '%')
            ->orderBy('document.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

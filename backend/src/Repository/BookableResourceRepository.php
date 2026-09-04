<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookableResource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BookableResource> */
final class BookableResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookableResource::class);
    }

    /** @return list<BookableResource> */
    public function findForAdministration(): array
    {
        return $this->findBy([], ['active' => 'DESC', 'name' => 'ASC']);
    }
}

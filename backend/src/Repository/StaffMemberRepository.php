<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StaffMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StaffMember> */
final class StaffMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffMember::class);
    }

    /** @return list<StaffMember> */
    public function findForAdministration(): array
    {
        return $this->createQueryBuilder('staff')
            ->orderBy('staff.active', 'DESC')
            ->addOrderBy('staff.position', 'ASC')
            ->addOrderBy('staff.lastName', 'ASC')
            ->addOrderBy('staff.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

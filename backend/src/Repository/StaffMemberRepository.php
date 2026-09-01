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
        return $this->createQueryBuilder('member')
            ->orderBy('member.active', 'DESC')
            ->addOrderBy('member.position', 'ASC')
            ->addOrderBy('member.lastName', 'ASC')
            ->addOrderBy('member.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

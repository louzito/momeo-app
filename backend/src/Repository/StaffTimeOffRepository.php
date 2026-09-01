<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StaffMember;
use App\Entity\StaffTimeOff;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StaffTimeOff> */
final class StaffTimeOffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffTimeOff::class);
    }

    /** @return list<StaffTimeOff> */
    public function findBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('timeOff')
            ->andWhere('timeOff.startsAt < :to')
            ->andWhere('timeOff.endsAt > :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('timeOff.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasOverlap(StaffMember $staff, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return (int) $this->createQueryBuilder('timeOff')
            ->select('COUNT(timeOff.id)')
            ->andWhere('timeOff.staffMember = :staff')
            ->andWhere('timeOff.startsAt < :end')
            ->andWhere('timeOff.endsAt > :start')
            ->setParameter('staff', $staff)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}

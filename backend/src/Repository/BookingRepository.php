<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\StaffMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Booking> */
final class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /** @return list<Booking> */
    public function findForAdministration(): array
    {
        return $this->findBy([], ['slotStart' => 'ASC']);
    }

    /** @return list<Booking> */
    public function findBlockingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('booking')
            ->andWhere('booking.status = :status')
            ->andWhere('booking.slotStart < :to')
            ->andWhere('booking.slotEnd > :from')
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    public function hasOverlap(StaffMember $staffMember, \DateTimeImmutable $start, \DateTimeImmutable $end, ?Booking $ignored = null): bool
    {
        $builder = $this->createQueryBuilder('booking')
            ->select('COUNT(booking.id)')
            ->andWhere('booking.staffMember = :staff')
            ->andWhere('booking.status = :status')
            ->andWhere('booking.slotStart < :end')
            ->andWhere('booking.slotEnd > :start')
            ->setParameter('staff', $staffMember)
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($ignored?->getId() !== null) {
            $builder->andWhere('booking.id != :ignored')->setParameter('ignored', $ignored->getId());
        }

        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

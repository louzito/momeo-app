<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WaitlistRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WaitlistRequest> */
final class WaitlistRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WaitlistRequest::class); }

    /** @return list<WaitlistRequest> */
    public function findActiveMatching(string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('request')
            ->andWhere('request.status = :status')->andWhere('request.serviceCode = :service')
            ->andWhere('request.periodStart < :end')->andWhere('request.periodEnd > :start')
            ->setParameter('status', WaitlistRequest::STATUS_ACTIVE)->setParameter('service', $serviceCode)
            ->setParameter('start', $start)->setParameter('end', $end)
            ->orderBy('request.createdAt', 'ASC')->getQuery()->getResult();
    }
}

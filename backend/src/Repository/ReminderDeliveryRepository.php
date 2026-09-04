<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReminderDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReminderDelivery> */
final class ReminderDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReminderDelivery::class);
    }
}

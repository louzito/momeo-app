<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GiftCard> */
class GiftCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GiftCard::class); }

    /** Lecture courante InnoDB, même si une transaction englobante a déjà ouvert un snapshot. */
    public function findIssuedForOrder(string $number, int $line = 0): ?GiftCard
    {
        return $this->createQueryBuilder('c')->where('c.purchaseOrderNumber = :number')->setParameter('number', $number)->andWhere('c.purchaseLine = :line')->setParameter('line', $line)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }
}

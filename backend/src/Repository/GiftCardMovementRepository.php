<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftCard;
use App\Entity\GiftCardMovement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GiftCardMovement> */
class GiftCardMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GiftCardMovement::class); }

    /** @return list<GiftCardMovement> Appeler après verrouillage de la commande et, si fourni, de la carte. */
    public function findForOrderForUpdate(string $number, ?GiftCard $card = null): array
    {
        $query = $this->createQueryBuilder('m')->where('m.orderNumber = :number')->setParameter('number', $number)->orderBy('m.id', 'ASC');
        if ($card !== null) $query->andWhere('m.card = :card')->setParameter('card', $card);
        return $query->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getResult();
    }
}

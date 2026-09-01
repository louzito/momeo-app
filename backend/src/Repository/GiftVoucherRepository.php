<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GiftVoucher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GiftVoucher>
 */
final class GiftVoucherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GiftVoucher::class);
    }

    public function findOneByCode(string $code): ?GiftVoucher
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** À appeler dans une transaction active pour sérialiser l'utilisation d'un chèque. */
    public function findOneByCodeForUpdate(string $code): ?GiftVoucher
    {
        return $this->createQueryBuilder('voucher')
            ->andWhere('voucher.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findOneByPurchaseOrderNumber(string $orderNumber): ?GiftVoucher
    {
        return $this->findOneBy(['purchaseOrderNumber' => $orderNumber]);
    }

    public function codeExists(string $code): bool
    {
        return null !== $this->findOneByCode($code);
    }

    /** @return GiftVoucher[] */
    public function findByEmail(string $email): array
    {
        return $this->findBy(['beneficiaryEmail' => $email], ['createdAt' => 'DESC']);
    }

    /** @return array<string, int> {awaiting_payment, active, used, expired} */
    public function countByEffectiveStatus(): array
    {
        $counts = [
            GiftVoucher::STATUS_AWAITING_PAYMENT => 0,
            GiftVoucher::STATUS_ACTIVE => 0,
            GiftVoucher::STATUS_USED => 0,
            GiftVoucher::STATUS_EXPIRED => 0,
        ];

        // Volume attendu par centre : quelques centaines/milliers de cheques
        // au grand maximum -> un select + boucle en PHP reste largement
        // suffisant et evite de dupliquer la logique d'expiration en SQL.
        foreach ($this->findAll() as $voucher) {
            $counts[$voucher->getEffectiveStatus()] = ($counts[$voucher->getEffectiveStatus()] ?? 0) + 1;
        }

        return $counts;
    }
}

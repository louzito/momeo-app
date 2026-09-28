<?php

declare(strict_types=1);

namespace App\Service\Customer;

use App\Entity\GiftCard;
use App\Entity\GiftCardMovement;
use App\Entity\Order\Order;
use App\Entity\User\ShopUser;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Le code aléatoire constitue la preuve de possession ; aucun rattachement par e-mail. */
final class CustomerGiftCards
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TenantContext $tenant) {}

    public function lists(ShopUser $user): array
    {
        $customer = $user->getCustomer();
        if ($customer?->getId() === null) return ['received' => [], 'purchased' => []];
        $received = $this->em->getRepository(GiftCard::class)->findBy(
            ['beneficiary' => $customer, 'establishment' => $this->tenant->getSlug()], ['createdAt' => 'DESC'],
        );
        $purchased = $this->em->createQueryBuilder()->select('c')->from(GiftCard::class, 'c')
            ->innerJoin(Order::class, 'o', 'WITH', 'o.number = c.purchaseOrderNumber')
            ->where('o.customer = :customer')->andWhere('c.establishment = :tenant')
            ->setParameter('customer', $customer)->setParameter('tenant', $this->tenant->getSlug())
            ->orderBy('c.createdAt', 'DESC')->getQuery()->getResult();

        return [
            'received' => array_map($this->received(...), $received),
            // L'acheteur voit son achat, jamais l'utilisation privée du bénéficiaire.
            'purchased' => array_map(static fn (GiftCard $card): array => [
                'id' => $card->getId(), 'amount' => $card->getInitialAmount(), 'currency' => $card->getCurrency(),
                'purchaseOrderNumber' => $card->getPurchaseOrderNumber(),
                'createdAt' => $card->getCreatedAt()->format(DATE_ATOM),
                'expiresAt' => $card->getExpiresAt()->format(DATE_ATOM),
            ], $purchased),
        ];
    }

    public function claim(ShopUser $user, string $code): void
    {
        $customer = $user->getCustomer();
        if ($customer?->getId() === null || !preg_match('/^[A-F0-9]{32}$/D', $code)) {
            throw new \DomainException('Cette carte ne peut pas être rattachée à ce compte.');
        }
        $this->em->wrapInTransaction(function () use ($customer, $code): void {
            $card = $this->em->getRepository(GiftCard::class)->findOneBy(['code' => $code, 'establishment' => $this->tenant->getSlug()]);
            if (!$card instanceof GiftCard) throw new \DomainException('Cette carte ne peut pas être rattachée à ce compte.');
            // Relire sous verrou avant l'attribution : deux comptes ne peuvent pas se l'approprier.
            $this->em->refresh($card, LockMode::PESSIMISTIC_WRITE);
            $card->claim($customer);
        });
    }

    private function received(GiftCard $card): array
    {
        $movements = $this->em->getRepository(GiftCardMovement::class)->findBy(['card' => $card], ['id' => 'DESC'], 51);
        return [
            'id' => $card->getId(), 'code' => $card->getCode(), 'currency' => $card->getCurrency(),
            'initialAmount' => $card->getInitialAmount(), 'available' => $card->getAvailable(), 'reserved' => $card->getReserved(),
            'status' => $card->getStatus(), 'expiresAt' => $card->getExpiresAt()->format(DATE_ATOM),
            'hasMoreHistory' => count($movements) > 50,
            // Les références de commande et de remboursement ne sont pas des données du portefeuille.
            'history' => array_map(static fn (GiftCardMovement $movement): array => [
                'kind' => $movement->getKind(), 'amount' => $movement->getAmount(),
                'availableAfter' => $movement->getAvailableAfter(), 'reservedAfter' => $movement->getReservedAfter(),
                'createdAt' => $movement->getCreatedAt()->format(DATE_ATOM),
            ], array_slice($movements, 0, 50)),
        ];
    }
}

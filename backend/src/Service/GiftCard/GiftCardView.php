<?php

declare(strict_types=1);

namespace App\Service\GiftCard;

use App\Entity\GiftCard;
use App\Entity\GiftCardMovement;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

final class GiftCardView
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TenantContext $tenant) {}

    public function cards(int $page): array
    {
        $cards = $this->em->getRepository(GiftCard::class)->findBy(['establishment' => $this->tenant->getSlug()], ['id' => 'DESC'], 51, ($page - 1) * 50);
        return ['hasMore' => count($cards) > 50, 'member' => array_map(static fn (GiftCard $card): array => [
            'id' => $card->getId(), 'code' => $card->getCode(), 'currency' => $card->getCurrency(),
            'initialAmount' => $card->getInitialAmount(), 'available' => $card->getAvailable(), 'reserved' => $card->getReserved(),
            'status' => $card->getStatus(), 'purchaseOrderNumber' => $card->getPurchaseOrderNumber(),
            'expiresAt' => $card->getExpiresAt()->format(DATE_ATOM),
        ], array_slice($cards, 0, 50))];
    }

    public function movements(int $cardId, int $page): ?array
    {
        $card = $this->em->getRepository(GiftCard::class)->findOneBy(['id' => $cardId, 'establishment' => $this->tenant->getSlug()]);
        if (!$card instanceof GiftCard) return null;
        $movements = $this->em->getRepository(GiftCardMovement::class)->findBy(['card' => $card], ['id' => 'DESC'], 51, ($page - 1) * 50);
        return ['hasMore' => count($movements) > 50, 'member' => array_map(static fn (GiftCardMovement $movement): array => [
            'id' => $movement->getId(), 'reference' => $movement->getReference(), 'kind' => $movement->getKind(), 'orderNumber' => $movement->getOrderNumber(),
            'amount' => $movement->getAmount(), 'availableAfter' => $movement->getAvailableAfter(), 'reservedAfter' => $movement->getReservedAfter(),
            'createdAt' => $movement->getCreatedAt()->format(DATE_ATOM),
        ], array_slice($movements, 0, 50))];
    }
}

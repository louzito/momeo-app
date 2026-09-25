<?php

declare(strict_types=1);

namespace App\Service\GiftVoucher;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;

/** Le token du panier est la preuve de possession ; aucune commande finalisée n’est sélectionnée. */
final class GiftOrderMarking
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findCart(string $tokenValue): ?OrderInterface
    {
        return $this->orderRepository->findCartByTokenValue($tokenValue);
    }

    public function mark(OrderInterface $order, ?string $name, string $email, ?string $message): void
    {
        $order->setNotes(GiftOrderMarker::create($name, $email, $message)->encode());
        $this->em->flush();
    }
}

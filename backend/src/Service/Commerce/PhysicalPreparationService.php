<?php

declare(strict_types=1);

namespace App\Service\Commerce;

use App\Entity\Order\Order;
use Doctrine\ORM\EntityManagerInterface;

final class PhysicalPreparationService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function update(string $tokenValue, string $state): ?array
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $tokenValue]);
        if (!$order instanceof Order || $order->getPreparationState() === null) return null;
        $allowed = [Order::PREPARATION_PENDING, Order::PREPARATION_PREPARING, Order::PREPARATION_READY, Order::PREPARATION_HANDED_OVER];
        if (!\in_array($state, $allowed, true)) throw new InvalidPhysicalCommerce('Statut de préparation invalide.');
        $order->setPreparationState($state);
        $this->entityManager->flush();
        return ['state' => $state];
    }
}

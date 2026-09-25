<?php

declare(strict_types=1);

namespace App\Service\Commerce;

use App\Entity\Order\Adjustment;
use App\Entity\Order\Order;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Configure la remise sans finaliser le checkout Sylius ni réserver le stock. */
final class PhysicalCheckoutService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** @return array{mode: string, deliveryFee: int} */
    public function configure(string $tokenValue, string $mode): array
    {
        if (!\in_array($mode, ['pickup', 'delivery'], true)) {
            throw new \InvalidArgumentException('Choisissez le retrait au centre ou la livraison.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $tokenValue]);
            if (!$order instanceof Order || $order->getCheckoutState() === 'completed') {
                throw new \DomainException('Ce panier est introuvable ou déjà finalisé.');
            }
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getItems()->isEmpty()) throw new \DomainException('Le panier est vide.');

            $deliveryFee = 0;
            foreach ($order->getItems() as $item) {
                $variant = $item->getVariant();
                if (!$variant instanceof ProductVariant) throw new \DomainException('Un article du panier est invalide.');
                $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
                $product = $variant->getProduct();
                if (!$product instanceof Product || !$product->isPhysical()) {
                    throw new \DomainException('Les produits physiques doivent être commandés séparément des prestations et options.');
                }
                if (($mode === 'pickup' && !$product->isPickupEnabled()) || ($mode === 'delivery' && !$product->isDeliveryEnabled())) {
                    throw new \DomainException('Ce mode de remise n’est pas disponible pour tous les articles.');
                }
                if (!$variant->isTracked() || $variant->getOnHand() - $variant->getOnHold() < $item->getQuantity()) {
                    throw new \DomainException(sprintf('Stock insuffisant pour « %s ».', $item->getProductName()));
                }
                $deliveryFee = max($deliveryFee, $product->getDeliveryFee());
            }
            if ($mode === 'delivery' && $order->getShippingAddress() === null) {
                throw new \DomainException('Une adresse de livraison complète est obligatoire.');
            }

            foreach ($order->getAdjustments('todatempo_delivery') as $adjustment) $order->removeAdjustment($adjustment);
            if ($mode === 'delivery' && $deliveryFee > 0) {
                $adjustment = new Adjustment();
                $adjustment->setType('todatempo_delivery');
                $adjustment->setLabel('Frais de livraison');
                $adjustment->setAmount($deliveryFee);
                $order->addAdjustment($adjustment);
            }
            $order->setFulfillmentMode($mode);
            $order->setPreparationState(Order::PREPARATION_PENDING);
            $this->entityManager->flush();
            $connection->commit();

            return ['mode' => $mode, 'deliveryFee' => $mode === 'delivery' ? $deliveryFee : 0];
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}

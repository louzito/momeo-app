<?php

declare(strict_types=1);

namespace App\Service\Commerce;

use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;

final class PhysicalProductManagementService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function update(string $code, array $payload): ?array
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $code]);
        if (!$product instanceof Product) return null;
        try {
            $product->setTodatempoType((string) ($payload['type'] ?? 'service'));
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidPhysicalCommerce($exception->getMessage(), previous: $exception);
        }
        $product->setPickupEnabled($product->isPhysical() && (bool) ($payload['pickupEnabled'] ?? false));
        $product->setDeliveryEnabled($product->isPhysical() && (bool) ($payload['deliveryEnabled'] ?? false));
        $product->setDeliveryFee((int) ($payload['deliveryFee'] ?? 0));
        if ($product->isPhysical() && !$product->isPickupEnabled() && !$product->isDeliveryEnabled()) {
            throw new InvalidPhysicalCommerce('Activez au moins un mode de remise.');
        }
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) continue;
            $variant->setShippingRequired($product->isPhysical());
            $variant->setTracked($product->isPhysical());
            if (isset($payload['stock'])) $variant->setOnHand(max(0, (int) $payload['stock']));
        }
        $this->entityManager->flush();
        return ['code' => $code, 'type' => $product->getTodatempoType()];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Commerce;

use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;

final class PhysicalProductReadService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function products(): array
    {
        $products = $this->entityManager->getRepository(Product::class)->findBy([
            'todatempoType' => Product::TYPE_PHYSICAL,
            'enabled' => true,
        ]);
        return ['member' => array_map(static function (Product $product): array {
            $variant = $product->getVariants()->first();
            $pricing = $variant instanceof ProductVariant ? $variant->getChannelPricings()->first() : null;
            return [
                'code' => $product->getCode(), 'name' => $product->getName(),
                'shortDescription' => $product->getShortDescription(), 'description' => $product->getDescription(),
                'pickupEnabled' => $product->isPickupEnabled(), 'deliveryEnabled' => $product->isDeliveryEnabled(),
                'deliveryFee' => $product->getDeliveryFee(),
                'defaultVariantData' => [
                    'price' => $pricing ? $pricing->getPrice() : 0,
                    'onHand' => $variant instanceof ProductVariant ? $variant->getOnHand() : 0,
                    'onHold' => $variant instanceof ProductVariant ? $variant->getOnHold() : 0,
                ],
                'images' => array_map(static fn ($image): array => ['type' => $image->getType(), 'path' => $image->getPath()], $product->getImages()->toArray()),
            ];
        }, $products)];
    }
}

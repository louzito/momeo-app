<?php

declare(strict_types=1);

namespace App\Service\Availability;

use App\Entity\Product\Product;
use Doctrine\ORM\EntityManagerInterface;

final class ServiceDuration
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function forCode(string $serviceCode): int
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product) {
            return 60;
        }
        return $this->forProduct($product);
    }

    public function forProduct(Product $product): int
    {
        $legacyDuration = null;
        foreach ($product->getAttributes() as $attributeValue) {
            if ($attributeValue->getCode() === 'todatempo_duration') {
                return max(15, min(480, (int) $attributeValue->getValue()));
            }
            if ($attributeValue->getCode() === 'momeo_duration') {
                $legacyDuration = (int) $attributeValue->getValue();
            }
        }
        return $legacyDuration === null ? 60 : max(15, min(480, $legacyDuration));
    }
}

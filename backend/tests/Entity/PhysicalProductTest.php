<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product\Product;
use PHPUnit\Framework\TestCase;

final class PhysicalProductTest extends TestCase
{
    public function testPhysicalCommerceConfigurationIsExplicit(): void
    {
        $product = new Product();
        self::assertFalse($product->isPhysical());

        $product->setTodatempoType(Product::TYPE_PHYSICAL);
        $product->setPickupEnabled(true);
        $product->setDeliveryEnabled(true);
        $product->setDeliveryFee(750);

        self::assertTrue($product->isPhysical());
        self::assertTrue($product->isPickupEnabled());
        self::assertTrue($product->isDeliveryEnabled());
        self::assertSame(750, $product->getDeliveryFee());
    }

    public function testUnknownProductTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Product())->setTodatempoType('appointment-ish');
    }
}

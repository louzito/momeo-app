<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Entity\Order\Order;
use App\Entity\Product\{Product, ProductVariant};
use App\Service\Commerce\{PhysicalPreparationService, PhysicalProductManagementService};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;

final class PhysicalManagementServiceTest extends TestCase
{
    public function testAllVariantsFollowProductTypeAndOptionalStock(): void
    {
        $product = new Product();
        foreach ([3, 7] as $stock) {
            $variant = new ProductVariant();
            $variant->setOnHand($stock);
            $product->addVariant($variant);
        }
        $em = $this->manager(Product::class, ['code' => 'p'], $product, 3);
        $service = new PhysicalProductManagementService($em);
        self::assertSame(['code' => 'p', 'type' => 'physical'], $service->update('p', ['type' => 'physical', 'pickupEnabled' => true]));
        self::assertSame([3, 7], array_map(static fn ($v) => $v->getOnHand(), $product->getVariants()->toArray()));
        foreach ($product->getVariants() as $variant) {
            self::assertTrue($variant->isTracked());
            self::assertTrue($variant->isShippingRequired());
        }
        $service->update('p', ['type' => 'physical', 'deliveryEnabled' => true, 'deliveryFee' => 750, 'stock' => -2]);
        self::assertSame(750, $product->getDeliveryFee());
        $service->update('p', []);
        foreach ($product->getVariants() as $variant) {
            self::assertSame(0, $variant->getOnHand());
            self::assertFalse($variant->isTracked());
            self::assertFalse($variant->isShippingRequired());
        }
        self::assertFalse($product->isPickupEnabled());
        self::assertFalse($product->isDeliveryEnabled());
    }

    public function testMissingFulfillmentModeDoesNotFlush(): void
    {
        $service = new PhysicalProductManagementService($this->manager(Product::class, ['code' => 'p'], new Product(), 0));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Activez au moins un mode de remise.');
        $service->update('p', ['type' => 'physical']);
    }

    public function testPreparationStatesAndRepeatedUpdate(): void
    {
        $order = new Order();
        $order->setPreparationState(Order::PREPARATION_PENDING);
        $service = new PhysicalPreparationService($this->manager(Order::class, ['tokenValue' => 't'], $order, 5));
        foreach (['pending', 'preparing', 'ready', 'handed_over', 'handed_over'] as $state) {
            self::assertSame(['state' => $state], $service->update('t', $state));
            self::assertSame($state, $order->getPreparationState());
        }
        $this->expectException(\InvalidArgumentException::class);
        $service->update('t', 'invalid');
    }

    public function testNonPhysicalOrderIsMissingEvenWithInvalidState(): void
    {
        $service = new PhysicalPreparationService($this->manager(Order::class, ['tokenValue' => 't'], new Order(), 0));
        self::assertNull($service->update('t', 'invalid'));
    }

    private function manager(string $class, array $criteria, object $entity, int $flushes): EntityManagerInterface
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with($criteria)->willReturn($entity);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with($class)->willReturn($repository);
        $em->expects(self::exactly($flushes))->method('flush');
        return $em;
    }
}

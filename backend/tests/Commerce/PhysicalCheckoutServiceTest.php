<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Entity\Addressing\Address;
use App\Entity\Order\{Adjustment, Order, OrderItem};
use App\Entity\Product\{Product, ProductVariant};
use App\Service\Commerce\PhysicalCheckoutService;
use Doctrine\DBAL\{Connection, LockMode};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhysicalCheckoutServiceTest extends TestCase
{
    public static function cart(): Order
    {
        $order = new Order();
        $order->setShippingAddress(new Address());
        foreach ([350, 750] as $fee) {
            $product = new Product();
            $product->setTodatempoType(Product::TYPE_PHYSICAL);
            $product->setPickupEnabled(true);
            $product->setDeliveryEnabled(true);
            $product->setDeliveryFee($fee);
            $variant = new ProductVariant();
            $product->addVariant($variant);
            $variant->setTracked(true);
            $variant->setOnHand(5);
            $variant->setOnHold(1);
            $item = new OrderItem();
            $item->setVariant($variant);
            $item->setProductName('Article');
            $item->setUnitPrice(1000);
            $item->setQuantity(2);
            $order->addItem($item);
        }
        return $order;
    }

    public function testRepeatedDeliveryThenPickupPreservesAmountsAndLockOrder(): void
    {
        $order = self::cart();
        $other = new Adjustment();
        $other->setType('other');
        $other->setAmount(100);
        $order->addAdjustment($other);
        $em = $this->manager($order, 3, 0);
        $expected = [];
        for ($i = 0; $i < 3; ++$i) {
            $expected[] = $order;
            foreach ($order->getItems() as $item) $expected[] = $item->getVariant();
        }
        $em->expects(self::exactly(9))->method('lock')->willReturnCallback(function ($entity, $mode) use (&$expected): void {
            self::assertSame(array_shift($expected), $entity);
            self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
        });
        $em->expects(self::exactly(3))->method('flush');
        $service = new PhysicalCheckoutService($em);
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(['mode' => 'delivery', 'deliveryFee' => 750], $service->configure('token', 'delivery'));
            self::assertCount(1, $order->getAdjustments('todatempo_delivery'));
            self::assertSame(4850, $order->getTotal());
        }
        self::assertSame(['mode' => 'pickup', 'deliveryFee' => 0], $service->configure('token', 'pickup'));
        self::assertCount(0, $order->getAdjustments('todatempo_delivery'));
        self::assertSame(4100, $order->getTotal());
        self::assertSame('pickup', $order->getFulfillmentMode());
        self::assertSame(Order::PREPARATION_PENDING, $order->getPreparationState());
        foreach ($order->getItems() as $item) {
            self::assertSame(5, $item->getVariant()->getOnHand());
            self::assertSame(1, $item->getVariant()->getOnHold());
        }
    }

    public function testPickupDoesNotRequireAddressAndFreeDeliveryAddsNoAdjustment(): void
    {
        $order = self::cart();
        $order->setShippingAddress(null);
        $em = $this->manager($order, 2, 0);
        $em->expects(self::exactly(2))->method('flush');
        $service = new PhysicalCheckoutService($em);
        self::assertSame(['mode' => 'pickup', 'deliveryFee' => 0], $service->configure('token', 'pickup'));
        $order->setShippingAddress(new Address());
        foreach ($order->getItems() as $item) $item->getVariant()->getProduct()->setDeliveryFee(0);
        self::assertSame(['mode' => 'delivery', 'deliveryFee' => 0], $service->configure('token', 'delivery'));
        self::assertCount(0, $order->getAdjustments('todatempo_delivery'));
        self::assertSame(4000, $order->getTotal());
    }

    public static function rejections(): iterable
    {
        yield ['missing', 'Ce panier est introuvable ou déjà finalisé.'];
        yield ['completed', 'Ce panier est introuvable ou déjà finalisé.'];
        yield ['empty', 'Le panier est vide.'];
        yield ['variant', 'Un article du panier est invalide.'];
        yield ['mixed', 'Les produits physiques doivent être commandés séparément des prestations et options.'];
        yield ['stock', 'Stock insuffisant pour « Article ».'];
        yield ['untracked', 'Stock insuffisant pour « Article ».'];
        yield ['mode', 'Ce mode de remise n’est pas disponible pour tous les articles.'];
        yield ['address', 'Une adresse de livraison complète est obligatoire.'];
    }

    #[DataProvider('rejections')]
    public function testRejectedCartRollsBackWithoutFlush(string $scenario, string $message): void
    {
        $order = self::cart();
        $variant = $order->getItems()->last()->getVariant();
        switch ($scenario) {
            case 'missing': $order = null; break;
            case 'completed': $order->setCheckoutState('completed'); break;
            case 'empty': foreach ($order->getItems()->toArray() as $item) $order->removeItem($item); break;
            case 'variant': $order->getItems()->last()->setVariant(null); break;
            case 'mixed': $variant->getProduct()->setTodatempoType(Product::TYPE_SERVICE); break;
            case 'stock': $variant->setOnHand(2); break;
            case 'untracked': $variant->setTracked(false); break;
            case 'mode': $variant->getProduct()->setDeliveryEnabled(false); break;
            case 'address': $order->setShippingAddress(null); break;
        }
        $em = $this->manager($order, 0, 1);
        $em->expects(self::never())->method('flush');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($message);
        (new PhysicalCheckoutService($em))->configure('token', 'delivery');
    }

    public function testFlushFailureRollsBackAndPropagates(): void
    {
        $order = self::cart();
        $em = $this->manager($order, 0, 1);
        $failure = new \RuntimeException('flush failed');
        $em->expects(self::once())->method('flush')->willThrowException($failure);
        try {
            (new PhysicalCheckoutService($em))->configure('token', 'delivery');
            self::fail('Expected flush failure');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    private function manager(?Order $order, int $commits, int $rollbacks): EntityManagerInterface
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly($commits + $rollbacks))->method('beginTransaction');
        $connection->expects(self::exactly($commits))->method('commit');
        $connection->expects(self::exactly($rollbacks))->method('rollBack');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['tokenValue' => 'token'])->willReturn($order);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->with(Order::class)->willReturn($repository);
        return $em;
    }
}

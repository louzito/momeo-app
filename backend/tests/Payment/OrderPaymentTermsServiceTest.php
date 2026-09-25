<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Controller\ShopPaymentTermsController;
use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Payment\Payment;
use App\Entity\Product\Product;
use App\Entity\Product\ProductAttribute;
use App\Entity\Product\ProductAttributeValue;
use App\Entity\Product\ProductVariant;
use App\Service\Payment\OrderPaymentTermsService;
use App\Service\Payment\ServicePaymentTerms;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderPaymentTermsServiceTest extends TestCase
{
    private function fixture(string $mode = 'percentage', int $value = 30, array $codes = ['service_test'], string $checkoutState = 'cart'): array
    {
        $items = [];
        foreach ($codes as $code) {
            $attributes = [];
            foreach (['todatempo_payment_mode' => $mode, 'todatempo_payment_value' => $value] as $attributeCode => $attributeValue) {
                $attribute = new ProductAttribute();
                $attribute->setCode($attributeCode);
                $av = $this->createMock(ProductAttributeValue::class);
                $av->method('getAttribute')->willReturn($attribute);
                $av->method('getValue')->willReturn($attributeValue);
                $attributes[] = $av;
            }
            $product = $this->createMock(Product::class);
            $product->method('getCode')->willReturn($code);
            $product->method('getAttributes')->willReturn(new ArrayCollection($attributes));
            $variant = new ProductVariant();
            $variant->setProduct($product);
            $item = new OrderItem();
            $item->setVariant($variant);
            $items[] = $item;
        }
        // Real adjustment collection and payment relationship; total starts from a fixed basket in cents.
        $order = $this->getMockBuilder(Order::class)->onlyMethods(['getTotal', 'getItems'])->getMock();
        $order->method('getTotal')->willReturnCallback(static fn (): int => 9999 + $order->getAdjustmentsTotal());
        $order->method('getItems')->willReturn(new ArrayCollection($items));
        $order->setCheckoutState($checkoutState);
        $payment = new Payment();
        $payment->setAmount(9999);
        $order->addPayment($payment);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['tokenValue' => 'token'])->willReturn($order);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Order::class)->willReturn($repository);
        return [new ShopPaymentTermsController(new OrderPaymentTermsService($em, new ServicePaymentTerms())), $order, $payment, $em];
    }

    public static function terms(): iterable
    {
        yield 'percentage rounds to cents' => ['percentage', 30, 3000, 6999];
        yield 'fixed deposit' => ['fixed', 2500, 2500, 7499];
        yield 'fixed capped at basket' => ['fixed', 12000, 9999, 0];
        yield 'full' => ['full', 0, 9999, 0];
        yield 'none' => ['none', 0, 0, 9999];
    }

    #[DataProvider('terms')]
    public function testApplyAndReplayHaveNoDuplicateAdjustment(string $mode, int $value, int $due, int $balance): void
    {
        [$controller, $order, $payment, $em] = $this->fixture($mode, $value, ['jump_test']);
        $em->expects(self::exactly($balance === 0 ? 2 : 1))->method('flush');
        for ($i = 0; $i < 2; ++$i) {
            $response = $controller('token');
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['mode' => $mode, 'value' => $value, 'totalAmount' => 9999, 'dueNow' => $due, 'balanceDue' => $balance], json_decode($response->getContent(), true));
            self::assertSame($due, $payment->getAmount());
            self::assertSame($due, $order->getTotal());
            $adjustments = $order->getAdjustments('todatempo_payment_terms');
            self::assertCount($balance === 0 ? 0 : 1, $adjustments);
            if ($balance !== 0) {
                self::assertSame(-$balance, $adjustments->first()->getAmount());
                self::assertTrue($adjustments->first()->isLocked());
                self::assertSame('Solde à régler sur place', $adjustments->first()->getLabel());
            }
        }
    }

    public static function rejectedTerms(): iterable
    {
        yield 'finalized order' => ['full', 0, ['service_test'], 'completed', 'La commande est introuvable ou déjà finalisée.'];
        yield 'no service' => ['full', 0, ['physical_test'], 'cart', 'La prestation de la commande est introuvable.'];
        yield 'two services' => ['full', 0, ['service_one', 'jump_two'], 'cart', 'Une commande de réservation ne peut contenir qu’une prestation.'];
        yield 'invalid percentage' => ['percentage', 101, ['service_test'], 'cart', 'Le montant de l’acompte de cette prestation est invalide.'];
    }

    #[DataProvider('rejectedTerms')]
    public function testInvalidTermsDoNotMutate(string $mode, int $value, array $codes, string $state, string $message): void
    {
        [$controller, $order, $payment, $em] = $this->fixture($mode, $value, $codes, $state);
        $em->expects(self::never())->method('flush');
        $response = $controller('token');
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => $message], json_decode($response->getContent(), true));
        self::assertSame(9999, $payment->getAmount());
        self::assertCount(0, $order->getAdjustments());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\GiftCard;

use App\Entity\Order\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GiftCardPurchaseTest extends TestCase
{
    public static function invalidPurchases(): iterable
    {
        yield 'below minimum' => [999, []];
        yield 'above maximum' => [100001, []];
        yield 'invalid buyer email' => [5000, ['buyerEmail' => 'not-an-email']];
        yield 'missing recipient name' => [5000, ['recipientName' => ' ']];
        yield 'recipient delivery needs email' => [5000, ['delivery' => 'recipient']];
        yield 'invalid delivery' => [5000, ['delivery' => 'both']];
        yield 'message too long' => [5000, ['message' => str_repeat('é', 1001)]];
        yield 'invalid field type' => [5000, ['buyerName' => []]];
    }

    #[DataProvider('invalidPurchases')]
    public function testInvalidPurchaseCannotMarkOrder(int $amount, array $overrides): void
    {
        $order = new Order();
        try {
            $order->configureGiftCardPurchase($amount, array_replace(self::purchase(), $overrides));
            self::fail('Invalid purchase accepted.');
        } catch (\InvalidArgumentException) {
            self::assertNull($order->getGiftCardAmount());
            self::assertNull($order->getGiftCardPurchase());
        }
    }

    public function testBuyerDeliveryDoesNotRequireRecipientEmailAndKeepsRawMessage(): void
    {
        $order = new Order();
        $purchase = self::purchase();
        $purchase['message'] = '<script>alert("cadeau")</script> & Joyeux anniversaire !';
        $order->configureGiftCardPurchase(7550, $purchase);
        self::assertSame(7550, $order->getGiftCardAmount());
        self::assertSame($purchase['message'], $order->getGiftCardPurchase()['message']);
        self::assertSame('', $order->getGiftCardPurchase()['recipientEmail']);
        $this->expectException(\DomainException::class);
        $order->configureGiftCardPurchase(10000, $purchase);
    }

    public function testCompletedOrderCannotBeTurnedIntoGiftPurchase(): void
    {
        $order = new Order();
        $order->setCheckoutState('completed');
        $this->expectException(\DomainException::class);
        $order->configureGiftCardPurchase(5000, self::purchase());
    }

    public static function purchase(): array
    {
        return ['buyerName' => 'Camille', 'buyerEmail' => 'buyer@example.test', 'recipientName' => 'Alex',
            'recipientEmail' => '', 'delivery' => 'buyer', 'message' => '', 'validityMonths' => 12];
    }
}

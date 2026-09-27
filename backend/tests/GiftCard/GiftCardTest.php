<?php

declare(strict_types=1);

namespace App\Tests\GiftCard;

use App\Entity\GiftCard;
use PHPUnit\Framework\TestCase;

final class GiftCardTest extends TestCase
{
    public function testPartialDebitAndRestitution(): void
    {
        $card = $this->card();
        $card->reserve(7000);
        self::assertSame(3000, $card->getAvailable());
        self::assertSame(7000, $card->getReserved());
        $card->debit(7000);
        self::assertSame(0, $card->getReserved());
        $card->refund(2000);
        self::assertSame(5000, $card->getAvailable());
        $card->reserve(5000);
        $card->release(5000);
        self::assertSame(5000, $card->getAvailable());
    }

    public function testSecondReservationCannotExceedBalance(): void
    {
        $card = $this->card();
        $card->reserve(7000);
        $this->expectException(\DomainException::class);
        $card->reserve(7000);
    }

    public function testExpiredReservationCanBeReleasedWithoutExtendingValidity(): void
    {
        $card = $this->card();
        $card->reserve(7000);
        $expiry = new \DateTimeImmutable('-1 second');
        (new \ReflectionProperty($card, 'expiresAt'))->setValue($card, $expiry);
        $card->release(7000);
        self::assertSame(10000, $card->getAvailable());
        self::assertSame($expiry, $card->getExpiresAt());
        $this->expectException(\DomainException::class);
        $card->reserve(1);
    }

    public function testInactiveCardCannotBeDebited(): void
    {
        $card = $this->card();
        $card->reserve(1000);
        $card->deactivate();
        $this->expectException(\DomainException::class);
        $card->debit(1000);
    }

    public function testWrongEstablishmentIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->card()->assertShop('other', 'WEB', 'EUR');
    }

    public function testWrongCurrencyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->card()->assertShop('demo', 'WEB', 'USD');
    }

    public function testRefundCannotExceedDebit(): void
    {
        $this->expectException(\DomainException::class);
        $this->card()->refund(1);
    }

    public function testCodeIsRandom128BitValue(): void
    {
        self::assertMatchesRegularExpression('/^[A-F0-9]{32}$/D', $this->card()->getCode());
        self::assertNotSame($this->card()->getCode(), $this->card()->getCode());
    }

    private function card(): GiftCard
    {
        return new GiftCard('demo', 'WEB', 'EUR', 10000, 'PURCHASE-1', new \DateTimeImmutable('+1 year'));
    }
}

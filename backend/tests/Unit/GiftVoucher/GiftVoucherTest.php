<?php

declare(strict_types=1);

namespace App\Tests\Unit\GiftVoucher;

use App\Entity\GiftVoucher;
use PHPUnit\Framework\TestCase;

final class GiftVoucherTest extends TestCase
{
    public function testAwaitingPaymentVoucherIsNotUsable(): void
    {
        $voucher = $this->voucher(GiftVoucher::STATUS_AWAITING_PAYMENT, new \DateTimeImmutable('+1 year'));

        self::assertFalse($voucher->isUsable());
        self::assertSame(GiftVoucher::STATUS_AWAITING_PAYMENT, $voucher->getEffectiveStatus());
    }

    public function testActiveVoucherWithinValidityIsUsable(): void
    {
        $voucher = $this->voucher(GiftVoucher::STATUS_ACTIVE, new \DateTimeImmutable('+1 day'));

        self::assertTrue($voucher->isUsable());
        self::assertSame(GiftVoucher::STATUS_ACTIVE, $voucher->getEffectiveStatus());
    }

    public function testActiveVoucherPastExpiryIsRefusedAsExpired(): void
    {
        $voucher = $this->voucher(GiftVoucher::STATUS_ACTIVE, new \DateTimeImmutable('-1 day'));

        self::assertFalse($voucher->isUsable());
        self::assertSame(GiftVoucher::STATUS_EXPIRED, $voucher->getEffectiveStatus());
    }

    public function testUsedVoucherCannotBeConsumedTwiceEvenBeforeExpiry(): void
    {
        $voucher = $this->voucher(GiftVoucher::STATUS_USED, new \DateTimeImmutable('+1 year'));
        $voucher->setUsedAt(new \DateTimeImmutable());
        $voucher->setUsageOrderNumber('BOOK-1');

        self::assertFalse($voucher->isUsable());
        self::assertSame(GiftVoucher::STATUS_USED, $voucher->getEffectiveStatus());
    }

    public function testUsedVoucherStaysUsedEvenPastItsExpiryDate(): void
    {
        $voucher = $this->voucher(GiftVoucher::STATUS_USED, new \DateTimeImmutable('-1 day'));

        self::assertFalse($voucher->isUsable());
        self::assertSame(GiftVoucher::STATUS_USED, $voucher->getEffectiveStatus(), 'A used voucher must never be reported as expired.');
    }

    private function voucher(string $status, \DateTimeImmutable $expiresAt): GiftVoucher
    {
        $voucher = new GiftVoucher();
        $voucher->setCode('1234567890');
        $voucher->setStatus($status);
        $voucher->setServiceCode('SERVICE');
        $voucher->setServiceName('Prestation');
        $voucher->setAmount(9900);
        $voucher->setCurrencyCode('EUR');
        $voucher->setPurchaserName('Acheteur Test');
        $voucher->setPurchaserEmail('purchaser@example.test');
        $voucher->setBeneficiaryEmail('beneficiary@example.test');
        $voucher->setPurchaseOrderNumber('ORDER-1');
        $voucher->setExpiresAt($expiresAt);

        return $voucher;
    }
}

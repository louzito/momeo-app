<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class GiftVoucherRedemptionContractTest extends TestCase
{
    public function testVoucherRedemptionIsAtomicPersistedAndNotified(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Controller/ShopBookingApiController.php');
        self::assertIsString($source);
        self::assertStringContainsString('beginTransaction()', $source);
        self::assertStringContainsString('findOneByCodeForUpdate($code)', $source);
        self::assertStringContainsString('LockMode::PESSIMISTIC_WRITE', $source);
        self::assertStringContainsString('!$voucher instanceof GiftVoucher || !$voucher->isUsable()', $source);
        self::assertStringContainsString('GiftVoucher::STATUS_USED', $source);
        self::assertStringContainsString('setUsageOrderNumber($booking->getReference())', $source);
        self::assertStringContainsString('slotGuard->assertAvailable', $source);
        self::assertStringContainsString('emailDispatcher->confirmation($booking)', $source);
        self::assertStringContainsString("connection->rollBack()", $source);
    }

    public function testThereIsNoConcurrentRedemptionPathAroundTheAtomicOne(): void
    {
        self::assertFileDoesNotExist(
            __DIR__.'/../../src/GiftVoucher/GiftVoucherRedeemer.php',
            'GiftVoucherRedeemer bypassed the real booking/slot logic (it only flipped the voucher status) and could double-book or leave usageOrderNumber unset if ever called again : it must stay removed.',
        );

        $controller = file_get_contents(__DIR__.'/../../src/Controller/ShopGiftVoucherApiController.php');
        self::assertIsString($controller);
        self::assertStringNotContainsString('/redeem', $controller);
        self::assertStringNotContainsString('GiftVoucherRedeemer', $controller);
    }

    public function testUnpaidOrExpiredVoucherCannotBeUsedToBook(): void
    {
        $entity = file_get_contents(__DIR__.'/../../src/Entity/GiftVoucher.php');
        self::assertIsString($entity);
        self::assertStringContainsString('getEffectiveStatus() === self::STATUS_ACTIVE', $entity);
        self::assertStringContainsString("if (\$this->status === self::STATUS_ACTIVE && \$this->expiresAt < new \\DateTimeImmutable())", $entity);
    }
}

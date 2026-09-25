<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class BookableResourceContractTest extends TestCase
{
    public function testRemainingBookingControllersValidateResources(): void
    {
        // Public order/voucher behavior is covered by GiftVoucherRedemptionContractTest::testUnassociatedResourceIsRejected.
        foreach (['AdminBookingApiController.php', 'ShopCustomerAccountApiController.php'] as $file) {
            $source = (string) file_get_contents(__DIR__.'/../../src/Controller/'.$file);
            self::assertStringContainsString('resourceAvailability->choose', $source, $file);
        }

    }

    public function testResourceCapacityIsEnforcedInsideTheTransaction(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT IGNORE INTO momeo_booking_lock (lock_key) VALUES (?)', ['resource:room'],
        )->willReturn(1);
        $connection->expects(self::exactly(3))->method('fetchOne')->willReturnOnConsecutiveCalls('resource:room', 2, 2);
        $booking = new \App\Entity\Booking();
        $booking->setResourceCode('room');
        $booking->setSlotStart(new \DateTimeImmutable('2026-10-10T12:00:00Z'));
        $booking->setSlotEnd(new \DateTimeImmutable('2026-10-10T13:00:00Z'));
        $this->expectException(\App\Service\Booking\SlotUnavailable::class);
        $this->expectExceptionMessage('La capacité de la ressource');
        (new \App\Service\Booking\BookingSlotGuard($connection))->assertAvailable($booking);
    }

    public function testAdminCrudAndServiceAssociationRoutesExist(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Controller/AdminBookableResourceApiController.php');
        self::assertStringContainsString("'/bookable-resources'", $source);
        self::assertStringContainsString("'/services/{code}/bookable-resources'", $source);
        self::assertStringContainsString("methods: ['DELETE']", $source);
    }
}

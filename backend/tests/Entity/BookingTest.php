<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Booking;
use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    public function testSlotInstantsAreStoredInUtcWithoutChangingInput(): void
    {
        $start = new \DateTimeImmutable('2026-10-25T02:30:00+02:00');
        $end = new \DateTimeImmutable('2026-10-25T02:30:00+01:00');
        $booking = new Booking();
        $booking->setSlotStart($start);
        $booking->setSlotEnd($end);
        self::assertSame($start->getTimestamp(), $booking->getSlotStart()->getTimestamp());
        self::assertSame($end->getTimestamp(), $booking->getSlotEnd()->getTimestamp());
        self::assertSame('UTC', $booking->getSlotStart()->getTimezone()->getName());
        self::assertSame('UTC', $booking->getSlotEnd()->getTimezone()->getName());
        self::assertSame('+02:00', $start->format('P'));
        self::assertSame(3600, $booking->getSlotEnd()->getTimestamp() - $booking->getSlotStart()->getTimestamp());
    }

    public function testHistoryAppendsAndMoneyRemainsInMinorUnits(): void
    {
        $booking = new Booking();
        $booking->recordChange(['action' => 'reschedule']);
        $booking->recordChange(['action' => 'cancel']);
        self::assertSame([['action' => 'reschedule'], ['action' => 'cancel']], $booking->getChangeHistory());
        $booking->setAmount(1234);
        $booking->setTotalAmount(5678);
        $booking->setBalanceDue(4444);
        self::assertSame(1234, $booking->getAmount());
        self::assertSame(5678, $booking->getTotalAmount());
        self::assertSame(4444, $booking->getBalanceDue());
        $booking->setAmount(null);
        self::assertNull($booking->getAmount());
    }
}

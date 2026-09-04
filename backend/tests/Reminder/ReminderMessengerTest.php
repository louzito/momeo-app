<?php

declare(strict_types=1);

namespace App\Tests\Reminder;

use App\Entity\Booking;
use App\Entity\ReminderDelivery;
use App\Reminder\Message\SendBookingReminder;
use App\Reminder\Sms\DisabledSmsProvider;
use App\Reminder\Sms\SmsProviderDisabled;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ReminderMessengerTest extends KernelTestCase
{
    public function testReminderMessageIsRoutedToAsyncTransport(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $container->get(MessageBusInterface::class)->dispatch(new SendBookingReminder(42));

        self::assertCount(1, $transport->getSent());
        self::assertSame(42, $transport->getSent()[0]->getMessage()->deliveryId);
    }

    public function testDeliveryKeyMakesSchedulingIdempotentPerSlotChannelAndDelay(): void
    {
        $booking = new Booking();
        $booking->setReference('RDV-42');
        $booking->setSlotStart(new \DateTimeImmutable('2027-06-01T10:00:00Z'));

        $first = new ReminderDelivery($booking, 'email', 24);
        $duplicate = new ReminderDelivery($booking, 'email', 24);
        $otherChannel = new ReminderDelivery($booking, 'sms', 24);

        self::assertSame($first->getIdempotencyKey(), $duplicate->getIdempotencyKey());
        self::assertNotSame($first->getIdempotencyKey(), $otherChannel->getIdempotencyKey());
    }

    public function testDisabledSmsProviderCannotAccidentallySend(): void
    {
        $this->expectException(SmsProviderDisabled::class);
        (new DisabledSmsProvider())->send('+33600000000', 'Rappel');
    }
}

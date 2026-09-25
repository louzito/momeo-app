<?php

declare(strict_types=1);

namespace App\Tests\Reminder;

use App\Command\ScheduleBookingRemindersCommand;
use App\Entity\Booking;
use App\Entity\ReminderDelivery;
use App\Reminder\Message\SendBookingReminder;
use App\Repository\BookingRepository;
use App\Repository\ReminderDeliveryRepository;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Reminder\ReminderConfiguration;
use App\Service\Reminder\ReminderScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReminderSchedulerTest extends \App\Tests\Availability\AvailabilityTestCase
{
    public function testRepeatedSchedulingPublishesPersistedDeliveryOnlyOnceAndKeepsCliContract(): void
    {
        $start = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+24 hours +5 minutes');
        $booking = $this->booking($start, $start->modify('+1 hour'));
        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $cancelled = $this->booking($start, $start->modify('+1 hour'));
        $cancelled->setStatus(Booking::STATUS_CANCELLED);
        $this->entityManager->persist($booking);
        $this->entityManager->persist($cancelled);
        $this->entityManager->flush();
        $configEm = $this->createMock(EntityManagerInterface::class);
        $configEm->method('getRepository')->willThrowException(new \RuntimeException('Use configuration defaults'));
        $ids = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (SendBookingReminder $message) use (&$ids): Envelope {
            $delivery = $this->entityManager->find(ReminderDelivery::class, $message->deliveryId);
            self::assertInstanceOf(ReminderDelivery::class, $delivery, 'Flush must precede publication');
            $ids[] = $message->deliveryId;
            return new Envelope($message);
        });
        $scheduler = new ReminderScheduler(
            self::getContainer()->get(BookingRepository::class),
            self::getContainer()->get(ReminderDeliveryRepository::class),
            new ReminderConfiguration($configEm, '24,24', '24', false),
            new CenterTimeZoneProvider($configEm), $this->entityManager, $bus,
        );
        $count = $scheduler->schedule(10);
        self::assertGreaterThanOrEqual(1, $count);
        self::assertCount($count, $ids);
        $deliveries = $this->entityManager->getRepository(ReminderDelivery::class)->findBy(['booking' => $booking]);
        self::assertCount(1, $deliveries);
        self::assertContains($deliveries[0]->getId(), $ids);
        self::assertCount(0, $this->entityManager->getRepository(ReminderDelivery::class)->findBy(['booking' => $cancelled]));
        $command = new ScheduleBookingRemindersCommand($scheduler);
        self::assertSame('todatempo:reminders:schedule', $command->getName());
        self::assertSame('10', $command->getDefinition()->getOption('window')->getDefault());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--window' => '10']));
        self::assertSame('0 rappel(s) planifié(s).', trim($tester->getDisplay()));
        self::assertCount($count, $ids);
    }
}

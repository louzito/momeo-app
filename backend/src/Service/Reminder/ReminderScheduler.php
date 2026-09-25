<?php

declare(strict_types=1);

namespace App\Service\Reminder;

use App\Service\Availability\CenterTimeZoneProvider;
use App\Entity\ReminderDelivery;
use App\Reminder\Message\SendBookingReminder;
use App\Repository\BookingRepository;
use App\Repository\ReminderDeliveryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Schedules in the current tenant connection; persist before publishing the historical message. */
final class ReminderScheduler
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ReminderDeliveryRepository $deliveries,
        private readonly ReminderConfiguration $configuration,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function schedule(int $window = 10): int
    {
        $window = max(1, $window);
        $localNow = new \DateTimeImmutable('now', $this->timeZoneProvider->get());
        $scheduled = 0;

        foreach ($this->configuration->channels() as $channel => $hoursList) {
            foreach ($hoursList as $hours) {
                $from = $localNow->modify(sprintf('+%d hours', $hours))->setTimezone(new \DateTimeZone('UTC'));
                $to = $from->modify(sprintf('+%d minutes', $window));
                foreach ($this->bookings->findConfirmedStartingBetween($from, $to) as $booking) {
                    $delivery = new ReminderDelivery($booking, $channel, $hours);
                    if ($this->deliveries->findOneBy(['idempotencyKey' => $delivery->getIdempotencyKey()]) instanceof ReminderDelivery) {
                        continue;
                    }
                    $this->entityManager->persist($delivery);
                    $this->entityManager->flush();
                    $this->bus->dispatch(new SendBookingReminder((int) $delivery->getId()));
                    ++$scheduled;
                }
            }
        }

        return $scheduled;
    }
}

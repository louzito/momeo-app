<?php

declare(strict_types=1);

namespace App\Command;

use App\Availability\CenterTimeZoneProvider;
use App\Entity\ReminderDelivery;
use App\Reminder\Message\SendBookingReminder;
use App\Reminder\ReminderConfiguration;
use App\Repository\BookingRepository;
use App\Repository\ReminderDeliveryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'todatempo:reminders:schedule', description: 'Planifie les rappels de rendez-vous arrivés à échéance.')]
final class ScheduleBookingRemindersCommand extends Command
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ReminderDeliveryRepository $deliveries,
        private readonly ReminderConfiguration $configuration,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('window', null, InputOption::VALUE_REQUIRED, 'Fenêtre de recherche en minutes (fréquence du cron).', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = max(1, (int) $input->getOption('window'));
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

        $output->writeln(sprintf('%d rappel(s) planifié(s).', $scheduled));

        return Command::SUCCESS;
    }
}

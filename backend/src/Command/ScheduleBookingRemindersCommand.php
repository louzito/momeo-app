<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Reminder\ReminderScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Console adapter: CLI name, options and output remain stable for cron. */
#[AsCommand(name: 'todatempo:reminders:schedule', description: 'Planifie les rappels de rendez-vous arrivés à échéance.')]
final class ScheduleBookingRemindersCommand extends Command
{
    public function __construct(private readonly ReminderScheduler $scheduler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('window', null, InputOption::VALUE_REQUIRED, 'Fenêtre de recherche en minutes (fréquence du cron).', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduled = $this->scheduler->schedule((int) $input->getOption('window'));

        $output->writeln(sprintf('%d rappel(s) planifié(s).', $scheduled));

        return Command::SUCCESS;
    }
}

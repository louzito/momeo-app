<?php

declare(strict_types=1);

namespace App\Command;

use App\Gdpr\CustomerDataManager;
use App\Tenant\TenantWorkerGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'todatempo:gdpr:purge', description: 'Applique les politiques de rétention au tenant explicitement configuré.')]
final class GdprPurgeCommand extends Command
{
    public function __construct(private readonly TenantWorkerGuard $tenantGuard, private readonly CustomerDataManager $dataManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les données concernées sans les modifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $tenant = $this->tenantGuard->validate();
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');
            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $counts = $this->dataManager->purge(new \DateTimeImmutable(), 'cron:gdpr-purge', $dryRun);
        $output->writeln(sprintf('%s tenant %s : %s', $dryRun ? 'Simulation' : 'Purge auditée', $tenant, json_encode($counts, \JSON_THROW_ON_ERROR)));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Tenant\TenantDatabaseDiagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** CLI adapter for the read-only database diagnostic; formats results and exit codes. */
#[AsCommand(name: 'skybook:tenant:database-diagnose', description: 'Verifie la base Doctrine effectivement ouverte pour un tenant')]
final class TenantDatabaseDiagnoseCommand extends Command
{
    public function __construct(
        private readonly TenantDatabaseDiagnostic $diagnostic,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tenant', InputArgument::REQUIRED, 'Slug explicite du tenant a verifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = (string) $input->getArgument('tenant');
        $result = $this->diagnostic->inspect($slug);
        if ($result === null) {
            $output->writeln(sprintf('<error>Tenant "%s" inconnu ou sans base dans config/tenants.json.</error>', $slug));

            return Command::INVALID;
        }

        $actualDatabase = $result['actual'];
        $expectedDatabase = $result['expected'];
        if (!$result['matches']) {
            $output->writeln(sprintf('<error>ECHEC : Doctrine a ouvert "%s", base attendue "%s".</error>', $actualDatabase, $expectedDatabase));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>OK : tenant "%s", base Doctrine "%s".</info>', $slug, $actualDatabase));

        return Command::SUCCESS;
    }
}

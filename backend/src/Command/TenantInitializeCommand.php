<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\MinimalSyliusInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'todatempo:tenant:initialize', description: 'Initialise les données Sylius minimales du tenant courant')]
final class TenantInitializeCommand extends Command
{
    public function __construct(private readonly MinimalSyliusInitializer $initializer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Nom du tenant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = getenv('TODATEMPO_ADMIN_EMAIL') ?: getenv('SKYBOOK_ADMIN_EMAIL');
        $password = getenv('TODATEMPO_ADMIN_PASSWORD') ?: getenv('SKYBOOK_ADMIN_PASSWORD');
        if (!is_string($email) || filter_var($email, \FILTER_VALIDATE_EMAIL) === false || !is_string($password)) {
            $output->writeln('<error>TODATEMPO_ADMIN_EMAIL et TODATEMPO_ADMIN_PASSWORD doivent être fournis par l’environnement.</error>');

            return Command::INVALID;
        }

        try {
            $this->initializer->initialize((string) $input->getArgument('name'), mb_strtolower($email), $password);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::INVALID;
        }
        $output->writeln('<info>Données Sylius minimales initialisées.</info>');

        return Command::SUCCESS;
    }
}

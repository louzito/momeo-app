<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\CustomDomainManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'todatempo:tenant:domain:verify', description: 'Vérifie la preuve DNS et active le domaine')]
final class TenantDomainVerifyCommand extends Command
{
    public function __construct(private readonly CustomDomainManager $domains)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tenant', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (!$this->domains->verify((string) $input->getArgument('tenant'))) {
                $output->writeln('<error>Preuve TXT absente ou incorrecte.</error>');
                return Command::FAILURE;
            }
        } catch (\Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Domaine vérifié et activé.</info>');
        return Command::SUCCESS;
    }
}

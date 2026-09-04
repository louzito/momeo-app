<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\CustomDomainManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'todatempo:tenant:domain:request', description: 'Prépare la preuve DNS d’un domaine personnalisé')]
final class TenantDomainRequestCommand extends Command
{
    public function __construct(private readonly CustomDomainManager $domains)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tenant', InputArgument::REQUIRED)->addArgument('domain', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $claim = $this->domains->request((string) $input->getArgument('tenant'), (string) $input->getArgument('domain'));
        } catch (\Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Créer le TXT %s avec la valeur %s', $claim['record'], $claim['value']));
        return Command::SUCCESS;
    }
}

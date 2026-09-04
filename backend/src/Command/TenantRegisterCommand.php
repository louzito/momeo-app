<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantRegistryWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'todatempo:tenant:register', description: 'Enregistre (ou met a jour) un centre dans le registre', aliases: ['skybook:tenant:register'])]
final class TenantRegisterCommand extends Command
{
    public function __construct(private readonly TenantRegistryWriter $writer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug du centre')
            ->addArgument('db', InputArgument::REQUIRED, 'Nom de la base de donnees')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiche', '')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Statut (active|pool|template)', 'active');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = strtolower((string) $input->getArgument('slug'));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            $output->writeln('<error>Slug invalide (attendu : [a-z0-9][a-z0-9-]*)</error>');

            return Command::FAILURE;
        }
        $this->writer->upsert($slug, [
            'db' => (string) $input->getArgument('db'),
            'name' => (string) $input->getOption('name'),
            'enabled' => true,
            'status' => (string) $input->getOption('status'),
        ]);
        $output->writeln(sprintf('Tenant "%s" enregistre.', $slug));

        return Command::SUCCESS;
    }
}

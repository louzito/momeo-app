<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantRegistryWriter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'skybook:tenant:remove', description: 'Retire un centre du registre (et optionnellement supprime sa BDD)')]
final class TenantRemoveCommand extends Command
{
    public function __construct(
        private readonly TenantRegistryWriter $writer,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED)
            ->addOption('drop-db', null, InputOption::VALUE_NONE, 'Supprime aussi la base de donnees (IRREVERSIBLE)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = (string) $input->getArgument('slug');
        $entry = $this->writer->read()[$slug] ?? null;
        if ($entry === null) {
            $output->writeln(sprintf('<error>Tenant "%s" inconnu.</error>', $slug));

            return Command::FAILURE;
        }
        if ($input->getOption('drop-db') && \is_string($entry['db'] ?? null) && $entry['db'] !== '') {
            $this->connection->executeStatement('DROP DATABASE IF EXISTS `' . str_replace('`', '', $entry['db']) . '`');
            $output->writeln(sprintf('BDD "%s" supprimee.', $entry['db']));
        }
        $this->writer->remove($slug);
        $output->writeln(sprintf('Tenant "%s" retire du registre.', $slug));

        return Command::SUCCESS;
    }
}

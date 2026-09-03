<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'todatempo:tenant:list', description: 'Liste les centres du registre (config/tenants.json)', aliases: ['skybook:tenant:list'])]
final class TenantListCommand extends Command
{
    public function __construct(private readonly TenantRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filtrer par statut (active, pool, template…)')
            ->addOption('count', null, InputOption::VALUE_NONE, 'N\'afficher que le nombre')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie JSON brute');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenants = $this->registry->all();
        if (null !== ($status = $input->getOption('status'))) {
            $tenants = array_filter($tenants, static fn (array $t): bool => ($t['status'] ?? 'active') === $status);
        }
        ksort($tenants);

        if ($input->getOption('count')) {
            $output->writeln((string) \count($tenants));

            return Command::SUCCESS;
        }
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($tenants, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        $io = new SymfonyStyle($input, $output);
        $io->table(
            ['slug', 'db', 'status', 'enabled', 'name'],
            array_map(
                static fn (string $slug, array $t): array => [
                    $slug,
                    $t['db'] ?? '?',
                    $t['status'] ?? 'active',
                    ($t['enabled'] ?? true) ? 'oui' : 'non',
                    $t['name'] ?? '',
                ],
                array_keys($tenants),
                array_values($tenants),
            ),
        );

        return Command::SUCCESS;
    }
}

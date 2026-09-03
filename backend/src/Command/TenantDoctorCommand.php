<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantDoctorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'todatempo:tenant:doctor', description: 'Diagnostique la configuration d’un tenant en lecture seule')]
final class TenantDoctorCommand extends Command
{
    public function __construct(private readonly TenantDoctorInterface $doctor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'Slug du tenant à diagnostiquer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = strtolower(trim((string) $input->getArgument('slug')));
        $results = $this->doctor->inspect($slug);
        $io = new SymfonyStyle($input, $output);
        $io->title(sprintf('Diagnostic du tenant « %s »', $slug));
        $io->table(
            ['Statut', 'Contrôle', 'Détail'],
            array_map(static fn (array $result): array => [$result['status'], $result['check'], $result['detail']], $results),
        );

        $hasError = array_filter($results, static fn (array $result): bool => $result['status'] === 'ERROR') !== [];

        return $hasError ? Command::FAILURE : Command::SUCCESS;
    }
}

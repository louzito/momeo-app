<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ATTRIBUTION INSTANTANEE d'un centre : prend le premier centre du pool
 * (status=pool, BDD deja installee/provisionnee) et ne fait QUE des operations
 * rapides — aucune installation :
 *   1. mise a jour du channel (nom, contactEmail) et du taxon skybook_config
 *      (nom affiche) DANS LA BDD DU CENTRE (bascule via TenantContext) ;
 *   2. socle Sylius minimal et compte admin du centre si email fourni ;
 *   3. repertoires medias / factures du slug ;
 *   4. rename dans le registre : slug provisoire -> slug definitif, status=active.
 * La logique réutilisable vit dans TenantProvisioner ; cette commande reste le
 * point d'entrée pratique pour les attributions manuelles.
 */
#[AsCommand(name: 'todatempo:tenant:claim', description: 'Attribue instantanement un centre du pool a un slug definitif', aliases: ['skybook:tenant:claim'])]
final class TenantClaimCommand extends Command
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug definitif (ex. skydive-lyon)')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom du centre (ex. "Skydive Lyon")')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email admin du centre (obligatoire)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = strtolower(trim((string) $input->getArgument('slug')));
        $name = trim((string) $input->getArgument('name'));
        $email = $input->getOption('email');

        try {
            $tenant = $this->provisioner->claim($slug, $name, \is_string($email) ? $email : null, 'manual:'.$slug);
        } catch (\Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('✔ Centre "%s" attribué au slug "%s" en %.1fs.', $tenant->name, $tenant->slug, $tenant->durationSeconds));
        if ($tenant->generatedPassword !== null && $tenant->adminEmail !== null) {
            $output->writeln(sprintf('  Admin : %s / %s  (mot de passe généré — à changer)', $tenant->adminEmail, $tenant->generatedPassword));
        }
        $output->writeln(sprintf('  Centres restants dans le pool : %d%s', $tenant->remainingPool, $tenant->remainingPool < 3 ? '  ⚠ pense à relancer pool-refill' : ''));

        return Command::SUCCESS;
    }
}

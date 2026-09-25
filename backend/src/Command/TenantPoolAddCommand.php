<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Tenant\TenantPoolManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ajoute UN centre blanc au pool : clone la BDD template (deja migree +
 * provisionnee) vers une BDD au nom neutre (skybook_pool_xxxxxxxx — le nom ne
 * derive JAMAIS du slug), puis enregistre un slug provisoire pool-NNN avec
 * status=pool. Rapide (quelques secondes) : l'installation lente a ete faite
 * une fois pour toutes dans la template (scripts/template-init.sh).
 */
#[AsCommand(name: 'todatempo:tenant:pool-add', description: 'Ajoute un centre blanc au pool (clone de la BDD template)', aliases: ['skybook:tenant:pool-add'])]
final class TenantPoolAddCommand extends Command
{
    public function __construct(
        private readonly TenantPoolManager $pool,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->pool->add();
        if ($result === null) {
            $output->writeln('<error>Aucune BDD template dans le registre. Lance d\'abord template-init.cmd.</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '+ %s (db %s, %d tables clonees en %.1fs)',
            $result['slug'],
            $result['db'],
            $result['tables'],
            $result['duration'],
        ));

        return Command::SUCCESS;
    }
}

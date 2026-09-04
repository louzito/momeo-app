<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\TenantDatabaseCloner;
use App\Tenant\TenantRegistryWriter;
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
        private readonly TenantRegistryWriter $writer,
        private readonly TenantDatabaseCloner $cloner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $this->writer->read();
        $templateDb = null;
        foreach ($all as $entry) {
            if (($entry['status'] ?? '') === 'template' && \is_string($entry['db'] ?? null)) {
                $templateDb = $entry['db'];
                break;
            }
        }
        if ($templateDb === null) {
            $output->writeln('<error>Aucune BDD template dans le registre. Lance d\'abord template-init.cmd.</error>');

            return Command::FAILURE;
        }

        $next = 1;
        foreach (array_keys($all) as $slug) {
            if (preg_match('/^pool-(\d{3})$/', (string) $slug, $m)) {
                $next = max($next, ((int) $m[1]) + 1);
            }
        }
        $slug = sprintf('pool-%03d', $next);
        $db = 'skybook_pool_' . bin2hex(random_bytes(4));

        $started = microtime(true);
        $tables = $this->cloner->cloneDatabase($templateDb, $db);
        $this->writer->upsert($slug, ['db' => $db, 'name' => 'Centre ' . $slug, 'enabled' => true, 'status' => 'pool']);

        $output->writeln(sprintf(
            '+ %s (db %s, %d tables clonees en %.1fs)',
            $slug,
            $db,
            $tables,
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }
}

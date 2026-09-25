<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Tenant\TenantRemoval;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** CLI adapter: explicit drop option and progress output; deletion order belongs to TenantRemoval. */
#[AsCommand(name: 'todatempo:tenant:remove', description: 'Retire un centre du registre (et optionnellement supprime sa BDD)', aliases: ['skybook:tenant:remove'])]
final class TenantRemoveCommand extends Command
{
    public function __construct(
        private readonly TenantRemoval $removal,
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
        $removed = $this->removal->remove($slug, (bool) $input->getOption('drop-db'), static function (string $database) use ($output): void {
            $output->writeln(sprintf('BDD "%s" supprimee.', $database));
        });
        if (!$removed) {
            $output->writeln(sprintf('<error>Tenant "%s" inconnu.</error>', $slug));

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Tenant "%s" retire du registre.', $slug));

        return Command::SUCCESS;
    }
}

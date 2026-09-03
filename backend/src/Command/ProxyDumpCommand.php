<?php

declare(strict_types=1);

namespace App\Command;

use App\Tenant\CaddyConfigDumper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'todatempo:proxy:dump', description: 'Regenere caddy/Caddyfile depuis le registre des tenants', aliases: ['skybook:proxy:dump'])]
final class ProxyDumpCommand extends Command
{
    public function __construct(private readonly CaddyConfigDumper $dumper)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->dumper->dump();
        $output->writeln(sprintf('Caddyfile regenere : %s (caddy --watch se recharge seul).', $file));

        return Command::SUCCESS;
    }
}

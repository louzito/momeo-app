<?php

declare(strict_types=1);
namespace App\Command;

use App\Service\GiftCard\GiftCardPaymentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:gift-card-payments:expire', description: 'Libère les crédits des paiements abandonnés, dans la base tenant sélectionnée.')]
final class ExpireGiftCardPaymentsCommand extends Command
{
    public function __construct(private readonly GiftCardPaymentService $payments, private readonly \App\Service\Tenant\TenantWorkerGuard $guard) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->guard->validate();
        $output->writeln((string) $this->payments->expire());
        return Command::SUCCESS;
    }
}

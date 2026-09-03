<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GiftVoucher;
use App\GiftVoucher\GiftVoucherCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Smoke-test Phase 0 du chantier "cheques cadeaux reels" : cree un cheque
 * cadeau factice (statut `active` par defaut) dans la BDD du TENANT COURANT
 * (`TODATEMPO_TENANT=<slug> bin/console skybook:voucher:create ...`), pour
 * verifier que la table skybook_gift_voucher existe et est utilisable sans
 * passer par le tunnel d'achat (pas encore branche a ce stade).
 */
#[AsCommand(name: 'skybook:voucher:create', description: 'Smoke-test : cree un cheque cadeau factice (chantier cheques cadeaux)')]
final class VoucherCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GiftVoucherCodeGenerator $codeGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('service-code', null, InputOption::VALUE_REQUIRED, 'Code produit de la prestation', 'service_soin_visage')
            ->addOption('service-name', null, InputOption::VALUE_REQUIRED, 'Nom de la prestation', 'Soin visage éclat')
            ->addOption('jump-type-code', null, InputOption::VALUE_OPTIONAL, 'Ancienne option conservée pour compatibilité')
            ->addOption('jump-type-name', null, InputOption::VALUE_OPTIONAL, 'Ancienne option conservée pour compatibilité')
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Montant en centimes', '25000')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Code devise', 'EUR')
            ->addOption('purchaser-name', null, InputOption::VALUE_REQUIRED, 'Nom de l\'acheteur', 'Acheteur Test')
            ->addOption('purchaser-email', null, InputOption::VALUE_REQUIRED, 'Email de l\'acheteur', 'acheteur@example.com')
            ->addOption('beneficiary-name', null, InputOption::VALUE_REQUIRED, 'Nom du beneficiaire', 'Beneficiaire Test')
            ->addOption('beneficiary-email', null, InputOption::VALUE_REQUIRED, 'Email du beneficiaire', 'beneficiaire@example.com')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Statut initial (awaiting_payment|active|used)', GiftVoucher::STATUS_ACTIVE)
            ->addOption('validity-months', null, InputOption::VALUE_REQUIRED, 'Duree de validite en mois', '12')
            ->addOption('order-number', null, InputOption::VALUE_REQUIRED, 'Numero de commande d\'achat factice', '000000000-SMOKE');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $voucher = new GiftVoucher();
        $voucher->setCode($this->codeGenerator->generate());
        $voucher->setStatus((string) $input->getOption('status'));
        $voucher->setServiceCode((string) ($input->getOption('jump-type-code') ?: $input->getOption('service-code')));
        $voucher->setServiceName((string) ($input->getOption('jump-type-name') ?: $input->getOption('service-name')));
        $voucher->setAmount((int) $input->getOption('amount'));
        $voucher->setCurrencyCode((string) $input->getOption('currency'));
        $voucher->setPurchaserName((string) $input->getOption('purchaser-name'));
        $voucher->setPurchaserEmail((string) $input->getOption('purchaser-email'));
        $voucher->setBeneficiaryName((string) $input->getOption('beneficiary-name'));
        $voucher->setBeneficiaryEmail((string) $input->getOption('beneficiary-email'));
        $voucher->setPurchaseOrderNumber((string) $input->getOption('order-number'));
        $voucher->setExpiresAt((new \DateTimeImmutable())->modify('+' . (int) $input->getOption('validity-months') . ' months'));
        if ($voucher->getStatus() !== GiftVoucher::STATUS_AWAITING_PAYMENT) {
            $voucher->setActivatedAt(new \DateTimeImmutable());
        }

        $this->em->persist($voucher);
        $this->em->flush();

        $io->success(sprintf(
            'Cheque cree : code=%s id=%d statut=%s expire le %s',
            $voucher->getCode(),
            $voucher->getId(),
            $voucher->getEffectiveStatus(),
            $voucher->getExpiresAt()->format('Y-m-d'),
        ));

        return Command::SUCCESS;
    }
}

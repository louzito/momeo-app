<?php

declare(strict_types=1);

namespace App\GiftVoucher;

use App\Entity\GiftVoucher;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Model\ChannelInterface;

/**
 * Fait passer un cheque cadeau de `awaiting_payment` a `active` (encaissement
 * du paiement de la commande d'achat) et declenche l'email d'activation
 * (code + QR). Idempotent : ignore un cheque deja active/used (rejoue possible
 * du workflow event en cas de nouvelle tentative de paiement, etc.).
 */
final class GiftVoucherActivator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GiftVoucherMailer $mailer,
    ) {
    }

    public function activate(GiftVoucher $voucher, ChannelInterface $channel): void
    {
        if ($voucher->getStatus() !== GiftVoucher::STATUS_AWAITING_PAYMENT) {
            return;
        }

        $voucher->setStatus(GiftVoucher::STATUS_ACTIVE);
        $voucher->setActivatedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->mailer->sendActivationEmail($voucher, $channel);
    }
}

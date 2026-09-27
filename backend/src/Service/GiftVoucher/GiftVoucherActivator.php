<?php

declare(strict_types=1);

namespace App\Service\GiftVoucher;

use App\Entity\GiftVoucher;
use App\Repository\GiftVoucherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentInterface;

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
        private readonly GiftVoucherRepository $repository,
    ) {
    }

    /** Appelé exclusivement après la transition Sylius de paiement complété. */
    public function activateFromPayment(PaymentInterface $payment): void
    {
        if ($payment->getState() !== PaymentInterface::STATE_COMPLETED) {
            return;
        }
        $order = $payment->getOrder();
        if ($order === null || GiftOrderMarker::decode($order->getNotes()) === null || $order->getNumber() === null) {
            return;
        }
        $paid = 0;
        foreach ($order->getPayments() as $candidate) {
            if ($candidate->getState() === PaymentInterface::STATE_COMPLETED && $candidate->getCurrencyCode() === $order->getCurrencyCode()) $paid += $candidate->getAmount();
        }
        if ($paid < $order->getTotal() || $order->getTotal() <= 0 || !$order->getAdjustments('todatempo_payment_terms')->isEmpty()) return;
        $voucher = $this->repository->findOneByPurchaseOrderNumber($order->getNumber());
        $channel = $order->getChannel();
        if ($voucher === null || $channel === null) {
            return;
        }
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->refresh($voucher, LockMode::PESSIMISTIC_WRITE);
        }
        $this->activate($voucher, $channel);
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

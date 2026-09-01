<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\GiftVoucher;
use App\GiftVoucher\GiftOrderMarker;
use App\GiftVoucher\GiftVoucherActivator;
use App\Repository\GiftVoucherRepository;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * Cheques cadeaux reels — activation (statut `active`) + envoi de l'email
 * (code + QR) a l'encaissement du paiement de la commande d'ACHAT du cheque.
 * MEME EVENEMENT que sylius/invoicing-plugin pour l'envoi de la facture
 * (Sylius\InvoicingPlugin\EventListener\Workflow\Payment\ProduceOrderPaymentPaidListener,
 * voir vendor/sylius/invoicing-plugin/config/services/listeners/workflow.xml) :
 * `workflow.sylius_payment.completed.complete`, event post-transition Symfony
 * Workflow ($event->getSubject() = le Payment).
 */
#[AsEventListener(event: 'workflow.sylius_payment.completed.complete')]
final class ActivateGiftVoucherOnPaymentCompletedListener
{
    public function __construct(
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly GiftVoucherActivator $activator,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        Assert::isInstanceOf($payment, PaymentInterface::class);

        $order = $payment->getOrder();
        if ($order === null) {
            return;
        }
        $marker = GiftOrderMarker::decode($order->getNotes());
        $orderNumber = $order->getNumber();
        if ($marker === null || $orderNumber === null) {
            return;
        }

        $voucher = $this->giftVoucherRepository->findOneByPurchaseOrderNumber($orderNumber);
        $channel = $order->getChannel();
        if (!$voucher instanceof GiftVoucher || $channel === null) {
            return;
        }

        $this->activator->activate($voucher, $channel);
    }
}

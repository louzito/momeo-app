<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\GiftVoucher\GiftVoucherActivator;
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
        private readonly GiftVoucherActivator $activator,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        Assert::isInstanceOf($payment, PaymentInterface::class);

        $this->activator->activateFromPayment($payment);
    }
}

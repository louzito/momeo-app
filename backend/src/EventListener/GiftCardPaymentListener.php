<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\GiftCard\GiftCardService;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

final class GiftCardPaymentListener
{
    public function __construct(private readonly GiftCardService $cards) {}

    #[AsEventListener(event: 'workflow.sylius_payment.completed.complete')]
    public function completed(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if ($payment instanceof PaymentInterface) $this->cards->issueFromPayment($payment);
    }

    #[AsEventListener(event: 'workflow.sylius_payment.completed.fail')]
    #[AsEventListener(event: 'workflow.sylius_payment.completed.cancel')]
    public function failed(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if ($payment instanceof PaymentInterface && $payment->getOrder()?->getId() !== null) {
            $this->cards->releaseForOrder($payment->getOrder()->getId());
        }
    }
}

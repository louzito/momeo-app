<?php

declare(strict_types=1);

namespace App\EventListener;

use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

#[AsEventListener(event: 'workflow.sylius_payment.completed.complete', priority: 200)]
final class RecordPaymentReceiptListener
{
    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }
        $details = $payment->getDetails();
        $details['received_at'] ??= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $payment->setDetails($details);
    }
}

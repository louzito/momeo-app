<?php

declare(strict_types=1);

namespace App\EventListener;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
use Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/** Materialise et archive le PDF avant que le plugin envoie l'email (priorite 50). */
#[AsEventListener(event: 'workflow.sylius_payment.completed.complete', priority: 100)]
final class CreateInvoiceOnPaymentCompletedListener
{
    public function __construct(
        #[Autowire(service: 'sylius_invoicing.repository.invoice')]
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        #[Autowire(service: 'sylius_invoicing.provider.invoice_file')]
        private readonly InvoiceFileProviderInterface $invoiceFileProvider,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface || null === $payment->getOrder()?->getNumber()) {
            return;
        }

        $invoices = $this->invoiceRepository->findByOrderNumber((string) $payment->getOrder()->getNumber());
        $invoice = $invoices[0] ?? null;
        if ($invoice instanceof InvoiceInterface) {
            $this->invoiceFileProvider->provide($invoice);
        }
    }
}

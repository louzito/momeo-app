<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\User\ShopUser;
use Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
use Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Repository et provider Sylius utilisent la connexion et le stockage du tenant résolu.
 * Les appels admin restent protégés par le firewall JWT et TeamPermissions ;
 * les appels client exigent en plus la propriété et le paiement de la facture.
 */
final class InvoiceAccess
{
    public function __construct(
        #[Autowire(service: 'sylius_invoicing.repository.invoice')]
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        #[Autowire(service: 'sylius_invoicing.provider.invoice_file')]
        private readonly InvoiceFileProviderInterface $invoiceFileProvider,
        #[Autowire(param: 'sylius_invoicing.pdf_generator.enabled')]
        private readonly bool $pdfGeneratorEnabled = true,
    ) {}

    /** @return list<InvoiceInterface> */
    public function forAdmin(string $orderNumber): array
    {
        return '' !== $orderNumber
            ? $this->invoiceRepository->findByOrderNumber($orderNumber)
            : $this->invoiceRepository->findBy([], ['issuedAt' => 'DESC'], 100);
    }

    /** @return array{content: string, filename: string} */
    public function downloadForAdmin(string $id): array
    {
        if (!$this->pdfGeneratorEnabled) {
            throw new InvoiceUnavailable('Generation PDF desactivee.');
        }
        return $this->pdf($this->find($id));
    }

    /** @return array{content: string, filename: string} */
    public function downloadForCustomer(string $id, ShopUser $user): array
    {
        $invoice = $this->find($id);
        if (!$this->ownsInvoice($invoice, $user)) {
            throw new InvoiceUnavailable('Facture introuvable.');
        }
        return $this->pdf($invoice);
    }

    public function ownsInvoice(InvoiceInterface $invoice, ShopUser $user): bool
    {
        $customerEmail = $invoice->order()->getCustomer()?->getEmail();

        return \is_string($customerEmail)
            && 0 === strcasecmp($customerEmail, (string) $user->getEmail())
            && $invoice->paymentState() === 'paid';
    }

    private function find(string $id): InvoiceInterface
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof InvoiceInterface) {
            throw new InvoiceUnavailable('Facture introuvable.');
        }
        return $invoice;
    }

    /** @return array{content: string, filename: string} */
    private function pdf(InvoiceInterface $invoice): array
    {
        $pdf = $this->invoiceFileProvider->provide($invoice);
        return ['content' => $pdf->content(), 'filename' => basename($pdf->filename())];
    }
}

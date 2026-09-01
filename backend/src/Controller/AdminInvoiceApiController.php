<?php

declare(strict_types=1);

namespace App\Controller;

use Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
use Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SkyBook — mini API v2 admin pour les FACTURES du plugin sylius/invoicing-plugin.
 *
 * Le plugin n'expose AUCUNE ressource API Platform : son panel (/admin/invoices)
 * n'est accessible qu'en session admin Sylius. L'espace centre du front, lui,
 * s'authentifie en JWT sur /api/v2/admin/... — ces deux routes vivent donc sous
 * ce prefixe pour heriter du firewall `api_admin` (JWT + ROLE_API_ACCESS),
 * et collent au contrat DEJA code dans le front (adminApi.js) :
 *
 *   GET /api/v2/admin/invoices?orderNumber=000000027  -> { member: [ {...} ] }
 *   GET /api/v2/admin/invoices/{id}/download          -> PDF (Gotenberg/archive)
 *
 * Le PDF est servi par le meme service que le bouton Download du panel
 * (sylius_invoicing.provider.invoice_file : lit l'archive private/invoices/,
 * ou la (re)genere via Gotenberg si absente).
 *
 * NB modele mono-centre : 1 deploiement = 1 centre = 1 BDD, donc pas de
 * filtrage par tenant — tout admin JWT du deploiement voit toutes les factures.
 */
final class AdminInvoiceApiController
{
    public function __construct(
        #[Autowire(service: 'sylius_invoicing.repository.invoice')]
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        #[Autowire(service: 'sylius_invoicing.provider.invoice_file')]
        private readonly InvoiceFileProviderInterface $invoiceFileProvider,
        #[Autowire(param: 'sylius_invoicing.pdf_generator.enabled')]
        private readonly bool $pdfGeneratorEnabled = true,
    ) {
    }

    #[Route('/api/v2/admin/invoices', name: 'skybook_api_admin_invoice_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $orderNumber = trim((string) $request->query->get('orderNumber', ''));

        /** @var InvoiceInterface[] $invoices */
        $invoices = '' !== $orderNumber
            ? $this->invoiceRepository->findByOrderNumber($orderNumber)
            : $this->invoiceRepository->findBy([], ['issuedAt' => 'DESC'], 100);

        return new JsonResponse([
            'member' => array_map($this->normalize(...), $invoices),
        ]);
    }

    #[Route('/api/v2/admin/invoices/{id}/download', name: 'skybook_api_admin_invoice_download', methods: ['GET'])]
    public function download(string $id): Response
    {
        if (!$this->pdfGeneratorEnabled) {
            return new JsonResponse(['error' => 'Generation PDF desactivee.'], Response::HTTP_NOT_FOUND);
        }

        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof InvoiceInterface) {
            return new JsonResponse(['error' => 'Facture introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $pdf = $this->invoiceFileProvider->provide($invoice);

        return new Response($pdf->content(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // inline : permet l'affichage dans un <iframe> cote front
            // (le front force de toute facon le telechargement via blob quand il veut).
            'Content-Disposition' => sprintf('inline; filename="%s"', $pdf->filename()),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalize(InvoiceInterface $invoice): array
    {
        return [
            'id' => $invoice->id(),
            'number' => $invoice->number(),
            'orderNumber' => $invoice->order()->getNumber(),
            'issuedAt' => $invoice->issuedAt()->format(\DateTimeInterface::ATOM),
            'total' => $invoice->total(),
            'currencyCode' => $invoice->currencyCode(),
            'paymentState' => $invoice->paymentState(),
        ];
    }
}

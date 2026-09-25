<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Invoice\InvoiceAccess;
use App\Service\Invoice\InvoiceUnavailable;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
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
 *   GET /api/v2/admin/invoices/{id}/download          -> PDF (archive privee)
 *
 * Le PDF est servi par le meme service que le bouton Download du panel
 * (sylius_invoicing.provider.invoice_file : lit l'archive private/invoices/,
 * ou la genere via wkhtmltopdf si absente). La resolution de BDD et la claim
 * JWT bornent la requete au tenant courant ; le storage applique le meme slug.
 */
final class AdminInvoiceApiController
{
    public function __construct(private readonly InvoiceAccess $invoices) {}

    #[Route('/api/v2/admin/invoices', name: 'skybook_api_admin_invoice_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $orderNumber = trim((string) $request->query->get('orderNumber', ''));

        $invoices = $this->invoices->forAdmin($orderNumber);

        return new JsonResponse([
            'member' => array_map($this->normalize(...), $invoices),
        ]);
    }

    #[Route('/api/v2/admin/invoices/{id}/download', name: 'skybook_api_admin_invoice_download', methods: ['GET'])]
    public function download(string $id): Response
    {
        try {
            $pdf = $this->invoices->downloadForAdmin($id);
        } catch (InvoiceUnavailable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return new Response($pdf['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // inline : permet l'affichage dans un <iframe> cote front
            // (le front force de toute facon le telechargement via blob quand il veut).
            'Content-Disposition' => sprintf('inline; filename="%s"', $pdf['filename']),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
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

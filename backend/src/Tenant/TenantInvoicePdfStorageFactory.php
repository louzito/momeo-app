<?php

declare(strict_types=1);

namespace App\Tenant;

use Gaufrette\Filesystem as GaufretteFilesystem;
use Sylius\PdfGenerationBundle\Filesystem\Gaufrette\GaufrettePdfStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem as LocalFilesystem;

/**
 * Factures par tenant : reconstruit le storage PDF du contexte
 * `sylius_invoicing` (service sylius_pdf_generation.storage.sylius_invoicing,
 * surcharge dans config/services.yaml) avec :
 *   - prefix gaufrette = slug  =>  PDF archives dans private/invoices/{slug}/
 *     (l'adapter local du plugin a `create: true`, sous-dossiers auto) ;
 *   - local_cache_directory par slug  =>  pas de collision entre deux centres
 *     ayant le meme numero de facture (ex. 2026_08_000000001.pdf) lors du
 *     resolveLocalPath (email avec PJ, download).
 * Le tenant par defaut (skyline) garde la racine historique private/invoices/.
 * Le nom de fichier lui-meme reste inchange (le bundle interdit les "/").
 */
final class TenantInvoicePdfStorageFactory
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        #[Autowire(service: 'gaufrette.sylius_invoicing_invoice_filesystem')] private readonly GaufretteFilesystem $filesystem,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDir,
    ) {
    }

    public function create(): GaufrettePdfStorage
    {
        $slug = $this->tenantContext->getSlug();

        return new GaufrettePdfStorage(
            $this->filesystem,
            new LocalFilesystem(),
            $this->tenantContext->isDefaultTenant() ? '' : $slug,
            $this->cacheDir . '/sylius_invoicing_pdf/' . $slug . '/',
        );
    }
}

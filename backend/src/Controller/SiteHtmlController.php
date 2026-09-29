<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Site\{SiteManagementService, SiteSeoService, SiteHtmlRenderer};
use App\Service\Tenant\{TenantContext, TenantRegistry};
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SiteHtmlController
{
    public function __construct(
        private readonly SiteManagementService $site,
        private readonly SiteSeoService $seo,
        private readonly SiteHtmlRenderer $renderer,
        private readonly TenantContext $tenant,
        private readonly TenantRegistry $registry,
    ) {}

    #[Route('/api/v2/shop/site/html/{path}', name: 'site_html', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'HEAD'])]
    public function page(Request $request, string $path = ''): Response
    {
        $slug = $this->tenant->getExplicitSlug();
        if ($slug === null || !$this->registry->isServable($slug)) throw new NotFoundHttpException();
        $domainTenant = $this->registry->slugForVerifiedDomain($request->getHost());
        if ($domainTenant !== null && $domainTenant !== $slug) throw new NotFoundHttpException();
        $headers = ['Cache-Control' => 'private, no-store'];
        $base = $this->seo->base();
        if ($path === 'sitemap.xml') {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($this->site->pages() as $page) {
                if ($page->isArchived() || $page->getPublished() === null) continue;
                $snapshot = $page->getPublished(); $snapshot['role'] = $page->getRole();
                $xml .= '<url><loc>'.htmlspecialchars($base.SiteSeoService::path($snapshot), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
            }
            return new Response($xml.'</urlset>', 200, $headers + ['Content-Type' => 'application/xml; charset=UTF-8']);
        }
        if ($path === 'accueil') return new RedirectResponse($base, 301, $headers);
        $role = match ($path) { '' => 'home', 'legal/terms' => 'terms', 'legal/mentions' => 'mentions', default => null };
        $entity = $role ? $this->site->rolePage($role) : (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $path) ? $this->site->publishedPage($path) : null);
        $page = $entity ? $this->site->render($entity) : null;
        if ($page !== null && $path !== SiteSeoService::path($page)) return new RedirectResponse($base.SiteSeoService::path($page), 301, $headers);
        // Preserve historical business screens. Their data and authorization stay in their APIs.
        $business = false;
        foreach ([
            'shop|products|cart|calendar|gift-card(?:/print)?',
            '(?:services|jump|boarding-pass)/[^/]+',
            'waitlist/unsubscribe/[^/]+',
            'checkout/(?:options|mode|schedule|details|eligibility|gift|summary|payment|gift-confirmation|(?:confirmation|shop-confirmation)/[^/]+)',
            'account(?:/login|/booking/[^/]+)?',
            'beneficiary(?:/login|/voucher/[^/]+/(?:schedule|expired|confirmation))?',
            'admin(?:/login|/(?:products|options)(?:/[^/]+)?|/(?:physical-products|staff|plannings|resources|agenda|schedule|bookings|waitlist|clients|orders|vouchers|payments|settings)|/orders/[^/]+/invoice|/site/(?:images|appearance|menus|pages|pages/[^/]+/edit|preview/[^/]+))?',
            'status/(?:slot-unavailable|eligibility-blocked|voucher-invalid)',
            't/[^/]+(?:/.*)?', // Historical Vue redirects retain their existing behavior.
        ] as $pattern) {
            if (preg_match('#^(?:'.$pattern.')$#D', $path)) { $business = true; break; }
        }
        $status = $page || $business || $role ? 200 : 404;
        $metadata = $page ? $this->seo->metadata($page) : ['title' => $status === 404 ? 'Page introuvable' : 'TodaTempo', 'description' => ''];
        if (!$page) $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        if ($page) {
            foreach ($page['media'] as &$media) {
                $media['path'] = $this->seo->mediaUrl($media['path']);
                $media['thumbnail'] = $this->seo->mediaUrl($media['thumbnail']);
            }
            unset($media);
        }
        try {
            $html = $this->renderer->render($page, $metadata, $base, $status === 404 ? '<h1>Page introuvable</h1>' : '');
        } catch (\RuntimeException $error) {
            // Never disguise an unavailable renderer as a successful empty/indexable page.
            return new Response('La page est momentanément indisponible. Réessayez dans quelques instants.', 503, $headers + ['X-Robots-Tag' => 'noindex', 'Retry-After' => '60']);
        }
        return new Response($html, $status, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}

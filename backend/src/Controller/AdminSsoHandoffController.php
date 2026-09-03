<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\AdminLoginTicketStore;
use App\Tenant\TenantContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Receives the opaque ticket in a POST body, never in the browser URL. */
final class AdminSsoHandoffController
{
    private const COOKIE_NAME = 'TODATEMPO_ADMIN_SSO';

    public function __construct(
        private readonly AdminLoginTicketStore $ticketStore,
        private readonly TenantContext $tenantContext,
        #[Autowire('%todatempo.public_base_url%')] private readonly string $publicBaseUrl,
    ) {}

    #[Route('/api/v2/admin/todatempo/sso/handoff', name: 'todatempo_api_admin_sso_handoff', methods: ['POST'])]
    #[Route('/api/v2/admin/momeo/sso/handoff', name: 'momeo_api_admin_sso_handoff_legacy', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $slug = $this->tenantContext->getSlug();
        $baseUrl = rtrim($this->publicBaseUrl, '/');
        $loginUrl = $baseUrl.'/'.$slug.'/admin/login';

        try {
            $ticket = $this->ticketStore->consume((string) $request->request->get('code', ''));
            $browserSession = $this->ticketStore->createBrowserSession($ticket);
        } catch (\Throwable) {
            return new RedirectResponse($loginUrl.'?sso=error');
        }

        $response = new RedirectResponse($loginUrl.'?sso=1');
        $response->headers->setCookie($this->cookie($browserSession, $slug, new \DateTimeImmutable('+60 seconds')));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function cookie(string $value, string $slug, \DateTimeImmutable $expires): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($value)
            ->withExpires($expires)
            ->withPath('/'.$slug.'/api/v2/admin/todatempo/sso/session')
            ->withSecure(str_starts_with($this->publicBaseUrl, 'https://'))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}

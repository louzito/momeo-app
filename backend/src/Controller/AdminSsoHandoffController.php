<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tenant\AdminSsoSession;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantUrlGenerator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Receives the opaque ticket in a POST body, never in the browser URL. */
final class AdminSsoHandoffController
{
    private const COOKIE_NAME = 'TODATEMPO_ADMIN_SSO';

    public function __construct(
        private readonly AdminSsoSession $session,
        private readonly TenantContext $tenantContext,
        private readonly TenantUrlGenerator $urlGenerator,
    ) {}

    #[Route('/api/v2/admin/todatempo/sso/handoff', name: 'todatempo_api_admin_sso_handoff', methods: ['POST'])]
    #[Route('/api/v2/admin/momeo/sso/handoff', name: 'momeo_api_admin_sso_handoff_legacy', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        if ($this->tenantContext->getExplicitSlug() === null) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Centre SSO absent.');
        }
        $slug = $this->tenantContext->getSlug();
        $loginUrl = $this->urlGenerator->url($slug, 'admin/login');

        try {
            $browserSession = $this->session->handoff((string) $request->request->get('code', ''));
        } catch (\Throwable) {
            return new RedirectResponse($loginUrl.'?sso=error');
        }

        $response = new RedirectResponse($loginUrl.'?sso=1');
        $response->headers->setCookie($this->cookie($browserSession, $request, new \DateTimeImmutable('+60 seconds')));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function cookie(string $value, Request $request, \DateTimeImmutable $expires): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($value)
            ->withExpires($expires)
            ->withPath($request->getBaseUrl().'/api/v2/admin/todatempo/sso/session')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}

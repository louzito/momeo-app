<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tenant\AdminSsoRejected;
use App\Service\Tenant\AdminSsoSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Attribute\Route;

/** HTTP session adapter: cookie parsing, rejection status and response headers. */
final class AdminSsoController
{
    private const COOKIE_NAME = 'TODATEMPO_ADMIN_SSO';
    private const LEGACY_COOKIE_NAME = 'MOMEO_ADMIN_SSO';

    public function __construct(
        private readonly AdminSsoSession $session,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/v2/admin/todatempo/sso/session', name: 'todatempo_api_admin_sso_session', methods: ['POST'])]
    #[Route('/api/v2/admin/momeo/sso/session', name: 'momeo_api_admin_sso_session_legacy', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $cookie = (string) ($request->cookies->get(self::COOKIE_NAME) ?? $request->cookies->get(self::LEGACY_COOKIE_NAME, ''));
        } catch (\Throwable $exception) {
            $this->logger->warning('TodaTempo admin browser session rejected.', ['exception' => $exception]);

            return new JsonResponse(['error' => 'invalid_sso_session'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $this->session->authenticate($cookie);
        } catch (AdminSsoRejected $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $response = new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue('')
                ->withExpires(new \DateTimeImmutable('-1 day'))
                ->withPath($request->getBaseUrl().'/api/v2/admin/todatempo/sso/session')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }
}

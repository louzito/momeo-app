<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User\AdminUser;
use App\Tenant\AdminLoginTicketStore;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Attribute\Route;

final class AdminSsoController
{
    private const COOKIE_NAME = 'MOMEO_ADMIN_SSO';

    public function __construct(
        private readonly AdminLoginTicketStore $ticketStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/v2/admin/momeo/sso/session', name: 'momeo_api_admin_sso_session', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $ticket = $this->ticketStore->consumeBrowserSession((string) $request->cookies->get(self::COOKIE_NAME, ''));
        } catch (\Throwable $exception) {
            $this->logger->warning('Momeo admin browser session rejected.', [
                'exception' => $exception,
            ]);

            return new JsonResponse(['error' => 'invalid_sso_session'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $ticket['email']]);
        if (!$admin instanceof AdminUser || !$admin->isEnabled()) {
            return new JsonResponse(['error' => 'admin_not_found'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $response = new JsonResponse([
            'token' => $this->jwtManager->create($admin),
            'admin' => ['email' => $admin->getEmail(), 'name' => $ticket['name']],
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue('')
                ->withExpires(new \DateTimeImmutable('-1 day'))
                ->withPath('/'.$ticket['slug'].'/api/v2/admin/momeo/sso/session')
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }
}

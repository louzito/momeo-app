<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\AdminLoginTicketStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InternalAdminLoginTicketController
{
    public function __construct(
        private readonly AdminLoginTicketStore $ticketStore,
        #[Autowire('%momeo.provisioning_secret%')] private readonly string $secret,
    ) {}

    #[Route('/internal/momeo/admin-login-tickets', name: 'momeo_internal_admin_login_ticket', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $providedSecret = (string) $request->headers->get('X-Momeo-Provisioning-Key', '');
        if ($this->secret === '' || !hash_equals($this->secret, $providedSecret)) {
            return new JsonResponse(['error' => 'unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $request->toArray();
            $code = $this->ticketStore->create(
                (string) ($payload['slug'] ?? ''),
                (string) ($payload['email'] ?? ''),
                (string) ($payload['name'] ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => $exception->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'ticket_creation_failed'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['code' => $code, 'expiresIn' => 60], JsonResponse::HTTP_CREATED);
    }
}

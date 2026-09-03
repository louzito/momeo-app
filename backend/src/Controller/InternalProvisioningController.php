<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\TenantProvisioner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InternalProvisioningController
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        #[Autowire('%todatempo.provisioning_secret%')] private readonly string $secret,
        #[Autowire('%todatempo.public_base_url%')] private readonly string $publicBaseUrl,
    ) {}

    #[Route('/internal/todatempo/provisioning/instances', name: 'todatempo_internal_provision_instance', methods: ['POST'])]
    #[Route('/internal/momeo/provisioning/instances', name: 'momeo_internal_provision_instance_legacy', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $providedSecret = (string) ($request->headers->get('X-TodaTempo-Provisioning-Key')
            ?? $request->headers->get('X-Momeo-Provisioning-Key', ''));
        if ($this->secret === '' || !hash_equals($this->secret, $providedSecret)) {
            return new JsonResponse(['error' => 'unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $request->toArray();
            $tenant = $this->provisioner->claim(
                (string) ($payload['slug'] ?? ''),
                (string) ($payload['name'] ?? ''),
                isset($payload['email']) ? (string) $payload['email'] : null,
                (string) ($payload['externalId'] ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => 'invalid_request', 'message' => $exception->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\UnderflowException $exception) {
            return new JsonResponse(['error' => 'pool_empty', 'message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => 'slug_conflict', 'message' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'provisioning_failed'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $baseUrl = rtrim($this->publicBaseUrl, '/');

        return new JsonResponse([
            'status' => 'ready',
            'externalId' => $tenant->externalId,
            'slug' => $tenant->slug,
            'storefrontUrl' => $baseUrl.'/'.$tenant->slug.'/',
            'adminUrl' => $baseUrl.'/'.$tenant->slug.'/admin/login',
            'remainingPool' => $tenant->remainingPool,
            'alreadyExisting' => $tenant->alreadyExisting,
        ], $tenant->alreadyExisting ? JsonResponse::HTTP_OK : JsonResponse::HTTP_CREATED);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Observability\HealthChecker;
use App\Observability\MetricsRegistry;
use App\Tenant\TenantRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ObservabilityController
{
    public function __construct(
        private HealthChecker $healthChecker,
        private TenantRegistry $tenantRegistry,
        private MetricsRegistry $metrics,
    ) {}

    #[Route('/health/live', name: 'todatempo_health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'todatempo_health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        return $this->readinessResponse($this->healthChecker->applicationReadiness());
    }

    #[Route('/health/tenants/{tenant<[a-z0-9][a-z0-9-]{0,62}>}/live', name: 'todatempo_health_tenant_live', methods: ['GET'])]
    public function tenantLive(string $tenant): JsonResponse
    {
        $ok = $this->tenantRegistry->isServable($tenant);

        return new JsonResponse(['status' => $ok ? 'ok' : 'unavailable'], $ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    #[Route('/health/tenants/{tenant<[a-z0-9][a-z0-9-]{0,62}>}/ready', name: 'todatempo_health_tenant_ready', methods: ['GET'])]
    public function tenantReady(string $tenant): JsonResponse
    {
        return $this->readinessResponse($this->healthChecker->tenantReadiness($tenant));
    }

    #[Route('/metrics', name: 'todatempo_metrics', methods: ['GET'])]
    public function metrics(): Response
    {
        return new Response($this->metrics->render(), Response::HTTP_OK, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    /** @param array<string, bool> $checks */
    private function readinessResponse(array $checks): JsonResponse
    {
        $ok = !\in_array(false, $checks, true);

        return new JsonResponse(['status' => $ok ? 'ready' : 'not_ready', 'checks' => $checks], $ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}

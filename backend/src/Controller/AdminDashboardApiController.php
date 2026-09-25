<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Dashboard\DashboardReadService;
use App\Service\Dashboard\InvalidDashboardRange;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v2/admin/dashboard')]
#[IsGranted('ROLE_API_ACCESS')]
final class AdminDashboardApiController
{
    public function __construct(private readonly DashboardReadService $dashboard) {}

    #[Route('/overview', name: 'todatempo_api_admin_dashboard_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        try {
            $from = $request->query->has('from') ? (string) $request->query->get('from') : null;
            $to = $request->query->has('to') ? (string) $request->query->get('to') : null;
            $timezone = $request->query->has('timezone') ? (string) $request->query->get('timezone') : null;
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'La plage ou le fuseau horaire est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            return new JsonResponse($this->dashboard->overview($from, $to, $timezone));
        } catch (InvalidDashboardRange $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}

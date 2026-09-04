<?php

declare(strict_types=1);

namespace App\Controller;

use App\Availability\CenterTimeZoneProvider;
use App\Dashboard\DashboardMetricsCalculator;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Repository\PlanningRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v2/admin/dashboard')]
#[IsGranted('ROLE_API_ACCESS')]
final class AdminDashboardApiController
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly PlanningRepository $plannings,
        private readonly GiftVoucherRepository $vouchers,
        private readonly DashboardMetricsCalculator $calculator,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
    ) {}

    #[Route('/overview', name: 'todatempo_api_admin_dashboard_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        try {
            $timezone = new \DateTimeZone((string) $request->query->get('timezone', $this->timeZoneProvider->get()->getName()));
            $today = new \DateTimeImmutable('today', $timezone);
            $from = new \DateTimeImmutable((string) $request->query->get('from', $today->format('Y-m-d')), $timezone);
            $to = new \DateTimeImmutable((string) $request->query->get('to', $from->modify('+1 day')->format('Y-m-d')), $timezone);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'La plage ou le fuseau horaire est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($to <= $from || $to > $from->modify('+366 days')) {
            return new JsonResponse(['error' => 'La plage doit contenir entre 1 et 366 jours.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $utc = new \DateTimeZone('UTC');
        $fromUtc = $from->setTimezone($utc);
        $toUtc = $to->setTimezone($utc);
        $metrics = $this->calculator->calculate(
            $this->bookings->findForAdministration(),
            $this->plannings->findForAdministration(),
            $this->vouchers->findAll(),
            $fromUtc,
            $toUtc,
            $timezone,
        );

        return new JsonResponse($metrics + ['range' => ['from' => $from->format(\DateTimeInterface::ATOM), 'to' => $to->format(\DateTimeInterface::ATOM), 'timezone' => $timezone->getName()]]);
    }
}

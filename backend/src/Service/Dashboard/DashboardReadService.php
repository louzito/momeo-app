<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Service\Availability\CenterTimeZoneProvider;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Repository\PlanningRepository;

/** Lecture dans les repositories du tenant courant ; plage locale, calcul UTC. */
final class DashboardReadService
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly PlanningRepository $plannings,
        private readonly GiftVoucherRepository $vouchers,
        private readonly DashboardMetricsCalculator $calculator,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
    ) {}

    /** @return array<string, mixed> */
    public function overview(?string $fromValue, ?string $toValue, ?string $timezoneName): array
    {
        try {
            $timezone = new \DateTimeZone($timezoneName ?? $this->timeZoneProvider->get()->getName());
            $today = new \DateTimeImmutable('today', $timezone);
            $from = new \DateTimeImmutable($fromValue ?? $today->format('Y-m-d'), $timezone);
            $to = new \DateTimeImmutable($toValue ?? $from->modify('+1 day')->format('Y-m-d'), $timezone);
        } catch (\Throwable) {
            throw new InvalidDashboardRange('La plage ou le fuseau horaire est invalide.');
        }
        if ($to <= $from || $to > $from->modify('+366 days')) {
            throw new InvalidDashboardRange('La plage doit contenir entre 1 et 366 jours.');
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

        return $metrics + ['range' => ['from' => $from->format(\DateTimeInterface::ATOM), 'to' => $to->format(\DateTimeInterface::ATOM), 'timezone' => $timezone->getName()]];
    }
}

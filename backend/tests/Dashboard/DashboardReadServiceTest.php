<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Controller\AdminDashboardApiController;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Repository\PlanningRepository;
use App\Service\Dashboard\DashboardMetricsCalculator;
use App\Tests\Availability\AvailabilityTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DashboardReadServiceTest extends AvailabilityTestCase
{
    public function testLocalDstRangeAndMetricsMatchExistingCalculator(): void
    {
        $booking = $this->booking(new \DateTimeImmutable('2026-03-28T23:30:00Z'), new \DateTimeImmutable('2026-03-29T00:30:00Z'));
        $this->configureRules([]);
        $calculator = new DashboardMetricsCalculator();
        $this->entityManager->persist($booking);
        $this->entityManager->flush();
        $controller = $this->controller();
        $response = $controller->overview(new Request(['from' => '2026-03-29']));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame(['from' => '2026-03-29T00:00:00+01:00', 'to' => '2026-03-30T00:00:00+02:00', 'timezone' => 'Europe/Paris'], $data['range']);
        unset($data['range']);
        self::assertEquals($calculator->calculate(self::getContainer()->get(BookingRepository::class)->findForAdministration(), self::getContainer()->get(PlanningRepository::class)->findForAdministration(), self::getContainer()->get(GiftVoucherRepository::class)->findAll(), new \DateTimeImmutable('2026-03-28T23:00:00Z'), new \DateTimeImmutable('2026-03-29T22:00:00Z'), new \DateTimeZone('Europe/Paris')), $data);
    }

    public function testInvalidRangesAre422(): void
    {
        foreach ([
            [['timezone' => ['invalid']], 'La plage ou le fuseau horaire est invalide.'],
            [['timezone' => 'invalid'], 'La plage ou le fuseau horaire est invalide.'],
            [['from' => 'invalid'], 'La plage ou le fuseau horaire est invalide.'],
            [['from' => '2026-01-01', 'to' => '2026-01-01'], 'La plage doit contenir entre 1 et 366 jours.'],
            [['from' => '2026-01-01', 'to' => '2027-01-03'], 'La plage doit contenir entre 1 et 366 jours.'],
        ] as [$query, $message]) {
            $response = $this->controller()->overview(new Request($query));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['error' => $message], json_decode($response->getContent(), true));
        }
    }

    public function testExplicitTimezoneAnd366DayBoundary(): void
    {
        $response = $this->controller()->overview(new Request(['from' => '2026-01-01', 'to' => '2027-01-02', 'timezone' => 'America/Montreal']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('America/Montreal', json_decode($response->getContent(), true)['range']['timezone']);
    }

    private function controller(): AdminDashboardApiController
    {
        return self::getContainer()->get(AdminDashboardApiController::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Service\Dashboard\DashboardMetricsCalculator;
use App\Entity\Booking;
use App\Entity\GiftVoucher;
use App\Entity\Planning;
use PHPUnit\Framework\TestCase;

final class DashboardMetricsCalculatorTest extends TestCase
{
    public function testItCalculatesTheDocumentedMetricsFromRealEntities(): void
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $from = new \DateTimeImmutable('2027-05-03T00:00:00+02:00');
        $to = $from->modify('+1 day');
        $bookings = [
            $this->booking('alice@example.test', '2027-04-01T10:00:00Z', '2027-04-10T08:00:00Z', '2027-04-10T09:00:00Z'),
            $this->booking('ALICE@example.test', '2027-05-03T06:00:00Z', '2027-05-03T07:00:00Z', '2027-05-03T09:00:00Z', Booking::STATUS_COMPLETED, 'paid', 12000),
            $this->booking('bob@example.test', '2027-05-03T06:30:00Z', '2027-05-03T09:00:00Z', '2027-05-03T10:00:00Z'),
            $this->booking('cancelled@example.test', '2027-05-03T06:30:00Z', '2027-05-03T10:00:00Z', '2027-05-03T11:00:00Z', Booking::STATUS_CANCELLED),
            $this->booking('absent@example.test', '2027-05-03T06:30:00Z', '2027-05-03T11:00:00Z', '2027-05-03T12:00:00Z', Booking::STATUS_NO_SHOW),
            $this->booking('boundary@example.test', '2027-05-03T06:30:00Z', $to->format(\DateTimeInterface::ATOM), $to->modify('+1 hour')->format(\DateTimeInterface::ATOM)),
        ];
        $planning = new Planning();
        $planning->setActive(true);
        $planning->setCapacity(2);
        $planning->setDays(['monday' => [['start' => '09:00', 'end' => '13:00']]]);
        $voucher = $this->voucher('2027-05-03T07:00:00Z', '2027-05-03T08:00:00Z', 5000);

        $result = (new DashboardMetricsCalculator())->calculate($bookings, [$planning], [$voucher], $from, $to, $timezone);

        self::assertSame(2, $result['appointments']);
        self::assertSame(17000, $result['paidRevenue']);
        self::assertSame(37.5, $result['occupancyRate']);
        self::assertSame(180, $result['occupiedMinutes']);
        self::assertSame(480, $result['capacityMinutes']);
        self::assertSame(1, $result['cancelled']);
        self::assertSame(1, $result['noShows']);
        self::assertSame(4, $result['newClients']);
        self::assertSame(1, $result['giftCards']['sold']);
        self::assertSame(5000, $result['giftCards']['paidAmount']);
        self::assertSame(1, $result['giftCards']['active']);
    }

    public function testItReturnsZeroOccupancyWithoutActiveCapacity(): void
    {
        $from = new \DateTimeImmutable('2027-05-03T00:00:00Z');
        $result = (new DashboardMetricsCalculator())->calculate([], [], [], $from, $from->modify('+1 day'), new \DateTimeZone('UTC'));
        self::assertSame(0.0, $result['occupancyRate']);
        self::assertSame(0, $result['capacityMinutes']);
    }

    public function testDatedOctoberPlanningCountsOnlyIts88Slots(): void
    {
        $days = [];
        for ($day = new \DateTimeImmutable('2026-10-01'); $day < new \DateTimeImmutable('2026-11-01'); $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5) {
                $days[$day->format('Y-m-d')] = ['09:00', '11:30', '14:00', '16:30'];
            }
        }
        $normalized = (new \App\Service\Planning\PlanningInput())->normalizeDays(['days' => $days]);
        $planning = new Planning();
        $planning->setCapacity(8);
        $planning->setDays($normalized['days']);
        $planning->setLegacyConfig($normalized['legacyConfig']);
        $calculator = new DashboardMetricsCalculator();
        $timezone = new \DateTimeZone('Europe/Paris');
        $from = new \DateTimeImmutable('2026-10-01', $timezone);
        $result = $calculator->calculate([], [$planning], [], $from, $from->modify('+1 month'), $timezone);
        self::assertSame(88 * 60 * 8, $result['capacityMinutes']);

        foreach (['2026-10-10', '2026-11-02'] as $date) {
            $from = new \DateTimeImmutable($date, $timezone);
            $result = $calculator->calculate([], [$planning], [], $from, $from->modify('+1 day'), $timezone);
            self::assertSame(0, $result['capacityMinutes']);
        }
        $planning->setActive(false);
        $from = new \DateTimeImmutable('2026-10-01', $timezone);
        $result = $calculator->calculate([], [$planning], [], $from, $from->modify('+1 month'), $timezone);
        self::assertSame(0, $result['capacityMinutes']);
    }

    public function testDatedSlotsRespectDaySpecificTimesAndPartialRange(): void
    {
        $planning = new Planning();
        $planning->setCapacity(2);
        $planning->setDays(['thursday' => [['start' => '09:00', 'end' => '10:00'], ['start' => '14:00', 'end' => '15:00']]]);
        $planning->setLegacyConfig(['days' => ['2026-10-01' => ['09:00'], '2026-10-08' => ['14:00']]]);
        $from = new \DateTimeImmutable('2026-10-01T09:30:00+02:00');
        $result = (new DashboardMetricsCalculator())->calculate([], [$planning], [], $from, new \DateTimeImmutable('2026-10-08T14:15:00+02:00'), new \DateTimeZone('Europe/Paris'));
        self::assertSame(90, $result['capacityMinutes']);
    }

    public function testCapacityUsesPlanningTimezoneAndElapsedMinutesAcrossDst(): void
    {
        $planning = new Planning();
        $planning->setDays(['sunday' => [['start' => '01:00', 'end' => '04:00']]]);
        $from = new \DateTimeImmutable('2026-03-29T00:00:00+01:00');
        $to = new \DateTimeImmutable('2026-03-30T00:00:00+02:00');
        $result = (new DashboardMetricsCalculator())->calculate([], [$planning], [], $from, $to, new \DateTimeZone('UTC'));
        self::assertSame(120, $result['capacityMinutes']);
    }

    private function booking(string $email, string $createdAt, string $start, string $end, string $status = Booking::STATUS_CONFIRMED, ?string $paymentState = null, ?int $amount = null): Booking
    {
        $booking = new Booking();
        $booking->setCustomerEmail($email);
        $booking->setStatus($status);
        $booking->setSlotStart(new \DateTimeImmutable($start));
        $booking->setSlotEnd(new \DateTimeImmutable($end));
        $booking->setPaymentState($paymentState);
        $booking->setTotalAmount($amount);
        $booking->setCurrencyCode('EUR');
        $this->setPrivate($booking, 'createdAt', new \DateTimeImmutable($createdAt));
        return $booking;
    }

    private function voucher(string $createdAt, string $activatedAt, int $amount): GiftVoucher
    {
        $voucher = new GiftVoucher();
        $voucher->setStatus(GiftVoucher::STATUS_ACTIVE);
        $voucher->setAmount($amount);
        $voucher->setCurrencyCode('EUR');
        $voucher->setExpiresAt(new \DateTimeImmutable('2099-01-01T00:00:00Z'));
        $voucher->setActivatedAt(new \DateTimeImmutable($activatedAt));
        $this->setPrivate($voucher, 'createdAt', new \DateTimeImmutable($createdAt));
        return $voucher;
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}

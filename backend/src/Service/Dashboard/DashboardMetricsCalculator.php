<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\Booking;
use App\Entity\GiftVoucher;
use App\Entity\Planning;

final class DashboardMetricsCalculator
{
    /**
     * @param list<Booking> $bookings All bookings are required to identify first-time clients.
     * @param list<Planning> $plannings
     * @param list<GiftVoucher> $vouchers
     * @return array<string, mixed>
     */
    public function calculate(array $bookings, array $plannings, array $vouchers, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $timezone): array
    {
        $appointments = $cancelled = $noShows = $paidAmount = $occupiedMinutes = 0;
        $currency = 'EUR';
        $firstBookingByEmail = [];

        foreach ($bookings as $booking) {
            $email = mb_strtolower(trim($booking->getCustomerEmail()));
            if ($email !== '' && (!isset($firstBookingByEmail[$email]) || $booking->getCreatedAt() < $firstBookingByEmail[$email])) {
                $firstBookingByEmail[$email] = $booking->getCreatedAt();
            }
            if (!$this->contains($booking->getSlotStart(), $from, $to)) {
                continue;
            }
            if ($booking->getPaymentState() === 'paid') {
                $paidAmount += $booking->getTotalAmount() ?? $booking->getAmount() ?? 0;
                $currency = $booking->getCurrencyCode();
            }
            if ($booking->getStatus() === Booking::STATUS_CANCELLED) {
                ++$cancelled;
                continue;
            }
            if ($booking->getStatus() === Booking::STATUS_NO_SHOW) {
                ++$noShows;
                continue;
            }
            ++$appointments;
            if (\in_array($booking->getStatus(), [Booking::STATUS_AWAITING_PAYMENT, Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED], true)) {
                $occupiedMinutes += max(0, (int) (($booking->getSlotEnd()->getTimestamp() - $booking->getSlotStart()->getTimestamp()) / 60));
            }
        }

        $newClients = count(array_filter($firstBookingByEmail, fn (\DateTimeImmutable $date): bool => $this->contains($date, $from, $to)));
        $capacityMinutes = $this->capacityMinutes($plannings, $from, $to);
        $giftCards = ['sold' => 0, 'paidAmount' => 0, 'awaitingPayment' => 0, 'active' => 0, 'used' => 0, 'expired' => 0];
        foreach ($vouchers as $voucher) {
            $status = $voucher->getEffectiveStatus();
            $key = match ($status) {
                GiftVoucher::STATUS_AWAITING_PAYMENT => 'awaitingPayment',
                GiftVoucher::STATUS_ACTIVE => 'active',
                GiftVoucher::STATUS_USED => 'used',
                default => 'expired',
            };
            ++$giftCards[$key];
            if ($this->contains($voucher->getCreatedAt(), $from, $to)) {
                ++$giftCards['sold'];
            }
            if ($voucher->getActivatedAt() !== null && $this->contains($voucher->getActivatedAt(), $from, $to)) {
                $giftCards['paidAmount'] += $voucher->getAmount();
                $currency = $voucher->getCurrencyCode();
            }
        }

        return [
            'appointments' => $appointments,
            'paidRevenue' => $paidAmount + $giftCards['paidAmount'],
            'currency' => $currency,
            'occupancyRate' => $capacityMinutes > 0 ? round(min(100, 100 * $occupiedMinutes / $capacityMinutes), 1) : 0.0,
            'occupiedMinutes' => $occupiedMinutes,
            'capacityMinutes' => $capacityMinutes,
            'cancelled' => $cancelled,
            'noShows' => $noShows,
            'newClients' => $newClients,
            'giftCards' => $giftCards,
        ];
    }

    /** @param list<Planning> $plannings */
    private function capacityMinutes(array $plannings, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $minutes = 0;
        foreach ($plannings as $planning) {
            if (!$planning->isActive()) continue;
            $planningTimezone = new \DateTimeZone($planning->getTimezone());
            $localFrom = $from->setTimezone($planningTimezone);
            $localTo = $to->setTimezone($planningTimezone);
            $firstDay = $localFrom->setTime(0, 0);
            $lastDay = $localTo->setTime(0, 0);
            $datedDays = $planning->getLegacyConfig()['days'] ?? [];
            for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
                // Explicit calendar dates take precedence over the inferred weekly rules.
                $ranges = $planning->getDays()[strtolower($day->format('l'))] ?? [];
                if ($datedDays !== []) {
                    $ranges = array_map(static function (string $time): array {
                        $end = (new \DateTimeImmutable('2000-01-01 '.$time))->modify('+1 hour')->format('H:i');
                        return ['start' => $time, 'end' => $end === '00:00' ? '23:59' : $end];
                    }, $datedDays[$day->format('Y-m-d')] ?? []);
                }
                foreach ($ranges as $range) {
                    try {
                        $start = new \DateTimeImmutable($day->format('Y-m-d').' '.$range['start'], $planningTimezone);
                        $end = new \DateTimeImmutable($day->format('Y-m-d').' '.$range['end'], $planningTimezone);
                    } catch (\Throwable) { continue; }
                    $start = max($start, $localFrom);
                    $end = min($end, $localTo);
                    $minutes += max(0, (int) (($end->getTimestamp() - $start->getTimestamp()) / 60)) * max(1, $planning->getCapacity());
                }
            }
        }
        return $minutes;
    }

    private function contains(\DateTimeImmutable $date, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return $date >= $from && $date < $to;
    }
}

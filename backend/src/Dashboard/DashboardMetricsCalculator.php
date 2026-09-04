<?php

declare(strict_types=1);

namespace App\Dashboard;

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
        $capacityMinutes = $this->capacityMinutes($plannings, $from, $to, $timezone);
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
    private function capacityMinutes(array $plannings, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $timezone): int
    {
        $minutes = 0;
        $firstDay = $from->setTimezone($timezone)->setTime(0, 0);
        $lastDay = $to->setTimezone($timezone)->setTime(0, 0);
        foreach ($plannings as $planning) {
            if (!$planning->isActive()) continue;
            for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
                foreach ($planning->getDays()[strtolower($day->format('l'))] ?? [] as $range) {
                    try {
                        $start = new \DateTimeImmutable($day->format('Y-m-d').' '.$range['start'], $timezone);
                        $end = new \DateTimeImmutable($day->format('Y-m-d').' '.$range['end'], $timezone);
                    } catch (\Throwable) { continue; }
                    $start = max($start, $from->setTimezone($timezone));
                    $end = min($end, $to->setTimezone($timezone));
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

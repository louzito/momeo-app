<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use Sylius\Component\Core\Model\PaymentInterface;

final class CollectedRevenueCalculator
{
    /** @param iterable<PaymentInterface> $payments
     *  @return array{paidRevenue: int, currency: string}
     */
    public function calculate(iterable $payments, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $amount = 0;
        $currency = 'EUR';
        foreach ($payments as $payment) {
            // Redeeming prepaid credit is not another cash receipt.
            if (!in_array($payment->getState(), ['completed', 'refunded'], true) || $payment->getMethod()?->getCode() === 'gift_card') {
                continue;
            }
            $receivedAt = $payment->getDetails()['received_at'] ?? null;
            $date = is_string($receivedAt) ? new \DateTimeImmutable($receivedAt) : $payment->getUpdatedAt();
            if ($date === null || $date < $from || $date >= $to) {
                continue;
            }
            $amount += (int) $payment->getAmount();
            $currency = $payment->getCurrencyCode() ?? $currency;
        }
        return ['paidRevenue' => $amount, 'currency' => $currency];
    }
}

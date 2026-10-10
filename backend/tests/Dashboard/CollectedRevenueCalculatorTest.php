<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Entity\Payment\Payment;
use App\Service\Dashboard\CollectedRevenueCalculator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethod;

final class CollectedRevenueCalculatorTest extends TestCase
{
    public function testReceiptsCountTheDepositAndIgnoreUnpaidAndRedeemedCredit(): void
    {
        $from = new \DateTimeImmutable('2026-10-10T00:00:00+02:00');
        $deposit = $this->payment(1680, 'completed', 'bank_transfer');
        $unpaid = $this->payment(3920, 'new', 'bank_transfer');
        $credit = $this->payment(5600, 'completed', 'gift_card');
        $result = (new CollectedRevenueCalculator())->calculate([$deposit, $unpaid, $credit], $from, $from->modify('+1 day'));
        self::assertSame(['paidRevenue' => 1680, 'currency' => 'EUR'], $result);
        self::assertSame(0, (new CollectedRevenueCalculator())->calculate([$deposit], $from->modify('+3 days'), $from->modify('+4 days'))['paidRevenue']);
    }

    public function testReceiptTimestampSurvivesLaterPaymentUpdatesAndEndIsExcluded(): void
    {
        $from = new \DateTimeImmutable('2026-10-10T00:00:00Z');
        $payment = $this->payment(1680, 'completed', 'bank_transfer');
        $payment->setDetails(['received_at' => $from->format(DATE_ATOM)]);
        $payment->setUpdatedAt(new \DateTime('2026-10-20T12:00:00Z'));
        $calculator = new CollectedRevenueCalculator();
        self::assertSame(1680, $calculator->calculate([$payment], $from, $from->modify('+1 day'))['paidRevenue']);
        self::assertSame(0, $calculator->calculate([$payment], $from->modify('-1 day'), $from)['paidRevenue']);
    }

    public function testReceiptListenerPreservesTimestampAndExistingDetails(): void
    {
        $payment = $this->payment(1680, 'completed', 'bank_transfer');
        $payment->setDetails(['checkout_expires' => 123]);
        $listener = new \App\EventListener\RecordPaymentReceiptListener();
        $event = new \Symfony\Component\Workflow\Event\CompletedEvent($payment, new \Symfony\Component\Workflow\Marking());
        $listener($event);
        $first = $payment->getDetails();
        self::assertArrayHasKey('received_at', $first);
        self::assertSame(123, $first['checkout_expires']);
        $listener($event);
        self::assertSame($first, $payment->getDetails());
    }

    private function payment(int $amount, string $state, string $methodCode): Payment
    {
        $payment = new Payment();
        $payment->setAmount($amount);
        $payment->setState($state);
        $payment->setCurrencyCode('EUR');
        $payment->setUpdatedAt(new \DateTime('2026-10-10T11:45:55Z'));
        $method = new PaymentMethod();
        $method->setCode($methodCode);
        $payment->setMethod($method);
        return $payment;
    }
}

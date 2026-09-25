<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class AdminRefundContractTest extends TestCase
{
    public function testRefundEndpointIsIdempotentAndSynchronizesBusinessObjects(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Controller/AdminRefundApiController.php');
        $entity = file_get_contents(__DIR__.'/../../src/Entity/Payment/RefundOperation.php');
        $migration = file_get_contents(__DIR__.'/../../migrations/Version20260909000000.php');
        self::assertStringContainsString("get('Idempotency-Key')", $controller);
        self::assertStringContainsString('PESSIMISTIC_WRITE', $controller);
        self::assertStringContainsString("setPaymentState(\$full ? 'refunded' : 'partially_refunded')", $controller);
        self::assertStringContainsString('Booking::STATUS_CANCELLED', $controller);
        self::assertStringContainsString('creditNoteNumber', $entity);
        self::assertStringContainsString('UNIQUE INDEX uniq_todatempo_refund_key', $migration);
    }

    public function testConfiguredProviderSupportsManualRefundsAndRejectsUnconfiguredStripe(): void
    {
        $payment = new \App\Entity\Payment\Payment();
        $method = new \App\Entity\Payment\PaymentMethod();
        $method->setCode('cash');
        $payment->setMethod($method);
        $provider = new \App\Service\Payment\ConfiguredRefundProvider();
        self::assertInstanceOf(\App\Service\Payment\RefundProvider::class, $provider);
        self::assertSame(['provider' => 'cash', 'reference' => 'manual-refund-42'], $provider->refund($payment, 100, 'refund-42'));
        $method->setCode('stripe_web_elements');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Stripe n’est pas configure pour ce centre.');
        $provider->refund($payment, 100, 'refund-42');
    }

    public function testRefundRouteRequiresFinancePermission(): void
    {
        $subscriber = file_get_contents(__DIR__.'/../../src/Security/AdminApiPermissionSubscriber.php');
        self::assertStringContainsString('payments', $subscriber);
        self::assertStringContainsString('TeamPermission::Finances', $subscriber);
    }
}

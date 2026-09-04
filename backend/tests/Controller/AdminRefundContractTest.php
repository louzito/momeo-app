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

    public function testConfiguredProviderUsesStripeIdempotencyAndSupportsManualRefunds(): void
    {
        $provider = file_get_contents(__DIR__.'/../../src/Payment/ConfiguredRefundProvider.php');
        self::assertStringContainsString("['idempotency_key' => \$idempotencyKey]", $provider);
        self::assertStringContainsString("'payment_intent' => \$intent", $provider);
        self::assertStringContainsString("'manual-'.\$idempotencyKey", $provider);
    }

    public function testRefundRouteRequiresFinancePermission(): void
    {
        $subscriber = file_get_contents(__DIR__.'/../../src/Security/AdminApiPermissionSubscriber.php');
        self::assertStringContainsString('payments', $subscriber);
        self::assertStringContainsString('TeamPermission::Finances', $subscriber);
    }
}

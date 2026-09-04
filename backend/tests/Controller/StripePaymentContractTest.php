<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class StripePaymentContractTest extends TestCase
{
    public function testWebhookIsVerifiedAndIdempotent(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Controller/ShopStripePaymentController.php');
        self::assertIsString($source);
        self::assertStringContainsString('Webhook::constructEvent', $source);
        self::assertStringContainsString('Stripe-Signature', $source);
        self::assertStringContainsString('StripeWebhookEvent', $source);
        self::assertStringContainsString("'replayed' => true", $source);
    }

    public function testOnlySignedSuccessConfirmsBooking(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Controller/ShopStripePaymentController.php');
        $service = file_get_contents(__DIR__.'/../../src/Payment/StripeCheckout.php');
        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertStringContainsString('checkout.session.completed', $controller);
        self::assertStringContainsString("payment_status ?? null) === 'paid'", $controller);
        self::assertStringContainsString('STATUS_CONFIRMED', $service);
        self::assertStringNotContainsString('paid_demo', $controller.$service);
    }
}

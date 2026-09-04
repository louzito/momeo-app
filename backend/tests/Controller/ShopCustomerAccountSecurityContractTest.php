<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class ShopCustomerAccountSecurityContractTest extends TestCase
{
    public function testAccountEndpointsAreServerProtectedAndScopedToAuthenticatedEmail(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Controller/ShopCustomerAccountApiController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("#[IsGranted('ROLE_USER')]", $controller);
        self::assertStringContainsString("LOWER(booking.customerEmail) = :email", $controller);
        self::assertStringContainsString('strcasecmp($booking->getCustomerEmail()', $controller);
        self::assertStringNotContainsString("query->get('customer", $controller);
    }

    public function testLegacyPublicTokenRouteAlsoChecksOwnership(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Controller/ShopBookingApiController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("#[IsGranted('ROLE_USER')]", $controller);
        self::assertStringContainsString('strcasecmp($booking->getCustomerEmail()', $controller);
    }
}

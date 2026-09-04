<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class BookingRulesContractTest extends TestCase
{
    public function testAvailabilityAndCreationEnforceServerRules(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Controller/ShopBookingApiController.php');
        self::assertGreaterThanOrEqual(2, substr_count($controller, 'bookingRules->assertBookableAt'));
        self::assertStringContainsString("bufferBeforeMinutes", $controller);
        self::assertStringContainsString("bufferAfterMinutes", $controller);

        $guard = (string) file_get_contents(__DIR__.'/../../src/Booking/BookingSlotGuard.php');
        self::assertStringContainsString("bufferBeforeMinutes", $guard);
        self::assertStringContainsString("bufferAfterMinutes", $guard);
    }
}

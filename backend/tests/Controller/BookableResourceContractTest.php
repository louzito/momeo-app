<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class BookableResourceContractTest extends TestCase
{
    public function testAllBookingEntryPointsValidateResources(): void
    {
        foreach (['ShopBookingApiController.php', 'AdminBookingApiController.php', 'ShopCustomerAccountApiController.php'] as $file) {
            $source = (string) file_get_contents(__DIR__.'/../../src/Controller/'.$file);
            self::assertStringContainsString('resourceAvailability->choose', $source, $file);
        }

        $guard = (string) file_get_contents(__DIR__.'/../../src/Booking/BookingSlotGuard.php');
        self::assertStringContainsString('SELECT capacity FROM todatempo_bookable_resource', $guard);
        self::assertStringContainsString("countOverlap('resource_code'", $guard);
    }

    public function testAdminCrudAndServiceAssociationRoutesExist(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Controller/AdminBookableResourceApiController.php');
        self::assertStringContainsString("'/bookable-resources'", $source);
        self::assertStringContainsString("'/services/{code}/bookable-resources'", $source);
        self::assertStringContainsString("methods: ['DELETE']", $source);
    }
}

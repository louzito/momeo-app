<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class PhysicalCheckoutContractTest extends TestCase
{
    public function testPhysicalCheckoutIsSeparatedFromBookingCreationAndLocksInventory(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Controller/ShopPhysicalOrderApiController.php');
        self::assertStringContainsString("LockMode::PESSIMISTIC_WRITE", $source);
        self::assertStringContainsString("getOnHand() - \$variant->getOnHold()", $source);
        self::assertStringContainsString("['pickup', 'delivery']", $source);
        self::assertStringNotContainsString('new Booking', $source);
        self::assertStringNotContainsString('/bookings', $source);
    }
}

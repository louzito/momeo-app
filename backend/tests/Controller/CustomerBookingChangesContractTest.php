<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerBookingChangesContractTest extends TestCase
{
    public function testCustomerChangesAreOwnedProtectedAtomicAndNotified(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Controller/ShopCustomerAccountApiController.php');
        self::assertIsString($source);
        self::assertStringContainsString("#[IsGranted('ROLE_USER')]", $source);
        self::assertStringContainsString('ownedBooking($publicToken, $user)', $source);
        self::assertStringContainsString('beginTransaction()', $source);
        self::assertStringContainsString('LockMode::PESSIMISTIC_WRITE', $source);
        self::assertStringContainsString('slotGuard->assertAvailable', $source);
        self::assertStringContainsString('emailDispatcher->cancellation', $source);
        self::assertStringContainsString('emailDispatcher->rescheduled', $source);
        self::assertStringContainsString('recordChange', $source);
    }

    public function testDeadlinePolicyIsEnforcedOnTheServer(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Booking/CustomerBookingChangePolicy.php');
        self::assertIsString($source);
        self::assertStringContainsString('bookingChanges', $source);
        self::assertStringContainsString("modify(sprintf('-%d hours'", $source);
        self::assertStringContainsString('Le délai de modification', $source);
    }
}

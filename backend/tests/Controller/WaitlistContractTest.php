<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class WaitlistContractTest extends TestCase
{
    public function testPublicSubscriptionRequiresLiteralExplicitConsent(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Controller/ShopWaitlistApiController.php');
        self::assertIsString($source);
        self::assertStringContainsString("(\$data['consent'] ?? false) !== true", $source);
        self::assertStringContainsString("methods: ['POST']", $source);
    }

    public function testAdminWaitlistUsesAgendaPermission(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Security/AdminApiPermissionSubscriber.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression('/bookings\|plannings\|staff-time-offs\|waitlist/', $source);
    }

    public function testNotificationHasDatabaseEnforcedIdempotency(): void
    {
        $migration = file_get_contents(__DIR__.'/../../migrations/Version20260911000000.php');
        self::assertIsString($migration);
        self::assertStringContainsString('UNIQUE INDEX uniq_waitlist_notification_key', $migration);
        self::assertStringContainsString('FOREIGN KEY (request_id)', $migration);
    }
}

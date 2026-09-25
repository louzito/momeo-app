<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class StaffPreferenceContractTest extends TestCase
{
    public function testPublicBookingCreationFallsBackToADeterministicStaffChoice(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Controller/ShopBookingApiController.php');

        self::assertSame(2, substr_count($source, '$this->chooseAutoStaff('), 'Both direct and voucher creation must auto-assign staff when none is requested.');
        self::assertStringContainsString('StaffEligibility::forService', $source);
    }

    public function testAvailabilityExposesANoPreferenceOptionAlongsideCompatibleStaff(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Controller/ShopBookingApiController.php');

        self::assertStringContainsString("'staffMemberId' => null", $source);
        self::assertStringContainsString('Sans préférence', $source);
    }

    public function testStaffEligibilityFiltersOnActiveBookableAndCompetent(): void
    {
        $eligible = new \App\Entity\StaffMember();
        $eligible->setActive(true);
        $eligible->setBookable(true);
        $eligible->setServiceCodes(['massage']);
        $inactive = clone $eligible;
        $inactive->setActive(false);
        $notBookable = clone $eligible;
        $notBookable->setBookable(false);
        $otherService = clone $eligible;
        $otherService->setServiceCodes(['other']);
        self::assertSame([$eligible], \App\Service\Staff\StaffEligibility::forService(
            [$inactive, $notBookable, $otherService, $eligible], 'massage',
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Staff;

use App\Entity\StaffMember;
use App\Staff\StaffEligibility;
use PHPUnit\Framework\TestCase;

final class StaffEligibilityTest extends TestCase
{
    public function testOnlyActiveBookableAndCompetentMembersAreEligible(): void
    {
        $inactive = $this->member(active: false, bookable: true, serviceCodes: ['jump_tandem'], position: 1);
        $notBookable = $this->member(active: true, bookable: false, serviceCodes: ['jump_tandem'], position: 2);
        $notCompetent = $this->member(active: true, bookable: true, serviceCodes: ['jump_solo'], position: 3);
        $eligible = $this->member(active: true, bookable: true, serviceCodes: ['jump_tandem'], position: 4);

        $result = StaffEligibility::forService([$inactive, $notBookable, $notCompetent, $eligible], 'jump_tandem');

        self::assertSame([$eligible], $result);
    }

    public function testEligibleMembersAreOrderedByPositionForDeterministicChoice(): void
    {
        $second = $this->member(active: true, bookable: true, serviceCodes: ['jump_tandem'], position: 5);
        $first = $this->member(active: true, bookable: true, serviceCodes: ['jump_tandem'], position: 1);
        $third = $this->member(active: true, bookable: true, serviceCodes: ['jump_tandem'], position: 9);

        $result = StaffEligibility::forService([$second, $first, $third], 'jump_tandem');

        self::assertSame([$first, $second, $third], $result);
    }

    /** @param list<string> $serviceCodes */
    private function member(bool $active, bool $bookable, array $serviceCodes, int $position): StaffMember
    {
        $member = new StaffMember();
        $member->setActive($active);
        $member->setBookable($bookable);
        $member->setServiceCodes($serviceCodes);
        $member->setPosition($position);

        return $member;
    }
}

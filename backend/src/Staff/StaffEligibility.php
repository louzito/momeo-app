<?php

declare(strict_types=1);

namespace App\Staff;

use App\Entity\StaffMember;

final class StaffEligibility
{
    /**
     * @param list<StaffMember> $members
     * @return list<StaffMember> active, bookable and competent members, ordered deterministically
     */
    public static function forService(array $members, string $serviceCode): array
    {
        $eligible = array_values(array_filter(
            $members,
            static fn (StaffMember $member): bool => $member->isActive()
                && $member->isBookable()
                && \in_array($serviceCode, $member->getServiceCodes(), true),
        ));

        usort($eligible, static fn (StaffMember $a, StaffMember $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $eligible;
    }
}

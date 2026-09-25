<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\Planning;

/** Explicit admin field allowlist; no authorization or database access. */
final class PlanningView
{
    /** @return array<string, mixed> */
    public function admin(Planning $planning): array
    {
        $staff = $planning->getStaffMember();
        $legacy = $planning->getLegacyConfig() ?? [];
        $legacyDays = $legacy['days'] ?? [];
        return [
            'id' => $planning->getId(),
            'code' => $planning->getCode(),
            'name' => $planning->getName(),
            'timezone' => $planning->getTimezone(),
            'staffMemberId' => $staff?->getId(),
            'scope' => $staff ? 'staff' : 'establishment',
            'weeklyDays' => $planning->getDays(),
            'days' => $legacyDays,
            'openDays' => $legacy['openDays'] ?? [],
            'times' => $legacy['times'] ?? [],
            'capacity' => $planning->getCapacity(),
            'serviceCodes' => $planning->getServiceCodes(),
            'jumpCodes' => $planning->getServiceCodes(),
            'active' => $planning->isActive(),
            'createdAt' => $planning->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $planning->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

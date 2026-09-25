<?php

declare(strict_types=1);

namespace App\Service\Staff;

use App\Entity\StaffMember;
use App\Entity\User\AdminUser;

/** Explicit admin field allowlist; no authorization or database access. */
final class StaffMemberView
{
    /** @return array<string, mixed> */
    public function admin(StaffMember $member, ?AdminUser $account): array
    {
        return [
            'id' => $member->getId(),
            'firstName' => $member->getFirstName(),
            'lastName' => $member->getLastName(),
            'displayName' => trim($member->getFirstName().' '.$member->getLastName()),
            'email' => $member->getEmail(),
            'phone' => $member->getPhone(),
            'jobTitle' => $member->getJobTitle(),
            'bio' => $member->getBio(),
            'color' => $member->getColor(),
            'active' => $member->isActive(),
            'bookable' => $member->isBookable(),
            'serviceCodes' => $member->getServiceCodes(),
            'workingHours' => $member->getWorkingHours(),
            'position' => $member->getPosition(),
            'accountEmail' => $account instanceof AdminUser ? $account->getEmail() : null,
            'role' => $account instanceof AdminUser ? $account->getTeamRole()->value : null,
            'createdAt' => $member->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $member->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

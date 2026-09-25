<?php

declare(strict_types=1);

namespace App\Service\Availability;

use App\Entity\Planning;
use App\Entity\Booking;
use App\Entity\StaffMember;
use App\Repository\PlanningRepository;
use App\Service\Booking\SlotUnavailable;

/** Admin/customer use planning ranges, not the public generated departure grid. */
final class PlanningSlotPolicy
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
    ) {
    }

    public function contains(Planning $planning, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $timezone = new \DateTimeZone($planning->getTimezone());
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);
        if ($localStart->format('Y-m-d') !== $localEnd->format('Y-m-d')) {
            return false;
        }
        foreach ($planning->getDays()[strtolower($localStart->format('l'))] ?? [] as $range) {
            if ($localStart->format('H:i') >= $range['start'] && $localEnd->format('H:i') <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    /** An explicit admin planning only needs to be active and service-compatible. */
    public function forAdmin(string $code, int $staffId, string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $end): ?Planning
    {
        if ($code !== '') {
            $planning = $this->planningRepository->findOneBy(['code' => $code, 'active' => true]);
            return $planning instanceof Planning && ($planning->getServiceCodes() === [] || \in_array($serviceCode, $planning->getServiceCodes(), true)) ? $planning : null;
        }

        foreach ($this->planningRepository->findActiveForService($serviceCode) as $planning) {
            if ($planning->getStaffMember() !== null && $planning->getStaffMember()?->getId() !== $staffId) {
                continue;
            }
            if ($this->contains($planning, $start, $end)) {
                return $planning;
            }
        }

        return null;
    }

    /** Customer moves preserve the booked elapsed duration, not the current catalogue duration. */
    public function assertPlannedSlot(Planning $planning, Booking $booking, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if (($end->getTimestamp() - $start->getTimestamp()) !== ($booking->getSlotEnd()->getTimestamp() - $booking->getSlotStart()->getTimestamp())) {
            throw new SlotUnavailable('La durée de la prestation ne peut pas être modifiée.');
        }
        if ($this->contains($planning, $start, $end)) {
            return;
        }
        throw new SlotUnavailable('Ce créneau ne figure plus au planning.');
    }

    public function assertStaffHours(StaffMember $staff, Planning $planning, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $timezone = new \DateTimeZone($planning->getTimezone());
        if (!\App\Service\Staff\WorkingHours::contains($staff->getWorkingHours(), $start, $end, $timezone)) {
            throw new SlotUnavailable('Ce créneau est en dehors des horaires du collaborateur ou empiète sur une pause.');
        }
    }
}

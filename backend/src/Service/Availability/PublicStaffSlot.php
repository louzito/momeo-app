<?php

declare(strict_types=1);

namespace App\Service\Availability;

use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Repository\BookingRepository;
use App\Repository\StaffMemberRepository;
use App\Repository\StaffTimeOffRepository;
use App\Service\Staff\StaffEligibility;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

final class PublicStaffSlot
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly StaffTimeOffRepository $timeOffRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
        private readonly PlanningProvider $planningProvider,
        private readonly AvailabilitySlotGenerator $slotGenerator,
        private readonly ServiceDuration $duration,
    ) {
    }

    // Called inside the caller transaction; candidate locking order is part of the booking contract.
    public function chooseAutoStaff(string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $end, string $planningCode): ?StaffMember
    {
        $candidates = StaffEligibility::forService($this->staffRepository->findBy(['active' => true]), $serviceCode);
        foreach ($candidates as $candidate) {
            $staff = $this->entityManager->find(StaffMember::class, $candidate->getId(), LockMode::PESSIMISTIC_WRITE);
            if ($staff instanceof StaffMember && $this->validateStaffSlot($staff, $serviceCode, $start, $end, $planningCode) === null) {
                return $staff;
            }
        }

        return null;
    }

    public function validateStaffSlot(?StaffMember $staff, string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $end, string $planningCode): ?string
    {
        if (!$staff instanceof StaffMember || !$staff->isActive() || !$staff->isBookable()) {
            return 'Ce collaborateur n’est pas disponible.';
        }
        if (!\in_array($serviceCode, $staff->getServiceCodes(), true)) {
            return 'Ce collaborateur ne réalise pas cette prestation.';
        }
        $timezone = $this->timeZoneProvider->get();
        $localStart = $start->setTimezone($timezone);
        if (!\App\Service\Staff\WorkingHours::contains($staff->getWorkingHours(), $start, $end, $timezone)) {
            return 'Ce créneau est en dehors des horaires du collaborateur ou empiète sur une pause.';
        }
        if (($end->getTimestamp() - $start->getTimestamp()) !== $this->duration->forCode($serviceCode) * 60) {
            return 'Ce créneau ne correspond plus aux disponibilités de cette prestation.';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $localStart->format('Y-m-d'), $timezone);
        if (!$date || !$this->isPlanned($serviceCode, $start, $date, $timezone, $planningCode)) {
            return 'Ce créneau ne figure plus au planning.';
        }
        if ($this->bookingRepository->hasOverlap($staff, $start, $end)) {
            return 'Ce créneau vient d’être réservé.';
        }
        if ($this->timeOffRepository->hasOverlap($staff, $start, $end)) {
            return 'Ce collaborateur est indisponible sur ce créneau.';
        }

        return null;
    }

    private function isPlanned(string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $date, \DateTimeZone $timezone, string $planningCode): bool
    {
        $slots = $this->slotGenerator->generate(
            $this->planningProvider->active(),
            $serviceCode,
            $this->duration->forCode($serviceCode),
            $date,
            $date,
            new \DateTimeImmutable('@0'),
            $timezone,
        );
        foreach ($slots as $slot) {
            if ($slot['start']->getTimestamp() === $start->getTimestamp() && hash_equals($slot['planningCode'], $planningCode)) {
                return true;
            }
        }

        return false;
    }
}

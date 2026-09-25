<?php

declare(strict_types=1);

namespace App\Service\Availability;

use App\Service\Booking\BookingRules;
use App\Entity\Booking;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Repository\BookingRepository;
use App\Repository\PlanningRepository;
use App\Repository\StaffMemberRepository;
use App\Repository\StaffTimeOffRepository;
use App\Service\Resource\ResourceAvailability;
use App\Service\Staff\StaffEligibility;

final class AvailabilityService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly PlanningRepository $planningRepository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly StaffTimeOffRepository $timeOffRepository,
        private readonly PlanningProvider $planningProvider,
        private readonly AvailabilitySlotGenerator $slotGenerator,
        private readonly BookingRules $bookingRules,
        private readonly ResourceAvailability $resourceAvailability,
        private readonly ServiceDuration $duration,
    ) {
    }

    /** @return array<string, mixed> Public slot identifiers, ordering and legacy fields are preserved. */
    public function find(Product $product, string $serviceCode, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $timezone, ?\DateTimeImmutable $now = null): array
    {
        $duration = $this->duration->forCode($serviceCode);

        $activeStaff = array_values(array_filter(
            $this->staffRepository->findBy(['active' => true], ['position' => 'ASC']),
            static fn (StaffMember $member): bool => $member->isBookable(),
        ));
        $eligibleStaff = StaffEligibility::forService($activeStaff, $serviceCode);

        $rules = $this->bookingRules->get();
        $rangeStart = $from->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('-%d minutes', $rules['bufferBeforeMinutes']));
        $rangeEnd = $to->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('+%d minutes', $rules['bufferAfterMinutes']));
        $blocking = $this->bookingRepository->findBlockingBetween($rangeStart, $rangeEnd);
        $timeOffs = $this->timeOffRepository->findBetween($rangeStart, $rangeEnd);
        $slots = [];
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $plannedSlots = $this->slotGenerator->generate($this->planningProvider->active(), $serviceCode, $duration, $from, $to, $now, $timezone);
        $planningCapacity = [];
        foreach ($this->planningRepository->findActiveForService($serviceCode) as $planning) {
            $planningCapacity[$planning->getCode()] = $planning->getCapacity();
        }

        foreach ($plannedSlots as $plannedSlot) {
            try {
                $this->bookingRules->assertBookableAt($plannedSlot['start'], $now);
            } catch (\DomainException) {
                continue;
            }
            $capacity = $planningCapacity[$plannedSlot['planningCode']] ?? 1;
            $booked = $this->bookedOnPlanning($blocking, $plannedSlot['planningCode'], $plannedSlot['start'], $plannedSlot['end']);
            if ($booked >= $capacity) {
                continue;
            }
            try {
                $resource = $this->resourceAvailability->choose($product, $plannedSlot['start'], $plannedSlot['end'], $blocking);
            } catch (\DomainException) {
                continue;
            }
            $matchedStaffCount = 0;
            foreach ($eligibleStaff as $staff) {
                if (\App\Service\Staff\WorkingHours::contains($staff->getWorkingHours(), $plannedSlot['localStart'], $plannedSlot['end'], $timezone)) {
                    $startUtc = $plannedSlot['start'];
                    $endUtc = $plannedSlot['end'];
                    if (!$this->isBlocked($staff, $startUtc, $endUtc, $blocking, $timeOffs)) {
                        ++$matchedStaffCount;
                        $slots[] = [
                            'id' => sprintf('staff_%d_%s_%s', $staff->getId(), $startUtc->format('Ymd_Hi'), $serviceCode),
                            'planningCode' => $plannedSlot['planningCode'],
                            'start' => $startUtc->format(\DateTimeInterface::ATOM),
                            'end' => $endUtc->format(\DateTimeInterface::ATOM),
                            'capacity' => $capacity,
                            'booked' => $booked,
                            'remaining' => $capacity - $booked,
                            'resourceCode' => $resource?->getCode(),
                            'resourceName' => $resource?->getName(),
                            'resourceRequired' => $product->isBookableResourceRequired(),
                            'availableResourceCodes' => $this->resourceAvailability->availableCodes($product, $startUtc, $endUtc, $blocking),
                            'compatibleJumpTypeIds' => [$serviceCode],
                            'serviceCode' => $serviceCode,
                            'staffMemberId' => $staff->getId(),
                            'staffName' => trim($staff->getFirstName().' '.$staff->getLastName()),
                            'instructor' => trim($staff->getFirstName().' '.$staff->getLastName()),
                        ];
                    }
                }
            }
            if ($matchedStaffCount > 0) {
                $startUtc = $plannedSlot['start'];
                $endUtc = $plannedSlot['end'];
                $slots[] = [
                    'id' => sprintf('staff_none_%s_%s_%s', $plannedSlot['planningCode'], $startUtc->format('Ymd_Hi'), $serviceCode),
                    'planningCode' => $plannedSlot['planningCode'],
                    'start' => $startUtc->format(\DateTimeInterface::ATOM),
                    'end' => $endUtc->format(\DateTimeInterface::ATOM),
                    'capacity' => $capacity,
                    'booked' => $booked,
                    'remaining' => $capacity - $booked,
                    'resourceCode' => $resource?->getCode(),
                    'resourceName' => $resource?->getName(),
                    'resourceRequired' => $product->isBookableResourceRequired(),
                    'availableResourceCodes' => $this->resourceAvailability->availableCodes($product, $startUtc, $endUtc, $blocking),
                    'compatibleJumpTypeIds' => [$serviceCode],
                    'serviceCode' => $serviceCode,
                    'staffMemberId' => null,
                    'staffName' => null,
                    'instructor' => 'Sans préférence',
                ];
            }
        }

        usort($slots, static fn (array $a, array $b): int => [$a['start'], $a['staffMemberId']] <=> [$b['start'], $b['staffMemberId']]);

        return [
            'member' => $slots,
            'staffConfigured' => \count($activeStaff) > 0,
            'durationMin' => $duration,
            'timezone' => $timezone->getName(),
        ];
    }

    /** @param list<Booking> $blocking @param list<\App\Entity\StaffTimeOff> $timeOffs */
    private function isBlocked(StaffMember $staff, \DateTimeImmutable $start, \DateTimeImmutable $end, array $blocking, array $timeOffs): bool
    {
        $rules = $this->bookingRules->get();
        $start = $start->modify(sprintf('-%d minutes', $rules['bufferBeforeMinutes']));
        $end = $end->modify(sprintf('+%d minutes', $rules['bufferAfterMinutes']));
        foreach ($blocking as $booking) {
            if ($booking->getStaffMember()?->getId() === $staff->getId() && $booking->getSlotStart() < $end && $booking->getSlotEnd() > $start) {
                return true;
            }
        }

        foreach ($timeOffs as $timeOff) {
            if ($timeOff->getStaffMember()->getId() === $staff->getId() && $timeOff->getStartsAt() < $end && $timeOff->getEndsAt() > $start) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Booking> $blocking */
    private function bookedOnPlanning(array $blocking, string $planningCode, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $rules = $this->bookingRules->get();
        $start = $start->modify(sprintf('-%d minutes', $rules['bufferBeforeMinutes']));
        $end = $end->modify(sprintf('+%d minutes', $rules['bufferAfterMinutes']));
        return count(array_filter($blocking, static fn (Booking $booking): bool => $booking->getPlanningCode() === $planningCode && $booking->getSlotStart() < $end && $booking->getSlotEnd() > $start));
    }
}

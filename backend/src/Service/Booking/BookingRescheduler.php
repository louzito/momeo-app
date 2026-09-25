<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Service\Availability\PlanningSlotPolicy;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Planning;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Repository\BookingRepository;
use App\Repository\PlanningRepository;
use App\Repository\StaffMemberRepository;
use App\Repository\StaffTimeOffRepository;
use App\Service\Resource\ResourceAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/** Moves preserve separate admin and customer policies and notify after commit. */
final class BookingRescheduler
{
    public function __construct(
        private readonly BookingMutation $mutation,
        private readonly PlanningRepository $planningRepository,
        private readonly CustomerBookingChangePolicy $changePolicy,
        private readonly PlanningSlotPolicy $planningSlots,
        private readonly BookingRepository $bookingRepository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly StaffTimeOffRepository $timeOffRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingSlotGuard $slotGuard,
        private readonly BookingEmailDispatcher $emailDispatcher,
        private readonly ResourceAvailability $resourceAvailability,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function admin(Booking $booking, array $payload, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        try {
            $this->mutation->run($booking, function () use ($booking, $payload, $start, $end): void {
                $staff = null;
                $staffId = (int) ($payload['staffMemberId'] ?? $booking->getStaffMember()?->getId() ?? 0);
                if ($staffId > 0) {
                    $staff = $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE);
                    if (!$staff instanceof StaffMember || !$staff->isActive() || !$staff->isBookable()) {
                        throw new BookingMutationFailed('Ce collaborateur n’est pas disponible.');
                    }
                    if (!\in_array($booking->getServiceCode(), $staff->getServiceCodes(), true)) {
                        throw new BookingMutationFailed('Ce collaborateur ne réalise pas cette prestation.');
                    }
                    if (!\App\Service\Staff\WorkingHours::contains($staff->getWorkingHours(), $start, $end, $this->timeZoneProvider->get())) {
                        throw new BookingMutationFailed('Ce rendez-vous dépasse une plage disponible ou empiète sur une pause.', 'slot_unavailable');
                    }
                    if ($this->bookingRepository->hasOverlap($staff, $start, $end, $booking)) {
                        throw new BookingMutationFailed('Ce créneau vient d’être réservé.', 'slot_unavailable');
                    }
                    if ($this->timeOffRepository->hasOverlap($staff, $start, $end)) {
                        throw new BookingMutationFailed('Ce collaborateur est indisponible sur ce créneau.');
                    }
                }

                $booking->setStaffMember($staff);
                $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
                $planning = $this->planningSlots->forAdmin(trim((string) ($payload['planningCode'] ?? '')), (int) ($payload['staffMemberId'] ?? 0), $booking->getServiceCode(), $start, $end);
                $booking->setPlanningCode($planning?->getCode());
                $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $booking->getServiceCode()]);
                if (!$product instanceof Product) {
                    throw new BookingMutationFailed('Cette prestation n’est plus disponible.');
                }
                try {
                    $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null, 100), $booking);
                } catch (\DomainException $exception) {
                    throw new BookingMutationFailed($exception->getMessage(), 'slot_unavailable');
                }
                $booking->setResourceCode($resource?->getCode());
                $booking->setSlotStart($start);
                $booking->setSlotEnd($end);
                $booking->setStatus(Booking::STATUS_CONFIRMED);
                $booking->setPostponedReason(null);
                $this->slotGuard->assertAvailable($booking, $planning?->getCapacity() ?? 1, $booking);
            });
        } catch (BookingMutationFailed $exception) {
            throw $exception;
        } catch (SlotUnavailable $exception) {
            throw new BookingMutationFailed($exception->getMessage(), 'slot_unavailable');
        } catch (\Throwable) {
            throw new BookingMutationFailed('Ce créneau vient d’être réservé.', 'slot_unavailable');
        }
        $this->emailDispatcher->rescheduled($booking);
    }

    /** @param array<string, mixed> $payload */
    public function customer(Booking $booking, string $email, array $payload, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $this->mutation->assertOwner($booking, $email);
        if ($end <= $start || $start <= new \DateTimeImmutable()) {
            throw new BookingMutationFailed('Ce créneau n’est plus disponible.', 'slot_unavailable');
        }
        try {
            $this->mutation->run($booking, function () use ($booking, $payload, $start, $end): void {
                $this->changePolicy->assertAllowed($booking, 'reschedule');
                $planning = $this->planningRepository->findOneBy(['code' => trim((string) ($payload['planningCode'] ?? '')), 'active' => true]);
                $staff = $this->staffRepository->find((int) ($payload['staffMemberId'] ?? 0));
                if (!$planning instanceof Planning || ($planning->getServiceCodes() !== [] && !\in_array($booking->getServiceCode(), $planning->getServiceCodes(), true))) {
                    throw new SlotUnavailable('Ce créneau ne figure plus au planning.');
                }
                $this->planningSlots->assertPlannedSlot($planning, $booking, $start, $end);
                if (!$staff instanceof StaffMember || !$staff->isActive() || !$staff->isBookable() || !\in_array($booking->getServiceCode(), $staff->getServiceCodes(), true)) {
                    throw new SlotUnavailable('Ce collaborateur n’est plus disponible.');
                }
                $this->planningSlots->assertStaffHours($staff, $planning, $start, $end);
                if ($this->timeOffRepository->hasOverlap($staff, $start, $end)) {
                    throw new SlotUnavailable('Ce collaborateur est indisponible sur ce créneau.');
                }
                $previousStart = $booking->getSlotStart();
                $previousEnd = $booking->getSlotEnd();
                $booking->setPlanningCode($planning->getCode());
                $booking->setStaffMember($staff);
                $booking->setStaffName(trim($staff->getFirstName().' '.$staff->getLastName()));
                $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $booking->getServiceCode()]);
                if (!$product instanceof Product) throw new SlotUnavailable('Cette prestation n’est plus disponible.');
                $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null, 255), $booking);
                $booking->setResourceCode($resource?->getCode());
                $booking->setSlotStart($start);
                $booking->setSlotEnd($end);
                $this->slotGuard->assertAvailable($booking, $planning->getCapacity(), $booking);
                $booking->recordChange([
                    'action' => 'rescheduled', 'actor' => 'customer',
                    'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'previousStart' => $previousStart->format(\DateTimeInterface::ATOM),
                    'previousEnd' => $previousEnd->format(\DateTimeInterface::ATOM),
                    'newStart' => $start->format(\DateTimeInterface::ATOM), 'newEnd' => $end->format(\DateTimeInterface::ATOM),
                ]);
            }, $email);
        } catch (BookingNotOwned $exception) {
            throw $exception;
        } catch (SlotUnavailable $exception) {
            throw new BookingMutationFailed($exception->getMessage(), 'slot_unavailable');
        } catch (\DomainException $exception) {
            throw new BookingMutationFailed($exception->getMessage(), 'change_deadline_passed');
        } catch (\Throwable) {
            throw new BookingMutationFailed('Ce créneau vient d’être réservé.', 'slot_unavailable');
        }
        $this->emailDispatcher->rescheduled($booking);
    }

    private function nullableText(mixed $value, ?int $length = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $length === null ? $value : mb_substr($value, 0, $length);
    }

}

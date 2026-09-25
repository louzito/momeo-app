<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Service\Availability\ServiceDuration;
use App\Service\Availability\PlanningSlotPolicy;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Repository\BookingRepository;
use App\Repository\StaffTimeOffRepository;
use App\Service\Resource\ResourceAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/** Manual creation retains admin slot rules and pay-on-site defaults. */
final class ManualBookingCreator
{
    public function __construct(
        private readonly BookingIdentity $identity,
        private readonly ServiceDuration $duration,
        private readonly PlanningSlotPolicy $planningSlots,
        private readonly BookingRepository $bookingRepository,
        private readonly StaffTimeOffRepository $timeOffRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingSlotGuard $slotGuard,
        private readonly BookingEmailDispatcher $emailDispatcher,
        private readonly ResourceAvailability $resourceAvailability,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload, \DateTimeImmutable $start, string $serviceCode, string $firstName, string $lastName, string $email): Booking
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product || !$product->isEnabled()) {
            throw new InvalidBooking('Cette prestation n’est plus disponible.');
        }
        $end = $start->modify(sprintf('+%d minutes', $this->duration->forProduct($product)));

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $staff = $this->entityManager->find(StaffMember::class, (int) ($payload['staffMemberId'] ?? 0), LockMode::PESSIMISTIC_WRITE);
            if (!$staff instanceof StaffMember || !$staff->isActive()) {
                throw new \DomainException('Choisissez un collaborateur actif.');
            }
            if (!\in_array($serviceCode, $staff->getServiceCodes(), true)) {
                throw new \DomainException('Ce collaborateur ne réalise pas cette prestation.');
            }
            if (!\App\Service\Staff\WorkingHours::contains($staff->getWorkingHours(), $start, $end, $this->timeZoneProvider->get())) {
                throw new SlotUnavailable('Ce rendez-vous dépasse une plage disponible ou empiète sur une pause.');
            }
            if ($this->bookingRepository->hasOverlap($staff, $start, $end)) {
                throw new SlotUnavailable('Ce créneau est déjà occupé.');
            }
            if ($this->timeOffRepository->hasOverlap($staff, $start, $end)) {
                throw new \DomainException('Ce collaborateur est indisponible sur ce créneau.');
            }

            $booking = new Booking();
            $this->identity->initialize($booking);
            $booking->setStatus(Booking::STATUS_CONFIRMED);
            $booking->setSource('manual');
            $booking->setServiceCode($serviceCode);
            $booking->setServiceName(mb_substr(trim((string) $product->getName()) ?: $serviceCode, 0, 255));
            $planning = $this->planningSlots->forAdmin(trim((string) ($payload['planningCode'] ?? '')), (int) ($payload['staffMemberId'] ?? 0), $serviceCode, $start, $end);
            $booking->setPlanningCode($planning?->getCode());
            $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null, 100));
            $booking->setResourceCode($resource?->getCode());
            $booking->setStaffMember($staff);
            $booking->setStaffName(trim($staff->getFirstName().' '.$staff->getLastName()));
            $booking->setCustomerFirstName($firstName);
            $booking->setCustomerLastName($lastName);
            $booking->setCustomerEmail($email);
            $booking->setCustomerPhone($this->nullableText($payload['customer']['phone'] ?? null, 40));
            $booking->setCustomerNotes($this->nullableText($payload['customer']['notes'] ?? null));
            $booking->setSlotStart($start);
            $booking->setSlotEnd($end);
            $booking->setOptions([]);
            $booking->setCurrencyCode('EUR');
            $booking->setPaymentState('pay_on_site');
            $this->entityManager->persist($booking);
            $this->slotGuard->assertAvailable($booking, $planning?->getCapacity() ?? 1);
            $this->entityManager->flush();
            $connection->commit();
        } catch (SlotUnavailable $exception) {
            $connection->rollBack();
            if (isset($booking) && $this->entityManager->isOpen()) $this->entityManager->detach($booking);
            throw new BookingMutationFailed($exception->getMessage(), 'slot_unavailable');
        } catch (\DomainException $exception) {
            $connection->rollBack();
            if (isset($booking) && $this->entityManager->isOpen()) $this->entityManager->detach($booking);
            throw new BookingMutationFailed($exception->getMessage());
        } catch (\Throwable) {
            $connection->rollBack();
            if (isset($booking) && $this->entityManager->isOpen()) $this->entityManager->detach($booking);
            throw new BookingMutationFailed('Le rendez-vous n’a pas pu être enregistré.', 'slot_unavailable');
        }

        $this->emailDispatcher->confirmation($booking);
        return $booking;
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

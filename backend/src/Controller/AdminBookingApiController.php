<?php

declare(strict_types=1);

namespace App\Controller;

use App\Booking\BookingSlotGuard;
use App\Booking\SlotUnavailable;
use App\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Planning;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Repository\BookingRepository;
use App\Repository\PlanningRepository;
use App\Repository\StaffMemberRepository;
use App\Repository\StaffTimeOffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/bookings')]
final class AdminBookingApiController
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly PlanningRepository $planningRepository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly StaffTimeOffRepository $timeOffRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingSlotGuard $slotGuard,
        private readonly BookingEmailDispatcher $emailDispatcher,
    ) {
    }

    #[Route('', name: 'momeo_api_admin_booking_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'member' => array_map($this->normalize(...), $this->bookingRepository->findForAdministration()),
        ]);
    }

    #[Route('', name: 'momeo_api_admin_booking_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $serviceCode = mb_substr(trim((string) ($payload['serviceCode'] ?? '')), 0, 255);
        $firstName = mb_substr(trim((string) ($payload['customer']['firstName'] ?? '')), 0, 100);
        $lastName = mb_substr(trim((string) ($payload['customer']['lastName'] ?? '')), 0, 100);
        $email = mb_substr(trim((string) ($payload['customer']['email'] ?? '')), 0, 180);

        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Le créneau est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($serviceCode === '' || $firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'La prestation et les coordonnées du client sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product || !$product->isEnabled()) {
            return new JsonResponse(['error' => 'Cette prestation n’est plus disponible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $end = $start->modify(sprintf('+%d minutes', $this->serviceDuration($product)));

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
            if ($this->bookingRepository->hasOverlap($staff, $start, $end)) {
                throw new SlotUnavailable('Ce créneau est déjà occupé.');
            }
            if ($this->timeOffRepository->hasOverlap($staff, $start, $end)) {
                throw new \DomainException('Ce collaborateur est indisponible sur ce créneau.');
            }

            $booking = new Booking();
            $booking->setReference($this->newReference());
            $booking->setPublicToken(bin2hex(random_bytes(16)));
            $booking->setStatus(Booking::STATUS_CONFIRMED);
            $booking->setSource('manual');
            $booking->setServiceCode($serviceCode);
            $booking->setServiceName(mb_substr(trim((string) $product->getName()) ?: $serviceCode, 0, 255));
            $planning = $this->planning($payload, $serviceCode, $start, $end);
            $booking->setPlanningCode($planning?->getCode());
            $booking->setResourceCode($this->nullableText($payload['resourceCode'] ?? null, 255));
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
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Le rendez-vous n’a pas pu être enregistré.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }

        $this->emailDispatcher->confirmation($booking);
        return new JsonResponse($this->normalize($booking), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}/reschedule', name: 'momeo_api_admin_booking_reschedule', methods: ['POST'])]
    public function reschedule(Booking $booking, Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($payload['end'] ?? ''));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Le nouveau créneau est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->entityManager->lock($booking, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($booking);
        $staff = null;
        $staffId = (int) ($payload['staffMemberId'] ?? 0);
        if ($staffId > 0) {
            $staff = $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE);
            if (!$staff instanceof StaffMember || !$staff->isActive() || !$staff->isBookable()) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Ce collaborateur n’est pas disponible.'], Response::HTTP_CONFLICT);
            }
            if (!\in_array($booking->getServiceCode(), $staff->getServiceCodes(), true)) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Ce collaborateur ne réalise pas cette prestation.'], Response::HTTP_CONFLICT);
            }
            if ($this->bookingRepository->hasOverlap($staff, $start, $end, $booking)) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Ce créneau vient d’être réservé.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
            }
            if ($this->timeOffRepository->hasOverlap($staff, $start, $end)) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Ce collaborateur est indisponible sur ce créneau.'], Response::HTTP_CONFLICT);
            }
        }

        $booking->setStaffMember($staff);
        $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
        $planning = $this->planning($payload, $booking->getServiceCode(), $start, $end);
        $booking->setPlanningCode($planning?->getCode());
        $booking->setResourceCode($this->nullableText($payload['resourceCode'] ?? null, 255));
        $booking->setSlotStart($start);
        $booking->setSlotEnd($end);
        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $booking->setPostponedReason(null);
        try {
            $this->slotGuard->assertAvailable($booking, $planning?->getCapacity() ?? 1, $booking);
            $this->entityManager->flush();
            $connection->commit();
        } catch (SlotUnavailable $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Ce créneau vient d’être réservé.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }

        $this->emailDispatcher->rescheduled($booking);
        return new JsonResponse($this->normalize($booking));
    }

    #[Route('/{id<\d+>}/postpone', name: 'momeo_api_admin_booking_postpone', methods: ['POST'])]
    public function postpone(Booking $booking, Request $request): JsonResponse
    {
        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            return new JsonResponse(['error' => 'Seul un rendez-vous confirmé peut être reporté.'], Response::HTTP_CONFLICT);
        }
        $payload = $this->payload($request);
        $reason = trim((string) ($payload['reason'] ?? ''));
        $booking->setStatus(Booking::STATUS_POSTPONED);
        $booking->setPostponedReason($reason !== '' ? $reason : 'Report demandé par l’établissement.');
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($booking));
    }

    #[Route('/{id<\d+>}/complete', name: 'momeo_api_admin_booking_complete', methods: ['POST'])]
    public function complete(Booking $booking): JsonResponse
    {
        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            return new JsonResponse(['error' => 'Seul un rendez-vous confirmé peut être marqué comme effectué.'], Response::HTTP_CONFLICT);
        }
        $booking->setStatus(Booking::STATUS_COMPLETED);
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($booking));
    }

    #[Route('/{id<\d+>}/cancel', name: 'momeo_api_admin_booking_cancel', methods: ['POST'])]
    public function cancel(Booking $booking): JsonResponse
    {
        if (!\in_array($booking->getStatus(), [Booking::STATUS_CONFIRMED, Booking::STATUS_POSTPONED], true)) {
            return new JsonResponse(['error' => 'Ce rendez-vous ne peut plus être annulé.'], Response::HTTP_CONFLICT);
        }
        $booking->setStatus(Booking::STATUS_CANCELLED);
        $this->entityManager->flush();
        $this->emailDispatcher->cancellation($booking);

        return new JsonResponse($this->normalize($booking));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        return \is_array($payload) ? $payload : [];
    }

    private function serviceDuration(Product $product): int
    {
        $legacyDuration = null;
        foreach ($product->getAttributes() as $attributeValue) {
            if ($attributeValue->getCode() === 'todatempo_duration') {
                return max(15, min(480, (int) $attributeValue->getValue()));
            }
            if ($attributeValue->getCode() === 'momeo_duration') {
                $legacyDuration = (int) $attributeValue->getValue();
            }
        }
        return $legacyDuration === null ? 60 : max(15, min(480, $legacyDuration));
    }

    private function nullableText(mixed $value, ?int $length = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $length === null ? $value : mb_substr($value, 0, $length);
    }

    private function newReference(): string
    {
        do {
            $reference = 'MOM-'.strtoupper(bin2hex(random_bytes(4)));
        } while ($this->bookingRepository->findOneBy(['reference' => $reference]) instanceof Booking);

        return $reference;
    }

    /** @param array<string, mixed> $payload */
    private function planning(array $payload, string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $end): ?Planning
    {
        $code = trim((string) ($payload['planningCode'] ?? ''));
        if ($code !== '') {
            $planning = $this->planningRepository->findOneBy(['code' => $code, 'active' => true]);
            return $planning instanceof Planning && ($planning->getServiceCodes() === [] || \in_array($serviceCode, $planning->getServiceCodes(), true)) ? $planning : null;
        }

        $staffId = (int) ($payload['staffMemberId'] ?? 0);
        foreach ($this->planningRepository->findActiveForService($serviceCode) as $planning) {
            if ($planning->getStaffMember() !== null && $planning->getStaffMember()?->getId() !== $staffId) {
                continue;
            }
            $timezone = new \DateTimeZone($planning->getTimezone());
            $localStart = $start->setTimezone($timezone);
            $localEnd = $end->setTimezone($timezone);
            if ($localStart->format('Y-m-d') !== $localEnd->format('Y-m-d')) {
                continue;
            }
            foreach ($planning->getDays()[strtolower($localStart->format('l'))] ?? [] as $range) {
                if ($localStart->format('H:i') >= $range['start'] && $localEnd->format('H:i') <= $range['end']) {
                    return $planning;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function normalize(Booking $booking): array
    {
        $customerName = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());
        return [
            'id' => $booking->getId(),
            'publicId' => $booking->getPublicToken(),
            'reference' => $booking->getReference(),
            'status' => $booking->getStatus(),
            'source' => $booking->getSource(),
            'serviceCode' => $booking->getServiceCode(),
            'serviceName' => $booking->getServiceName(),
            'planningCode' => $booking->getPlanningCode(),
            'resourceCode' => $booking->getResourceCode(),
            'jumpTypeId' => $booking->getServiceCode(),
            'jumpTypeName' => $booking->getServiceName(),
            'customerName' => $customerName,
            'jumperName' => $customerName,
            'customerEmail' => $booking->getCustomerEmail(),
            'customerPhone' => $booking->getCustomerPhone(),
            'customerNotes' => $booking->getCustomerNotes(),
            'staffMemberId' => $booking->getStaffMember()?->getId(),
            'staffName' => $booking->getStaffName(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'orderNumber' => $booking->getOrderNumber(),
            'voucherCode' => $booking->getVoucherCode(),
            'options' => $booking->getOptions(),
            'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(),
            'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(),
            'paymentState' => $booking->getPaymentState(),
            'postponedReason' => $booking->getPostponedReason(),
            'createdAt' => $booking->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $booking->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

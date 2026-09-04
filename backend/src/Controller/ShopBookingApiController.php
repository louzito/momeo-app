<?php

declare(strict_types=1);

namespace App\Controller;

use App\Availability\AvailabilitySlotGenerator;
use App\Availability\CenterTimeZoneProvider;
use App\Availability\PlanningProvider;
use App\Entity\Booking;
use App\Entity\GiftVoucher;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Repository\StaffMemberRepository;
use App\Repository\StaffTimeOffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop')]
final class ShopBookingApiController
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly StaffTimeOffRepository $timeOffRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
        private readonly PlanningProvider $planningProvider,
        private readonly AvailabilitySlotGenerator $slotGenerator,
    ) {
    }

    #[Route('/availability', name: 'momeo_api_shop_availability', methods: ['GET'])]
    public function availability(Request $request): JsonResponse
    {
        $serviceCode = trim((string) $request->query->get('serviceCode', ''));
        if ($serviceCode === '') {
            return new JsonResponse(['error' => 'La prestation est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product || !$product->isEnabled()) {
            return new JsonResponse(['error' => 'Cette prestation n’est pas disponible.'], Response::HTTP_NOT_FOUND);
        }

        $timezone = $this->timeZoneProvider->get();
        $today = new \DateTimeImmutable('today', $timezone);
        $from = $this->dateOrDefault((string) $request->query->get('from', ''), $today, $timezone);
        $requestedTo = $this->dateOrDefault((string) $request->query->get('to', ''), $from->modify('+45 days'), $timezone);
        $to = min($requestedTo, $from->modify('+62 days'));
        $duration = $this->serviceDuration($serviceCode);

        $activeStaff = array_values(array_filter(
            $this->staffRepository->findBy(['active' => true], ['position' => 'ASC']),
            static fn (StaffMember $member): bool => $member->isBookable(),
        ));
        $eligibleStaff = array_values(array_filter(
            $activeStaff,
            static fn (StaffMember $member): bool => \in_array($serviceCode, $member->getServiceCodes(), true),
        ));

        $rangeStart = $from->setTimezone(new \DateTimeZone('UTC'));
        $rangeEnd = $to->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
        $blocking = $this->bookingRepository->findBlockingBetween($rangeStart, $rangeEnd);
        $timeOffs = $this->timeOffRepository->findBetween($rangeStart, $rangeEnd);
        $slots = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $plannedSlots = $this->slotGenerator->generate($this->planningProvider->active(), $serviceCode, $duration, $from, $to, $now, $timezone);

        foreach ($plannedSlots as $plannedSlot) {
            $dayKey = strtolower($plannedSlot['localStart']->format('l'));
            foreach ($eligibleStaff as $staff) {
                $hours = $staff->getWorkingHours()[$dayKey] ?? null;
                if (!\is_array($hours) || !($hours['enabled'] ?? false)) {
                    continue;
                }

                $date = $plannedSlot['localStart']->format('Y-m-d');
                $workStart = new \DateTimeImmutable($date.' '.($hours['start'] ?? '09:00'), $timezone);
                $workEnd = new \DateTimeImmutable($date.' '.($hours['end'] ?? '18:00'), $timezone);
                if ($plannedSlot['localStart'] >= $workStart && $plannedSlot['end'] <= $workEnd->setTimezone(new \DateTimeZone('UTC'))) {
                    $startUtc = $plannedSlot['start'];
                    $endUtc = $plannedSlot['end'];
                    if (!$this->isBlocked($staff, $startUtc, $endUtc, $blocking, $timeOffs)) {
                        $slots[] = [
                            'id' => sprintf('staff_%d_%s_%s', $staff->getId(), $startUtc->format('Ymd_Hi'), $serviceCode),
                            'planningCode' => $plannedSlot['planningCode'],
                            'start' => $startUtc->format(\DateTimeInterface::ATOM),
                            'end' => $endUtc->format(\DateTimeInterface::ATOM),
                            'capacity' => 1,
                            'booked' => 0,
                            'remaining' => 1,
                            'compatibleJumpTypeIds' => [$serviceCode],
                            'serviceCode' => $serviceCode,
                            'staffMemberId' => $staff->getId(),
                            'staffName' => trim($staff->getFirstName().' '.$staff->getLastName()),
                            'instructor' => trim($staff->getFirstName().' '.$staff->getLastName()),
                        ];
                    }
                }
            }
        }

        usort($slots, static fn (array $a, array $b): int => [$a['start'], $a['staffMemberId']] <=> [$b['start'], $b['staffMemberId']]);

        return new JsonResponse([
            'member' => $slots,
            'staffConfigured' => \count($activeStaff) > 0,
            'durationMin' => $duration,
            'timezone' => $timezone->getName(),
        ]);
    }

    #[Route('/bookings', name: 'momeo_api_shop_booking_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($payload['end'] ?? ''));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Le créneau est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($end <= $start || $start <= new \DateTimeImmutable()) {
            return new JsonResponse(['error' => 'Ce créneau n’est plus disponible.'], Response::HTTP_CONFLICT);
        }

        $serviceCode = mb_substr(trim((string) ($payload['serviceCode'] ?? '')), 0, 255);
        $firstName = mb_substr(trim((string) ($payload['customer']['firstName'] ?? '')), 0, 100);
        $lastName = mb_substr(trim((string) ($payload['customer']['lastName'] ?? '')), 0, 100);
        $email = mb_substr(trim((string) ($payload['customer']['email'] ?? '')), 0, 180);
        if ($serviceCode === '' || $firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'La prestation et les coordonnées du client sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product || !$product->isEnabled()) {
            return new JsonResponse(['error' => 'Cette prestation n’est plus disponible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $serviceName = trim((string) $product->getName()) ?: trim((string) ($payload['serviceName'] ?? $serviceCode));

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $staff = null;
        $staffId = (int) ($payload['staffMemberId'] ?? 0);
        $staff = $staffId > 0 ? $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE) : null;
        $error = $this->validateStaffSlot($staff, $serviceCode, $start, $end);
        if ($error !== null) {
            $connection->rollBack();
            return new JsonResponse(['error' => $error], Response::HTTP_CONFLICT);
        }

        $booking = new Booking();
        $booking->setReference($this->newReference());
        $booking->setPublicToken(bin2hex(random_bytes(16)));
        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $booking->setSource(\in_array($payload['source'] ?? '', ['direct', 'voucher'], true) ? $payload['source'] : 'direct');
        $booking->setServiceCode($serviceCode);
        $booking->setServiceName(mb_substr($serviceName, 0, 255));
        $booking->setStaffMember($staff);
        $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
        $booking->setCustomerFirstName($firstName);
        $booking->setCustomerLastName($lastName);
        $booking->setCustomerEmail($email);
        $booking->setCustomerPhone($this->nullableText($payload['customer']['phone'] ?? null, 40));
        $booking->setCustomerNotes($this->nullableText($payload['customer']['notes'] ?? null));
        $booking->setSlotStart($start);
        $booking->setSlotEnd($end);
        $booking->setOrderNumber($this->nullableText($payload['orderNumber'] ?? null, 255));
        $booking->setVoucherCode($this->nullableText($payload['voucherCode'] ?? null, 32));
        $booking->setOptions(\is_array($payload['options'] ?? null) ? array_values($payload['options']) : []);
        $booking->setAmount(isset($payload['amount']) ? max(0, (int) $payload['amount']) : null);
        $booking->setCurrencyCode(mb_substr(strtoupper((string) ($payload['currencyCode'] ?? 'EUR')), 0, 3));
        $booking->setPaymentState($this->nullableText($payload['paymentState'] ?? null, 30));

        $this->entityManager->persist($booking);
        try {
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Ce créneau vient d’être réservé. Choisissez-en un autre.'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->normalize($booking), Response::HTTP_CREATED);
    }

    #[Route('/bookings/from-voucher/{code}', name: 'momeo_api_shop_booking_from_voucher', methods: ['POST'], requirements: ['code' => '\\d{10}'])]
    public function createFromVoucher(string $code, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($payload['end'] ?? ''));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Le créneau est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $serviceCode = trim((string) ($payload['serviceCode'] ?? ''));
        $firstName = mb_substr(trim((string) ($payload['customer']['firstName'] ?? '')), 0, 100);
        $lastName = mb_substr(trim((string) ($payload['customer']['lastName'] ?? '')), 0, 100);
        $email = mb_substr(trim((string) ($payload['customer']['email'] ?? '')), 0, 180);
        if ($serviceCode === '' || $firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Les coordonnées du bénéficiaire sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $voucher = $this->giftVoucherRepository->findOneByCodeForUpdate($code);
            if (!$voucher instanceof GiftVoucher || !$voucher->isUsable()) {
                throw new \DomainException('Ce chèque cadeau n’est pas utilisable.');
            }
            if ($serviceCode !== $voucher->getServiceCode()) {
                throw new \DomainException('Ce chèque ne correspond pas à cette prestation.');
            }
            if (strcasecmp($email, $voucher->getBeneficiaryEmail()) !== 0) {
                throw new \DomainException('Cet email ne correspond pas au bénéficiaire du chèque.');
            }

            $staff = null;
            $staffId = (int) ($payload['staffMemberId'] ?? 0);
            $staff = $staffId > 0 ? $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE) : null;
            $error = $this->validateStaffSlot($staff, $serviceCode, $start, $end);
            if ($error !== null) {
                throw new \DomainException($error);
            }

            $booking = new Booking();
            $booking->setReference($this->newReference());
            $booking->setPublicToken(bin2hex(random_bytes(16)));
            $booking->setStatus(Booking::STATUS_CONFIRMED);
            $booking->setSource('voucher');
            $booking->setServiceCode($voucher->getServiceCode());
            $booking->setServiceName($voucher->getServiceName());
            $booking->setStaffMember($staff);
            $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
            $booking->setCustomerFirstName($firstName);
            $booking->setCustomerLastName($lastName);
            $booking->setCustomerEmail($email);
            $booking->setCustomerPhone($this->nullableText($payload['customer']['phone'] ?? null, 40));
            $booking->setCustomerNotes($this->nullableText($payload['customer']['notes'] ?? null));
            $booking->setSlotStart($start);
            $booking->setSlotEnd($end);
            $booking->setVoucherCode($voucher->getCode());
            $booking->setOptions([]);
            $booking->setAmount($voucher->getAmount());
            $booking->setCurrencyCode($voucher->getCurrencyCode());
            $booking->setPaymentState('paid');

            $voucher->setStatus(GiftVoucher::STATUS_USED);
            $voucher->setUsedAt(new \DateTimeImmutable());
            $voucher->setUsageOrderNumber($booking->getReference());
            $this->entityManager->persist($booking);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Ce créneau vient d’être réservé. Le chèque reste disponible.'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'booking' => $this->normalize($booking),
            'voucher' => [
                'code' => $voucher->getCode(),
                'status' => $voucher->getEffectiveStatus(),
                'serviceCode' => $voucher->getServiceCode(),
                'serviceName' => $voucher->getServiceName(),
                'jumpTypeCode' => $voucher->getServiceCode(),
                'jumpTypeName' => $voucher->getServiceName(),
                'amount' => $voucher->getAmount(),
                'currencyCode' => $voucher->getCurrencyCode(),
                'beneficiaryName' => $voucher->getBeneficiaryName(),
                'beneficiaryEmail' => $voucher->getBeneficiaryEmail(),
                'personalMessage' => $voucher->getPersonalMessage(),
                'purchaserName' => $voucher->getPurchaserName(),
                'expiresAt' => $voucher->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/bookings/{publicToken<[0-9a-f]{32}>}', name: 'momeo_api_shop_booking_show', methods: ['GET'])]
    public function show(string $publicToken): JsonResponse
    {
        $booking = $this->bookingRepository->findOneBy(['publicToken' => $publicToken]);
        if (!$booking instanceof Booking) {
            return new JsonResponse(['error' => 'Réservation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalize($booking));
    }

    private function serviceDuration(string $serviceCode): int
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product) {
            return 60;
        }
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

    /**
     * @param list<Booking> $blocking
     * @param list<\App\Entity\StaffTimeOff> $timeOffs
     */
    private function isBlocked(StaffMember $staff, \DateTimeImmutable $start, \DateTimeImmutable $end, array $blocking, array $timeOffs): bool
    {
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

    private function validateStaffSlot(?StaffMember $staff, string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $end): ?string
    {
        if (!$staff instanceof StaffMember || !$staff->isActive() || !$staff->isBookable()) {
            return 'Ce collaborateur n’est pas disponible.';
        }
        if (!\in_array($serviceCode, $staff->getServiceCodes(), true)) {
            return 'Ce collaborateur ne réalise pas cette prestation.';
        }
        $timezone = $this->timeZoneProvider->get();
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);
        $hours = $staff->getWorkingHours()[strtolower($localStart->format('l'))] ?? null;
        if (!\is_array($hours) || !($hours['enabled'] ?? false) || $localStart->format('Y-m-d') !== $localEnd->format('Y-m-d')) {
            return 'Ce créneau est en dehors des horaires du collaborateur.';
        }
        $opening = new \DateTimeImmutable($localStart->format('Y-m-d').' '.($hours['start'] ?? '09:00'), $timezone);
        $closing = new \DateTimeImmutable($localStart->format('Y-m-d').' '.($hours['end'] ?? '18:00'), $timezone);
        if ($localStart < $opening || $localEnd > $closing || ($end->getTimestamp() - $start->getTimestamp()) !== $this->serviceDuration($serviceCode) * 60) {
            return 'Ce créneau ne correspond plus aux disponibilités de cette prestation.';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $localStart->format('Y-m-d'), $timezone);
        if (!$date || !$this->isPlanned($serviceCode, $start, $date, $timezone)) {
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

    private function isPlanned(string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $date, \DateTimeZone $timezone): bool
    {
        $slots = $this->slotGenerator->generate(
            $this->planningProvider->active(),
            $serviceCode,
            $this->serviceDuration($serviceCode),
            $date,
            $date,
            new \DateTimeImmutable('@0'),
            $timezone,
        );
        foreach ($slots as $slot) {
            if ($slot['start']->getTimestamp() === $start->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    private function dateOrDefault(string $value, \DateTimeImmutable $default, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $default;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date && $date->format('Y-m-d') === $value ? $date : $default;
    }

    private function nullableText(mixed $value, ?int $length = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        return $length === null ? $value : mb_substr($value, 0, $length);
    }

    private function newReference(): string
    {
        do {
            $reference = 'MOM-'.strtoupper(bin2hex(random_bytes(4)));
        } while ($this->bookingRepository->findOneBy(['reference' => $reference]) instanceof Booking);

        return $reference;
    }

    /** @return array<string, mixed> */
    private function normalize(Booking $booking): array
    {
        $customerName = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());
        return [
            'id' => $booking->getPublicToken(),
            'reference' => $booking->getReference(),
            'status' => $booking->getStatus(),
            'source' => $booking->getSource(),
            'serviceCode' => $booking->getServiceCode(),
            'serviceName' => $booking->getServiceName(),
            'jumpTypeId' => $booking->getServiceCode(),
            'jumpTypeName' => $booking->getServiceName(),
            'customerName' => $customerName,
            'jumperName' => $customerName,
            'staffMemberId' => $booking->getStaffMember()?->getId(),
            'staffName' => $booking->getStaffName(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'options' => $booking->getOptions(),
            'paymentState' => $booking->getPaymentState(),
        ];
    }
}

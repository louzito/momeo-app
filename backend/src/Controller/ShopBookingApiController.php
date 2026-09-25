<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Availability\PublicStaffSlot;
use App\Service\Availability\AvailabilityService;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Booking\BookingSlotGuard;
use App\Service\Booking\BookingRules;
use App\Service\Booking\SlotUnavailable;
use App\Service\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\GiftVoucher;
use App\Entity\Order\Order;
use App\Entity\Planning;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Entity\User\ShopUser;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Repository\PlanningRepository;
use App\Service\Payment\ServicePaymentTerms;
use App\Service\Resource\ResourceAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v2/shop')]
final class ShopBookingApiController
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly PublicStaffSlot $publicStaffSlot,
        private readonly BookingRepository $bookingRepository,
        private readonly PlanningRepository $planningRepository,
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
        private readonly BookingSlotGuard $slotGuard,
        private readonly BookingRules $bookingRules,
        private readonly ServicePaymentTerms $paymentTerms,
        private readonly BookingEmailDispatcher $emailDispatcher,
        private readonly ResourceAvailability $resourceAvailability,
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
        return new JsonResponse($this->availabilityService->find($product, $serviceCode, $from, $to, $timezone));
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
            return new JsonResponse(['error' => 'Ce créneau n’est plus disponible.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }
        try {
            $this->bookingRules->assertBookableAt($start);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => 'booking_rule_violation'], Response::HTTP_CONFLICT);
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

        $orderToken = trim((string) ($payload['orderToken'] ?? ''));
        $order = $orderToken !== '' ? $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $orderToken]) : null;
        if (!$order instanceof Order || $order->getCheckoutState() !== 'completed') {
            return new JsonResponse(['error' => 'La commande associée est introuvable ou incomplète.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $staffId = (int) ($payload['staffMemberId'] ?? 0);
        $planningCodeInput = (string) ($payload['planningCode'] ?? '');
        if ($staffId > 0) {
            $staff = $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE);
            $error = $this->publicStaffSlot->validateStaffSlot($staff, $serviceCode, $start, $end, $planningCodeInput);
            if ($error !== null) {
                $connection->rollBack();
                return new JsonResponse(['error' => $error, 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
            }
        } else {
            $staff = $this->publicStaffSlot->chooseAutoStaff($serviceCode, $start, $end, $planningCodeInput);
            if ($staff === null) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Aucun collaborateur compatible n’est disponible sur ce créneau.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
            }
        }

        $booking = new Booking();
        $booking->setReference($this->newReference());
        $booking->setPublicToken(bin2hex(random_bytes(16)));
        $cardPayment = $order->getLastPayment()?->getMethod()?->getCode() === 'stripe_web_elements';
        $booking->setStatus($cardPayment ? Booking::STATUS_AWAITING_PAYMENT : Booking::STATUS_CONFIRMED);
        $booking->setSource(\in_array($payload['source'] ?? '', ['direct', 'voucher'], true) ? $payload['source'] : 'direct');
        $booking->setServiceCode($serviceCode);
        $booking->setServiceName(mb_substr($serviceName, 0, 255));
        $planning = $this->planningForBooking($payload, $serviceCode);
        if (!$planning instanceof Planning) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Ce créneau ne correspond plus à un planning disponible.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }
        $booking->setPlanningCode($planning->getCode());
        try {
            $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null, 100));
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }
        $booking->setResourceCode($resource?->getCode());
        $booking->setStaffMember($staff);
        $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
        $booking->setCustomerFirstName($firstName);
        $booking->setCustomerLastName($lastName);
        $booking->setCustomerEmail($email);
        $booking->setCustomerPhone($this->nullableText($payload['customer']['phone'] ?? null, 40));
        $booking->setSmsReminderConsent(($payload['customer']['smsReminderConsent'] ?? false) === true);
        $booking->setCustomerNotes($this->nullableText($payload['customer']['notes'] ?? null));
        $booking->setSlotStart($start);
        $booking->setSlotEnd($end);
        $booking->setOrderNumber($order->getNumber());
        $booking->setVoucherCode($this->nullableText($payload['voucherCode'] ?? null, 32));
        $booking->setOptions(\is_array($payload['options'] ?? null) ? array_values($payload['options']) : []);
        $totalAmount = $order->getTotal();
        foreach ($order->getAdjustments('todatempo_payment_terms') as $paymentTermsAdjustment) {
            $totalAmount -= $paymentTermsAdjustment->getAmount();
        }
        try {
            $terms = $this->paymentTerms->calculate($product, $totalAmount);
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($order->getTotal() !== $terms['dueNow']) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Le montant de la commande ne correspond pas à la règle de paiement de la prestation.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $booking->setAmount($terms['dueNow']);
        $booking->setTotalAmount($terms['totalAmount']);
        $booking->setBalanceDue($terms['balanceDue']);
        $booking->setCurrencyCode($order->getCurrencyCode() ?? 'EUR');
        $booking->setPaymentState($order->getPaymentState());

        $this->entityManager->persist($booking);
        try {
            $this->slotGuard->assertAvailable($booking, $planning->getCapacity());
            $this->entityManager->flush();
            $connection->commit();
        } catch (SlotUnavailable $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Ce créneau vient d’être réservé. Choisissez-en un autre.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }

        $this->emailDispatcher->confirmation($booking);
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

            $staffId = (int) ($payload['staffMemberId'] ?? 0);
            $planningCodeInput = (string) ($payload['planningCode'] ?? '');
            if ($staffId > 0) {
                $staff = $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE);
                $error = $this->publicStaffSlot->validateStaffSlot($staff, $serviceCode, $start, $end, $planningCodeInput);
                if ($error !== null) {
                    throw new SlotUnavailable($error);
                }
            } else {
                $staff = $this->publicStaffSlot->chooseAutoStaff($serviceCode, $start, $end, $planningCodeInput);
                if ($staff === null) {
                    throw new SlotUnavailable('Aucun collaborateur compatible n’est disponible sur ce créneau.');
                }
            }

            $booking = new Booking();
            $booking->setReference($this->newReference());
            $booking->setPublicToken(bin2hex(random_bytes(16)));
            $booking->setStatus(Booking::STATUS_CONFIRMED);
            $booking->setSource('voucher');
            $booking->setServiceCode($voucher->getServiceCode());
            $booking->setServiceName($voucher->getServiceName());
            $planning = $this->planningForBooking($payload, $serviceCode);
            if (!$planning instanceof Planning) {
                throw new SlotUnavailable('Ce créneau ne correspond plus à un planning disponible.');
            }
            $booking->setPlanningCode($planning->getCode());
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
            if (!$product instanceof Product) throw new \DomainException('Cette prestation n’est plus disponible.');
            $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null, 100));
            $booking->setResourceCode($resource?->getCode());
            $booking->setStaffMember($staff);
            $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
            $booking->setCustomerFirstName($firstName);
            $booking->setCustomerLastName($lastName);
            $booking->setCustomerEmail($email);
            $booking->setCustomerPhone($this->nullableText($payload['customer']['phone'] ?? null, 40));
            $booking->setSmsReminderConsent(($payload['customer']['smsReminderConsent'] ?? false) === true);
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
            $this->slotGuard->assertAvailable($booking, $planning->getCapacity());
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
            return new JsonResponse(['error' => 'Ce créneau vient d’être réservé. Le chèque reste disponible.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }

        $this->emailDispatcher->confirmation($booking);
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
    #[IsGranted('ROLE_USER')]
    public function show(string $publicToken, #[CurrentUser] ShopUser $user): JsonResponse
    {
        $booking = $this->bookingRepository->findOneBy(['publicToken' => $publicToken]);
        if (!$booking instanceof Booking || 0 !== strcasecmp($booking->getCustomerEmail(), (string) $user->getEmail())) {
            return new JsonResponse(['error' => 'Réservation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalize($booking));
    }

    /** @param array<string, mixed> $payload */
    private function planningForBooking(array $payload, string $serviceCode): ?Planning
    {
        $code = trim((string) ($payload['planningCode'] ?? ''));
        $planning = $code === '' ? null : $this->planningRepository->findOneBy(['code' => $code]);
        if (!$planning instanceof Planning || !$planning->isActive()) {
            return null;
        }

        return $planning->getServiceCodes() === [] || \in_array($serviceCode, $planning->getServiceCodes(), true) ? $planning : null;
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
            'planningCode' => $booking->getPlanningCode(),
            'resourceCode' => $booking->getResourceCode(),
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
            'orderNumber' => $booking->getOrderNumber(),
            'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(),
            'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(),
        ];
    }
}

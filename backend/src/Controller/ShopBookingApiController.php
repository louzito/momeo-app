<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Booking\BookingView;
use App\Service\Availability\AvailabilityService;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Booking\BookingRules;
use App\Service\Booking\BookingCreationService;
use App\Service\Booking\InvalidBooking;
use App\Service\Email\BookingEmailDispatcher;
use App\Service\Booking\SlotUnavailable;
use App\Entity\Booking;
use App\Entity\Product\Product;
use App\Entity\User\ShopUser;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly BookingEmailDispatcher $emailDispatcher,
        private readonly BookingCreationService $bookingCreation,
        private readonly AvailabilityService $availabilityService,
        private readonly BookingRepository $bookingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
        private readonly BookingRules $bookingRules,
        private readonly BookingView $view,
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
        try {
            $booking = $this->bookingCreation->createFromOrder($payload, $start, $end, $serviceCode, $firstName, $lastName, $email);
        } catch (InvalidBooking $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SlotUnavailable $exception) {
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }

        $this->emailDispatcher->confirmation($booking);
        return new JsonResponse($this->view->publicBooking($booking), Response::HTTP_CREATED);
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

        try {
            ['booking' => $booking, 'voucher' => $voucher] = $this->bookingCreation->createFromVoucher($code, $payload, $start, $end, $serviceCode, $firstName, $lastName, $email);
        } catch (SlotUnavailable $exception) {
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        $this->emailDispatcher->confirmation($booking);
        return new JsonResponse([
            'booking' => $this->view->publicBooking($booking),
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
        if (!$booking instanceof Booking || !\App\Service\Customer\CustomerAccountAccess::ownsBooking($booking, (string) $user->getEmail())) {
            return new JsonResponse(['error' => 'Réservation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->view->publicBooking($booking));
    }

    private function dateOrDefault(string $value, \DateTimeImmutable $default, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $default;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date && $date->format('Y-m-d') === $value ? $date : $default;
    }

}

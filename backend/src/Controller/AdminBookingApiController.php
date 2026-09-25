<?php

declare(strict_types=1);

namespace App\Controller;
use App\Service\Booking\ManualBookingCreator;
use App\Service\Booking\BookingRescheduler;
use App\Service\Booking\BookingLifecycle;
use App\Service\Booking\BookingMutationFailed;
use App\Service\Booking\InvalidBooking;
use App\Entity\Booking;
use App\Repository\BookingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/bookings')]
final class AdminBookingApiController
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly ManualBookingCreator $creator,
        private readonly BookingRescheduler $rescheduler,
        private readonly BookingLifecycle $lifecycle,
    ) {}

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

        try {
            $booking = $this->creator->create($payload, $start, $serviceCode, $firstName, $lastName, $email);
        } catch (InvalidBooking $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (BookingMutationFailed $exception) {
            return $this->failure($exception);
        }

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

        try {
            $this->rescheduler->admin($booking, $payload, $start, $end);
        } catch (BookingMutationFailed $exception) {
            return $this->failure($exception);
        }
        return new JsonResponse($this->normalize($booking));
    }

    #[Route('/{id<\d+>}/postpone', name: 'momeo_api_admin_booking_postpone', methods: ['POST'])]
    public function postpone(Booking $booking, Request $request): JsonResponse
    {
        try {
            $this->lifecycle->postpone($booking, trim((string) ($this->payload($request)['reason'] ?? '')));
        } catch (BookingMutationFailed $exception) {
            return $this->failure($exception);
        }

        return new JsonResponse($this->normalize($booking));
    }

    #[Route('/{id<\d+>}/complete', name: 'momeo_api_admin_booking_complete', methods: ['POST'])]
    public function complete(Booking $booking): JsonResponse
    {
        try {
            $this->lifecycle->complete($booking);
        } catch (BookingMutationFailed $exception) {
            return $this->failure($exception);
        }

        return new JsonResponse($this->normalize($booking));
    }

    #[Route('/{id<\d+>}/no-show', name: 'momeo_api_admin_booking_no_show', methods: ['POST'])]
    public function noShow(Booking $booking): JsonResponse
    {
        try {
            $this->lifecycle->noShow($booking);
        } catch (BookingMutationFailed $exception) {
            return $this->failure($exception);
        }

        return new JsonResponse($this->normalize($booking));
    }

    #[Route('/{id<\d+>}/cancel', name: 'momeo_api_admin_booking_cancel', methods: ['POST'])]
    public function cancel(Booking $booking): JsonResponse
    {
        try {
            $this->lifecycle->cancelByAdmin($booking);
        } catch (BookingMutationFailed $exception) {
            return $this->failure($exception);
        }

        return new JsonResponse($this->normalize($booking));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        return \is_array($payload) ? $payload : [];
    }

    private function failure(BookingMutationFailed $exception): JsonResponse
    {
        $payload = ['error' => $exception->getMessage()];
        if ($exception->errorCode !== null) $payload['code'] = $exception->errorCode;
        return new JsonResponse($payload, Response::HTTP_CONFLICT);
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

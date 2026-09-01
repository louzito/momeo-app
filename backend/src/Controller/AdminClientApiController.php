<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/clients')]
final class AdminClientApiController
{
    public function __construct(private readonly BookingRepository $bookingRepository)
    {
    }

    #[Route('', name: 'momeo_api_admin_client_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $clients = [];

        foreach ($this->bookingRepository->findForAdministration() as $booking) {
            $email = mb_strtolower(trim($booking->getCustomerEmail()));
            if ($email === '') {
                continue;
            }

            if (!isset($clients[$email])) {
                $clients[$email] = [
                    'id' => substr(hash('sha256', $email), 0, 16),
                    'firstName' => $booking->getCustomerFirstName(),
                    'lastName' => $booking->getCustomerLastName(),
                    'displayName' => trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName()),
                    'email' => $email,
                    'phone' => $booking->getCustomerPhone(),
                    'notes' => $booking->getCustomerNotes(),
                    'bookingCount' => 0,
                    'completedCount' => 0,
                    'cancelledCount' => 0,
                    'totalAmount' => 0,
                    'currencyCode' => $booking->getCurrencyCode(),
                    'firstBookingAt' => $booking->getCreatedAt(),
                    'lastBookingAt' => null,
                    'nextBookingAt' => null,
                    'lastServiceName' => null,
                    'nextServiceName' => null,
                    'bookings' => [],
                ];
            }

            $client = &$clients[$email];
            $client['bookingCount']++;
            $client['firstBookingAt'] = min($client['firstBookingAt'], $booking->getCreatedAt());
            if ($booking->getCustomerPhone()) {
                $client['phone'] = $booking->getCustomerPhone();
            }
            if ($booking->getCustomerNotes()) {
                $client['notes'] = $booking->getCustomerNotes();
            }
            if ($booking->getCustomerFirstName() !== '' && $booking->getCustomerLastName() !== '') {
                $client['firstName'] = $booking->getCustomerFirstName();
                $client['lastName'] = $booking->getCustomerLastName();
                $client['displayName'] = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());
            }

            if ($booking->getStatus() === Booking::STATUS_COMPLETED) {
                $client['completedCount']++;
            }
            if ($booking->getStatus() === Booking::STATUS_CANCELLED) {
                $client['cancelledCount']++;
            } else {
                $client['totalAmount'] += $booking->getAmount() ?? 0;
            }

            if ($booking->getStatus() !== Booking::STATUS_CANCELLED && $booking->getSlotStart() <= $now) {
                if (!$client['lastBookingAt'] instanceof \DateTimeImmutable || $booking->getSlotStart() > $client['lastBookingAt']) {
                    $client['lastBookingAt'] = $booking->getSlotStart();
                    $client['lastServiceName'] = $booking->getServiceName();
                }
            }
            if ($booking->getStatus() === Booking::STATUS_CONFIRMED && $booking->getSlotStart() > $now) {
                if (!$client['nextBookingAt'] instanceof \DateTimeImmutable || $booking->getSlotStart() < $client['nextBookingAt']) {
                    $client['nextBookingAt'] = $booking->getSlotStart();
                    $client['nextServiceName'] = $booking->getServiceName();
                }
            }

            $client['bookings'][] = $this->normalizeBooking($booking);
            unset($client);
        }

        $newThisMonth = 0;
        $withUpcoming = 0;
        $recurring = 0;
        foreach ($clients as &$client) {
            usort($client['bookings'], static fn (array $left, array $right): int => strcmp($right['slotStart'], $left['slotStart']));
            if ($client['firstBookingAt'] >= $monthStart) {
                $newThisMonth++;
            }
            if ($client['nextBookingAt'] instanceof \DateTimeImmutable) {
                $withUpcoming++;
            }
            if ($client['bookingCount'] > 1) {
                $recurring++;
            }
            $client['firstBookingAt'] = $client['firstBookingAt']->format(\DateTimeInterface::ATOM);
            $client['lastBookingAt'] = $client['lastBookingAt']?->format(\DateTimeInterface::ATOM);
            $client['nextBookingAt'] = $client['nextBookingAt']?->format(\DateTimeInterface::ATOM);
        }
        unset($client);

        $clients = array_values($clients);
        usort($clients, static function (array $left, array $right): int {
            if ($left['nextBookingAt'] !== null && $right['nextBookingAt'] === null) {
                return -1;
            }
            if ($left['nextBookingAt'] === null && $right['nextBookingAt'] !== null) {
                return 1;
            }

            return strcasecmp($left['lastName'].' '.$left['firstName'], $right['lastName'].' '.$right['firstName']);
        });

        return new JsonResponse([
            'member' => $clients,
            'stats' => [
                'total' => \count($clients),
                'newThisMonth' => $newThisMonth,
                'withUpcoming' => $withUpcoming,
                'recurring' => $recurring,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function normalizeBooking(Booking $booking): array
    {
        return [
            'id' => $booking->getId(),
            'reference' => $booking->getReference(),
            'status' => $booking->getStatus(),
            'source' => $booking->getSource(),
            'serviceCode' => $booking->getServiceCode(),
            'serviceName' => $booking->getServiceName(),
            'staffName' => $booking->getStaffName(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'amount' => $booking->getAmount(),
            'currencyCode' => $booking->getCurrencyCode(),
            'paymentState' => $booking->getPaymentState(),
        ];
    }
}

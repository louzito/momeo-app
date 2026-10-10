<?php

declare(strict_types=1);

namespace App\Service\Customer;

use App\Service\Booking\BookingView;
use App\Entity\Booking;
use App\Entity\ClientProfile;
use App\Entity\Customer\Customer;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ClientDirectoryService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientProfileService $profiles,
        private readonly BookingView $view,
    ) {}

    public function list(string $query = ''): array
    {
        $now = new \DateTimeImmutable();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $clients = [];

        $profiles = [];
        foreach ($this->entityManager->getRepository(ClientProfile::class)->findAll() as $profile) {
            $profiles[$profile->getBookingEmail()] = $profile;
        }

        foreach ($this->bookingRepository->findForAdministration() as $booking) {
            $email = mb_strtolower(trim($booking->getCustomerEmail()));
            if ($email === '') {
                continue;
            }

            if (!isset($clients[$email])) {
                $clients[$email] = $this->newClient($email, $booking->getCustomerFirstName(), $booking->getCustomerLastName(), $booking->getCustomerPhone(), $booking->getCustomerNotes(), $booking->getCurrencyCode(), $booking->getCreatedAt());
            }

            $client = &$clients[$email];
            $client['bookingCount']++;
            $client['firstBookingAt'] = min($client['firstBookingAt'], $booking->getCreatedAt());
            $client['clientSince'] = $client['firstBookingAt'];
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

            $client['bookings'][] = $this->view->adminHistory($booking);
            if ($booking->getOrderNumber() !== null) {
                $client['purchases'][$booking->getOrderNumber()] = [
                    'orderNumber' => $booking->getOrderNumber(),
                    'purchasedAt' => $booking->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'label' => $booking->getServiceName(),
                    'amount' => $booking->getTotalAmount() ?? $booking->getAmount(),
                    'currencyCode' => $booking->getCurrencyCode(),
                    'paymentState' => $booking->getPaymentState(),
                ];
            }
            unset($client);
        }

        foreach ($this->entityManager->getRepository(Customer::class)->findAll() as $customer) {
            $email = mb_strtolower(trim((string) $customer->getEmail()));
            if ($email === '') continue;
            $createdAt = $customer->getCreatedAt();
            if (!isset($clients[$email])) {
                $clients[$email] = $this->newClient($email, (string) $customer->getFirstName(), (string) $customer->getLastName(), $customer->getPhoneNumber(), null, 'EUR', $createdAt);
                $clients[$email]['firstBookingAt'] = null;
            }
            $clients[$email]['clientSince'] = min($clients[$email]['clientSince'], $createdAt);
            if (trim((string) $customer->getFirstName()) !== '' && trim((string) $customer->getLastName()) !== '') {
                $clients[$email]['firstName'] = $customer->getFirstName();
                $clients[$email]['lastName'] = $customer->getLastName();
                $clients[$email]['displayName'] = trim($customer->getFirstName().' '.$customer->getLastName());
            }
            $clients[$email]['phone'] = $customer->getPhoneNumber() ?? $clients[$email]['phone'];
        }

        $newThisMonth = 0;
        $withUpcoming = 0;
        $recurring = 0;
        foreach ($clients as &$client) {
            $profile = $profiles[$client['email']] ?? null;
            if ($profile instanceof ClientProfile) {
                $client = array_replace($client, $this->profiles->normalize($profile));
                $client['displayName'] = trim($client['firstName'].' '.$client['lastName']);
            } else {
                $client += ['visibleNotes' => $client['notes'], 'internalNotes' => null, 'tags' => [], 'allergies' => null, 'contraindications' => null, 'consents' => [], 'consentHistory' => []];
            }
            unset($client['notes']);
            $client['purchases'] = array_values($client['purchases']);
            usort($client['bookings'], static fn (array $left, array $right): int => strcmp($right['slotStart'], $left['slotStart']));
            if ($client['clientSince'] >= $monthStart) {
                $newThisMonth++;
            }
            if ($client['nextBookingAt'] instanceof \DateTimeImmutable) {
                $withUpcoming++;
            }
            if ($client['bookingCount'] > 1) {
                $recurring++;
            }
            $client['clientSince'] = $client['clientSince']->format(\DateTimeInterface::ATOM);
            $client['firstBookingAt'] = $client['firstBookingAt']?->format(\DateTimeInterface::ATOM);
            $client['lastBookingAt'] = $client['lastBookingAt']?->format(\DateTimeInterface::ATOM);
            $client['nextBookingAt'] = $client['nextBookingAt']?->format(\DateTimeInterface::ATOM);
        }
        unset($client);

        $clients = array_values($clients);
        $needle = mb_strtolower(trim($query));
        if ($needle !== '') {
            $clients = array_values(array_filter($clients, static function (array $client) use ($needle): bool {
                $haystack = implode(' ', array_filter([$client['displayName'], $client['email'], $client['phone'], implode(' ', $client['tags']), $client['lastServiceName'], $client['nextServiceName']]));

                return str_contains(mb_strtolower($haystack), $needle);
            }));
        }
        usort($clients, static function (array $left, array $right): int {
            if ($left['nextBookingAt'] !== null && $right['nextBookingAt'] === null) {
                return -1;
            }
            if ($left['nextBookingAt'] === null && $right['nextBookingAt'] !== null) {
                return 1;
            }

            return strcasecmp($left['lastName'].' '.$left['firstName'], $right['lastName'].' '.$right['firstName']);
        });

        return [
            'member' => $clients,
            'stats' => [
                'total' => \count($clients),
                'newThisMonth' => $newThisMonth,
                'withUpcoming' => $withUpcoming,
                'recurring' => $recurring,
            ],
        ];
    }

    private function newClient(string $email, string $firstName, string $lastName, ?string $phone, ?string $notes, string $currency, \DateTimeInterface $createdAt): array
    {
        return [
            'id' => substr(hash('sha256', $email), 0, 16),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'displayName' => trim($firstName.' '.$lastName),
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
            'bookingCount' => 0,
            'completedCount' => 0,
            'cancelledCount' => 0,
            'totalAmount' => 0,
            'currencyCode' => $currency,
            'firstBookingAt' => $createdAt,
            'clientSince' => $createdAt,
            'lastBookingAt' => null,
            'nextBookingAt' => null,
            'lastServiceName' => null,
            'nextServiceName' => null,
            'bookings' => [],
            'purchases' => [],
        ];
    }

    public function findClient(string $id): Booking|Customer|null
    {
        $booking = $this->findBookingForClient($id);
        if ($booking !== null) return $booking;
        foreach ($this->entityManager->getRepository(Customer::class)->findAll() as $customer) {
            if (hash_equals(substr(hash('sha256', mb_strtolower(trim((string) $customer->getEmail()))), 0, 16), $id)) return $customer;
        }
        return null;
    }

    public function findBookingForClient(string $id): ?Booking
    {
        foreach ($this->bookingRepository->findForAdministration() as $booking) {
            if (hash_equals(substr(hash('sha256', mb_strtolower(trim($booking->getCustomerEmail()))), 0, 16), $id)) {
                return $booking;
            }
        }

        return null;
    }
}

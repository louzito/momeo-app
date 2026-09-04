<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\ClientProfile;
use App\Gdpr\CustomerDataManager;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v2/admin/clients')]
#[IsGranted('ROLE_API_ACCESS')]
final class AdminClientApiController
{
    private const CONSENT_TYPES = ['marketing', 'dataProcessing', 'medicalData'];

    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly CustomerDataManager $customerDataManager,
    ) {}

    #[Route('/{id}/export', name: 'todatempo_api_admin_client_export', methods: ['GET'])]
    public function export(string $id): JsonResponse
    {
        $booking = $this->findBookingForClient($id);
        if (!$booking instanceof Booking) return new JsonResponse(['message' => 'Client introuvable.'], 404);
        $actor = $this->security->getUser()?->getUserIdentifier() ?? 'admin';

        return new JsonResponse($this->customerDataManager->export($booking->getCustomerEmail(), $actor), headers: [
            'Content-Disposition' => sprintf('attachment; filename="donnees-client-%s.json"', $id),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    #[Route('/{id}', name: 'todatempo_api_admin_client_erase', methods: ['DELETE'])]
    public function erase(string $id): JsonResponse
    {
        $booking = $this->findBookingForClient($id);
        if (!$booking instanceof Booking) return new JsonResponse(['message' => 'Client introuvable.'], 404);
        $actor = $this->security->getUser()?->getUserIdentifier() ?? 'admin';

        return new JsonResponse(['status' => 'anonymized', 'counts' => $this->customerDataManager->erase($booking->getCustomerEmail(), $actor)]);
    }

    #[Route('', name: 'momeo_api_admin_client_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
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
                    'purchases' => [],
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

        $newThisMonth = 0;
        $withUpcoming = 0;
        $recurring = 0;
        foreach ($clients as &$client) {
            $profile = $profiles[$client['email']] ?? null;
            if ($profile instanceof ClientProfile) {
                $client = array_replace($client, $this->normalizeProfile($profile));
                $client['displayName'] = trim($client['firstName'].' '.$client['lastName']);
            } else {
                $client += ['visibleNotes' => $client['notes'], 'internalNotes' => null, 'tags' => [], 'allergies' => null, 'contraindications' => null, 'consents' => [], 'consentHistory' => []];
            }
            unset($client['notes']);
            $client['purchases'] = array_values($client['purchases']);
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
        $needle = mb_strtolower(trim((string) $request->query->get('q', '')));
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

    #[Route('/{id}', name: 'momeo_api_admin_client_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $booking = $this->findBookingForClient($id);
        if (!$booking instanceof Booking) {
            return new JsonResponse(['message' => 'Client introuvable.'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        $email = mb_strtolower(trim($booking->getCustomerEmail()));
        $profile = $this->entityManager->getRepository(ClientProfile::class)->findOneBy(['bookingEmail' => $email]) ?? new ClientProfile($email);
        $firstName = trim((string) ($data['firstName'] ?? $booking->getCustomerFirstName()));
        $lastName = trim((string) ($data['lastName'] ?? $booking->getCustomerLastName()));
        $newEmail = mb_strtolower(trim((string) ($data['email'] ?? $email)));
        if ($firstName === '' || $lastName === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['message' => 'Nom, prénom et email valide sont obligatoires.'], 422);
        }

        $profile->setFirstName($firstName);
        $profile->setLastName($lastName);
        $profile->setEmail($newEmail);
        $profile->setPhone(isset($data['phone']) ? (string) $data['phone'] : $booking->getCustomerPhone());
        $profile->setVisibleNotes(isset($data['visibleNotes']) ? (string) $data['visibleNotes'] : $profile->getVisibleNotes());
        $profile->setInternalNotes(isset($data['internalNotes']) ? (string) $data['internalNotes'] : $profile->getInternalNotes());
        $profile->setAllergies(isset($data['allergies']) ? (string) $data['allergies'] : $profile->getAllergies());
        $profile->setContraindications(isset($data['contraindications']) ? (string) $data['contraindications'] : $profile->getContraindications());
        if (isset($data['tags']) && is_array($data['tags'])) {
            $profile->setTags(array_slice(array_map('strval', $data['tags']), 0, 30));
        }
        if (isset($data['consents']) && is_array($data['consents'])) {
            $actor = $this->security->getUser()?->getUserIdentifier() ?? 'admin';
            foreach (self::CONSENT_TYPES as $type) {
                if (array_key_exists($type, $data['consents']) && (!array_key_exists($type, $profile->getConsents()) || $profile->getConsents()[$type] !== (bool) $data['consents'][$type])) {
                    $profile->recordConsent($type, (bool) $data['consents'][$type], $actor);
                }
            }
        }

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeProfile($profile));
    }

    private function findBookingForClient(string $id): ?Booking
    {
        foreach ($this->bookingRepository->findForAdministration() as $booking) {
            if (hash_equals(substr(hash('sha256', mb_strtolower(trim($booking->getCustomerEmail()))), 0, 16), $id)) {
                return $booking;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function normalizeProfile(ClientProfile $profile): array
    {
        return [
            'firstName' => $profile->getFirstName(), 'lastName' => $profile->getLastName(),
            'email' => $profile->getEmail(), 'phone' => $profile->getPhone(),
            'visibleNotes' => $profile->getVisibleNotes(), 'internalNotes' => $profile->getInternalNotes(),
            'tags' => $profile->getTags(), 'allergies' => $profile->getAllergies(),
            'contraindications' => $profile->getContraindications(), 'consents' => $profile->getConsents(),
            'consentHistory' => $profile->getConsentHistory(),
        ];
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
            'totalAmount' => $booking->getTotalAmount(),
            'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(),
            'paymentState' => $booking->getPaymentState(),
        ];
    }
}

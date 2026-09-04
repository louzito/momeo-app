<?php

declare(strict_types=1);

namespace App\Controller;

use App\Booking\BookingSlotGuard;
use App\Booking\CustomerBookingChangePolicy;
use App\Booking\SlotUnavailable;
use App\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Planning;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Entity\Order\Order;
use App\Entity\User\ShopUser;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Repository\PlanningRepository;
use App\Repository\StaffMemberRepository;
use App\Repository\StaffTimeOffRepository;
use App\Resource\ResourceAvailability;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
use Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v2/shop/account')]
#[IsGranted('ROLE_USER')]
final class ShopCustomerAccountApiController extends AbstractController
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningRepository $planningRepository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly StaffTimeOffRepository $timeOffRepository,
        private readonly BookingSlotGuard $slotGuard,
        private readonly CustomerBookingChangePolicy $changePolicy,
        private readonly BookingEmailDispatcher $emailDispatcher,
        private readonly ResourceAvailability $resourceAvailability,
        #[Autowire(service: 'sylius_invoicing.repository.invoice')]
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        #[Autowire(service: 'sylius_invoicing.provider.invoice_file')]
        private readonly InvoiceFileProviderInterface $invoiceFileProvider,
    ) {
    }

    #[Route('/profile', name: 'todatempo_api_shop_account_profile', methods: ['GET'])]
    public function profile(#[CurrentUser] ShopUser $user): JsonResponse
    {
        $customer = $user->getCustomer();

        return $this->json([
            'id' => $customer?->getId(),
            'email' => $user->getEmail(),
            'firstName' => $customer?->getFirstName() ?? '',
            'lastName' => $customer?->getLastName() ?? '',
            'phone' => $customer?->getPhoneNumber() ?? '',
        ]);
    }

    #[Route('/bookings', name: 'todatempo_api_shop_account_bookings', methods: ['GET'])]
    public function bookings(#[CurrentUser] ShopUser $user): JsonResponse
    {
        $bookings = $this->bookingRepository->createQueryBuilder('booking')
            ->andWhere('LOWER(booking.customerEmail) = :email')
            ->setParameter('email', mb_strtolower((string) $user->getEmail()))
            ->orderBy('booking.slotStart', 'DESC')
            ->getQuery()->getResult();

        return $this->json(['member' => array_map($this->normalizeBooking(...), $bookings)]);
    }

    #[Route('/bookings/{publicToken<[0-9a-f]{32}>}', name: 'todatempo_api_shop_account_booking', methods: ['GET'])]
    public function booking(string $publicToken, #[CurrentUser] ShopUser $user): JsonResponse
    {
        $booking = $this->bookingRepository->findOneBy(['publicToken' => $publicToken]);
        if (!$booking instanceof Booking || 0 !== strcasecmp($booking->getCustomerEmail(), (string) $user->getEmail())) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }

        return $this->json($this->normalizeBooking($booking));
    }

    #[Route('/bookings/{publicToken<[0-9a-f]{32}>}/cancel', name: 'todatempo_api_shop_account_booking_cancel', methods: ['POST'])]
    public function cancel(string $publicToken, #[CurrentUser] ShopUser $user): JsonResponse
    {
        $booking = $this->ownedBooking($publicToken, $user);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($booking, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($booking);
            $this->changePolicy->assertAllowed($booking, 'cancel');
            $booking->recordChange([
                'action' => 'cancelled', 'actor' => 'customer',
                'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'previousStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
                'previousEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            ]);
            $booking->setStatus(Booking::STATUS_CANCELLED);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return $this->json(['error' => $exception->getMessage(), 'code' => 'change_deadline_passed'], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return $this->json(['error' => 'L’annulation n’a pas pu être enregistrée.'], Response::HTTP_CONFLICT);
        }
        $this->emailDispatcher->cancellation($booking);

        return $this->json($this->normalizeBooking($booking));
    }

    #[Route('/bookings/{publicToken<[0-9a-f]{32}>}/reschedule', name: 'todatempo_api_shop_account_booking_reschedule', methods: ['POST'])]
    public function reschedule(string $publicToken, Request $request, #[CurrentUser] ShopUser $user): JsonResponse
    {
        $booking = $this->ownedBooking($publicToken, $user);
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($payload['end'] ?? ''));
        } catch (\Throwable) {
            return $this->json(['error' => 'Le nouveau créneau est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($end <= $start || $start <= new \DateTimeImmutable()) {
            return $this->json(['error' => 'Ce créneau n’est plus disponible.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($booking, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($booking);
            $this->changePolicy->assertAllowed($booking, 'reschedule');
            $planning = $this->planningRepository->findOneBy(['code' => trim((string) ($payload['planningCode'] ?? '')), 'active' => true]);
            $staff = $this->staffRepository->find((int) ($payload['staffMemberId'] ?? 0));
            if (!$planning instanceof Planning || ($planning->getServiceCodes() !== [] && !\in_array($booking->getServiceCode(), $planning->getServiceCodes(), true))) {
                throw new SlotUnavailable('Ce créneau ne figure plus au planning.');
            }
            $this->assertPlannedSlot($planning, $booking, $start, $end);
            if (!$staff instanceof StaffMember || !$staff->isActive() || !$staff->isBookable() || !\in_array($booking->getServiceCode(), $staff->getServiceCodes(), true)) {
                throw new SlotUnavailable('Ce collaborateur n’est plus disponible.');
            }
            $this->assertStaffHours($staff, $planning, $start, $end);
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
            $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null), $booking);
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
            $this->entityManager->flush();
            $connection->commit();
        } catch (SlotUnavailable $exception) {
            $connection->rollBack();
            return $this->json(['error' => $exception->getMessage(), 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return $this->json(['error' => $exception->getMessage(), 'code' => 'change_deadline_passed'], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return $this->json(['error' => 'Ce créneau vient d’être réservé.', 'code' => 'slot_unavailable'], Response::HTTP_CONFLICT);
        }
        $this->emailDispatcher->rescheduled($booking);

        return $this->json($this->normalizeBooking($booking));
    }

    #[Route('/orders', name: 'todatempo_api_shop_account_orders', methods: ['GET'])]
    public function orders(#[CurrentUser] ShopUser $user): JsonResponse
    {
        $customer = $user->getCustomer();
        $orders = null === $customer ? [] : $this->entityManager->getRepository(Order::class)->findBy(['customer' => $customer], ['createdAt' => 'DESC']);

        return $this->json(['member' => array_map(fn (Order $order): array => [
            'id' => $order->getTokenValue(),
            'number' => $order->getNumber(),
            'status' => $order->getState(),
            'paymentState' => $order->getPaymentState(),
            'total' => $order->getTotal() / 100,
            'currency' => $order->getCurrencyCode(),
            'createdAt' => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'kind' => null !== $this->giftVoucherRepository->findOneBy(['purchaseOrderNumber' => $order->getNumber()]) ? 'gift' : 'direct',
        ], $orders)]);
    }

    #[Route('/invoices/{id}/download', name: 'todatempo_api_shop_account_invoice_download', methods: ['GET'])]
    public function invoice(string $id, #[CurrentUser] ShopUser $user): Response
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof InvoiceInterface || !$this->ownsInvoice($invoice, $user)) {
            throw $this->createNotFoundException('Facture introuvable.');
        }

        $pdf = $this->invoiceFileProvider->provide($invoice);

        return new Response($pdf->content(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', basename($pdf->filename())),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ownsInvoice(InvoiceInterface $invoice, ShopUser $user): bool
    {
        $customerEmail = $invoice->order()->getCustomer()?->getEmail();

        return \is_string($customerEmail)
            && 0 === strcasecmp($customerEmail, (string) $user->getEmail())
            && $invoice->paymentState() === 'paid';
    }

    private function ownedBooking(string $publicToken, ShopUser $user): Booking
    {
        $booking = $this->bookingRepository->findOneBy(['publicToken' => $publicToken]);
        if (!$booking instanceof Booking || 0 !== strcasecmp($booking->getCustomerEmail(), (string) $user->getEmail())) {
            throw $this->createNotFoundException('Réservation introuvable.');
        }
        return $booking;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private function assertPlannedSlot(Planning $planning, Booking $booking, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if (($end->getTimestamp() - $start->getTimestamp()) !== ($booking->getSlotEnd()->getTimestamp() - $booking->getSlotStart()->getTimestamp())) {
            throw new SlotUnavailable('La durée de la prestation ne peut pas être modifiée.');
        }
        $timezone = new \DateTimeZone($planning->getTimezone());
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);
        if ($localStart->format('Y-m-d') !== $localEnd->format('Y-m-d')) {
            throw new SlotUnavailable('Ce créneau ne figure plus au planning.');
        }
        foreach ($planning->getDays()[strtolower($localStart->format('l'))] ?? [] as $range) {
            if ($localStart->format('H:i') >= $range['start'] && $localEnd->format('H:i') <= $range['end']) {
                return;
            }
        }
        throw new SlotUnavailable('Ce créneau ne figure plus au planning.');
    }

    private function assertStaffHours(StaffMember $staff, Planning $planning, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $timezone = new \DateTimeZone($planning->getTimezone());
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);
        $hours = $staff->getWorkingHours()[strtolower($localStart->format('l'))] ?? null;
        if (!\is_array($hours) || !($hours['enabled'] ?? false)) {
            throw new SlotUnavailable('Ce créneau est en dehors des horaires du collaborateur.');
        }
        $opening = new \DateTimeImmutable($localStart->format('Y-m-d').' '.($hours['start'] ?? '09:00'), $timezone);
        $closing = new \DateTimeImmutable($localStart->format('Y-m-d').' '.($hours['end'] ?? '18:00'), $timezone);
        if ($localStart < $opening || $localEnd > $closing) {
            throw new SlotUnavailable('Ce créneau est en dehors des horaires du collaborateur.');
        }
    }

    /** @return array<string, mixed> */
    private function normalizeBooking(Booking $booking): array
    {
        $name = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());

        return [
            'id' => $booking->getPublicToken(), 'reference' => $booking->getReference(),
            'status' => $booking->getStatus(), 'source' => $booking->getSource(),
            'jumpTypeId' => $booking->getServiceCode(), 'jumpTypeName' => $booking->getServiceName(),
            'jumperName' => $name, 'customerName' => $name, 'staffName' => $booking->getStaffName(),
            'resourceCode' => $booking->getResourceCode(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'options' => $booking->getOptions(), 'paymentState' => $booking->getPaymentState(),
            'orderNumber' => $booking->getOrderNumber(), 'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(), 'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(), 'postponedReason' => $booking->getPostponedReason(),
            'changeHistory' => $booking->getChangeHistory(), 'changePolicy' => $this->changePolicy->limits(),
        ];
    }
}

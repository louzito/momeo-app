<?php

declare(strict_types=1);

namespace App\Controller;
use App\Service\Booking\BookingRescheduler;
use App\Service\Booking\BookingLifecycle;
use App\Service\Booking\BookingMutationFailed;
use App\Service\Booking\BookingNotOwned;
use App\Service\Booking\CustomerBookingChangePolicy;
use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\User\ShopUser;
use App\Service\Gdpr\CustomerDataManager;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
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
        private readonly BookingLifecycle $lifecycle,
        private readonly BookingRescheduler $rescheduler,
        private readonly BookingRepository $bookingRepository,
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerBookingChangePolicy $changePolicy,
        private readonly CustomerDataManager $customerDataManager,
        #[Autowire(service: 'sylius_invoicing.repository.invoice')]
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        #[Autowire(service: 'sylius_invoicing.provider.invoice_file')]
        private readonly InvoiceFileProviderInterface $invoiceFileProvider,
    ) {
    }

    #[Route('/data-export', name: 'todatempo_api_shop_account_data_export', methods: ['GET'])]
    public function dataExport(#[CurrentUser] ShopUser $user): JsonResponse
    {
        return $this->json($this->customerDataManager->export((string) $user->getEmail(), 'customer'), headers: [
            'Content-Disposition' => 'attachment; filename="mes-donnees.json"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    #[Route('/profile', name: 'todatempo_api_shop_account_erase', methods: ['DELETE'])]
    public function eraseProfile(#[CurrentUser] ShopUser $user): JsonResponse
    {
        return $this->json(['status' => 'anonymized', 'counts' => $this->customerDataManager->erase((string) $user->getEmail(), 'customer')]);
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
        try {
            $this->lifecycle->cancelByCustomer($booking, (string) $user->getEmail());
        } catch (BookingNotOwned $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        } catch (BookingMutationFailed $exception) {
            $error = ['error' => $exception->getMessage()];
            if ($exception->errorCode !== null) $error['code'] = $exception->errorCode;
            return $this->json($error, Response::HTTP_CONFLICT);
        }

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
        try {
            $this->rescheduler->customer($booking, (string) $user->getEmail(), $payload, $start, $end);
        } catch (BookingNotOwned $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        } catch (BookingMutationFailed $exception) {
            $error = ['error' => $exception->getMessage()];
            if ($exception->errorCode !== null) $error['code'] = $exception->errorCode;
            return $this->json($error, Response::HTTP_CONFLICT);
        }

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

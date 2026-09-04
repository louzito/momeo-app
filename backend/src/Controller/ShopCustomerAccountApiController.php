<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\User\ShopUser;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    /** @return array<string, mixed> */
    private function normalizeBooking(Booking $booking): array
    {
        $name = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());

        return [
            'id' => $booking->getPublicToken(), 'reference' => $booking->getReference(),
            'status' => $booking->getStatus(), 'source' => $booking->getSource(),
            'jumpTypeId' => $booking->getServiceCode(), 'jumpTypeName' => $booking->getServiceName(),
            'jumperName' => $name, 'customerName' => $name, 'staffName' => $booking->getStaffName(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'options' => $booking->getOptions(), 'paymentState' => $booking->getPaymentState(),
            'orderNumber' => $booking->getOrderNumber(), 'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(), 'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(), 'postponedReason' => $booking->getPostponedReason(),
        ];
    }
}

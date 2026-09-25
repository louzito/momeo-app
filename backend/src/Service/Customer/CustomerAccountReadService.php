<?php

declare(strict_types=1);

namespace App\Service\Customer;

use App\Service\Booking\BookingView;
use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\User\ShopUser;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Service\Booking\CustomerBookingChangePolicy;
use Doctrine\ORM\EntityManagerInterface;

final class CustomerAccountReadService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerBookingChangePolicy $changePolicy,
        private readonly BookingView $view,
    ) {}

    public function profile(ShopUser $user): array
    {
        $customer = $user->getCustomer();

        return [
            'id' => $customer?->getId(),
            'email' => $user->getEmail(),
            'firstName' => $customer?->getFirstName() ?? '',
            'lastName' => $customer?->getLastName() ?? '',
            'phone' => $customer?->getPhoneNumber() ?? '',
        ];
    }

    public function bookings(ShopUser $user): array
    {
        return ['member' => array_map($this->normalizeBooking(...), $this->bookingRepository->findForCustomerEmail((string) $user->getEmail()))];
    }

    public function orders(ShopUser $user): array
    {
        $customer = $user->getCustomer();
        $orders = null === $customer ? [] : $this->entityManager->getRepository(Order::class)->findBy(['customer' => $customer], ['createdAt' => 'DESC']);

        return ['member' => array_map(fn (Order $order): array => [
            'id' => $order->getTokenValue(),
            'number' => $order->getNumber(),
            'status' => $order->getState(),
            'paymentState' => $order->getPaymentState(),
            'total' => $order->getTotal() / 100,
            'currency' => $order->getCurrencyCode(),
            'createdAt' => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'kind' => null !== $this->giftVoucherRepository->findOneBy(['purchaseOrderNumber' => $order->getNumber()]) ? 'gift' : 'direct',
        ], $orders)];
    }

    public function normalizeBooking(Booking $booking): array
    {
        return $this->view->client($booking, $this->changePolicy->limits());
    }
}

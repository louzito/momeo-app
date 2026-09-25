<?php

declare(strict_types=1);

namespace App\Service\Customer;

use App\Entity\Booking;
use App\Entity\User\ShopUser;
use App\Repository\BookingRepository;
use App\Service\Booking\BookingNotOwned;

final class CustomerAccountAccess
{
    public function __construct(private readonly BookingRepository $bookingRepository) {}

    // Deliberately no trim: account ownership preserves the historical email comparison.
    public static function ownsBooking(Booking $booking, string $email): bool
    {
        return 0 === strcasecmp($booking->getCustomerEmail(), $email);
    }

    public function ownedBooking(string $publicToken, ShopUser $user): Booking
    {
        $booking = $this->bookingRepository->findOneBy(['publicToken' => $publicToken]);
        if (!$booking instanceof Booking || !self::ownsBooking($booking, (string) $user->getEmail())) {
            throw new BookingNotOwned('Réservation introuvable.');
        }
        return $booking;
    }
}

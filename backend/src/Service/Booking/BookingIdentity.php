<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Repository\BookingRepository;

/** Identifiers shared by public, gift and manual creation. */
final class BookingIdentity
{
    public function __construct(private readonly BookingRepository $bookingRepository) {}

    public function initialize(Booking $booking): void
    {
        $booking->setReference($this->newReference());
        $booking->setPublicToken(bin2hex(random_bytes(16)));
    }

    private function newReference(): string
    {
        do {
            $reference = 'MOM-'.strtoupper(bin2hex(random_bytes(4)));
        } while ($this->bookingRepository->findOneBy(['reference' => $reference]) instanceof Booking);

        return $reference;
    }
}

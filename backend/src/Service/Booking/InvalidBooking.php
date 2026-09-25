<?php

declare(strict_types=1);

namespace App\Service\Booking;

/** The order, product or payment terms cannot support this booking. */
final class InvalidBooking extends \DomainException
{
}

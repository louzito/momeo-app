<?php

declare(strict_types=1);

namespace App\Booking;

final class SlotUnavailable extends \DomainException
{
    public function __construct(string $message = 'Ce créneau vient d’être réservé. Choisissez-en un autre.')
    {
        parent::__construct($message);
    }
}

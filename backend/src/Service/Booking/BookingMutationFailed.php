<?php

declare(strict_types=1);

namespace App\Service\Booking;


/** Business failure translated by HTTP adapters, without an HTTP dependency. */
final class BookingMutationFailed extends \RuntimeException
{
    public function __construct(string $message = '', public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Reminder\Message;

final readonly class SendBookingReminder
{
    public function __construct(public int $deliveryId)
    {
    }
}

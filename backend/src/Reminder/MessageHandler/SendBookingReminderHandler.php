<?php

declare(strict_types=1);

namespace App\Reminder\MessageHandler;

use App\Reminder\Message\SendBookingReminder;
use App\Service\Reminder\BookingReminderSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Messenger adapter; keep the historical message type for queued deliveries. */
#[AsMessageHandler]
final readonly class SendBookingReminderHandler
{
    public function __construct(private BookingReminderSender $sender) {}

    public function __invoke(SendBookingReminder $message): void
    {
        $this->sender->send($message->deliveryId);
    }
}

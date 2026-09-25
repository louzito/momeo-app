<?php

declare(strict_types=1);

namespace App\Service\Reminder\Sms;

interface SmsProvider
{
    /** Returns the provider delivery reference when available. */
    public function send(string $recipient, string $body): ?string;
}

<?php

declare(strict_types=1);

namespace App\Reminder\Sms;

final class DisabledSmsProvider implements SmsProvider
{
    public function send(string $recipient, string $body): ?string
    {
        throw new SmsProviderDisabled('Le fournisseur SMS est désactivé.');
    }
}

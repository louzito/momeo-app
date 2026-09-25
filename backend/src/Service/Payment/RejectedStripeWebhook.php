<?php

declare(strict_types=1);

namespace App\Service\Payment;

final class RejectedStripeWebhook extends \DomainException
{
    public const NOT_CONFIGURED = 1;
    public const INVALID_SIGNATURE = 2;
}

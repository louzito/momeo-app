<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Payment\Payment;

interface RefundProvider
{
    /** @return array{provider: string, reference: string} */
    public function refund(Payment $payment, int $amount, string $idempotencyKey): array;
}

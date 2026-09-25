<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Payment\RefundOperation;

final class RefundView
{
    /** @return array<string, mixed> */
    public function normalize(RefundOperation $operation): array
    {
        return [
            'id' => $operation->getId(),
            'idempotencyKey' => $operation->getIdempotencyKey(),
            'amount' => $operation->getAmount(),
            'currency' => $operation->getCurrency(),
            'status' => $operation->getStatus(),
            'provider' => $operation->getProvider(),
            'providerReference' => $operation->getProviderReference(),
            'creditNoteNumber' => $operation->getCreditNoteNumber(),
            'actor' => $operation->getActor(),
            'reason' => $operation->getReason(),
            'createdAt' => $operation->getCreatedAt()->format(DATE_ATOM),
            'completedAt' => $operation->getCompletedAt()?->format(DATE_ATOM),
        ];
    }
}

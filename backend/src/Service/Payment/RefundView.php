<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Payment\RefundOperation;

final class RefundView
{
    public function context(\App\Entity\Payment\Payment $payment, RefundService $refunds): array
    {
        $order = $payment->getOrder();
        $payments = [];
        $collected = $refunded = 0;
        foreach ($order?->getPayments() ?? [] as $part) {
            if (!$part instanceof \App\Entity\Payment\Payment) continue;
            $settled = in_array($part->getState(), ['completed', 'refunded'], true);
            $method = $part->getMethod();
            $code = $method?->getCode();
            $mode = isset($part->getDetails()['gift_card_code']) ? 'gift_card'
                : ($code === 'stripe_web_elements' ? 'automatic'
                : (in_array($code, ['bank_transfer', 'cash'], true) || $method?->getGatewayConfig()?->getFactoryName() === 'offline' ? 'manual' : 'unsupported'));
            $collected += $settled ? (int) $part->getAmount() : 0;
            $refunded += $part->getRefundedAmount();
            $payments[] = [
                'id' => $part->getId(), 'state' => $part->getState(),
                'amount' => (int) $part->getAmount(), 'refundedAmount' => $part->getRefundedAmount(),
                'refundableAmount' => $part->getState() === 'completed' ? $part->getRefundableAmount() : 0,
                'method' => $code, 'methodName' => $method?->getName() ?? $code,
                'mode' => $mode,
                'operations' => array_map(fn (RefundOperation $operation): array => $this->normalize($operation), $refunds->operations($part)),
            ];
        }
        $balance = 0;
        foreach ($order?->getAdjustments('todatempo_payment_terms') ?? [] as $adjustment) $balance -= $adjustment->getAmount();
        return [
            'orderTotal' => (int) $order?->getTotal() + $balance,
            'balanceDue' => max(0, $balance), 'collectedAmount' => $collected,
            'refundedAmount' => $refunded, 'paymentState' => $order?->getPaymentState(),
            'payments' => $payments,
        ];
    }

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

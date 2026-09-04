<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use Stripe\StripeClient;

final class ConfiguredRefundProvider implements RefundProvider
{
    public function refund(Payment $payment, int $amount, string $idempotencyKey): array
    {
        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethod) throw new \DomainException('Le moyen de paiement est introuvable.');
        if ($method->getCode() !== 'stripe_web_elements') {
            return ['provider' => $method->getCode(), 'reference' => 'manual-'.$idempotencyKey];
        }
        $config = $method->getGatewayConfig()?->getConfig() ?? [];
        $secret = trim((string) ($config['secret_key'] ?? ''));
        $intent = trim((string) (($payment->getDetails()['stripe_payment_intent'] ?? '')));
        if ($secret === '') throw new \DomainException('Stripe n’est pas configure pour ce centre.');
        $stripe = new StripeClient($secret);
        // Compatibilite avec les paiements encaisses avant que le PaymentIntent
        // ne soit archive localement : sa metadata contient deja payment_id.
        if ($intent === '') {
            $matches = $stripe->paymentIntents->search(['query' => sprintf("metadata['payment_id']:'%d'", $payment->getId()), 'limit' => 1]);
            $intent = (string) ($matches->data[0]->id ?? '');
        }
        if ($intent === '') throw new \DomainException('La reference Stripe du paiement est absente.');
        $refund = $stripe->refunds->create(
            ['payment_intent' => $intent, 'amount' => $amount, 'metadata' => ['payment_id' => (string) $payment->getId()]],
            ['idempotency_key' => $idempotencyKey],
        );
        if (!\in_array($refund->status, ['pending', 'succeeded'], true)) throw new \RuntimeException('Stripe a refuse le remboursement.');
        return ['provider' => 'stripe', 'reference' => (string) $refund->id];
    }
}

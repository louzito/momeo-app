<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use Stripe\StripeClient;
use Symfony\Component\Workflow\Registry;

final class StripeCheckout
{
    public function __construct(private readonly Registry $workflows) {}

    /** @param array<string, mixed> $config */
    public function createSession(Order $order, Payment $payment, ?Booking $booking, array $config, string $successUrl, string $cancelUrl): array
    {
        $secretKey = trim((string) ($config['secret_key'] ?? ''));
        if ($secretKey === '') {
            throw new \DomainException('Le paiement par carte n’est pas configuré pour ce centre.');
        }

        $stripe = new StripeClient($secretKey);
        $parameters = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'client_reference_id' => (string) $order->getNumber(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower((string) $order->getCurrencyCode()),
                    'unit_amount' => $payment->getAmount(),
                    'product_data' => ['name' => ($booking ? 'Réservation ' : 'Commande ').($order->getNumber() ?? '')],
                ],
            ]],
            'metadata' => [
                'order_token' => (string) $order->getTokenValue(),
                'payment_id' => (string) $payment->getId(),
                'booking_token' => $booking?->getPublicToken() ?? '',
            ],
            'payment_intent_data' => ['metadata' => [
                'order_number' => (string) $order->getNumber(),
                'payment_id' => (string) $payment->getId(),
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];
        $customerEmail = $order->getCustomer()?->getEmail();
        if (\is_string($customerEmail) && $customerEmail !== '') {
            $parameters['customer_email'] = $customerEmail;
        }
        $session = $stripe->checkout->sessions->create($parameters, ['idempotency_key' => 'todatempo-payment-'.$order->getTokenValue().'-'.$payment->getId()]);

        return ['id' => $session->id, 'url' => $session->url];
    }

    public function complete(Payment $payment, ?Booking $booking): bool
    {
        if ($payment->getState() === 'completed') return false;
        $workflow = $this->workflows->get($payment, 'sylius_payment');
        if (!$workflow->can($payment, 'complete')) return false;
        $workflow->apply($payment, 'complete');
        if ($booking !== null) {
            $booking->setPaymentState('paid');
            $booking->setStatus(Booking::STATUS_CONFIRMED);
        }
        return true;
    }

    public function cancel(Payment $payment, ?Booking $booking): void
    {
        $this->unsuccessful($payment, $booking, 'cancel', 'cancelled');
    }

    public function fail(Payment $payment, ?Booking $booking): void
    {
        $this->unsuccessful($payment, $booking, 'fail', 'failed');
    }

    private function unsuccessful(Payment $payment, ?Booking $booking, string $transition, string $state): void
    {
        if ($payment->getState() === 'completed' || $booking?->getPaymentState() === 'paid') return;
        $workflow = $this->workflows->get($payment, 'sylius_payment');
        if (!$workflow->can($payment, $transition)) return;
        $workflow->apply($payment, $transition);
        if ($booking !== null) {
            $booking->setPaymentState($state);
            $booking->setStatus(Booking::STATUS_CANCELLED);
        }
    }
}

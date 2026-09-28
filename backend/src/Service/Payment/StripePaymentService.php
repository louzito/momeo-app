<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Booking;
use App\Service\GiftVoucher\GiftOrderMarker;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;

final class StripePaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeCheckout $checkout,
    ) {}

    /** @param array<string, mixed> $data */
    public function session(array $data, string $host): array
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => (string) ($data['orderToken'] ?? '')]);
        $bookingToken = (string) ($data['bookingToken'] ?? '');
        $booking = $bookingToken !== '' ? $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => $bookingToken]) : null;
        if (!$order instanceof Order || ($bookingToken !== '' && (!$booking instanceof Booking || $booking->getOrderNumber() !== $order->getNumber()))) {
            throw new PaymentNotFound('Commande ou réservation introuvable.');
        }

        $payment = $this->payment($order, (int) ($data['paymentId'] ?? 0));
        $method = $payment?->getMethod();
        if (!$payment instanceof Payment || !$method instanceof PaymentMethod || $method->getCode() !== 'stripe_web_elements' || !$method->isEnabled() || ($order->getChannel() !== null && !$method->hasChannel($order->getChannel()))) {
            throw new \DomainException('Le paiement Stripe est invalide.');
        }
        if (!$booking && $order->getFulfillmentMode() === null && $order->getGiftCardAmount() === null && GiftOrderMarker::decode($order->getNotes()) === null) {
            throw new PaymentNotFound('Commande ou réservation introuvable.');
        }
        if ($order->getCheckoutState() !== 'completed' || in_array($payment->getState(), ['completed', 'cancelled', 'failed', 'refunded'], true)) {
            throw new \DomainException('Ce paiement ne peut plus être démarré.');
        }
        $gift = $order->getGiftCardAmount() !== null || GiftOrderMarker::decode($order->getNotes()) !== null;
        if ($gift && !$order->getAdjustments('todatempo_payment_terms')->isEmpty()) {
            throw new \DomainException('Un cadeau doit être réglé intégralement.');
        }
        $due = $order->getTotal();
        foreach ($order->getPayments() as $other) {
            if ($other !== $payment && $other->getState() === 'completed' && $other->getCurrencyCode() === $order->getCurrencyCode()) $due -= $other->getAmount();
        }
        if ($due <= 0 || $payment->getCurrencyCode() !== $order->getCurrencyCode() || $payment->getAmount() !== $due || ($booking && $booking->getAmount() !== $order->getTotal()) || ($order->getGiftCardAmount() !== null && ($due !== $order->getGiftCardAmount() || $due !== $order->getTotal()))) {
            throw new \DomainException('Le montant du paiement ne correspond pas à la commande.');
        }

        try {
            $successUrl = $this->returnUrl($host, (string) ($data['successUrl'] ?? ''));
            $cancelUrl = $this->returnUrl($host, (string) ($data['cancelUrl'] ?? ''));
            $session = $this->checkout->createSession($order, $payment, $booking, $method->getGatewayConfig()?->getConfig() ?? [], $successUrl, $cancelUrl);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new StripeSessionUnavailable('Stripe est momentanément indisponible. Réessayez.', 0, $exception);
        }

        return $session;
    }

    public function status(string $token): array
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $token]);
        if (!$order instanceof Order || $token === '') throw new PaymentNotFound('Commande introuvable.');
        $payment = $this->payment($order);
        foreach ($order->getPayments() as $candidate) {
            if ($candidate instanceof Payment && in_array($candidate->getMethod()?->getCode(), ['stripe_web_elements', 'bank_transfer'], true)) $payment = $candidate;
        }
        $paid = 0;
        foreach ($order->getPayments() as $candidate) {
            if ($candidate->getState() === 'completed' && $candidate->getCurrencyCode() === $order->getCurrencyCode()) $paid += $candidate->getAmount();
        }
        return [
            'number' => $order->getNumber(), 'total' => $order->getTotal() / 100,
            'currency' => $order->getCurrencyCode(),
            'status' => $paid >= $order->getTotal() && $order->getTotal() > 0 ? 'paid' : ($payment?->getState() ?? 'pending'),
            'kind' => $order->getGiftCardAmount() !== null || GiftOrderMarker::decode($order->getNotes()) !== null ? 'gift' : 'products',
            'paymentId' => $payment?->getId(),
            'canPay' => $payment?->getMethod()?->getCode() === 'stripe_web_elements' && $payment->getMethod()->isEnabled() && in_array($payment->getState(), ['new', 'processing'], true) && $paid < $order->getTotal(),
            'paymentMethod' => $payment?->getMethod()?->getCode(),
            'paymentInstructions' => $payment?->getMethod()?->getInstructions(),
            'preparationState' => $order->getPreparationState(),
            'giftCardTerms' => $order->getGiftCardPurchase() === null ? null : [
                'validityMonths' => $order->getGiftCardPurchase()['validityMonths'],
                'shopName' => $order->getChannel()?->getName(),
                'delivery' => $order->getGiftCardPurchase()['delivery'],
            ],
        ];
    }

    /** @return array{status: string} */
    public function cancel(string $bookingToken): array
    {
        $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => $bookingToken]);
        $order = $booking instanceof Booking ? $this->entityManager->getRepository(Order::class)->findOneBy(['number' => $booking->getOrderNumber()]) : null;
        $payment = $order instanceof Order ? $this->payment($order) : null;
        if (!$booking instanceof Booking || !$payment instanceof Payment) {
            throw new PaymentNotFound('Paiement introuvable.');
        }
        // Returning from Checkout is not proof of cancellation or payment.
        return ['status' => $booking->getPaymentState()];
    }

    private function payment(Order $order, int $id = 0): ?Payment
    {
        foreach ($order->getPayments() as $candidate) {
            if ($candidate instanceof Payment && ($id === 0 || $candidate->getId() === $id)) return $candidate;
        }
        return null;
    }

    private function returnUrl(string $host, string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array($parts['scheme'], ['http', 'https'], true) || strcasecmp($parts['host'], $host) !== 0) {
            throw new \InvalidArgumentException('URL de retour Stripe invalide.');
        }
        return $url;
    }
}

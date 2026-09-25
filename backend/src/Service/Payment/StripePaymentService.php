<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Booking;
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
        $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => (string) ($data['bookingToken'] ?? '')]);
        if (!$order instanceof Order || !$booking instanceof Booking || $booking->getOrderNumber() !== $order->getNumber()) {
            throw new PaymentNotFound('Commande ou réservation introuvable.');
        }

        $payment = $this->payment($order, (int) ($data['paymentId'] ?? 0));
        $method = $payment?->getMethod();
        if (!$payment instanceof Payment || !$method instanceof PaymentMethod || $method->getCode() !== 'stripe_web_elements') {
            throw new \DomainException('Le paiement Stripe est invalide.');
        }
        if ($order->getTotal() <= 0 || $payment->getAmount() !== $order->getTotal() || $booking->getAmount() !== $order->getTotal()) {
            throw new \DomainException('Le montant du paiement ne correspond pas à la réservation.');
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

    /** @return array{status: string} */
    public function cancel(string $bookingToken): array
    {
        $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => $bookingToken]);
        $order = $booking instanceof Booking ? $this->entityManager->getRepository(Order::class)->findOneBy(['number' => $booking->getOrderNumber()]) : null;
        $payment = $order instanceof Order ? $this->payment($order) : null;
        if (!$booking instanceof Booking || !$payment instanceof Payment) {
            throw new PaymentNotFound('Paiement introuvable.');
        }
        if ($booking->getPaymentState() !== 'paid') {
            $this->checkout->cancel($payment, $booking);
            $this->entityManager->flush();
        }

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

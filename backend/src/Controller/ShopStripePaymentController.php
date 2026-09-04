<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Entity\StripeWebhookEvent;
use App\Payment\StripeCheckout;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop/payments/stripe')]
final class ShopStripePaymentController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeCheckout $checkout,
    ) {}

    #[Route('/checkout-session', name: 'todatempo_stripe_checkout_session', methods: ['POST'])]
    public function session(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => (string) ($data['orderToken'] ?? '')]);
        $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => (string) ($data['bookingToken'] ?? '')]);
        if (!$order instanceof Order || !$booking instanceof Booking || $booking->getOrderNumber() !== $order->getNumber()) {
            return new JsonResponse(['error' => 'Commande ou réservation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $payment = $this->payment($order, (int) ($data['paymentId'] ?? 0));
        $method = $payment?->getMethod();
        if (!$payment instanceof Payment || !$method instanceof PaymentMethod || $method->getCode() !== 'stripe_web_elements') {
            return new JsonResponse(['error' => 'Le paiement Stripe est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($order->getTotal() <= 0 || $payment->getAmount() !== $order->getTotal() || $booking->getAmount() !== $order->getTotal()) {
            return new JsonResponse(['error' => 'Le montant du paiement ne correspond pas à la réservation.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $successUrl = $this->returnUrl($request, (string) ($data['successUrl'] ?? ''));
            $cancelUrl = $this->returnUrl($request, (string) ($data['cancelUrl'] ?? ''));
            $session = $this->checkout->createSession($order, $payment, $booking, $method->getGatewayConfig()?->getConfig() ?? [], $successUrl, $cancelUrl);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Stripe est momentanément indisponible. Réessayez.'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse($session, Response::HTTP_CREATED);
    }

    #[Route('/cancel/{bookingToken<[0-9a-f]{32}>}', name: 'todatempo_stripe_cancel', methods: ['POST'])]
    public function cancel(string $bookingToken): JsonResponse
    {
        $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => $bookingToken]);
        $order = $booking instanceof Booking ? $this->entityManager->getRepository(Order::class)->findOneBy(['number' => $booking->getOrderNumber()]) : null;
        $payment = $order instanceof Order ? $this->payment($order) : null;
        if (!$booking instanceof Booking || !$payment instanceof Payment) {
            return new JsonResponse(['error' => 'Paiement introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($booking->getPaymentState() !== 'paid') {
            $this->checkout->cancel($payment, $booking);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => $booking->getPaymentState()]);
    }

    #[Route('/webhook/{tenant<[a-z0-9][a-z0-9-]{0,62}>}', name: 'todatempo_stripe_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $method = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements', 'enabled' => true]);
        $secret = trim((string) ($method?->getGatewayConfig()?->getConfig()['webhook_secret_key'] ?? ''));
        if ($secret === '') {
            return new JsonResponse(['error' => 'Webhook Stripe non configuré.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->headers->get('Stripe-Signature'), $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return new JsonResponse(['error' => 'Signature Stripe invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->entityManager->persist(new StripeWebhookEvent($event->id, $event->type));
        try {
            // Claim the event before any workflow side effect. The unique index
            // serialises concurrent deliveries as well as later replays.
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $connection->rollBack();
            return new JsonResponse(['received' => true, 'replayed' => true]);
        }

        $object = $event->data->object;
        $metadata = $object->metadata ?? null;
        $payment = $metadata ? $this->entityManager->find(Payment::class, (int) ($metadata->payment_id ?? 0)) : null;
        $booking = $metadata ? $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => (string) ($metadata->booking_token ?? '')]) : null;
        if ($payment instanceof Payment && $booking instanceof Booking) {
            if ($event->type === 'checkout.session.completed' && ($object->payment_status ?? null) === 'paid') {
                $this->checkout->complete($payment, $booking);
            } elseif ($event->type === 'checkout.session.expired') {
                $this->checkout->cancel($payment, $booking);
            } elseif ($event->type === 'checkout.session.async_payment_failed') {
                $this->checkout->fail($payment, $booking);
            }
        }

        try {
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return new JsonResponse(['received' => true]);
    }

    private function payment(Order $order, int $id = 0): ?Payment
    {
        foreach ($order->getPayments() as $candidate) {
            if ($candidate instanceof Payment && ($id === 0 || $candidate->getId() === $id)) return $candidate;
        }
        return null;
    }

    private function returnUrl(Request $request, string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array($parts['scheme'], ['http', 'https'], true) || strcasecmp($parts['host'], $request->getHost()) !== 0) {
            throw new \InvalidArgumentException('URL de retour Stripe invalide.');
        }
        return $url;
    }
}

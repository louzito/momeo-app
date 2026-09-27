<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Entity\StripeWebhookEvent;
use App\Service\Observability\MetricsRegistry;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/** Signed event processing on the tenant EntityManager; gift email queuing shares the transaction. */
final class StripeWebhookProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeCheckout $checkout,
        private readonly BookingEmailDispatcher $emailDispatcher,
        private readonly MetricsRegistry $metrics,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @return array{received: true, replayed?: true} */
    public function process(string $payload, string $signature): array
    {
        $method = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']);
        $secret = trim((string) ($method?->getGatewayConfig()?->getConfig()['webhook_secret_key'] ?? ''));
        if ($secret === '') {
            $this->metrics->increment('webhook_failed', $this->tenantContext->getSlug());
            throw new RejectedStripeWebhook('Webhook Stripe non configuré.', RejectedStripeWebhook::NOT_CONFIGURED);
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            $this->metrics->increment('webhook_failed', $this->tenantContext->getSlug());
            throw new RejectedStripeWebhook('Signature Stripe invalide.', RejectedStripeWebhook::INVALID_SIGNATURE);
        }
        $this->metrics->increment('webhook_received', $this->tenantContext->getSlug());

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->entityManager->persist(new StripeWebhookEvent($event->id, $event->type));
        try {
            // Claim the event before any workflow side effect. The unique index
            // serialises concurrent deliveries as well as later replays.
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $connection->rollBack();
            return ['received' => true, 'replayed' => true];
        }

        $paymentCompleted = false;
        $booking = null;
        try {
            $object = $event->data->object;
            $metadata = $object->metadata ?? null;
            $payment = $metadata ? $this->entityManager->find(Payment::class, (int) ($metadata->payment_id ?? 0)) : null;
            $order = $payment instanceof Payment ? $payment->getOrder() : null;
            if ($order instanceof Order) {
                // Same lock order as GiftCardService; different event IDs for the
                // same payment cannot run the workflow and notifications twice.
                $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
                $bookingToken = (string) ($metadata->booking_token ?? '');
                $booking = $bookingToken !== '' ? $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => $bookingToken]) : null;
                $matches = $order->getTokenValue() === (string) ($metadata->order_token ?? '')
                    && $payment->getMethod()?->getCode() === 'stripe_web_elements'
                    && $payment->getCurrencyCode() === $order->getCurrencyCode()
                    && (int) ($object->amount_total ?? -1) === $payment->getAmount()
                    && strtolower((string) ($object->currency ?? '')) === strtolower((string) $order->getCurrencyCode())
                    && ($bookingToken === '' || ($booking instanceof Booking && $booking->getOrderNumber() === $order->getNumber()));
                if ($matches) {
                    if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && ($object->payment_status ?? null) === 'paid') {
                        $details = $payment->getDetails();
                        $details['stripe_payment_intent'] = (string) ($object->payment_intent ?? '');
                        $payment->setDetails($details);
                        $paymentCompleted = $this->checkout->complete($payment, $booking);
                    } elseif ($event->type === 'checkout.session.expired') {
                        $this->checkout->cancel($payment, $booking);
                    } elseif ($event->type === 'checkout.session.async_payment_failed') {
                        $this->checkout->fail($payment, $booking);
                        $this->metrics->increment('reservation_failed', $this->tenantContext->getSlug());
                    }
                }
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->metrics->increment('webhook_failed', $this->tenantContext->getSlug());
            throw $exception;
        }

        if ($paymentCompleted && $booking instanceof Booking) {
            $this->emailDispatcher->paymentConfirmation($booking);
        }

        return ['received' => true];
    }
}

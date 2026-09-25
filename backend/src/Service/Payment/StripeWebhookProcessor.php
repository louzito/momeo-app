<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Entity\StripeWebhookEvent;
use App\Service\Observability\MetricsRegistry;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/** Signed event processing on the tenant EntityManager; notifications follow commit. */
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
        $method = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements', 'enabled' => true]);
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

        $object = $event->data->object;
        $metadata = $object->metadata ?? null;
        $payment = $metadata ? $this->entityManager->find(Payment::class, (int) ($metadata->payment_id ?? 0)) : null;
        $booking = $metadata ? $this->entityManager->getRepository(Booking::class)->findOneBy(['publicToken' => (string) ($metadata->booking_token ?? '')]) : null;
        $paymentCompleted = false;
        if ($payment instanceof Payment && $booking instanceof Booking) {
            if ($event->type === 'checkout.session.completed' && ($object->payment_status ?? null) === 'paid') {
                $details = $payment->getDetails();
                $details['stripe_payment_intent'] = (string) ($object->payment_intent ?? '');
                $payment->setDetails($details);
                $this->checkout->complete($payment, $booking);
                $paymentCompleted = true;
            } elseif ($event->type === 'checkout.session.expired') {
                $this->checkout->cancel($payment, $booking);
            } elseif ($event->type === 'checkout.session.async_payment_failed') {
                $this->checkout->fail($payment, $booking);
                $this->metrics->increment('reservation_failed', $this->tenantContext->getSlug());
            }
        }

        try {
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

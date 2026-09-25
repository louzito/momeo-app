<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Payment\PaymentNotFound;
use App\Service\Payment\RejectedStripeWebhook;
use App\Service\Payment\StripePaymentService;
use App\Service\Payment\StripeSessionUnavailable;
use App\Service\Payment\StripeWebhookProcessor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop/payments/stripe')]
final class ShopStripePaymentController
{
    public function __construct(
        private readonly StripePaymentService $payments,
        private readonly StripeWebhookProcessor $webhooks,
    ) {}

    #[Route('/checkout-session', name: 'todatempo_stripe_checkout_session', methods: ['POST'])]
    public function session(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];
        try {
            return new JsonResponse($this->payments->session($data, $request->getHost()), Response::HTTP_CREATED);
        } catch (PaymentNotFound $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (StripeSessionUnavailable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/cancel/{bookingToken<[0-9a-f]{32}>}', name: 'todatempo_stripe_cancel', methods: ['POST'])]
    public function cancel(string $bookingToken): JsonResponse
    {
        try {
            return new JsonResponse($this->payments->cancel($bookingToken));
        } catch (PaymentNotFound $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/webhook/{tenant<[a-z0-9][a-z0-9-]{0,62}>}', name: 'todatempo_stripe_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->webhooks->process($request->getContent(), (string) $request->headers->get('Stripe-Signature')));
        } catch (RejectedStripeWebhook $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], $exception->getCode() === RejectedStripeWebhook::NOT_CONFIGURED
                ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_BAD_REQUEST);
        }
    }
}

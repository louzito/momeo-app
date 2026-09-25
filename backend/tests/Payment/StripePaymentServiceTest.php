<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Controller\ShopStripePaymentController;
use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Payment\GatewayConfig;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Service\Payment\StripeCheckout;
use App\Service\Payment\StripePaymentService;
use App\Service\Payment\StripeWebhookProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

final class StripePaymentServiceTest extends TestCase
{
    private function fixture(int $paymentAmount = 2500, int $bookingAmount = 2500, string $state = 'pending'): array
    {
        $payment = new Payment();
        $payment->setAmount($paymentAmount);
        $method = new PaymentMethod();
        $method->setCode('stripe_web_elements');
        $gateway = new GatewayConfig();
        $gateway->setConfig(['secret_key' => 'sk_test_contract_only']);
        $method->setGatewayConfig($gateway);
        $payment->setMethod($method);
        $booking = new Booking();
        $booking->setOrderNumber('ORDER106');
        $booking->setPublicToken(str_repeat('a', 32));
        $booking->setAmount($bookingAmount);
        $booking->setPaymentState($state);
        $booking->setStatus($state === 'paid' ? Booking::STATUS_CONFIRMED : Booking::STATUS_AWAITING_PAYMENT);
        $order = $this->createMock(Order::class);
        $order->method('getNumber')->willReturn('ORDER106');
        $order->method('getTokenValue')->willReturn('order-token');
        $order->method('getTotal')->willReturn(2500);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getPayments')->willReturn(new ArrayCollection([$payment]));
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('findOneBy')->willReturn($order);
        $bookingRepository = $this->createMock(EntityRepository::class);
        $bookingRepository->method('findOneBy')->with(['publicToken' => $booking->getPublicToken()])->willReturn($booking);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([[Order::class, $orderRepository], [Booking::class, $bookingRepository]]);
        $workflow = $this->createMock(WorkflowInterface::class);
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->with($payment, 'sylius_payment')->willReturn($workflow);
        $service = new StripePaymentService($em, new StripeCheckout($registry));
        // This controller path cannot invoke the webhook; no provider or email dependency is used.
        $webhooks = (new \ReflectionClass(StripeWebhookProcessor::class))->newInstanceWithoutConstructor();
        return [new ShopStripePaymentController($service, $webhooks), $booking, $payment, $em, $workflow];
    }

    public static function rejectedSessions(): iterable
    {
        yield 'payment amount mismatch' => [2400, 2500, 'https://example.test/ok', 0, 'Le montant du paiement ne correspond pas à la réservation.'];
        yield 'booking amount mismatch' => [2500, 2400, 'https://example.test/ok', 0, 'Le montant du paiement ne correspond pas à la réservation.'];
        yield 'other payment id' => [2500, 2500, 'https://example.test/ok', 99, 'Le paiement Stripe est invalide.'];
        yield 'foreign return host' => [2500, 2500, 'https://foreign.test/ok', 0, 'URL de retour Stripe invalide.'];
        yield 'relative return url' => [2500, 2500, '/ok', 0, 'URL de retour Stripe invalide.'];
        yield 'invalid scheme' => [2500, 2500, 'ftp://example.test/ok', 0, 'URL de retour Stripe invalide.'];
    }

    #[DataProvider('rejectedSessions')]
    public function testSessionRejectsBeforeProvider(int $paymentAmount, int $bookingAmount, string $url, int $paymentId, string $error): void
    {
        [$controller, $booking, , $em, $workflow] = $this->fixture($paymentAmount, $bookingAmount);
        $em->expects(self::never())->method('flush');
        $workflow->expects(self::never())->method('apply');
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($client);
        try {
            $response = $controller->session($this->request($booking, $url, $paymentId));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['error' => $error], json_decode($response->getContent(), true));
            self::assertSame('pending', $booking->getPaymentState());
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }
    }

    public function testSessionKeepsCentsCurrencyReturnUrlsAndProviderIdempotencyWithoutConfirming(): void
    {
        [$controller, $booking, , $em, $workflow] = $this->fixture();
        $em->expects(self::never())->method('flush');
        $workflow->expects(self::never())->method('apply');
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::exactly(2))->method('request')->willReturnCallback(static function ($method, $url, $headers, $parameters): array {
            self::assertSame('post', strtolower($method));
            self::assertStringEndsWith('/v1/checkout/sessions', $url);
            self::assertContains('Idempotency-Key: todatempo-order-ORDER106', $headers);
            self::assertSame(2500, $parameters['line_items'][0]['price_data']['unit_amount']);
            self::assertSame('eur', $parameters['line_items'][0]['price_data']['currency']);
            self::assertSame('https://EXAMPLE.test/ok', $parameters['success_url']);
            self::assertSame('https://example.test/cancel', $parameters['cancel_url']);
            self::assertSame('order-token', $parameters['metadata']['order_token']);
            return ['{"id":"cs_contract","object":"checkout.session","url":"https://checkout.example.test/session"}', 200, []];
        });
        ApiRequestor::setHttpClient($client);
        try {
            for ($i = 0; $i < 2; ++$i) {
                $response = $controller->session($this->request($booking, 'https://EXAMPLE.test/ok'));
                self::assertSame(201, $response->getStatusCode());
                self::assertSame(['id' => 'cs_contract', 'url' => 'https://checkout.example.test/session'], json_decode($response->getContent(), true));
                self::assertSame('pending', $booking->getPaymentState(), 'Only the signed webhook confirms payment.');
                self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $booking->getStatus());
            }
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }
    }

    public static function cancellationStates(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'paid is preserved' => ['paid'];
    }

    #[DataProvider('cancellationStates')]
    public function testCancellation(string $state): void
    {
        [$controller, $booking, $payment, $em, $workflow] = $this->fixture(state: $state);
        $em->expects($state === 'paid' ? self::never() : self::once())->method('flush');
        $workflow->expects($state === 'paid' ? self::never() : self::once())->method('can')->with($payment, 'cancel')->willReturn(true);
        $workflow->expects($state === 'paid' ? self::never() : self::once())->method('apply')->with($payment, 'cancel')->willReturn(new Marking());
        $response = $controller->cancel($booking->getPublicToken());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => $state === 'paid' ? 'paid' : 'cancelled'], json_decode($response->getContent(), true));
        self::assertSame($state === 'paid' ? Booking::STATUS_CONFIRMED : Booking::STATUS_CANCELLED, $booking->getStatus());
    }

    public function testProviderFailureKeepsTheExistingGatewayError(): void
    {
        [$controller, $booking] = $this->fixture();
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willThrowException(new \RuntimeException('Provider unavailable'));
        ApiRequestor::setHttpClient($client);
        try {
            $response = $controller->session($this->request($booking, 'https://example.test/ok'));
            self::assertSame(502, $response->getStatusCode());
            self::assertSame(['error' => 'Stripe est momentanément indisponible. Réessayez.'], json_decode($response->getContent(), true));
            self::assertSame('pending', $booking->getPaymentState());
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }
    }

    public function testMissingTokensKeepNotFoundResponses(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects(self::never())->method('flush');
        $controller = new ShopStripePaymentController(
            new StripePaymentService($em, new StripeCheckout($this->createMock(Registry::class))),
            (new \ReflectionClass(StripeWebhookProcessor::class))->newInstanceWithoutConstructor(),
        );
        $response = $controller->session(Request::create('https://example.test/', 'POST', content: '{invalid'));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Commande ou réservation introuvable.'], json_decode($response->getContent(), true));
        $response = $controller->cancel(str_repeat('a', 32));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Paiement introuvable.'], json_decode($response->getContent(), true));
    }

    private function request(Booking $booking, string $url, int $paymentId = 0): Request
    {
        return Request::create('https://example.test/api/v2/shop/payments/stripe/checkout-session', 'POST', content: json_encode([
            'orderToken' => 'order-token', 'bookingToken' => $booking->getPublicToken(), 'paymentId' => $paymentId,
            'successUrl' => $url, 'cancelUrl' => 'https://example.test/cancel',
        ], JSON_THROW_ON_ERROR));
    }
}

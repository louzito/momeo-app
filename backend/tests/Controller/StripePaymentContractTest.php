<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\Availability\CenterTimeZoneProvider;
use App\Controller\ShopStripePaymentController;
use App\Service\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Channel\Channel;
use App\Entity\Payment\GatewayConfig;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Entity\StripeWebhookEvent;
use App\Service\Observability\MetricsRegistry;
use App\Service\Payment\StripeCheckout;
use App\Service\Payment\StripePaymentService;
use App\Service\Payment\StripeWebhookProcessor;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use App\Service\Tenant\TenantUrlGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/** Real signature verification and application code; only persistence, workflow and email I/O are doubled. */
final class StripePaymentContractTest extends TestCase
{
    private string $metricsFile;

    protected function setUp(): void
    {
        $this->metricsFile = tempnam(sys_get_temp_dir(), 'stripe-contract-');
    }

    protected function tearDown(): void
    {
        unlink($this->metricsFile);
    }

    public static function events(): iterable
    {
        yield 'signed paid' => ['checkout.session.completed', 'paid', true, false, 'paid', Booking::STATUS_CONFIRMED, 'complete'];
        yield 'successful delivery followed by replay' => ['checkout.session.completed', 'paid', true, false, 'paid', Booking::STATUS_CONFIRMED, 'complete', 'pending', true, true];
        yield 'commit failure sends no email' => ['checkout.session.completed', 'paid', true, false, 'paid', Booking::STATUS_CONFIRMED, 'complete', 'pending', true, false, true];
        yield 'asynchronous success' => ['checkout.session.async_payment_succeeded', 'paid', true, false, 'paid', Booking::STATUS_CONFIRMED, 'complete'];
        yield 'signed unpaid' => ['checkout.session.completed', 'unpaid', true, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
        yield 'invalid signature' => ['checkout.session.completed', 'paid', false, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
        yield 'replayed paid event' => ['checkout.session.completed', 'paid', true, true, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
        yield 'expired' => ['checkout.session.expired', 'unpaid', true, false, 'cancelled', Booking::STATUS_CANCELLED, 'cancel'];
        yield 'failed' => ['checkout.session.async_payment_failed', 'unpaid', true, false, 'failed', Booking::STATUS_CANCELLED, 'fail'];
        yield 'late expiry preserves paid' => ['checkout.session.expired', 'unpaid', true, false, 'paid', Booking::STATUS_AWAITING_PAYMENT, null, 'paid', false];
        yield 'late failure preserves paid' => ['checkout.session.async_payment_failed', 'unpaid', true, false, 'paid', Booking::STATUS_AWAITING_PAYMENT, null, 'paid', false];
        yield 'workflow refusal preserves state' => ['checkout.session.completed', 'paid', true, false, 'cancelled', Booking::STATUS_AWAITING_PAYMENT, 'complete', 'cancelled', false];
        yield 'second distinct paid event does not notify again' => ['checkout.session.completed', 'paid', true, false, 'paid', Booking::STATUS_AWAITING_PAYMENT, null, 'paid', false];
        foreach (['products', 'gift'] as $kind) {
            yield "$kind success" => ['checkout.session.completed', 'paid', true, false, 'paid', Booking::STATUS_AWAITING_PAYMENT, 'complete', 'pending', true, false, false, $kind];
            yield "$kind duplicate webhook" => ['checkout.session.completed', 'paid', true, false, 'paid', Booking::STATUS_AWAITING_PAYMENT, 'complete', 'pending', true, true, false, $kind];
            yield "$kind expiry" => ['checkout.session.expired', 'unpaid', true, false, 'cancelled', Booking::STATUS_AWAITING_PAYMENT, 'cancel', 'pending', true, false, false, $kind];
            yield "$kind failure" => ['checkout.session.async_payment_failed', 'unpaid', true, false, 'failed', Booking::STATUS_AWAITING_PAYMENT, 'fail', 'pending', true, false, false, $kind];
        }
        yield 'wrong order token' => ['checkout.session.completed', 'paid', true, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null, 'pending', true, false, false, 'products', 'foreign-token'];
        yield 'wrong currency' => ['checkout.session.completed', 'paid', true, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null, 'pending', true, false, false, 'products', 'order-token', 'usd'];
        yield 'wrong amount' => ['checkout.session.completed', 'paid', true, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null, 'pending', true, false, false, 'products', 'order-token', 'eur', 1];
        yield 'unrelated event' => ['payment_intent.created', 'paid', true, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
    }

    #[DataProvider('events')]
    public function testWebhookOutcome(string $type, string $paymentStatus, bool $signed, bool $replayed, string $expectedPayment, string $expectedBooking, ?string $transition, string $initialPayment = 'pending', bool $canTransition = true, bool $deliverTwice = false, bool $commitFails = false, string $kind = 'booking', string $orderToken = 'order-token', string $currency = 'eur', int $amount = 2500): void
    {
        $booking = new Booking();
        $booking->setPublicToken(str_repeat('a', 32));
        $booking->setCustomerEmail('customer@example.test');
        $booking->setStatus(Booking::STATUS_AWAITING_PAYMENT);
        $booking->setPaymentState($initialPayment);
        $booking->setOrderNumber('ORDER119');
        $payment = new Payment();
        $payment->setAmount(2500);
        $payment->setCurrencyCode('EUR');
        $payment->setState($initialPayment === 'paid' ? 'completed' : $initialPayment);
        $order = new Order();
        $order->setNumber('ORDER119');
        $order->setTokenValue('order-token');
        $order->setCurrencyCode('EUR');
        $order->addPayment($payment);
        if ($kind === 'products') $order->setFulfillmentMode('pickup');
        if ($kind === 'gift') $order->setGiftCardAmount(2500);
        $gateway = new GatewayConfig();
        $gateway->setConfig(['webhook_secret_key' => 'whsec_contract_only']);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gateway);
        $method->setCode('stripe_web_elements');
        $payment->setMethod($method);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $methodRepository = $this->createMock(EntityRepository::class);
        $methodRepository->method('findOneBy')->with(['code' => 'stripe_web_elements'])->willReturn($method);
        $bookingRepository = $this->createMock(EntityRepository::class);
        $bookingRepository->expects($signed && !$replayed && $kind === 'booking' ? self::once() : self::never())->method('findOneBy')
            ->with(['publicToken' => $booking->getPublicToken()])->willReturn($booking);
        $channelRepository = $this->createMock(EntityRepository::class);
        $channelRepository->method('findOneBy')->willReturn(new Channel());
        $configRepository = $this->createMock(EntityRepository::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class) => match ($class) {
            PaymentMethod::class => $methodRepository,
            Booking::class => $bookingRepository,
            Channel::class => $channelRepository,
            default => $configRepository,
        });
        $entityManager->expects($signed && !$replayed ? self::once() : self::never())->method('find')->with(Payment::class, 42)->willReturn($payment);
        $connection = $this->createMock(Connection::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $connection->expects($signed ? self::exactly($deliverTwice ? 2 : 1) : self::never())->method('beginTransaction');
        $committed = false;
        $connection->expects($signed && !$replayed ? self::once() : self::never())->method('commit')->willReturnCallback(static function () use (&$committed, $commitFails): bool { if ($commitFails) { throw new \RuntimeException('Commit failed'); } $committed = true; return true; });
        $connection->expects($replayed || $deliverTwice || $commitFails ? self::once() : self::never())->method('rollBack');
        $entityManager->expects($signed ? self::exactly($deliverTwice ? 2 : 1) : self::never())->method('persist')->with(self::callback(
            static fn (mixed $event): bool => $event instanceof StripeWebhookEvent && $event->getEventId() === 'evt_contract',
        ));
        $flush = $entityManager->expects($signed ? self::exactly($deliverTwice ? 3 : ($replayed ? 1 : 2)) : self::never())->method('flush');
        if ($replayed || $deliverTwice) {
            $driverException = new class('Duplicate event') extends \RuntimeException implements DriverException {
                public function getSQLState(): ?string { return '23000'; }
            };
            $flushCount = 0;
            $flush->willReturnCallback(static function () use (&$flushCount, $deliverTwice, $driverException): void {
                if (++$flushCount === ($deliverTwice ? 3 : 1)) {
                    throw new UniqueConstraintViolationException($driverException, null);
                }
            });
        }
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects($transition !== null ? self::once() : self::never())->method('can')->with($payment, $transition)->willReturn($canTransition);
        $workflow->expects($transition !== null && $canTransition ? self::once() : self::never())->method('apply')->with($payment, $transition)->willReturnCallback(static function () use ($payment, $transition) {
            $payment->setState(['complete' => 'completed', 'cancel' => 'cancelled', 'fail' => 'failed'][$transition]);
            return new \Symfony\Component\Workflow\Marking();
        });
        $workflows = $this->createMock(Registry::class);
        $workflows->method('get')->with($payment, 'sylius_payment')->willReturn($workflow);
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($transition === 'complete' && $canTransition && !$commitFails && $kind === 'booking' ? self::once() : self::never())->method('send')
            ->with('payment_confirmation', ['customer@example.test'], self::callback(static function (array $data) use (&$committed, $booking): bool {
                self::assertTrue($committed, 'Email must follow the commit.');
                return $data['booking'] === $booking;
            }));
        $registry = new TenantRegistry($this->metricsFile.'.absent', false);
        $tenant = new TenantContext($registry, new TenantIdentifierResolver(), 'contract');
        $tenant->setSlug('contract');
        $emails = new BookingEmailDispatcher($sender, $entityManager, $tenant, new CenterTimeZoneProvider($entityManager), new TenantUrlGenerator($registry, 'https://example.test'));
        $checkout = new StripeCheckout($workflows);
        $controller = new ShopStripePaymentController(new StripePaymentService($entityManager, $checkout), new StripeWebhookProcessor($entityManager, $checkout, $emails, new MetricsRegistry($this->metricsFile), $tenant));
        $payload = json_encode(['id' => 'evt_contract', 'object' => 'event', 'type' => $type, 'data' => ['object' => [
            'amount_total' => $amount, 'currency' => $currency, 'object' => 'checkout.session', 'payment_status' => $paymentStatus, 'payment_intent' => 'pi_contract',
            'metadata' => ['order_token' => $orderToken, 'payment_id' => '42', 'booking_token' => $kind === 'booking' ? $booking->getPublicToken() : ''],
        ]]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $signed ? 'whsec_contract_only' : 'wrong_secret');
        $request = Request::create('/api/v2/shop/payments/stripe/webhook/contract', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], content: $payload);

        if ($commitFails) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Commit failed');
        }
        $response = $controller->webhook($request);

        self::assertSame($signed ? 200 : 400, $response->getStatusCode());
        self::assertSame($signed ? ($replayed ? ['received' => true, 'replayed' => true] : ['received' => true]) : ['error' => 'Signature Stripe invalide.'], json_decode($response->getContent(), true));
        if ($kind === 'booking') self::assertSame($expectedPayment, $booking->getPaymentState());
        else self::assertSame($expectedPayment === 'paid' ? 'completed' : $expectedPayment, $payment->getState());
        self::assertSame($expectedBooking, $booking->getStatus());
        self::assertSame(($transition === 'complete' || $initialPayment === 'paid' && $type === 'checkout.session.completed') ? 'pi_contract' : null, $payment->getDetails()['stripe_payment_intent'] ?? null);
        if ($deliverTwice) {
            $replayResponse = $controller->webhook($request);
            self::assertSame(200, $replayResponse->getStatusCode());
            self::assertSame(['received' => true, 'replayed' => true], json_decode($replayResponse->getContent(), true));
            self::assertSame('completed', $payment->getState());
            if ($kind === 'booking') {
                self::assertSame('paid', $booking->getPaymentState());
                self::assertSame(Booking::STATUS_CONFIRMED, $booking->getStatus());
            }
        }
    }
}

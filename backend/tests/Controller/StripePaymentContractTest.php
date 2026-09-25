<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\Availability\CenterTimeZoneProvider;
use App\Controller\ShopStripePaymentController;
use App\Service\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Entity\Payment\GatewayConfig;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Entity\StripeWebhookEvent;
use App\Observability\MetricsRegistry;
use App\Service\Payment\StripeCheckout;
use App\Tenant\TenantContext;
use App\Tenant\TenantIdentifierResolver;
use App\Tenant\TenantRegistry;
use App\Tenant\TenantUrlGenerator;
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
        yield 'signed unpaid' => ['checkout.session.completed', 'unpaid', true, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
        yield 'invalid signature' => ['checkout.session.completed', 'paid', false, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
        yield 'replayed paid event' => ['checkout.session.completed', 'paid', true, true, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
        yield 'expired' => ['checkout.session.expired', 'unpaid', true, false, 'cancelled', Booking::STATUS_CANCELLED, 'cancel'];
        yield 'failed' => ['checkout.session.async_payment_failed', 'unpaid', true, false, 'failed', Booking::STATUS_CANCELLED, 'fail'];
        yield 'unrelated event' => ['payment_intent.created', 'paid', true, false, 'pending', Booking::STATUS_AWAITING_PAYMENT, null];
    }

    #[DataProvider('events')]
    public function testWebhookOutcome(string $type, string $paymentStatus, bool $signed, bool $replayed, string $expectedPayment, string $expectedBooking, ?string $transition): void
    {
        $booking = new Booking();
        $booking->setPublicToken(str_repeat('a', 32));
        $booking->setCustomerEmail('customer@example.test');
        $booking->setStatus(Booking::STATUS_AWAITING_PAYMENT);
        $booking->setPaymentState('pending');
        $payment = new Payment();
        $gateway = new GatewayConfig();
        $gateway->setConfig(['webhook_secret_key' => 'whsec_contract_only']);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gateway);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $methodRepository = $this->createMock(EntityRepository::class);
        $methodRepository->method('findOneBy')->with(['code' => 'stripe_web_elements', 'enabled' => true])->willReturn($method);
        $bookingRepository = $this->createMock(EntityRepository::class);
        $bookingRepository->expects($signed && !$replayed ? self::once() : self::never())->method('findOneBy')
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
        $connection->expects($signed ? self::once() : self::never())->method('beginTransaction');
        $committed = false;
        $connection->expects($signed && !$replayed ? self::once() : self::never())->method('commit')->willReturnCallback(static function () use (&$committed): bool { $committed = true; return true; });
        $connection->expects($replayed ? self::once() : self::never())->method('rollBack');
        $entityManager->expects($signed ? self::once() : self::never())->method('persist')->with(self::callback(
            static fn (mixed $event): bool => $event instanceof StripeWebhookEvent && $event->getEventId() === 'evt_contract',
        ));
        $flush = $entityManager->expects($signed ? self::exactly($replayed ? 1 : 2) : self::never())->method('flush');
        if ($replayed) {
            $driverException = new class('Duplicate event') extends \RuntimeException implements DriverException {
                public function getSQLState(): ?string { return '23000'; }
            };
            $flush->willThrowException(new UniqueConstraintViolationException($driverException, null));
        }
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects($transition !== null ? self::once() : self::never())->method('can')->with($payment, $transition)->willReturn(true);
        $workflow->expects($transition !== null ? self::once() : self::never())->method('apply')->with($payment, $transition)->willReturn(new \Symfony\Component\Workflow\Marking());
        $workflows = $this->createMock(Registry::class);
        $workflows->method('get')->with($payment, 'sylius_payment')->willReturn($workflow);
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($transition === 'complete' ? self::once() : self::never())->method('send')
            ->with('payment_confirmation', ['customer@example.test'], self::callback(static function (array $data) use (&$committed, $booking): bool {
                self::assertTrue($committed, 'Email must follow the commit.');
                return $data['booking'] === $booking;
            }));
        $registry = new TenantRegistry($this->metricsFile.'.absent', false);
        $tenant = new TenantContext($registry, new TenantIdentifierResolver(), 'contract');
        $tenant->setSlug('contract');
        $emails = new BookingEmailDispatcher($sender, $entityManager, $tenant, new CenterTimeZoneProvider($entityManager), new TenantUrlGenerator($registry, 'https://example.test'));
        $controller = new ShopStripePaymentController($entityManager, new StripeCheckout($workflows), $emails, new MetricsRegistry($this->metricsFile), $tenant);
        $payload = json_encode(['id' => 'evt_contract', 'object' => 'event', 'type' => $type, 'data' => ['object' => [
            'object' => 'checkout.session', 'payment_status' => $paymentStatus, 'payment_intent' => 'pi_contract',
            'metadata' => ['payment_id' => '42', 'booking_token' => $booking->getPublicToken()],
        ]]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $signed ? 'whsec_contract_only' : 'wrong_secret');
        $request = Request::create('/api/v2/shop/payments/stripe/webhook/contract', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], content: $payload);

        $response = $controller->webhook($request);

        self::assertSame($signed ? 200 : 400, $response->getStatusCode());
        self::assertSame($signed ? ($replayed ? ['received' => true, 'replayed' => true] : ['received' => true]) : ['error' => 'Signature Stripe invalide.'], json_decode($response->getContent(), true));
        self::assertSame($expectedPayment, $booking->getPaymentState());
        self::assertSame($expectedBooking, $booking->getStatus());
        self::assertSame($transition === 'complete' ? 'pi_contract' : null, $payment->getDetails()['stripe_payment_intent'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order\Order;
use App\Entity\Payment\GatewayConfig;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Service\GiftVoucher\GiftOrderMarker;
use App\Service\Payment\StripeCheckout;
use App\Service\Payment\StripePaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\Workflow\Registry;

final class ShopStripePaymentTest extends TestCase
{
    public static function orders(): iterable
    {
        yield 'products' => ['products', 0];
        yield 'legacy gift' => ['voucher', 0];
        yield 'monetary gift' => ['gift', 0];
        yield 'future split payment' => ['products', 6000];
    }

    private function fixture(string $kind, int $credit = 0): array
    {
        $order = $this->getMockBuilder(Order::class)->onlyMethods(['getTotal'])->getMock();
        $order->method('getTotal')->willReturn(10000);
        $order->setCurrencyCode('EUR');
        $order->setNumber('SHOP119');
        $order->setTokenValue('private-order-token');
        $order->setCheckoutState('completed');
        if ($kind === 'products') $order->setFulfillmentMode('pickup');
        if ($kind === 'gift') $order->setGiftCardAmount(10000);
        if ($kind === 'voucher') $order->setNotes(GiftOrderMarker::create(null, 'recipient@example.test', null)->encode());
        $payment = new Payment();
        (new \ReflectionProperty(\Sylius\Component\Payment\Model\Payment::class, 'id'))->setValue($payment, 42);
        $payment->setAmount(10000 - $credit);
        $payment->setCurrencyCode('EUR');
        $payment->setState('new');
        $method = new PaymentMethod();
        $method->setCode('stripe_web_elements');
        $method->setCurrentLocale('fr_FR');
        $method->setFallbackLocale('fr_FR');
        $method->setEnabled(true);
        $gateway = new GatewayConfig();
        $gateway->setConfig(['secret_key' => 'sk_test_contract_only']);
        $method->setGatewayConfig($gateway);
        $payment->setMethod($method);
        $order->addPayment($payment);
        if ($credit) {
            $covered = new Payment();
            $covered->setAmount($credit);
            $covered->setCurrencyCode('EUR');
            $covered->setState('completed');
            $order->addPayment($covered);
        }
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['tokenValue' => 'private-order-token'])->willReturn($order);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Order::class)->willReturn($repository);
        $em->expects(self::never())->method('flush');
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('get');
        return [new StripePaymentService($em, new StripeCheckout($registry)), $payment, $method];
    }

    #[DataProvider('orders')]
    public function testCheckoutNeedsNoBookingAndReturnCannotConfirm(string $kind, int $credit): void
    {
        [$service, $payment] = $this->fixture($kind, $credit);
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willReturnCallback(static function ($method, $url, $headers, $parameters) use ($credit): array {
            self::assertSame('', $parameters['metadata']['booking_token']);
            self::assertSame('private-order-token', $parameters['metadata']['order_token']);
            self::assertSame(10000 - $credit, $parameters['line_items'][0]['price_data']['unit_amount']);
            self::assertContains('Idempotency-Key: todatempo-payment-private-order-token-42', $headers);
            return ['{"id":"cs_shop","object":"checkout.session","url":"https://checkout.example.test/pay"}', 200, []];
        });
        ApiRequestor::setHttpClient($client);
        try {
            $service->session($this->request(), 'example.test');
            self::assertSame('new', $payment->getState());
            self::assertNotSame('paid', $service->status('private-order-token')['status']);
            $payment->setState('completed');
            self::assertSame('paid', $service->status('private-order-token')['status']);
        } finally { ApiRequestor::setHttpClient(CurlClient::instance()); }
    }

    public function testDisabledStripeIsRejected(): void
    {
        [$service, , $method] = $this->fixture('products');
        $method->setEnabled(false);
        $this->expectException(\DomainException::class);
        $service->session($this->request(), 'example.test');
    }

    public function testMonetaryGiftCannotBePartiallyCharged(): void
    {
        [$service] = $this->fixture('gift', 6000);
        $this->expectException(\DomainException::class);
        $service->session($this->request(), 'example.test');
    }

    private function request(): array
    {
        return ['orderToken' => 'private-order-token', 'paymentId' => 42,
            'successUrl' => 'https://example.test/confirmation?payment=success',
            'cancelUrl' => 'https://example.test/confirmation?payment=cancelled'];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\GiftCard;

use App\Entity\Channel\Channel;
use App\Entity\Currency\Currency;
use App\Entity\Customer\Customer;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Entity\Taxonomy\Taxon;
use App\Service\GiftCard\GiftCardCheckoutService;
use App\Service\GiftVoucher\GiftVoucherConfig;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantRegistry;
use App\Service\Tenant\TenantUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class GiftCardCheckoutServiceTest extends TestCase
{
    private array $persisted = [];

    private function service(bool $enabled = true): GiftCardCheckoutService
    {
        $currency = new Currency();
        $currency->setCode('EUR');
        $channel = new Channel();
        $channel->setCode('FASHION_WEB');
        $channel->setName('Boutique démo');
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $method = new PaymentMethod();
        $method->setCode('bank_transfer');
        $method->setEnabled(true);
        $method->addChannel($channel);
        $taxon = new Taxon();
        $taxon->setCurrentLocale('en_US');
        $taxon->setFallbackLocale('en_US');
        $taxon->setDescription(json_encode(['giftVouchersEnabled' => $enabled, 'giftVoucherValidityMonths' => 6], JSON_THROW_ON_ERROR));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $operation) => $operation());
        $em->method('persist')->willReturnCallback(function (object $object): void { $this->persisted[] = $object; });
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($channel, $method, $taxon): EntityRepository {
            $repository = $this->createMock(EntityRepository::class);
            $repository->method('findOneBy')->willReturn(match ($class) { Channel::class => $channel, Taxon::class => $taxon, default => null });
            $repository->method('findBy')->willReturn($class === PaymentMethod::class ? [$method] : []);
            return $repository;
        });
        $tenant = (new \ReflectionClass(TenantContext::class))->newInstanceWithoutConstructor();
        $tenant->setSlug('demo');
        $urls = new TenantUrlGenerator(new TenantRegistry(__DIR__.'/absent-registry.json', false), 'https://example.test');
        return new GiftCardCheckoutService($em, new GiftVoucherConfig($em), $tenant, $urls);
    }

    public function testDedicatedOrderHasExactPriceAndNoBookingOrCardBeforePayment(): void
    {
        $service = $this->service();
        $data = GiftCardPurchaseTest::purchase() + ['shopUrl' => 'https://evil.test', 'documentUrl' => 'https://evil.test'];
        $order = $service->purchase(7550, $data, 'bank_transfer');
        self::assertSame(7550, $order->getTotal());
        self::assertSame($order->getTotal(), $order->getGiftCardAmount());
        self::assertSame('completed', $order->getCheckoutState());
        self::assertNotNull($order->getCheckoutCompletedAt());
        self::assertCount(0, $order->getItems());
        self::assertNull($order->getNotes());
        $payment = $order->getPayments()->first();
        self::assertSame(7550, $payment->getAmount());
        self::assertSame('new', $payment->getState());
        self::assertSame('EUR', $payment->getCurrencyCode());
        self::assertSame(6, $order->getGiftCardPurchase()['validityMonths']);
        self::assertSame('https://example.test/demo/shop', $order->getGiftCardPurchase()['shopUrl']);
        self::assertSame('https://example.test/demo/gift-card/print', $order->getGiftCardPurchase()['documentUrl']);
        self::assertSame([Customer::class, Order::class, Payment::class], array_map(static fn (object $value): string => $value::class, $this->persisted));
    }

    public function testDisabledSalesRejectCreation(): void
    {
        $service = $this->service(false);
        self::assertFalse($service->offer()['enabled']);
        $this->expectException(\DomainException::class);
        try { $service->purchase(5000, GiftCardPurchaseTest::purchase(), 'bank_transfer'); }
        finally { self::assertSame([], $this->persisted); }
    }

    public function testUnavailablePaymentMethodRejectsCreation(): void
    {
        $this->expectException(\DomainException::class);
        $this->service()->purchase(5000, GiftCardPurchaseTest::purchase(), 'cash_on_delivery');
    }
}

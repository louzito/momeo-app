<?php

declare(strict_types=1);
namespace App\Tests\GiftCard;

use App\Entity\Channel\Channel;
use App\Entity\GiftCard;
use App\Entity\Order\{Order, OrderItem, Adjustment};
use App\Entity\Payment\{Payment, PaymentMethod, GatewayConfig};
use App\Service\GiftCard\GiftCardPaymentService;
use App\Service\Payment\RefundService;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;

/** Transactions et workflows réels ; aucun appel externe de paiement. */
final class GiftCardPaymentIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GiftCardPaymentService $service;
    private Channel $channel;

    protected function setUp(): void
    {
        self::bootKernel();
        // Le moteur PDF est un processus externe, hors du paiement testé ici.
        $pdf = $this->createMock(\Sylius\InvoicingPlugin\Generator\InvoicePdfFileGeneratorInterface::class);
        $pdf->method('generate')->willReturnCallback(static fn ($invoice) => new \Sylius\InvoicingPlugin\Model\InvoicePdf((new \Sylius\InvoicingPlugin\Generator\InvoiceFileNameGenerator())->generateForPdf($invoice), '%PDF-test'));
        self::getContainer()->set('sylius_invoicing.generator.invoice_pdf_file', $pdf);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->service = self::getContainer()->get(GiftCardPaymentService::class);
        $this->channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']);
        if (!$method instanceof PaymentMethod) {
            $gateway = new GatewayConfig(); $gateway->setGatewayName('stripe_web_elements'); $gateway->setFactoryName('stripe'); $gateway->setConfig(['secret_key' => 'sk_test_local_only']);
            $method = new PaymentMethod(); $method->setCode('stripe_web_elements'); $method->setGatewayConfig($gateway); $method->setCurrentLocale('fr_FR'); $method->setFallbackLocale('fr_FR'); $method->setName('Stripe test');
            $this->em->persist($gateway); $this->em->persist($method);
        }
        $method->getGatewayConfig()->setConfig(['secret_key' => 'sk_test_local_only']);
        $method->setEnabled(true); $method->addChannel($this->channel); $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function fixture(int $credit, int $due, int $later = 0): array
    {
        $suffix = bin2hex(random_bytes(8));
        $card = new GiftCard(self::getContainer()->get(TenantContext::class)->getSlug(), $this->channel->getCode(), 'EUR', $credit, 'ISSUE-'.$suffix, new \DateTimeImmutable('+1 year'));
        $order = new Order(); $order->setChannel($this->channel); $order->setCurrencyCode('EUR'); $order->setLocaleCode('fr_FR'); $order->setNumber('USE-'.$suffix); $order->setTokenValue($suffix); $order->setState('new'); $order->setCheckoutState('shipping_skipped'); $order->setFulfillmentMode('pickup');
        $product = new \App\Entity\Product\Product(); $product->setCode('product-'.$suffix); $product->setCurrentLocale('fr_FR'); $product->setFallbackLocale('fr_FR'); $product->setName('Test'); $product->setSlug('product-'.$suffix);
        $variant = new \App\Entity\Product\ProductVariant(); $variant->setCode('variant-'.$suffix); $variant->setCurrentLocale('fr_FR'); $variant->setFallbackLocale('fr_FR'); $variant->setName('Test'); $variant->setProduct($product); $variant->setTracked(false); $this->em->persist($product); $this->em->persist($variant);
        $address = new \App\Entity\Addressing\Address(); $address->setFirstName('Client'); $address->setLastName('Test'); $address->setCountryCode('FR'); $address->setStreet('1 rue du Test'); $address->setPostcode('75001'); $address->setCity('Paris'); $order->setBillingAddress($address);
        $customer = new \App\Entity\Customer\Customer(); $customer->setEmail($suffix.'@example.test'); $this->em->persist($customer); $order->setCustomer($customer);
        $item = new OrderItem(); $item->setVariant($variant); new \App\Entity\Order\OrderItemUnit($item); $item->setUnitPrice($due + $later); $order->addItem($item);
        if ($later) { $adjustment = new Adjustment(); $adjustment->setType('todatempo_payment_terms'); $adjustment->setAmount(-$later); $order->addAdjustment($adjustment); $adjustment->lock(); }
        $payment = new Payment(); $payment->setAmount($due); $payment->setCurrencyCode('EUR'); $payment->setState('cart'); $order->addPayment($payment);
        $this->em->persist($card); $this->em->persist($order); $this->em->flush();
        return [$card, $order, $payment];
    }

    private function settle(GiftCard $card, Order $order, Payment $payment): array
    {
        $this->service->prepare($order->getTokenValue(), $card->getCode());
        self::assertSame('payment_selected', $order->getCheckoutState());
        // Stock et créneau sont validés par les parcours existants avant settle.
        $order->setCheckoutState('completed'); $order->setPaymentState('awaiting_payment'); $payment->setState('new'); $this->em->flush();
        return $this->service->settle($order->getTokenValue());
    }

    public function testAccountBalanceReflectsCompletedCheckoutWithoutAnotherLogin(): void
    {
        [$card, $order, $payment] = $this->fixture(10000, 7000);
        $user = new \App\Entity\User\ShopUser();
        $user->setCustomer($order->getCustomer());
        $account = self::getContainer()->get(\App\Service\Customer\CustomerGiftCards::class);
        $account->claim($user, $card->getCode());
        self::assertSame(10000, $account->lists($user)['received'][0]['available']);
        $this->settle($card, $order, $payment);
        $this->em->clear();
        $view = $account->lists($user)['received'][0];
        self::assertSame(3000, $view['available']);
        self::assertSame(0, $view['reserved']);
        self::assertSame('debit', $view['history'][0]['kind']);
        self::assertArrayNotHasKey('orderNumber', $view['history'][0]);
    }

    public function testSeventyFromHundredIsPaidWithoutBankAndReplayDoesNotDebitAgain(): void
    {
        [$card, $order, $payment] = $this->fixture(10000, 7000);
        $result = $this->settle($card, $order, $payment);
        self::assertSame(3000, $card->getAvailable()); self::assertSame(0, $card->getReserved()); self::assertSame('paid', $order->getPaymentState());
        self::assertSame(7000, $order->getTotal()); self::assertSame('gift_card', $result['paymentMethod']); self::assertCount(1, $order->getPayments());
        $this->service->settle($order->getTokenValue()); self::assertSame(3000, $card->getAvailable());
    }

    public function testFiftyFromEightyReservesThenDebitsOnBankConfirmationOnce(): void
    {
        [$card, $order, $payment] = $this->fixture(5000, 8000);
        $result = $this->settle($card, $order, $payment);
        self::assertSame(3000, $payment->getAmount()); self::assertSame(3000, $result['remaining']); self::assertSame(5000, $card->getReserved());
        self::getContainer()->get(Registry::class)->get($payment, 'sylius_payment')->apply($payment, 'complete'); $this->em->flush();
        self::assertSame(0, $card->getReserved()); self::assertSame('paid', $order->getPaymentState());
        $this->service->paid($payment); self::assertSame(0, $card->getReserved()); self::assertSame(0, $card->getAvailable());
    }

    public function testExactAmountAndDepositDoNotChangeDeferredBalance(): void
    {
        [$card, $order, $payment] = $this->fixture(3000, 3000, 7000);
        $result = $this->settle($card, $order, $payment);
        self::assertSame(0, $result['remaining']); self::assertSame(7000, $result['dueLater']); self::assertSame(3000, $order->getTotal()); self::assertSame(0, $card->getAvailable());
    }

    public function testFailedBankPaymentReleasesCredit(): void
    {
        [$card, $order, $payment] = $this->fixture(5000, 8000);
        $this->settle($card, $order, $payment);
        self::getContainer()->get(Registry::class)->get($payment, 'sylius_payment')->apply($payment, 'fail'); $this->em->flush();
        self::assertSame(5000, $card->getAvailable()); self::assertSame(0, $card->getReserved());
        $this->service->failed($order); self::assertSame(5000, $card->getAvailable());
    }

    public function testAbandonedPaymentExpiresAndRestoresCredit(): void
    {
        [$card, $order, $payment] = $this->fixture(5000, 8000);
        $this->settle($card, $order, $payment);
        foreach ($order->getPayments() as $gift) if (isset($gift->getDetails()['gift_card_code'])) $gift->setDetails(array_replace($gift->getDetails(), ['gift_card_expires' => time() - 1]));
        $this->em->flush(); self::assertSame(1, $this->service->expire()); self::assertSame(5000, $card->getAvailable()); self::assertSame('cancelled', $payment->getState());
    }

    public function testRefundOnlyRestoresTheGiftPartAndReplayIsSafe(): void
    {
        [$card, $order, $payment] = $this->fixture(10000, 7000);
        $this->settle($card, $order, $payment);
        $refunds = self::getContainer()->get(RefundService::class);
        $key = 'refund-'.bin2hex(random_bytes(8));
        $refunds->refund($payment, 2000, $key, 'test', null); self::assertSame(5000, $card->getAvailable());
        $refunds->refund($payment, 2000, $key, 'test', null); self::assertSame(5000, $card->getAvailable()); self::assertSame('partially_refunded', $order->getPaymentState());
    }

    public function testMissingBookingDoesNotReserveAnything(): void
    {
        [$card, $order, $payment] = $this->fixture(10000, 7000);
        $order->setFulfillmentMode(null); $this->em->flush();
        try { $this->settle($card, $order, $payment); self::fail('Expected missing booking'); }
        catch (\DomainException $e) { self::assertStringContainsString('créneau', $e->getMessage()); }
        self::assertSame(10000, $card->getAvailable()); self::assertSame(0, $card->getReserved());
    }

    public function testBookingDepositPaidByGiftKeepsLaterBalance(): void
    {
        [$card, $order, $payment] = $this->fixture(10000, 3000, 7000);
        $order->setFulfillmentMode(null);
        $booking = new \App\Entity\Booking();
        $booking->setReference('B-'.bin2hex(random_bytes(6))); $booking->setPublicToken(bin2hex(random_bytes(16)));
        $booking->setServiceCode('service_test'); $booking->setServiceName('Prestation test');
        $booking->setCustomerFirstName('Client'); $booking->setCustomerLastName('Test'); $booking->setCustomerEmail('client@example.test');
        $booking->setSlotStart(new \DateTimeImmutable('+1 day')); $booking->setSlotEnd(new \DateTimeImmutable('+1 day +1 hour'));
        $booking->setOrderNumber($order->getNumber()); $booking->setAmount(3000); $booking->setTotalAmount(10000); $booking->setBalanceDue(7000); $booking->setPaymentState('awaiting_payment');
        $this->em->persist($booking); $this->em->flush();
        $result = $this->settle($card, $order, $payment);
        self::assertSame('paid', $booking->getPaymentState()); self::assertSame(7000, $booking->getBalanceDue()); self::assertSame(7000, $card->getAvailable());
        self::assertSame(7000, $result['dueLater']);
    }

    public function testStripeOnlyReceivesTheComplementAndSignedReplaysDoNotDebitTwice(): void
    {
        [$card, $order, $payment] = $this->fixture(5000, 8000);
        $this->settle($card, $order, $payment);
        $client = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $client->expects(self::once())->method('request')->willReturnCallback(static function ($method, $url, $headers, $parameters): array {
            self::assertSame(3000, $parameters['line_items'][0]['price_data']['unit_amount']);
            self::assertGreaterThan(time() + 1800, $parameters['expires_at']);
            return ['{"id":"cs_test","object":"checkout.session","url":"https://checkout.example.test/pay"}', 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($client);
        try {
            self::getContainer()->get(\App\Service\Payment\StripePaymentService::class)->session([
                'orderToken' => $order->getTokenValue(), 'paymentId' => $payment->getId(),
                'successUrl' => 'https://example.test/success', 'cancelUrl' => 'https://example.test/cancel',
            ], 'example.test');
        } finally { \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance()); }
        // Une échéance locale ne libère pas une session dont le webhook est encore en transit.
        foreach ($order->getPayments() as $gift) if (isset($gift->getDetails()['gift_card_code'])) $gift->setDetails(array_replace($gift->getDetails(), ['gift_card_expires' => time() - 1]));
        $payment->getMethod()->getGatewayConfig()->setConfig(['secret_key' => 'sk_test_local_only', 'webhook_secret_key' => 'whsec_local_test']);
        $this->em->flush(); self::assertSame(0, $this->service->expire()); self::assertSame(5000, $card->getReserved());
        $processor = self::getContainer()->get(\App\Service\Payment\StripeWebhookProcessor::class);
        foreach (['completed', 'async_payment_succeeded'] as $event) {
            $payload = json_encode(['id' => 'evt_'.bin2hex(random_bytes(8)), 'object' => 'event', 'type' => 'checkout.session.'.$event,
                'data' => ['object' => ['object' => 'checkout.session', 'amount_total' => 3000, 'currency' => 'eur', 'payment_status' => 'paid', 'payment_intent' => 'pi_test',
                    'metadata' => ['payment_id' => (string) $payment->getId(), 'order_token' => $order->getTokenValue(), 'booking_token' => '']]]], JSON_THROW_ON_ERROR);
            $time = time(); $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_local_test');
            self::assertTrue($processor->process($payload, $signature)['received']);
        }
        self::assertSame(0, $card->getAvailable()); self::assertSame(0, $card->getReserved()); self::assertSame('paid', $order->getPaymentState());
    }

    public function testMixedRefundRestoresOnlyGiftAndKeepsBankPartSeparate(): void
    {
        [$card, $order, $payment] = $this->fixture(5000, 8000);
        $this->settle($card, $order, $payment);
        self::getContainer()->get(Registry::class)->get($payment, 'sylius_payment')->apply($payment, 'complete'); $this->em->flush();
        $gift = $order->getPayments()->last();
        self::getContainer()->get(RefundService::class)->refund($gift, 5000, 'refund-'.bin2hex(random_bytes(8)), 'test', null);
        self::assertSame(5000, $card->getAvailable()); self::assertSame('completed', $payment->getState()); self::assertSame(0, $payment->getRefundedAmount());
        self::assertSame('partially_refunded', $order->getPaymentState());
    }

    public function testAnotherEstablishmentCannotUseTheCode(): void
    {
        [$card] = $this->fixture(5000, 8000);
        (new \ReflectionProperty(GiftCard::class, 'establishment'))->setValue($card, 'another-establishment'); $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->service->quote($card->getCode());
    }


    public function testReservedCreditCanBeDebitedWhenSignedConfirmationArrivesAfterExpiry(): void
    {
        [$card, $order, $payment] = $this->fixture(5000, 8000);
        $this->settle($card, $order, $payment);
        (new \ReflectionProperty(GiftCard::class, 'expiresAt'))->setValue($card, new \DateTimeImmutable('-1 second'));
        $this->em->flush();
        self::getContainer()->get(Registry::class)->get($payment, 'sylius_payment')->apply($payment, 'complete'); $this->em->flush();
        self::assertSame(0, $card->getReserved()); self::assertSame('paid', $order->getPaymentState());
    }

}

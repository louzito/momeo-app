<?php
namespace App\Tests\Commerce;
use App\Entity\Channel\Channel;
use App\Entity\Order\Order;
use App\Entity\Product\{Product, ProductVariant};
use App\Entity\Channel\ChannelPricing;
use App\Entity\Payment\PaymentMethod;
use App\Service\Commerce\UnifiedCheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;

final class UnifiedCheckoutTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UnifiedCheckoutService $checkout;
    private Channel $channel;
    protected function setUp(): void
    {
        self::bootKernel();
        $pdf = $this->createMock(\Sylius\InvoicingPlugin\Generator\InvoicePdfFileGeneratorInterface::class);
        $pdf->method('generate')->willReturnCallback(static fn ($invoice) => new \Sylius\InvoicingPlugin\Model\InvoicePdf((new \Sylius\InvoicingPlugin\Generator\InvoiceFileNameGenerator())->generateForPdf($invoice), '%PDF-test'));
        self::getContainer()->set('sylius_invoicing.generator.invoice_pdf_file', $pdf);
        $this->em = self::getContainer()->get(EntityManagerInterface::class); $this->em->getConnection()->beginTransaction();
        $this->checkout = self::getContainer()->get(UnifiedCheckoutService::class);
        $this->channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => 'bank_transfer']);
        $method->setEnabled(true); $method->addChannel($this->channel);
        $this->em->flush();
    }
    protected function tearDown(): void { if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) $this->em->getConnection()->rollBack(); parent::tearDown(); }
    private function product(int $stock = 5): ProductVariant
    {
        $code = 'physical_'.bin2hex(random_bytes(6));
        $product = new Product(); $product->setCode($code); $product->setCurrentLocale('en_US'); $product->setFallbackLocale('en_US'); $product->setName('Crème'); $product->setSlug($code); $product->addChannel($this->channel); $product->setTodatempoType('physical'); $product->setPickupEnabled(true); $product->setDeliveryEnabled(true); $product->setDeliveryFee(500);
        $variant = new ProductVariant(); $variant->setCode($code.'-variant'); $variant->setCurrentLocale('en_US'); $variant->setFallbackLocale('en_US'); $variant->setName('Crème'); $product->addVariant($variant); $variant->setTracked(true); $variant->setOnHand($stock); $variant->setShippingRequired(true);
        $price = new ChannelPricing(); $price->setChannelCode($this->channel->getCode()); $price->setPrice(2000); $variant->addChannelPricing($price);
        $this->em->persist($product); $this->em->flush(); return $variant;
    }
    private function data(ProductVariant $variant): array
    {
        return ['key' => bin2hex(random_bytes(16)), 'items' => [['code' => $variant->getProduct()->getCode(), 'quantity' => 2]], 'gifts' => [], 'mode' => 'delivery', 'paymentMethod' => 'bank_transfer', 'customer' => ['firstName' => 'Jean', 'lastName' => 'Test', 'email' => 'checkout-'.bin2hex(random_bytes(6)).'@example.test', 'street' => '1 rue des Tests', 'city' => 'Paris', 'postcode' => '75001', 'countryCode' => 'FR']];
    }
    private function addService(array $data): array
    {
        $variant = $this->product(); $product = $variant->getProduct();
        $code = 'service_'.bin2hex(random_bytes(6)); $product->setCode($code); $product->setTodatempoType('service'); $variant->setCode($code.'-variant'); $variant->setShippingRequired(false); $variant->setTracked(false);
        foreach (['todatempo_payment_mode' => 'percentage', 'todatempo_payment_value' => '30'] as $attributeCode => $value) {
            $attribute = $this->em->getRepository(\App\Entity\Product\ProductAttribute::class)->findOneBy(['code' => $attributeCode]);
            if ($attribute === null) { $attribute = new \App\Entity\Product\ProductAttribute(); $attribute->setCode($attributeCode); $attribute->setType('text'); $attribute->setStorageType('text'); $attribute->setCurrentLocale('en_US'); $attribute->setFallbackLocale('en_US'); $attribute->setName($attributeCode); $this->em->persist($attribute); }
            $attributeValue = new \App\Entity\Product\ProductAttributeValue(); $attributeValue->setAttribute($attribute); $attributeValue->setLocaleCode('en_US'); $attributeValue->setValue($value); $product->addAttribute($attributeValue);
        }
        $start = new \DateTimeImmutable('+7 days 10:00', new \DateTimeZone('Europe/Paris'));
        $planningCode = 'planning_'.bin2hex(random_bytes(6));
        $staff = new \App\Entity\StaffMember(); $staff->setFirstName('Anne'); $staff->setLastName('Test'); $staff->setActive(true); $staff->setBookable(true); $staff->setServiceCodes([$code]); $staff->setWorkingHours([strtolower($start->format('l')) => [['start' => '09:00', 'end' => '18:00']]]); $this->em->persist($staff);
        $planning = new \App\Entity\Planning(); $planning->setCode($planningCode); $planning->setName('Test'); $planning->setServiceCodes([$code]); $planning->setActive(true); $this->em->persist($planning);
        $taxon = new \App\Entity\Taxonomy\Taxon(); $taxon->setCode($planningCode); $taxon->setCurrentLocale('en_US'); $taxon->setFallbackLocale('en_US'); $taxon->setName('Planning test'); $taxon->setSlug($planningCode); $taxon->setDescription(json_encode(['days' => [$start->format('Y-m-d') => ['10:00']], 'jumpCodes' => [$code]])); $this->em->persist($taxon); $this->em->flush();
        $data['items'][] = ['code' => $code, 'quantity' => 1];
        $data['slot'] = ['start' => $start->format(DATE_ATOM), 'end' => $start->modify('+1 hour')->format(DATE_ATOM), 'planningCode' => $planningCode, 'staffMemberId' => $staff->getId()];
        return $data;
    }
    public function testMixedServiceDepositIsSeparateAndBookingReplayIsAtomic(): void
    {
        $variant = $this->product(); $data = $this->addService($this->data($variant));
        $result = $this->checkout->checkout($data);
        self::assertSame(51, (int) $result['order']['total']); // 6 deposit + 40 products + 5 delivery.
        self::assertSame(600, $result['booking']['amount']); self::assertSame(2000, $result['booking']['totalAmount']); self::assertSame(1400, $result['booking']['balanceDue']);
        $replayed = $this->checkout->checkout($data); self::assertSame($result['booking']['id'], $replayed['booking']['id']);
        self::assertSame(1, $this->em->getRepository(\App\Entity\Booking::class)->count(['orderNumber' => $result['order']['number']]));
        $this->em->refresh($variant); self::assertSame(2, $variant->getOnHold());
        $data['key'] = bin2hex(random_bytes(16));
        try { $this->checkout->checkout($data); self::fail('Occupied slot accepted'); } catch (\App\Service\Booking\SlotUnavailable) {}
        self::assertNull($this->em->getRepository(Order::class)->findOneBy(['checkoutKey' => $data['key']]));
        $variant = $this->em->find(ProductVariant::class, $variant->getId()); self::assertSame(2, $variant->getOnHold());
    }
    public function testCreditPaysEligibleLinesOnlyAndFailureRestoresEverything(): void
    {
        $variant = $this->product(); $data = $this->addService($this->data($variant));
        $data['gifts'] = [['amount' => 5000, 'recipientName' => 'Marie', 'recipientEmail' => '', 'message' => '', 'delivery' => 'buyer']];
        $card = new \App\Entity\GiftCard('demo', $this->channel->getCode(), 'EUR', 20000, 'TEST-'.bin2hex(random_bytes(6)), new \DateTimeImmutable('+1 year')); $this->em->persist($card);
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']);
        $method->setEnabled(true); $method->addChannel($this->channel); $method->getGatewayConfig()->setConfig(['secret_key' => 'sk_test_local_only']); $this->em->flush();
        $data['giftCardCode'] = $card->getCode(); $data['paymentMethod'] = 'stripe_web_elements';
        $result = $this->checkout->checkout($data);
        self::assertSame(5100, $result['order']['paymentBreakdown']['giftCard']); self::assertSame(14900, $card->getAvailable()); self::assertSame(5100, $card->getReserved());
        $order = $this->em->getRepository(Order::class)->findOneBy(['tokenValue' => $result['order']['orderToken']]);
        $bank = $this->em->find(\App\Entity\Payment\Payment::class, $result['order']['paymentId']); self::assertSame(5000, $bank->getAmount());
        self::getContainer()->get(Registry::class)->get($bank, 'sylius_payment')->apply($bank, 'fail'); $this->em->flush();
        self::assertSame(20000, $card->getAvailable()); self::assertSame(0, $card->getReserved());
        $this->em->refresh($variant); self::assertSame(0, $variant->getOnHold());
        self::assertSame('cancelled', $this->em->getRepository(\App\Entity\Booking::class)->findOneBy(['orderNumber' => $order->getNumber()])->getStatus());
        self::assertCount(0, $this->em->getRepository(\App\Entity\GiftCard::class)->findBy(['purchaseOrderNumber' => $order->getNumber()]));
    }
    public function testFullCreditIsDebitedOnceAndNoBankPaymentIsRequired(): void
    {
        $variant = $this->product(); $data = $this->data($variant); $data['mode'] = 'pickup'; $data['paymentMethod'] = 'gift_card';
        $card = new \App\Entity\GiftCard('demo', $this->channel->getCode(), 'EUR', 10000, 'FULL-'.bin2hex(random_bytes(6)), new \DateTimeImmutable('+1 year')); $this->em->persist($card); $this->em->flush(); $data['giftCardCode'] = $card->getCode();
        $result = $this->checkout->checkout($data); self::assertSame('paid', $result['order']['status']); self::assertSame('gift_card', $result['order']['paymentMethod']);
        $this->checkout->checkout($data); self::assertSame(6000, $card->getAvailable()); self::assertSame(0, $card->getReserved());
        $this->em->refresh($variant); self::assertSame(3, $variant->getOnHand()); self::assertSame(0, $variant->getOnHold());
    }
    public function testMixedCreditBankCompletionIssuesGiftOnce(): void
    {
        $variant = $this->product(); $data = $this->data($variant);
        $data['gifts'] = [['amount' => 5000, 'recipientName' => 'Marie', 'recipientEmail' => '', 'message' => '', 'delivery' => 'buyer']];
        $card = new \App\Entity\GiftCard('demo', $this->channel->getCode(), 'EUR', 3000, 'PART-'.bin2hex(random_bytes(6)), new \DateTimeImmutable('+1 year')); $this->em->persist($card);
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']); $method->setEnabled(true); $method->addChannel($this->channel); $method->getGatewayConfig()->setConfig(['secret_key' => 'sk_test_local_only']); $this->em->flush();
        $data['giftCardCode'] = $card->getCode(); $data['paymentMethod'] = 'stripe_web_elements';
        $result = $this->checkout->checkout($data); $order = $this->em->getRepository(Order::class)->findOneBy(['tokenValue' => $result['order']['orderToken']]);
        $bank = $this->em->find(\App\Entity\Payment\Payment::class, $result['order']['paymentId']); self::assertSame(6500, $bank->getAmount());
        self::getContainer()->get(\App\Service\Payment\StripeCheckout::class)->complete($bank, null); $this->em->flush();
        self::assertSame('paid', $order->getPaymentState()); self::assertSame(0, $card->getReserved()); self::assertSame(0, $card->getAvailable());
        self::assertFalse(self::getContainer()->get(\App\Service\Payment\StripeCheckout::class)->complete($bank, null));
        self::assertCount(1, $this->em->getRepository(\App\Entity\GiftCard::class)->findBy(['purchaseOrderNumber' => $order->getNumber()]));
        $this->em->refresh($variant); self::assertSame(3, $variant->getOnHand());
    }
    public function testUnstartedStripeCheckoutExpiresAndRestoresTheSlotAndStock(): void
    {
        $variant = $this->product(); $data = $this->addService($this->data($variant));
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']); $method->setEnabled(true); $method->addChannel($this->channel); $method->getGatewayConfig()->setConfig(['secret_key' => 'sk_test_local_only']); $this->em->flush();
        $data['paymentMethod'] = 'stripe_web_elements'; $result = $this->checkout->checkout($data);
        $payment = $this->em->find(\App\Entity\Payment\Payment::class, $result['order']['paymentId']); $payment->setDetails(['checkout_expires' => time() - 10]); $this->em->flush();
        self::getContainer()->get(\App\Service\GiftCard\GiftCardPaymentService::class)->expire();
        self::assertSame('cancelled', $payment->getState());
        self::assertSame('cancelled', $this->em->getRepository(\App\Entity\Booking::class)->findOneBy(['orderNumber' => $result['order']['number']])->getStatus());
        $this->em->refresh($variant); self::assertSame(0, $variant->getOnHold());
    }
    public function testCardsAloneDoNotCreateBookingOrRequireSlot(): void
    {
        $variant = $this->product(); $data = $this->data($variant); $data['items'] = [];
        $data['gifts'] = [['amount' => 5000, 'recipientName' => 'Marie', 'recipientEmail' => '', 'message' => '', 'delivery' => 'buyer']];
        $result = $this->checkout->checkout($data); self::assertNull($result['booking']); self::assertSame(50, (int) $result['order']['total']);
    }
    public function testConcurrentReplayWaitsAndReturnsTheSameOrder(): void
    {
        $variant = $this->product(); $data = $this->data($variant); $result = $this->checkout->checkout($data);
        $script = <<<'CHILD'
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test'; $_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';
require $argv[1].'/tests/bootstrap.php'; $kernel = new App\Kernel('test', false); $kernel->boot();
$container = $kernel->getContainer()->get('test.service_container'); echo "ready\n"; flush();
try { $result = $container->get(App\Service\Commerce\UnifiedCheckoutService::class)->checkout(json_decode($argv[2], true)); echo $result['order']['orderToken']; } catch (Throwable $e) { echo get_class($e).':'.$e->getMessage(); exit(1); }
CHILD;
        $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2), json_encode($data)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            self::assertIsResource($process); stream_set_timeout($pipes[1], 30); self::assertSame("ready\n", fgets($pipes[1])); usleep(200000); self::assertTrue(proc_get_status($process)['running']);
            $this->em->getConnection()->commit();
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); $exit = proc_close($process); $process = null;
            self::assertSame(0, $exit, $errors.' '.$output); self::assertSame($result['order']['orderToken'], $output);
            self::assertSame(1, $this->em->getRepository(Order::class)->count(['checkoutKey' => $data['key']]));
            $this->em->refresh($variant); self::assertSame(2, $variant->getOnHold());
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            if (!$this->em->getConnection()->isTransactionActive()) {
                $order = $this->em->getRepository(Order::class)->findOneBy(['checkoutKey' => $data['key']]);
                if ($order) { $customer = $order->getCustomer(); foreach (self::getContainer()->get('sylius_invoicing.repository.invoice')->findByOrderNumber($order->getNumber()) as $invoice) $this->em->remove($invoice); $this->em->flush(); $this->em->remove($order); $this->em->flush(); $this->em->remove($customer); }
                $productId = $variant->getProduct()->getId(); $this->em->flush(); $this->em->getConnection()->executeStatement('DELETE FROM sylius_product WHERE id = ?', [$productId]);
            }
        }
    }
    public function testHistoricalOptionCodesBelongToTheServiceDeposit(): void
    {
        $physical = $this->product(); $data = $this->addService($this->data($physical));
        $option = $this->product(); $code = 'opt_pj_'.bin2hex(random_bytes(6)); $option->getProduct()->setCode($code); $option->getProduct()->setTodatempoType('service'); $option->setCode($code.'-variant'); $option->setTracked(false); $option->setShippingRequired(false); $this->em->flush();
        $data['items'][] = ['code' => $code, 'quantity' => 1]; $result = $this->checkout->checkout($data);
        self::assertSame(1200, $result['booking']['amount']); self::assertSame(4000, $result['booking']['totalAmount']); self::assertSame(2800, $result['booking']['balanceDue']); self::assertSame(57, (int) $result['order']['total']);
    }
    public function testUnavailableDeliveryCannotReserveAnyStock(): void
    {
        $variant = $this->product(); $variant->getProduct()->setDeliveryEnabled(false); $this->em->flush(); $data = $this->data($variant);
        try { $this->checkout->checkout($data); self::fail('Unavailable fulfillment accepted'); } catch (\DomainException) {}
        self::assertNull($this->em->getRepository(Order::class)->findOneBy(['checkoutKey' => $data['key']]));
        $variant = $this->em->find(ProductVariant::class, $variant->getId()); self::assertSame(0, $variant->getOnHold());
    }
    public function testProductQuantitiesAndDeliveryAreRecomputedAndReplayDoesNotHoldTwice(): void
    {
        $variant = $this->product(); $data = $this->data($variant); $data['total'] = 1;
        $first = $this->checkout->checkout($data); $second = $this->checkout->checkout($data);
        self::assertSame($first['order']['orderToken'], $second['order']['orderToken']); self::assertSame(45, (int) $first['order']['total']);
        $this->em->refresh($variant); self::assertSame(2, $variant->getOnHold()); self::assertSame(5, $variant->getOnHand());
    }
    public function testUnavailableStockLeavesNoOrder(): void
    {
        $variant = $this->product(1); $data = $this->data($variant);
        try { $this->checkout->checkout($data); self::fail('Stock unavailable'); } catch (\DomainException) {}
        self::assertNull($this->em->getRepository(Order::class)->findOneBy(['checkoutKey' => $data['key']]));
    }
    public function testPaymentFailureReleasesStockOnce(): void
    {
        $variant = $this->product(); $result = $this->checkout->checkout($this->data($variant));
        $order = $this->em->getRepository(Order::class)->findOneBy(['tokenValue' => $result['order']['orderToken']]);
        $workflow = self::getContainer()->get(Registry::class)->get($order->getLastPayment(), 'sylius_payment');
        $workflow->apply($order->getLastPayment(), 'fail'); $this->em->flush();
        self::assertSame('cancelled', $order->getState()); $this->em->refresh($variant); self::assertSame(0, $variant->getOnHold());
        self::getContainer()->get(\App\Service\GiftCard\GiftCardPaymentService::class)->failed($order); $this->em->flush();
        $this->em->refresh($variant); self::assertSame(0, $variant->getOnHold()); self::assertSame(5, $variant->getOnHand());
    }
    public function testGiftCreditCannotPayForTheNewCardsAndIssueIsPerLine(): void
    {
        $variant = $this->product(); $data = $this->data($variant);
        $gift = ['amount' => 5000, 'recipientName' => 'Marie', 'recipientEmail' => '', 'message' => '', 'delivery' => 'buyer'];
        $data['gifts'] = [$gift, $gift];
        $result = $this->checkout->checkout($data);
        $order = $this->em->getRepository(Order::class)->findOneBy(['tokenValue' => $result['order']['orderToken']]);
        self::assertSame(14500, $order->getTotal()); self::assertSame(4500, $order->getGiftEligibleTotal());
        $workflows = self::getContainer()->get(Registry::class); $workflows->get($order->getLastPayment(), 'sylius_payment')->apply($order->getLastPayment(), 'complete'); $this->em->flush();
        $cards = $this->em->getRepository(\App\Entity\GiftCard::class)->findBy(['purchaseOrderNumber' => $order->getNumber()]); self::assertCount(2, $cards);
        self::getContainer()->get(\App\Service\GiftCard\GiftCardService::class)->issueFromPayment($order->getLastPayment()); $this->em->flush();
        self::assertCount(2, $this->em->getRepository(\App\Entity\GiftCard::class)->findBy(['purchaseOrderNumber' => $order->getNumber()]));
    }
}

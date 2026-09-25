<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminGiftVoucherApiController;
use App\Controller\ShopGiftVoucherApiController;
use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Entity\GiftVoucher;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\EventListener\ActivateGiftVoucherOnPaymentCompletedListener;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Service\GiftVoucher\GiftOrderMarker;
use App\Service\GiftVoucher\GiftVoucherAccess;
use App\Service\GiftVoucher\GiftVoucherActivator;
use App\Service\GiftVoucher\GiftVoucherMailer;
use App\Service\GiftVoucher\GiftVoucherQrCodeGenerator;
use App\Service\GiftVoucher\GiftVoucherView;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use App\Service\Tenant\TenantUrlGenerator;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;

/** Repositories réels, base de test isolée et rollback ; aucun transport email réel. */
final class GiftVoucherAccessContractTest extends \App\Tests\Availability\AvailabilityTestCase
{
    private GiftVoucher $voucher;
    private GiftVoucherRepository $repository;
    private GiftVoucherQrCodeGenerator $qr;
    private GiftVoucherAccess $access;
    private ShopGiftVoucherApiController $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $v = $this->voucher = new GiftVoucher();
        $v->setCode((string) random_int(2000000000, 2999999999));
        $v->setServiceCode($this->serviceCode);
        $v->setServiceName('Prestation');
        $v->setAmount(9900);
        $v->setCurrencyCode('EUR');
        $v->setPurchaserName('Acheteur');
        $v->setPurchaserEmail('buyer@example.test');
        $v->setBeneficiaryName('Alice Dupont');
        $v->setBeneficiaryEmail($this->serviceCode.'@example.test');
        $v->setPersonalMessage('Bonjour');
        $v->setPurchaseOrderNumber('ORDER-'.$this->serviceCode);
        $v->setStatus('active');
        $v->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($v);
        $this->entityManager->flush();
        $this->repository = self::getContainer()->get(GiftVoucherRepository::class);
        // Fichier absent : repli URL connu, sans lire le registre du déploiement.
        $registry = new TenantRegistry('/tmp/nonexistent-gift-registry-'.bin2hex(random_bytes(8)), false);
        $context = new TenantContext($registry, new TenantIdentifierResolver(), 'demo');
        $context->setSlug('demo');
        $this->qr = new GiftVoucherQrCodeGenerator($context, new TenantUrlGenerator($registry, 'https://app.example.test'));
        $this->access = new GiftVoucherAccess($this->repository, new GiftVoucherView(self::getContainer()->get(BookingRepository::class)), $this->qr);
        $this->shop = new ShopGiftVoucherApiController($this->access);
    }

    public function testLoginAllowsExpiredAndUsedVouchersButRejectsWrongCodeOrEmail(): void
    {
        foreach (['active', 'used', 'awaiting_payment'] as $status) {
            $this->voucher->setStatus($status);
            $response = $this->shop->login($this->request(['code' => ' '.$this->voucher->getCode().' ', 'email' => strtoupper($this->voucher->getBeneficiaryEmail())]));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['email' => $this->voucher->getBeneficiaryEmail(), 'firstName' => 'Alice'], json_decode($response->getContent(), true));
        }
        foreach ([['code' => '0000000000', 'email' => $this->voucher->getBeneficiaryEmail()], ['code' => $this->voucher->getCode(), 'email' => 'wrong@example.test']] as $payload) {
            $response = $this->shop->login($this->request($payload));
            self::assertSame(401, $response->getStatusCode());
            self::assertSame(['error' => 'Ce code ne correspond pas a cet email.'], json_decode($response->getContent(), true));
        }
        self::assertSame(422, $this->shop->login($this->request([]))->getStatusCode());
    }

    public function testPublicProjectionAndAllLookupsKeepTheirPayload(): void
    {
        $v = $this->voucher;
        $expected = [
            'code' => $v->getCode(), 'status' => 'expired', 'serviceCode' => $this->serviceCode,
            'serviceName' => 'Prestation', 'jumpTypeCode' => $this->serviceCode, 'jumpTypeName' => 'Prestation',
            'amount' => 9900, 'currencyCode' => 'EUR', 'beneficiaryName' => 'Alice Dupont',
            'beneficiaryEmail' => $v->getBeneficiaryEmail(), 'personalMessage' => 'Bonjour',
            'purchaserName' => 'Acheteur', 'expiresAt' => $v->getExpiresAt()->format(DATE_ATOM), 'booking' => null,
        ];
        self::assertSame($expected, json_decode($this->shop->show($v->getCode())->getContent(), true));
        self::assertSame($expected, json_decode($this->shop->byOrderNumber($v->getPurchaseOrderNumber())->getContent(), true));
        self::assertSame([$expected], json_decode($this->shop->byEmail(' '.$v->getBeneficiaryEmail().' ')->getContent(), true));
        foreach ([[$this->shop->show('0000000000'), 'Chèque cadeau introuvable.'], [$this->shop->byOrderNumber('missing'), 'Chèque introuvable pour cette commande.'], [$this->shop->qr('0000000000'), 'Chèque introuvable.']] as [$response, $error]) {
            self::assertSame(404, $response->getStatusCode());
            self::assertSame(['error' => $error], json_decode($response->getContent(), true));
        }
        $booking = $this->booking($this->start, $this->start->modify('+1 hour'));
        $this->entityManager->persist($booking);
        $v->setUsageOrderNumber($booking->getReference());
        $v->setStatus('used');
        $this->entityManager->flush();
        $data = json_decode($this->shop->show($v->getCode())->getContent(), true);
        self::assertSame('used', $data['status']);
        self::assertSame(['reference' => $booking->getReference(), 'jumpTypeName' => 'Contract', 'slotStart' => $booking->getSlotStart()->format(DATE_ATOM), 'slotEnd' => $booking->getSlotEnd()->format(DATE_ATOM)], $data['booking']);
    }

    public function testAdminProjectionUsesEffectiveStatusAndUnfilteredStats(): void
    {
        $admin = new AdminGiftVoucherApiController($this->access);
        $all = json_decode($admin->index(Request::create('/'))->getContent(), true);
        $filtered = json_decode($admin->index(Request::create('/?status=expired'))->getContent(), true);
        self::assertSame($all['stats'], $filtered['stats']);
        $rows = array_values(array_filter($filtered['member'], fn (array $row) => $row['code'] === $this->voucher->getCode()));
        $v = $this->voucher;
        self::assertSame([[
            'code' => $v->getCode(), 'status' => 'expired', 'serviceCode' => $this->serviceCode,
            'serviceName' => 'Prestation', 'jumpTypeCode' => $this->serviceCode, 'jumpTypeName' => 'Prestation',
            'amount' => 9900, 'currencyCode' => 'EUR', 'purchaserName' => 'Acheteur', 'purchaserEmail' => 'buyer@example.test',
            'beneficiaryName' => 'Alice Dupont', 'beneficiaryEmail' => $v->getBeneficiaryEmail(), 'personalMessage' => 'Bonjour',
            'purchaseOrderNumber' => $v->getPurchaseOrderNumber(), 'usageOrderNumber' => null,
            'expiresAt' => $v->getExpiresAt()->format(DATE_ATOM), 'createdAt' => $v->getCreatedAt()->format(DATE_ATOM),
            'activatedAt' => null, 'usedAt' => null,
        ]], $rows);
        self::assertSame([], json_decode($admin->index(Request::create('/?status=unknown'))->getContent(), true)['member']);
    }

    public function testQrKeepsUrlPngAndCacheEvenWhenExpired(): void
    {
        self::assertSame('https://app.example.test/demo/beneficiary/login?code='.$this->voucher->getCode(), $this->qr->activationUrl($this->voucher->getCode()));
        $response = $this->shop->qr($this->voucher->getCode());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent());
    }

    public function testPaymentActivationRequiresCompletedPaymentAndIsIdempotent(): void
    {
        $this->voucher->setStatus('awaiting_payment');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::exactly(2))->method('send')->with('gift_voucher', self::callback(fn (array $to) => in_array($to, [[$this->voucher->getBeneficiaryEmail()], ['buyer@example.test']], true)), self::callback(function (array $data): bool {
            self::assertSame('active', $data['voucher']->getStatus());
            self::assertStringStartsWith('data:image/png;base64,', $data['qrDataUri']);
            return true;
        }));
        $listener = new ActivateGiftVoucherOnPaymentCompletedListener(new GiftVoucherActivator($this->entityManager, new GiftVoucherMailer($sender, $this->qr), $this->repository));
        $payment = new Payment();
        $invoke = static fn () => $listener(new CompletedEvent($payment, new Marking()));
        $payment->setState('completed');
        $invoke(); // paiement sans commande
        $order = new Order();
        $order->addPayment($payment);
        $order->setNumber($this->voucher->getPurchaseOrderNumber());
        $invoke(); // sans marqueur
        $order->setNotes(GiftOrderMarker::create('Alice', $this->voucher->getBeneficiaryEmail(), null)->encode());
        $invoke(); // sans canal
        $order->setChannel(new Channel());
        $payment->setState('new');
        $invoke(); // absence de paiement effectif
        self::assertSame('awaiting_payment', $this->voucher->getStatus());
        self::assertNull($this->voucher->getActivatedAt());
        $payment->setState('completed');
        $invoke();
        $activatedAt = $this->voucher->getActivatedAt();
        self::assertNotNull($activatedAt);
        $invoke();
        self::assertSame($activatedAt, $this->voucher->getActivatedAt());
    }

    public function testOrderPlacedAdapterDecodesMarkerAndCreatesOnlyOnce(): void
    {
        $creator = new \App\Service\GiftVoucher\GiftVoucherCreator(
            $this->entityManager,
            $this->repository,
            new \App\Service\GiftVoucher\GiftVoucherCodeGenerator($this->repository),
            new \App\Service\GiftVoucher\GiftVoucherConfig($this->entityManager),
        );
        $listener = new \App\EventListener\CreateGiftVoucherOnOrderPlacedListener($creator);
        $order = new Order();
        $order->setNumber('CREATE-'.$this->serviceCode);
        $event = new \Doctrine\ORM\Event\PostPersistEventArgs($order, $this->entityManager);
        $listener->postPersist($event); // panier non finalisé
        $order->setCheckoutState('completed');
        $listener->postPersist($event); // pas de marqueur
        $order->setNotes(GiftOrderMarker::create('Alice', 'alice@example.test', 'Bonjour')->encode());
        $listener->postPersist($event); // pas de produit
        self::assertNull($this->repository->findOneByPurchaseOrderNumber($order->getNumber()));
        $product = new \App\Entity\Product\Product();
        $product->setCode($this->serviceCode);
        $variant = new \App\Entity\Product\ProductVariant();
        $variant->setProduct($product);
        $item = new \App\Entity\Order\OrderItem();
        $item->setVariant($variant);
        $item->setProductName('Prestation cadeau');
        $item->setUnitPrice(9900);
        $order->addItem($item);
        $listener->postPersist($event);
        $voucher = $this->repository->findOneByPurchaseOrderNumber($order->getNumber());
        self::assertInstanceOf(GiftVoucher::class, $voucher);
        self::assertSame('awaiting_payment', $voucher->getStatus());
        self::assertSame('alice@example.test', $voucher->getBeneficiaryEmail());
        self::assertSame('Bonjour', $voucher->getPersonalMessage());
        self::assertSame($this->serviceCode, $voucher->getServiceCode());
        self::assertSame('Prestation cadeau', $voucher->getServiceName());
        self::assertSame($order->getTotal(), $voucher->getAmount());
        self::assertMatchesRegularExpression('/^\\d{10}$/', $voucher->getCode());
        $listener->postPersist($event);
        self::assertCount(1, $this->repository->findBy(['purchaseOrderNumber' => $order->getNumber()]));
    }

    private function request(array $payload): Request
    {
        return Request::create('/', 'POST', content: json_encode($payload, JSON_THROW_ON_ERROR));
    }
}

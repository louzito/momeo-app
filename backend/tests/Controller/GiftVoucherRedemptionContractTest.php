<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ShopBookingApiController;
use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Entity\GiftVoucher;
use App\Entity\Order\Order;
use App\Entity\Planning;
use App\Repository\BookingRepository;
use App\Service\Availability\AvailabilityService;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Booking\BookingCreationService;
use App\Service\Booking\BookingRules;
use App\Service\Email\BookingEmailDispatcher;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\HttpFoundation\Request;

/** Real creation service, repositories and slot validation on the isolated test database. */
final class GiftVoucherRedemptionContractTest extends \App\Tests\Availability\AvailabilityTestCase
{
    private GiftVoucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        $planning = new Planning();
        $planning->setCode($this->planningCode);
        $planning->setName('Creation contract');
        $planning->setServiceCodes([$this->serviceCode]);
        $this->entityManager->persist($planning);
        $this->voucher = new GiftVoucher();
        $this->voucher->setCode((string) random_int(1000000000, 1999999999));
        $this->voucher->setStatus(GiftVoucher::STATUS_ACTIVE);
        $this->voucher->setServiceCode($this->serviceCode);
        $this->voucher->setServiceName('Voucher service');
        $this->voucher->setAmount(9900);
        $this->voucher->setCurrencyCode('EUR');
        $this->voucher->setPurchaserName('Purchaser');
        $this->voucher->setPurchaserEmail('purchaser@example.test');
        $this->voucher->setBeneficiaryEmail('beneficiary@example.test');
        $this->voucher->setPurchaseOrderNumber('GIFT-'.$this->serviceCode);
        $this->voucher->setExpiresAt(new \DateTimeImmutable('+1 year'));
        $this->entityManager->persist($this->voucher);
        $this->configureRules(['minimumNoticeHours' => 0, 'maximumAdvanceDays' => 365]);
    }

    public function testVoucherSuccessThenReplay(): void
    {
        $controller = $this->controller(1);
        $response = $controller->createFromVoucher($this->voucher->getCode(), $this->request());
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('used', $data['voucher']['status']);
        self::assertSame($this->serviceCode, $data['voucher']['jumpTypeCode']);
        self::assertSame('paid', $data['booking']['paymentState']);
        self::assertSame(9900, $data['booking']['amount']);
        self::assertSame('voucher', $data['booking']['source']);
        self::assertSame($data['booking']['reference'], $this->voucher->getUsageOrderNumber());
        self::assertNotNull($this->voucher->getUsedAt());
        self::assertSame(1, $this->bookingCount());
        $replay = $controller->createFromVoucher($this->voucher->getCode(), $this->request());
        self::assertSame(409, $replay->getStatusCode());
        self::assertSame(['error' => 'Ce chèque cadeau n’est pas utilisable.'], json_decode($replay->getContent(), true));
        self::assertSame(1, $this->bookingCount());
    }

    public static function unusableVouchers(): iterable
    {
        yield 'unpaid' => [GiftVoucher::STATUS_AWAITING_PAYMENT, '+1 year'];
        yield 'used' => [GiftVoucher::STATUS_USED, '+1 year'];
        yield 'expired' => [GiftVoucher::STATUS_ACTIVE, '-1 day'];
    }

    #[DataProvider('unusableVouchers')]
    public function testUnusableVoucher(string $status, string $expiry): void
    {
        $this->voucher->setStatus($status);
        $this->voucher->setExpiresAt(new \DateTimeImmutable($expiry));
        $this->entityManager->flush();
        $response = $this->controller(0)->createFromVoucher($this->voucher->getCode(), $this->request());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'Ce chèque cadeau n’est pas utilisable.'], json_decode($response->getContent(), true));
        self::assertSame(0, $this->bookingCount());
        self::assertSame(1, $this->entityManager->getConnection()->getTransactionNestingLevel());
    }

    public function testLockedReadRefreshesPreviouslyLoadedVoucher(): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE skybook_gift_voucher SET status = ? WHERE code = ?', ['used', $this->voucher->getCode()]);
        self::assertSame(GiftVoucher::STATUS_ACTIVE, $this->voucher->getStatus());
        $response = $this->controller(0)->createFromVoucher($this->voucher->getCode(), $this->request());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(0, $this->bookingCount());
    }

    public function testCapacityFailureRollsBackVoucherWithoutEmail(): void
    {
        // No staff on the blocking booking: staff validation passes, then the locked capacity check rejects.
        $blocking = $this->booking($this->start, $this->start->modify('+1 hour'));
        $blocking->setStatus(Booking::STATUS_CONFIRMED);
        $this->entityManager->persist($blocking);
        $this->entityManager->flush();
        $response = $this->controller(0)->createFromVoucher($this->voucher->getCode(), $this->request());
        self::assertSame(409, $response->getStatusCode(), $response->getContent());
        self::assertSame('slot_unavailable', json_decode($response->getContent(), true)['code']);
        self::assertSame(1, $this->bookingCount());
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT status, used_at, usage_order_number FROM skybook_gift_voucher WHERE code = ?', [$this->voucher->getCode()]);
        self::assertSame(['status' => 'active', 'used_at' => null, 'usage_order_number' => null], $row);
        self::assertSame(1, $this->entityManager->getConnection()->getTransactionNestingLevel());
    }

    public function testMissingOrderReturns422WithoutEmail(): void
    {
        $response = $this->controller(0)->create($this->request());
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'La commande associée est introuvable ou incomplète.'], json_decode($response->getContent(), true));
        self::assertSame(0, $this->bookingCount());
    }

    public function testOrderSuccessAndLosingSlotAttempt(): void
    {
        $order = $this->order();
        $controller = $this->controller(1);
        $request = $this->request(['orderToken' => $order->getTokenValue()]);
        $response = $controller->create($request);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true);
        self::assertSame('confirmed', $data['status']);
        self::assertSame($order->getNumber(), $data['orderNumber']);
        self::assertSame(0, $data['amount']);
        self::assertSame($this->staff->getId(), $data['staffMemberId']);
        $loser = $controller->create($request);
        self::assertSame(409, $loser->getStatusCode());
        self::assertSame('slot_unavailable', json_decode($loser->getContent(), true)['code']);
        self::assertSame(1, $this->bookingCount());
    }

    public static function entryPoints(): iterable
    {
        yield 'order' => [false];
        yield 'voucher' => [true];
    }

    #[DataProvider('entryPoints')]
    public function testUnassociatedResourceIsRejected(bool $voucher): void
    {
        $request = $this->request(['resourceCode' => 'not-associated', 'orderToken' => $this->order()->getTokenValue()]);
        $controller = $this->controller(0);
        $response = $voucher ? $controller->createFromVoucher($this->voucher->getCode(), $request) : $controller->create($request);
        self::assertSame(409, $response->getStatusCode());
        $expected = ['error' => 'Cette ressource n’est pas associée à la prestation.'];
        if (!$voucher) {
            $expected['code'] = 'slot_unavailable';
        }
        self::assertSame($expected, json_decode($response->getContent(), true));
        self::assertSame(0, $this->bookingCount());
    }

    #[DataProvider('entryPoints')]
    public function testFlushFailureRollsBackWithoutEmail(bool $voucher): void
    {
        $request = $this->request(['orderToken' => $this->order()->getTokenValue()]);
        $listener = new class {
            public function onFlush(): void { throw new \RuntimeException('Injected persistence failure'); }
        };
        $events = $this->entityManager->getEventManager();
        $events->addEventListener(['onFlush'], $listener);
        try {
            $controller = $this->controller(0);
            $response = $voucher ? $controller->createFromVoucher($this->voucher->getCode(), $request) : $controller->create($request);
            self::assertSame(409, $response->getStatusCode());
            self::assertSame([
                'error' => $voucher ? 'Ce créneau vient d’être réservé. Le chèque reste disponible.' : 'Ce créneau vient d’être réservé. Choisissez-en un autre.',
                'code' => 'slot_unavailable',
            ], json_decode($response->getContent(), true));
            self::assertSame(0, $this->bookingCount());
            self::assertSame('active', $this->entityManager->getConnection()->fetchOne('SELECT status FROM skybook_gift_voucher WHERE code = ?', [$this->voucher->getCode()]));
            self::assertSame(1, $this->entityManager->getConnection()->getTransactionNestingLevel());
        } finally {
            $events->removeEventListener(['onFlush'], $listener);
        }
    }

    private function order(): Order
    {
        $order = new Order();
        $order->setTokenValue('order-'.$this->serviceCode);
        $order->setNumber('ORDER-'.$this->serviceCode);
        $order->setCheckoutState('completed');
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('en_US');
        $order->setChannel($this->entityManager->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']));
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        return $order;
    }

    private function request(array $overrides = []): Request
    {
        return Request::create('/api/v2/shop/bookings', 'POST', content: json_encode(array_replace([
            'serviceCode' => $this->serviceCode, 'planningCode' => $this->planningCode,
            'staffMemberId' => $this->staff->getId(),
            'start' => $this->start->format(DATE_ATOM), 'end' => $this->start->modify('+1 hour')->format(DATE_ATOM),
            'customer' => ['firstName' => 'Beneficiary', 'lastName' => 'Contract', 'email' => 'beneficiary@example.test'],
        ], $overrides), JSON_THROW_ON_ERROR));
    }

    private function bookingCount(): int
    {
        return $this->entityManager->getRepository(Booking::class)->count(['serviceCode' => $this->serviceCode]);
    }

    private function controller(int $emails): ShopBookingApiController
    {
        $container = self::getContainer();
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::exactly($emails))->method('send')->willReturnCallback(function (): void {
            // The fixture owns the outer transaction; creation must already have committed its own level.
            self::assertSame(1, $this->entityManager->getConnection()->getTransactionNestingLevel());
            self::assertSame(1, $this->bookingCount());
        });
        $channelRepository = $this->createMock(EntityRepository::class);
        $channelRepository->method('findOneBy')->willReturn(new Channel());
        $emailManager = $this->createMock(EntityManagerInterface::class);
        $emailManager->method('getRepository')->with(Channel::class)->willReturn($channelRepository);
        $dispatcher = new BookingEmailDispatcher($sender, $emailManager, $container->get(TenantContext::class), $container->get(CenterTimeZoneProvider::class), $container->get(TenantUrlGenerator::class));
        return new ShopBookingApiController($dispatcher, $container->get(BookingCreationService::class), $container->get(AvailabilityService::class), $container->get(BookingRepository::class), $this->entityManager, $container->get(CenterTimeZoneProvider::class), $container->get(BookingRules::class));
    }
}

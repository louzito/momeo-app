<?php

declare(strict_types=1);

namespace App\Tests\GiftCard;

use App\Entity\GiftCard;
use App\Entity\GiftCardMovement;
use App\Entity\Order\Order;
use App\Entity\Channel\Channel;
use App\Entity\Payment\Payment;
use App\Service\GiftCard\GiftCardService;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class GiftCardServiceTest extends TestCase
{
    private array $movements = [];
    private array $cards = [];
    private GiftCardService $service;
    private GiftCard $card;
    private Order $order;
    private \Sylius\Component\Mailer\Sender\SenderInterface $sender;

    protected function setUp(): void
    {
        $this->card = new GiftCard('demo', 'WEB', 'EUR', 10000, 'PURCHASE', new \DateTimeImmutable('+1 year'));
        $this->cards = [$this->card];
        $channel = new Channel();
        $channel->setCode('WEB');
        $this->order = $this->getMockBuilder(Order::class)->onlyMethods(['getTotal', 'getId'])->getMock();
        $this->order->method('getTotal')->willReturn(10000);
        $this->order->method('getId')->willReturn(1);
        $this->order->setNumber('USE-1');
        $this->order->setChannel($channel);
        $this->order->setCurrencyCode('EUR');
        $this->order->setCheckoutState('completed');
        $tenant = (new \ReflectionClass(TenantContext::class))->newInstanceWithoutConstructor();
        $tenant->setSlug('demo');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $operation) => $operation());
        $em->method('find')->willReturn($this->order);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof GiftCardMovement) $this->movements[] = $entity;
            if ($entity instanceof GiftCard) $this->cards[] = $entity;
        });
        $em->method('refresh')->willReturnCallback(static function (object $entity, $mode): void {
            self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
        });
        $em->method('getRepository')->willReturnCallback(function (string $class): EntityRepository {
            $repository = $this->createMock($class === GiftCard::class ? \App\Repository\GiftCardRepository::class : \App\Repository\GiftCardMovementRepository::class);
            $find = function (array $criteria) use ($class): array {
                $entities = $class === GiftCard::class ? $this->cards : $this->movements;
                return array_values(array_filter($entities, static function (object $entity) use ($criteria): bool {
                    foreach ($criteria as $field => $value) {
                        if ((new \ReflectionProperty($entity, $field))->getValue($entity) !== $value) return false;
                    }
                    return true;
                }));
            };
            if ($class === GiftCard::class) {
                $repository->method('findIssuedForOrder')->willReturnCallback(static fn (string $number) => $find(['purchaseOrderNumber' => $number])[0] ?? null);
            } else {
                $repository->method('findForOrderForUpdate')->willReturnCallback(static fn (string $number, ?GiftCard $card = null) => $find($card === null ? ['orderNumber' => $number] : ['orderNumber' => $number, 'card' => $card]));
            }
            $repository->method('findBy')->willReturnCallback($find);
            $repository->method('findOneBy')->willReturnCallback(static fn (array $criteria) => $find($criteria)[0] ?? null);
            return $repository;
        });
        $this->sender = $this->createMock(\Sylius\Component\Mailer\Sender\SenderInterface::class);
        $this->service = new GiftCardService($em, $tenant, $this->sender);
    }

    public function testReplayDoesNotDebitTwiceAndRefundIsCappedPerOrder(): void
    {
        $code = $this->card->getCode();
        $reservation = $this->service->reserve($code, 1, 7000);
        self::assertSame($reservation, $this->service->reserve($code, 1, 7000));
        $debit = $this->service->debit($code, 1);
        self::assertSame($debit, $this->service->debit($code, 1));
        self::assertSame(3000, $this->card->getAvailable());
        self::assertCount(2, $this->movements);
        $refund = $this->service->refund($code, 1, 2000, 'REFUND-1');
        self::assertSame($refund, $this->service->refund($code, 1, 2000, 'REFUND-1'));
        self::assertSame(5000, $this->card->getAvailable());
        $this->expectException(\DomainException::class);
        $this->service->refund($code, 1, 6000, 'REFUND-2');
    }

    public function testConflictingReplayIsRejected(): void
    {
        $this->service->reserve($this->card->getCode(), 1, 7000);
        $this->expectException(\DomainException::class);
        $this->service->reserve($this->card->getCode(), 1, 6000);
    }

    public function testFailureReleasesReservationOnlyOnce(): void
    {
        $this->service->reserve($this->card->getCode(), 1, 7000);
        $this->service->releaseForOrder(1);
        $this->service->releaseForOrder(1);
        self::assertSame(10000, $this->card->getAvailable());
        self::assertSame(0, $this->card->getReserved());
        self::assertCount(2, $this->movements);
        $this->expectException(\DomainException::class);
        $this->service->debit($this->card->getCode(), 1);
    }

    public function testGiftCardCannotBuyAnotherGiftCard(): void
    {
        $this->order->setGiftCardAmount(10000);
        $this->expectException(\DomainException::class);
        $this->service->reserve($this->card->getCode(), 1, 10000);
    }

    public function testIssueOnlyAfterFullPaymentAndOnlyOnce(): void
    {
        $customer = new \App\Entity\Customer\Customer();
        $customer->setEmail('buyer@example.test');
        $this->order->setCustomer($customer);
        $this->sender->expects(self::once())->method('send')->with('gift_card', ['buyer@example.test'], self::isType('array'));
        $this->order->setGiftCardAmount(10000);
        $payment = new Payment();
        $payment->setCurrencyCode('EUR');
        $payment->setAmount(10000);
        $this->order->addPayment($payment);
        self::assertNull($this->service->issueFromPayment($payment));
        $payment->setState('completed');
        $card = $this->service->issueFromPayment($payment);
        self::assertInstanceOf(GiftCard::class, $card);
        self::assertSame($card, $this->service->issueFromPayment($payment));
        self::assertCount(1, $this->movements);
        self::assertSame(10000, $card->getAvailable());
    }

    public function testAnotherOrderCannotReceiveARefundFromThisDebit(): void
    {
        $this->service->reserve($this->card->getCode(), 1, 7000);
        $this->service->debit($this->card->getCode(), 1);
        $this->order->setNumber('USE-2');
        $this->expectException(\DomainException::class);
        $this->service->refund($this->card->getCode(), 1, 1000, 'OTHER-REFUND');
    }

    public function testLegacyGiftPurchaseCannotBePaidWithCredit(): void
    {
        $this->order->setNotes(\App\Service\GiftVoucher\GiftOrderMarker::create(null, 'recipient@example.test', null)->encode());
        $this->expectException(\DomainException::class);
        $this->service->reserve($this->card->getCode(), 1, 1000);
    }

    public function testFailedUnnumberedCartDoesNotChangeCard(): void
    {
        $this->order->setNumber(null);
        $this->service->releaseForOrder(1);
        self::assertSame(10000, $this->card->getAvailable());
        self::assertCount(0, $this->movements);
    }

    public function testDepositDoesNotIssueCard(): void
    {
        $this->order->setGiftCardAmount(10000);
        $payment = new Payment();
        $payment->setCurrencyCode('EUR');
        $payment->setAmount(3000);
        $payment->setState('completed');
        $this->order->addPayment($payment);
        self::assertNull($this->service->issueFromPayment($payment));
        self::assertCount(0, $this->movements);
    }
}

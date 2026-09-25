<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminBookingApiController;
use App\Controller\ShopCustomerAccountApiController;
use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Entity\Planning;
use App\Entity\User\ShopUser;
use App\Entity\WaitlistRequest;
use App\Repository\WaitlistRequestRepository;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Booking\BookingLifecycle;
use App\Service\Booking\BookingNotOwned;
use App\Service\Booking\BookingRescheduler;
use App\Service\Email\BookingEmailDispatcher;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantUrlGenerator;
use App\Service\Waitlist\WaitlistNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Isolated Doctrine fixture; real policies/guards/services, intercepted outbound mail. */
final class CustomerBookingChangesContractTest extends \App\Tests\Availability\AvailabilityTestCase
{
    private Booking $reservation;
    private bool $failWaitlist = false;
    private array $emails = [];
    private array $expectedEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planning = new Planning();
        $planning->setCode($this->planningCode);
        $planning->setName('Mutation contract');
        $planning->setTimezone('Europe/Paris');
        $planning->setServiceCodes([$this->serviceCode]);
        $planning->setDays([strtolower($this->start->format('l')) => [['start' => '00:00', 'end' => '23:59']]]);
        $this->entityManager->persist($planning);
        $this->reservation = $this->booking($this->start, $this->start->modify('+1 hour'));
        $this->reservation->setStatus(Booking::STATUS_CONFIRMED);
        $this->reservation->setStaffMember($this->staff);
        $this->entityManager->persist($this->reservation);
        $this->configureRules(['minimumNoticeHours' => 0, 'maximumAdvanceDays' => 365, 'cancellationNoticeHours' => 24]);
        $product = $this->entityManager->getRepository(\App\Entity\Product\Product::class)->findOneBy(['code' => $this->serviceCode]);
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mutation contract');
        $product->setSlug($this->serviceCode);
        $this->entityManager->flush();
        $container = self::getContainer();
        $sender = $this->createMock(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (string $code): void {
            self::assertSame(1, $this->entityManager->getConnection()->getTransactionNestingLevel(), 'Send only after service commit');
            $this->emails[] = $code;
            if ($code === 'waitlist_availability' && $this->failWaitlist) throw new \RuntimeException('Transport unavailable');
        });
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(new Channel());
        $emailManager = $this->createMock(EntityManagerInterface::class);
        $emailManager->method('getRepository')->with(Channel::class)->willReturn($repository);
        $context = $container->get(TenantContext::class);
        $timezone = $container->get(CenterTimeZoneProvider::class);
        $urls = $container->get(TenantUrlGenerator::class);
        $container->set(BookingEmailDispatcher::class, new BookingEmailDispatcher($sender, $emailManager, $context, $timezone, $urls));
        $container->set(WaitlistNotifier::class, new WaitlistNotifier($container->get(WaitlistRequestRepository::class), $this->entityManager, $sender, $context, $timezone, $urls));
    }

    protected function tearDown(): void
    {
        try {
            self::assertSame($this->expectedEmails, $this->emails);
        } finally {
            parent::tearDown();
        }
    }

    public static function transitions(): iterable
    {
        yield 'postpone' => ['postpone', Booking::STATUS_POSTPONED];
        yield 'complete' => ['complete', Booking::STATUS_COMPLETED];
        yield 'no show' => ['noShow', Booking::STATUS_NO_SHOW];
        yield 'cancel' => ['cancel', Booking::STATUS_CANCELLED];
    }

    #[DataProvider('transitions')]
    public function testAdminTransitionsAndReplay(string $action, string $status): void
    {
        if ($action === 'cancel') $this->expectedEmails = ['booking_cancelled'];
        $admin = self::getContainer()->get(AdminBookingApiController::class);
        $response = $admin->$action($this->reservation, $this->request());
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->entityManager->refresh($this->reservation);
        self::assertSame($status, $this->reservation->getStatus());
        self::assertSame([], $this->reservation->getChangeHistory(), 'Admin historically adds no customer history');
        if ($action === 'postpone') self::assertSame('Report demandé par l’établissement.', $this->reservation->getPostponedReason());
        self::assertSame(409, $admin->$action($this->reservation, $this->request())->getStatusCode());
    }

    public function testAdminCanCancelPostponedBooking(): void
    {
        $this->reservation->setStatus(Booking::STATUS_POSTPONED);
        $this->entityManager->flush();
        $this->expectedEmails = ['booking_cancelled'];
        self::assertSame(200, self::getContainer()->get(AdminBookingApiController::class)->cancel($this->reservation)->getStatusCode());
    }

    #[DataProvider('callers')]
    public function testCustomerCancellationHistoryReplayAndWaitlistAfterCommit(bool $failWaitlist): void
    {
        $this->failWaitlist = $failWaitlist;
        $waiting = new WaitlistRequest();
        $waiting->setServiceCode($this->serviceCode);
        $waiting->setServiceName('Contract');
        $waiting->setCustomerFirstName('Waiting');
        $waiting->setCustomerLastName('Customer');
        $waiting->setCustomerEmail('waiting@example.test');
        $waiting->setPeriodStart($this->start);
        $waiting->setPeriodEnd($this->start->modify('+1 hour'));
        $this->entityManager->persist($waiting);
        $this->entityManager->flush();
        $this->expectedEmails = ['booking_cancelled', 'waitlist_availability'];
        $customer = self::getContainer()->get(ShopCustomerAccountApiController::class);
        $response = $customer->cancel($this->reservation->getPublicToken(), $this->user('CONTRACT@example.test'));
        self::assertSame(200, $response->getStatusCode());
        $history = $this->reservation->getChangeHistory();
        self::assertCount(1, $history);
        self::assertSame('customer', $history[0]['actor']);
        self::assertSame('cancelled', $history[0]['action']);
        self::assertSame($this->reservation->getSlotStart()->format(DATE_ATOM), $history[0]['previousStart']);
        self::assertSame($this->reservation->getSlotEnd()->format(DATE_ATOM), $history[0]['previousEnd']);
        self::assertNotEmpty($history[0]['at']);
        $replacement = $this->booking($this->start, $this->start->modify('+1 hour'));
        $replacement->setStaffMember($this->staff);
        self::getContainer()->get(\App\Service\Booking\BookingSlotGuard::class)->assertAvailable($replacement);

        $replay = $customer->cancel($this->reservation->getPublicToken(), $this->user());
        self::assertSame(409, $replay->getStatusCode());
        self::assertSame('change_deadline_passed', json_decode($replay->getContent(), true)['code']);
        self::assertEquals($history, $this->reservation->getChangeHistory());
    }

    public static function callers(): iterable
    {
        yield 'admin' => [false];
        yield 'customer' => [true];
    }

    #[DataProvider('callers')]
    public function testRescheduleSuccess(bool $customer): void
    {
        $this->expectedEmails = ['booking_rescheduled'];
        $oldStart = $this->reservation->getSlotStart()->format(DATE_ATOM);
        $response = $this->reschedule($customer);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->entityManager->refresh($this->reservation);
        self::assertSame($this->start->modify('+2 hours')->getTimestamp(), $this->reservation->getSlotStart()->getTimestamp());
        $history = $this->reservation->getChangeHistory();
        self::assertCount($customer ? 1 : 0, $history);
        if ($customer) {
            self::assertSame('customer', $history[0]['actor']);
            self::assertSame('rescheduled', $history[0]['action']);
            self::assertSame($oldStart, $history[0]['previousStart']);
            self::assertSame($this->start->modify('+2 hours')->format(DATE_ATOM), $history[0]['newStart']);
        }
    }

    #[DataProvider('callers')]
    public function testUnavailableResourceDoesNotLeavePartialChanges(bool $customer): void
    {
        $response = $this->reschedule($customer, ['resourceCode' => 'not-associated']);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'error' => 'Cette ressource n’est pas associée à la prestation.',
            'code' => $customer ? 'change_deadline_passed' : 'slot_unavailable',
        ], json_decode($response->getContent(), true));
        $this->assertUnchanged();
    }

    #[DataProvider('callers')]
    public function testCapacityConflictRollsBackAndRestoresManagedBooking(bool $customer): void
    {
        $blocking = $this->booking($this->start->modify('+2 hours'), $this->start->modify('+3 hours'));
        $blocking->setStatus(Booking::STATUS_CONFIRMED);
        $this->entityManager->persist($blocking);
        $this->entityManager->flush();
        $response = $this->reschedule($customer);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('slot_unavailable', json_decode($response->getContent(), true)['code']);
        $this->assertUnchanged();
    }

    #[DataProvider('callers')]
    public function testFlushFailureDoesNotPersistRescheduleOrSendEmail(bool $customer): void
    {
        $listener = new class {
            public function onFlush(): void { throw new \RuntimeException('Injected persistence failure'); }
        };
        $events = $this->entityManager->getEventManager();
        $events->addEventListener(['onFlush'], $listener);
        try {
            self::assertSame(409, $this->reschedule($customer)->getStatusCode());
        } finally {
            $events->removeEventListener(['onFlush'], $listener);
        }
        $this->assertUnchanged();
    }

    public static function customerActions(): iterable
    {
        yield 'cancel' => ['cancel'];
        yield 'reschedule' => ['reschedule'];
    }

    #[DataProvider('customerActions')]
    public function testOtherCustomerGets404(string $action): void
    {
        $controller = self::getContainer()->get(ShopCustomerAccountApiController::class);
        try {
            if ($action === 'cancel') $controller->cancel($this->reservation->getPublicToken(), $this->user('other@example.test'));
            else $controller->reschedule($this->reservation->getPublicToken(), $this->request(), $this->user('other@example.test'));
            self::fail('Ownership must be checked before mutation');
        } catch (NotFoundHttpException) {
            $this->assertUnchanged();
        }
    }

    #[DataProvider('customerActions')]
    public function testReusableCallsRecheckOwnerAfterLock(string $action): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE momeo_booking SET customer_email = ? WHERE id = ?', ['other@example.test', $this->reservation->getId()]);
        try {
            if ($action === 'cancel') self::getContainer()->get(BookingLifecycle::class)->cancelByCustomer($this->reservation, 'contract@example.test');
            else self::getContainer()->get(BookingRescheduler::class)->customer($this->reservation, 'contract@example.test', [], $this->start, $this->start->modify('+1 hour'));
            self::fail('Locked ownership must be checked');
        } catch (BookingNotOwned) {
            $this->assertUnchanged();
        }
    }

    #[DataProvider('customerActions')]
    public function testDeadlineRefusalDoesNotMutate(string $action): void
    {
        $this->reservation->setSlotStart(new \DateTimeImmutable('+1 hour'));
        $this->reservation->setSlotEnd(new \DateTimeImmutable('+2 hours'));
        $this->entityManager->flush();
        $controller = self::getContainer()->get(ShopCustomerAccountApiController::class);
        $response = $action === 'cancel' ? $controller->cancel($this->reservation->getPublicToken(), $this->user()) : $this->reschedule(true);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('change_deadline_passed', json_decode($response->getContent(), true)['code']);
        self::assertSame(Booking::STATUS_CONFIRMED, $this->reservation->getStatus());
        self::assertSame([], $this->reservation->getChangeHistory());
    }

    public function testManualCreationAndResourceRefusal(): void
    {
        $admin = self::getContainer()->get(AdminBookingApiController::class);
        $response = $admin->create($this->request(['resourceCode' => 'not-associated']));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'Cette ressource n’est pas associée à la prestation.'], json_decode($response->getContent(), true));
        $this->expectedEmails = ['booking_confirmation'];
        $response = $admin->create($this->request());
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true);
        self::assertSame('manual', $data['source']);
        self::assertSame('confirmed', $data['status']);
        self::assertSame('pay_on_site', $data['paymentState']);
        self::assertSame('EUR', $data['currencyCode']);
        self::assertMatchesRegularExpression('/^MOM-[0-9A-F]{8}$/', $data['reference']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['publicId']);
        self::assertSame(2, $this->entityManager->getRepository(Booking::class)->count(['serviceCode' => $this->serviceCode]));
    }

    #[DataProvider('customerActions')]
    public function testReusableCallsRejectAnotherOwnerBeforeTransaction(string $action): void
    {
        try {
            if ($action === 'cancel') self::getContainer()->get(BookingLifecycle::class)->cancelByCustomer($this->reservation, 'other@example.test');
            else self::getContainer()->get(BookingRescheduler::class)->customer($this->reservation, 'other@example.test', [], $this->start, $this->start->modify('+1 hour'));
            self::fail('Reusable calls require ownership');
        } catch (BookingNotOwned) {
            $this->assertUnchanged();
        }
    }

    public function testAccountRequiresAuthenticatedUser(): void
    {
        $attributes = (new \ReflectionClass(ShopCustomerAccountApiController::class))->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class);
        self::assertCount(1, $attributes);
        self::assertSame('ROLE_USER', $attributes[0]->newInstance()->attribute);
    }

    public function testManualCreationFlushFailureAndCustomerCancellationFailure(): void
    {
        $listener = new class {
            public function onFlush(): void { throw new \RuntimeException('Injected persistence failure'); }
        };
        $events = $this->entityManager->getEventManager();
        $events->addEventListener(['onFlush'], $listener);
        try {
            $response = self::getContainer()->get(AdminBookingApiController::class)->create($this->request());
            self::assertSame(409, $response->getStatusCode());
            self::assertSame(1, $this->entityManager->getRepository(Booking::class)->count(['serviceCode' => $this->serviceCode]));
            $response = self::getContainer()->get(ShopCustomerAccountApiController::class)->cancel($this->reservation->getPublicToken(), $this->user());
            self::assertSame(409, $response->getStatusCode());
            self::assertSame(['error' => 'L’annulation n’a pas pu être enregistrée.'], json_decode($response->getContent(), true));
        } finally {
            $events->removeEventListener(['onFlush'], $listener);
        }
        $this->assertUnchanged();
    }

    private function assertUnchanged(): void
    {
        self::assertSame(1, $this->entityManager->getConnection()->getTransactionNestingLevel());
        // Assert the identity map before refresh, then flush to detect latent partial changes.
        self::assertSame($this->start->getTimestamp(), $this->reservation->getSlotStart()->getTimestamp());
        self::assertSame(Booking::STATUS_CONFIRMED, $this->reservation->getStatus());
        self::assertSame([], $this->reservation->getChangeHistory());
        $this->entityManager->flush();
        $this->reservation = $this->entityManager->find(Booking::class, $this->reservation->getId());
        $this->entityManager->refresh($this->reservation);
        self::assertSame($this->start->getTimestamp(), $this->reservation->getSlotStart()->getTimestamp());
    }

    private function reschedule(bool $customer, array $overrides = []): \Symfony\Component\HttpFoundation\JsonResponse
    {
        return $customer
            ? self::getContainer()->get(ShopCustomerAccountApiController::class)->reschedule($this->reservation->getPublicToken(), $this->request($overrides), $this->user())
            : self::getContainer()->get(AdminBookingApiController::class)->reschedule($this->reservation, $this->request($overrides));
    }

    private function user(string $email = 'contract@example.test'): ShopUser
    {
        $user = new ShopUser();
        $customer = new \App\Entity\Customer\Customer();
        $customer->setEmail($email);
        $user->setCustomer($customer);
        $user->setEmail($email);
        return $user;
    }

    private function request(array $overrides = []): Request
    {
        return Request::create('/', 'POST', content: json_encode(array_replace([
            'serviceCode' => $this->serviceCode, 'planningCode' => $this->planningCode,
            'staffMemberId' => $this->staff->getId(),
            'start' => $this->start->modify('+2 hours')->format(DATE_ATOM),
            'end' => $this->start->modify('+3 hours')->format(DATE_ATOM),
            'customer' => ['firstName' => 'Contract', 'lastName' => 'Test', 'email' => 'contract@example.test'],
        ], $overrides), JSON_THROW_ON_ERROR));
    }
    public function testDeadlinePolicyIsEnforcedOnTheServer(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $config = new \App\Entity\Taxonomy\Taxon();
        $config->setCurrentLocale('en_US');
        $config->setFallbackLocale('en_US');
        $config->getTranslation('en_US')->setDescription('null');
        $repository->method('findOneBy')->willReturn($config);
        $em->method('getRepository')->willReturn($repository);
        $policy = new \App\Service\Booking\CustomerBookingChangePolicy($em, new \App\Service\Booking\BookingRules($em));
        $booking = new \App\Entity\Booking();
        $booking->setStatus(\App\Entity\Booking::STATUS_CONFIRMED);
        $booking->setSlotStart(new \DateTimeImmutable('2026-10-10T12:00:00Z'));
        self::assertSame(['cancelHours' => 24, 'rescheduleHours' => 24], $policy->limits());
        $policy->assertAllowed($booking, 'cancel', new \DateTimeImmutable('2026-10-09T11:59:59Z'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Le délai de modification de 24 heure(s)');
        $policy->assertAllowed($booking, 'cancel', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
    }
}

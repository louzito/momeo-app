<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\Booking\BookingSlotGuard;
use App\Service\Booking\SlotUnavailable;
use App\Controller\ShopBookingApiController;
use App\Entity\Booking;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/** Exercises the HTTP adapter and slot guard against the isolated tenant database. */
final class BookingRulesContractTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private string $serviceCode;
    private string $planningCode;
    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $suffix = bin2hex(random_bytes(6));
        $this->serviceCode = 'contract_'.$suffix;
        $this->planningCode = 'planning_contract_'.$suffix;
        $this->start = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Paris')))->modify('+1 day')->setTime(12, 0);

        $product = new Product();
        $product->setCode($this->serviceCode);
        $product->setEnabled(true);
        $this->entityManager->persist($product);
        $staff = new StaffMember();
        $staff->setFirstName('Contract');
        $staff->setLastName($suffix);
        $staff->setServiceCodes([$this->serviceCode]);
        $staff->setWorkingHours([strtolower($this->start->format('l')) => [['start' => '00:00', 'end' => '23:59']]]);
        $this->entityManager->persist($staff);
        $planning = new Taxon();
        $planning->setCode($this->planningCode);
        $planning->setEnabled(true);
        $planning->getTranslation('en_US')->setName($this->planningCode);
        $planning->getTranslation('en_US')->setSlug($this->planningCode);
        $planning->getTranslation('en_US')->setDescription(json_encode([
            'days' => [$this->start->format('Y-m-d') => ['12:00']], 'jumpCodes' => [$this->serviceCode],
        ], JSON_THROW_ON_ERROR));
        $this->entityManager->persist($planning);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager?->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public static function noticeRules(): iterable
    {
        yield 'open slot' => [0, 365, true];
        yield 'minimum notice' => [72, 365, false];
        yield 'maximum horizon' => [0, 1, false];
    }

    #[DataProvider('noticeRules')]
    public function testAvailabilityAndCreationEnforceServerRules(int $notice, int $horizon, bool $available): void
    {
        $this->configureRules(['minimumNoticeHours' => $notice, 'maximumAdvanceDays' => $horizon]);
        $controller = self::getContainer()->get(ShopBookingApiController::class);
        $response = $controller->availability(Request::create('/api/v2/shop/availability', 'GET', [
            'serviceCode' => $this->serviceCode, 'from' => $this->start->format('Y-m-d'), 'to' => $this->start->format('Y-m-d'),
        ]));
        self::assertSame(200, $response->getStatusCode());
        $slots = $this->planningSlots($response->getContent());
        self::assertCount($available ? 2 : 0, $slots);
        if (!$available) {
            $response = $controller->create(Request::create('/api/v2/shop/bookings', 'POST', content: json_encode([
                'serviceCode' => $this->serviceCode, 'start' => $this->start->format(DATE_ATOM), 'end' => $this->start->modify('+1 hour')->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR)));
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('booking_rule_violation', json_decode($response->getContent(), true)['code']);
            self::assertSame(0, $this->entityManager->getRepository(Booking::class)->count(['serviceCode' => $this->serviceCode]));
        }
    }

    public static function buffers(): iterable
    {
        yield 'without buffer' => [0, 0, '-45 minutes', '-5 minutes', true];
        yield 'before buffer' => [10, 0, '-45 minutes', '-5 minutes', false];
        yield 'after buffer' => [0, 10, '+65 minutes', '+90 minutes', false];
        yield 'touching boundary' => [5, 0, '-45 minutes', '-5 minutes', true];
    }

    #[DataProvider('buffers')]
    public function testBuffersAffectAvailabilityAndReservation(int $before, int $after, string $blockingStart, string $blockingEnd, bool $available): void
    {
        $this->configureRules(['bufferBeforeMinutes' => $before, 'bufferAfterMinutes' => $after]);
        $blocking = $this->booking($this->start->modify($blockingStart), $this->start->modify($blockingEnd));
        $blocking->setStatus(Booking::STATUS_CONFIRMED);
        $this->entityManager->persist($blocking);
        $this->entityManager->flush();
        $response = self::getContainer()->get(ShopBookingApiController::class)->availability(Request::create('/api/v2/shop/availability', 'GET', [
            'serviceCode' => $this->serviceCode, 'from' => $this->start->format('Y-m-d'), 'to' => $this->start->format('Y-m-d'),
        ]));
        self::assertSame(200, $response->getStatusCode());
        self::assertCount($available ? 2 : 0, $this->planningSlots($response->getContent()));
        $guard = self::getContainer()->get(BookingSlotGuard::class);
        if (!$available) {
            $this->expectException(SlotUnavailable::class);
        }
        $guard->assertAvailable($this->booking($this->start, $this->start->modify('+1 hour')));
    }

    /** @return list<array<string, mixed>> */
    private function planningSlots(string $json): array
    {
        return array_values(array_filter(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR)['member'],
            fn (array $slot): bool => $slot['planningCode'] === $this->planningCode,
        ));
    }

    private function configureRules(array $rules): void
    {
        $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config']);
        if (!$taxon instanceof Taxon) {
            $taxon = new Taxon();
            $taxon->setCode('todatempo_config');
            $taxon->getTranslation('en_US')->setName('Contract configuration');
            $taxon->getTranslation('en_US')->setSlug('contract-configuration');
            $this->entityManager->persist($taxon);
        }
        $taxon->getTranslation('en_US')->setDescription(json_encode(['timezone' => 'Europe/Paris', 'bookingRules' => $rules], JSON_THROW_ON_ERROR));
        $this->entityManager->flush();
    }

    private function booking(\DateTimeImmutable $start, \DateTimeImmutable $end): Booking
    {
        $booking = new Booking();
        $booking->setReference('CONTRACT-'.bin2hex(random_bytes(5)));
        $booking->setPublicToken(bin2hex(random_bytes(16)));
        $booking->setServiceCode($this->serviceCode);
        $booking->setServiceName('Contract');
        $booking->setPlanningCode($this->planningCode);
        $booking->setCustomerFirstName('Contract');
        $booking->setCustomerLastName('Test');
        $booking->setCustomerEmail('contract@example.test');
        $booking->setSlotStart($start->setTimezone(new \DateTimeZone('UTC')));
        $booking->setSlotEnd($end->setTimezone(new \DateTimeZone('UTC')));

        return $booking;
    }
}

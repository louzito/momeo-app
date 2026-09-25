<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\Booking\BookingSlotGuard;
use App\Service\Booking\SlotUnavailable;
use App\Controller\ShopBookingApiController;
use App\Entity\Booking;
use App\Entity\Product\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

/** Exercises the HTTP adapter and slot guard against the isolated tenant database. */
final class BookingRulesContractTest extends \App\Tests\Availability\AvailabilityTestCase
{
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

}

<?php

declare(strict_types=1);

namespace App\Tests\Availability;

use App\Controller\ShopBookingApiController;
use App\Entity\BookableResource;
use App\Entity\Booking;
use App\Entity\Planning;
use App\Entity\Product\Product;
use App\Entity\StaffTimeOff;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

final class AvailabilityServiceTest extends AvailabilityTestCase
{
    public static function constraints(): iterable
    {
        yield 'capacity available' => [2, false, false, 0, 2];
        yield 'capacity exhausted' => [1, false, false, 0, 0];
        yield 'staff break' => [2, true, false, 0, 0];
        yield 'staff absent' => [2, false, true, 0, 0];
        yield 'required resource available' => [2, false, false, 2, 2];
        yield 'required resource exhausted' => [2, false, false, 1, 0];
    }

    #[DataProvider('constraints')]
    public function testCombinedAvailability(int $capacity, bool $pause, bool $absent, int $resourceCapacity, int $expected): void
    {
        $planning = new Planning();
        $planning->setCode($this->planningCode);
        $planning->setName('Availability contract');
        $planning->setServiceCodes([$this->serviceCode]);
        $planning->setCapacity($capacity);
        $this->entityManager->persist($planning);
        $blocking = $this->booking($this->start, $this->start->modify('+1 hour'));
        $blocking->setStatus(Booking::STATUS_CONFIRMED);
        $this->entityManager->persist($blocking);
        if ($pause) {
            $this->staff->setWorkingHours([strtolower($this->start->format('l')) => [
                ['start' => '09:00', 'end' => '12:30'], ['start' => '13:00', 'end' => '18:00'],
            ]]);
        }
        if ($absent) {
            $absence = new StaffTimeOff();
            $absence->setStaffMember($this->staff);
            $absence->setStartsAt($this->start->setTimezone(new \DateTimeZone('UTC')));
            $absence->setEndsAt($this->start->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC')));
            $this->entityManager->persist($absence);
        }
        $this->configureRules([]);
        if ($resourceCapacity > 0) {
            $resource = new BookableResource();
            $resource->setCode('resource_'.$this->serviceCode);
            $resource->setName('Cabin');
            $resource->setCapacity($resourceCapacity);
            $resource->setCalendar([strtolower($this->start->format('l')) => [['start' => '00:00', 'end' => '23:59']]]);
            $this->entityManager->persist($resource);
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $this->serviceCode]);
            $product->setBookableResourceCodes([$resource->getCode()]);
            $product->setBookableResourceRequired(true);
            $blocking->setResourceCode($resource->getCode());
            $this->entityManager->flush();
        }
        $response = self::getContainer()->get(ShopBookingApiController::class)->availability(Request::create('/api/v2/shop/availability', 'GET', [
            'serviceCode' => $this->serviceCode, 'from' => $this->start->format('Y-m-d'), 'to' => $this->start->format('Y-m-d'),
        ]));
        self::assertSame(200, $response->getStatusCode());
        $slots = $this->planningSlots($response->getContent());
        self::assertCount($expected, $slots);
        foreach ($slots as $slot) {
            self::assertSame(2, $slot['capacity']);
            self::assertSame(1, $slot['booked']);
            self::assertSame(1, $slot['remaining']);
            self::assertSame($resourceCapacity > 0 ? ['resource_'.$this->serviceCode] : [], $slot['availableResourceCodes']);
            self::assertSame($resourceCapacity > 0, $slot['resourceRequired']);
        }
    }

    public function testAdminExplicitPlanningKeepsItsDifferentRangePolicy(): void
    {
        $planning = new Planning();
        $planning->setCode($this->planningCode);
        $planning->setName('Admin range contract');
        $planning->setServiceCodes([$this->serviceCode]);
        $planning->setTimezone('Europe/Paris');
        $planning->setDays([strtolower($this->start->format('l')) => [['start' => '09:00', 'end' => '10:00']]]);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $policy = self::getContainer()->get(\App\Service\Availability\PlanningSlotPolicy::class);
        $end = $this->start->modify('+1 hour');
        self::assertSame($planning, $policy->forAdmin($this->planningCode, 0, $this->serviceCode, $this->start, $end));
        self::assertNull($policy->forAdmin('', 0, $this->serviceCode, $this->start, $end));
        self::assertNull($policy->forAdmin($this->planningCode, 0, 'other', $this->start, $end));
        $this->expectException(\App\Service\Booking\SlotUnavailable::class);
        $this->expectExceptionMessage('Ce créneau ne figure plus au planning.');
        $policy->assertPlannedSlot($planning, $this->booking($this->start, $end), $this->start, $end);
    }

    public function testHttpValidationIsPreserved(): void
    {
        $controller = self::getContainer()->get(ShopBookingApiController::class);
        $response = $controller->availability(Request::create('/api/v2/shop/availability'));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'La prestation est obligatoire.'], json_decode($response->getContent(), true));
        $response = $controller->availability(Request::create('/api/v2/shop/availability', 'GET', ['serviceCode' => 'missing_'.$this->serviceCode]));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Cette prestation n’est pas disponible.'], json_decode($response->getContent(), true));
    }
}

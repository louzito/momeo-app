<?php

declare(strict_types=1);

namespace App\Tests\Availability;

use App\Entity\Booking;
use App\Entity\Planning;
use App\Entity\StaffMember;
use App\Repository\PlanningRepository;
use App\Service\Availability\PlanningSlotPolicy;
use App\Service\Booking\SlotUnavailable;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class PlanningSlotPolicyTest extends TestCase
{
    public function testPlanningTimezoneAndActualDurationAcrossDst(): void
    {
        $policy = new PlanningSlotPolicy(new PlanningRepository($this->createStub(ManagerRegistry::class)));
        $planning = new Planning();
        $planning->setTimezone('Europe/Paris');
        $planning->setDays(['sunday' => [['start' => '01:00', 'end' => '04:00']]]);
        $start = new \DateTimeImmutable('2026-03-29T00:30:00Z');
        $end = new \DateTimeImmutable('2026-03-29T01:30:00Z');
        self::assertTrue($policy->contains($planning, $start, $end));
        self::assertFalse($policy->contains($planning, $start, new \DateTimeImmutable('2026-03-29T22:00:00Z')));
        $booking = new Booking();
        $booking->setSlotStart(new \DateTimeImmutable('2026-03-28T12:00:00Z'));
        $booking->setSlotEnd(new \DateTimeImmutable('2026-03-28T13:00:00Z'));
        $policy->assertPlannedSlot($planning, $booking, $start, $end);
        $this->expectException(SlotUnavailable::class);
        $this->expectExceptionMessage('La durée de la prestation ne peut pas être modifiée.');
        $policy->assertPlannedSlot($planning, $booking, $start, $end->modify('+1 minute'));
    }

    public function testCustomerStaffHoursUsePlanningTimezoneAndRejectBreaks(): void
    {
        $policy = new PlanningSlotPolicy(new PlanningRepository($this->createStub(ManagerRegistry::class)));
        $planning = new Planning();
        $planning->setTimezone('America/New_York');
        $staff = new StaffMember();
        $staff->setWorkingHours(['monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']]]);
        $policy->assertStaffHours($staff, $planning, new \DateTimeImmutable('2026-09-07T13:00:00Z'), new \DateTimeImmutable('2026-09-07T16:00:00Z'));
        $this->expectException(SlotUnavailable::class);
        $this->expectExceptionMessage('Ce créneau est en dehors des horaires du collaborateur ou empiète sur une pause.');
        $policy->assertStaffHours($staff, $planning, new \DateTimeImmutable('2026-09-07T15:30:00Z'), new \DateTimeImmutable('2026-09-07T17:30:00Z'));
    }
}

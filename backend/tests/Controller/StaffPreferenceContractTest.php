<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ShopBookingApiController;
use App\Entity\StaffMember;
use App\Entity\StaffTimeOff;
use App\Service\Availability\PublicStaffSlot;
use Symfony\Component\HttpFoundation\Request;

final class StaffPreferenceContractTest extends \App\Tests\Availability\AvailabilityTestCase
{
    public function testAvailabilityPreservesIdentifiersFieldsOrderAndAutomaticChoice(): void
    {
        $this->configureRules([]);
        $response = self::getContainer()->get(ShopBookingApiController::class)->availability(Request::create('/api/v2/shop/availability', 'GET', [
            'serviceCode' => $this->serviceCode, 'from' => $this->start->format('Y-m-d'), 'to' => $this->start->format('Y-m-d'),
        ]));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(60, $data['durationMin']);
        self::assertTrue($data['staffConfigured']);
        self::assertSame('Europe/Paris', $data['timezone']);
        $slots = $this->planningSlots($response->getContent());
        $utc = $this->start->setTimezone(new \DateTimeZone('UTC'));
        $common = [
            'planningCode' => $this->planningCode,
            'start' => $utc->format(DATE_ATOM), 'end' => $utc->modify('+1 hour')->format(DATE_ATOM),
            'capacity' => 1, 'booked' => 0, 'remaining' => 1,
            'resourceCode' => null, 'resourceName' => null, 'resourceRequired' => false,
            'availableResourceCodes' => [], 'compatibleJumpTypeIds' => [$this->serviceCode], 'serviceCode' => $this->serviceCode,
        ];
        $name = trim($this->staff->getFirstName().' '.$this->staff->getLastName());
        self::assertSame([
            ['id' => sprintf('staff_none_%s_%s_%s', $this->planningCode, $utc->format('Ymd_Hi'), $this->serviceCode)] + $common + ['staffMemberId' => null, 'staffName' => null, 'instructor' => 'Sans préférence'],
            ['id' => sprintf('staff_%d_%s_%s', $this->staff->getId(), $utc->format('Ymd_Hi'), $this->serviceCode)] + $common + ['staffMemberId' => $this->staff->getId(), 'staffName' => $name, 'instructor' => $name],
        ], $slots);
        self::assertSame($this->staff, self::getContainer()->get(PublicStaffSlot::class)->chooseAutoStaff($this->serviceCode, $this->start->setTimezone(new \DateTimeZone('UTC')), $this->start->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC')), $this->planningCode));
    }

    public function testAutomaticChoiceSkipsAbsentStaffAndThenReturnsNull(): void
    {
        $second = new StaffMember();
        $second->setFirstName('Second');
        $second->setLastName('Candidate');
        $second->setPosition($this->staff->getPosition() + 1);
        $second->setServiceCodes([$this->serviceCode]);
        $second->setWorkingHours($this->staff->getWorkingHours());
        $this->entityManager->persist($second);
        $absence = new StaffTimeOff();
        $absence->setStaffMember($this->staff);
        $absence->setStartsAt($this->start->setTimezone(new \DateTimeZone('UTC')));
        $absence->setEndsAt($this->start->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC')));
        $this->entityManager->persist($absence);
        $this->configureRules([]);
        $selection = self::getContainer()->get(PublicStaffSlot::class);
        self::assertSame($second, $selection->chooseAutoStaff($this->serviceCode, $this->start->setTimezone(new \DateTimeZone('UTC')), $this->start->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC')), $this->planningCode));
        $second->setBookable(false);
        $this->entityManager->flush();
        self::assertNull($selection->chooseAutoStaff($this->serviceCode, $this->start->setTimezone(new \DateTimeZone('UTC')), $this->start->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC')), $this->planningCode));
    }

    public function testPublicWriteRequiresCatalogueDurationAndGeneratedDeparture(): void
    {
        $this->configureRules([]);
        $policy = self::getContainer()->get(PublicStaffSlot::class);
        $start = $this->start->setTimezone(new \DateTimeZone('UTC'));
        self::assertSame('Ce créneau ne correspond plus aux disponibilités de cette prestation.', $policy->validateStaffSlot($this->staff, $this->serviceCode, $start, $start->modify('+30 minutes'), $this->planningCode));
        self::assertSame('Ce créneau ne figure plus au planning.', $policy->validateStaffSlot($this->staff, $this->serviceCode, $start->modify('+5 minutes'), $start->modify('+65 minutes'), $this->planningCode));
        self::assertSame('Ce collaborateur n’est pas disponible.', $policy->validateStaffSlot(null, $this->serviceCode, $start, $start->modify('+1 hour'), $this->planningCode));
    }

    public function testStaffEligibilityFiltersOnActiveBookableAndCompetent(): void
    {
        $eligible = new \App\Entity\StaffMember();
        $eligible->setActive(true);
        $eligible->setBookable(true);
        $eligible->setServiceCodes(['massage']);
        $inactive = clone $eligible;
        $inactive->setActive(false);
        $notBookable = clone $eligible;
        $notBookable->setBookable(false);
        $otherService = clone $eligible;
        $otherService->setServiceCodes(['other']);
        self::assertSame([$eligible], \App\Service\Staff\StaffEligibility::forService(
            [$inactive, $notBookable, $otherService, $eligible], 'massage',
        ));
    }
}

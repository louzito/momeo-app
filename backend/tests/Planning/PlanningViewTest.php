<?php

declare(strict_types=1);

namespace App\Tests\Planning;

use App\Entity\Planning;
use App\Entity\StaffMember;
use App\Service\Planning\PlanningView;
use PHPUnit\Framework\TestCase;

final class PlanningViewTest extends TestCase
{
    public function testWeeklyAndLegacySchedulesRemainDistinct(): void
    {
        $planning = new Planning();
        $planning->setCode('weekly');
        $planning->setName('Planning');
        $planning->setDays(['monday' => [['start' => '09:00', 'end' => '12:00']]]);
        $planning->setServiceCodes(['massage', 'massage']);
        $legacy = ['days' => ['2026-10-25'], 'openDays' => [0], 'times' => ['09:00'], 'private' => 'hidden'];
        $planning->setLegacyConfig($legacy);
        $view = new PlanningView();
        $data = $view->admin($planning);
        self::assertSame(explode(' ', 'id code name timezone staffMemberId scope weeklyDays days openDays times capacity serviceCodes jumpCodes active createdAt updatedAt'), array_keys($data));
        self::assertSame($planning->getDays(), $data['weeklyDays']);
        self::assertSame(['2026-10-25'], $data['days']);
        self::assertSame([0], $data['openDays']);
        self::assertSame(['09:00'], $data['times']);
        self::assertSame(['massage'], $data['serviceCodes']);
        self::assertSame($data['serviceCodes'], $data['jumpCodes']);
        self::assertSame('establishment', $data['scope']);
        self::assertNull($data['staffMemberId']);
        $staff = new StaffMember();
        (new \ReflectionProperty(StaffMember::class, 'id'))->setValue($staff, 15);
        $planning->setStaffMember($staff);
        $planning->setLegacyConfig(null);
        $data = $view->admin($planning);
        self::assertSame('staff', $data['scope']);
        self::assertSame(15, $data['staffMemberId']);
        self::assertSame([], $data['days']);
        self::assertSame([], $data['openDays']);
        self::assertSame([], $data['times']);
    }
}
